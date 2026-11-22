# Onboarding de uma região

Runbook da página 2 da wiki: criar uma região deve ser `terraform apply` + `ansible-playbook` + validação.
O exemplo usa `sa-east-1` (São Paulo). Na AWS, `infra/prod/hostrema-prod.sh` faz todos os passos abaixo sozinho
(veja `docs/platform.md`, "Produção na AWS com um comando"); este guia é o mesmo processo à mão.

## Arquitetura da região

```
                 *.apps.example.com / edge.sa-east-1.example.com (Route53 latency ou Cloudflare)
                                         │
                         Network Load Balancer (TCP 80/443, IP do cliente preservado)
                                         │
              ┌──────────────────────────┼──────────────────────────┐
              ▼                          ▼                          ▼
   nó 01: edge :10080/:10443   nó 02: edge :10080/:10443   …   (Traefik "edge", configuração
          │                          │                            vinda do Coolify a cada 5 s)
          └──────── HTTP pelo Host / HTTPS por SNI (passthrough) ──► coolify-proxy :80/:443 do nó
                                                                     que roda o host (TLS + ACME)

   nó de controle (sub-rede pública): bastion SSH, WireGuard do control plane, registry mirror,
   Prometheus/Alertmanager/Loki/Grafana/Blackbox
```

- O **control plane** (Coolify master) gerencia os nós por SSH (não há agente residente), pelo peering entre as VPCs ou pelo túnel WireGuard até o nó de controle, que faz NAT apenas para a porta 22 dos nós.
- O **edge** existe porque um único wildcard DNS/LB precisa chegar a qualquer nó. Cada edge sabe, via `GET /api/v1/platform/edge/sa-east-1`, em que nó(s) roda cada host. O TLS continua no proxy do Coolify de cada nó, e os desafios ACME HTTP-01 passam pelo edge normalmente.
- Os **containers dos tenants** não alcançam o metadata service, a VPC, o WireGuard nem as portas de gerência do host (role `firewall`). O IMDSv2 com hop limit 1 também impede o uso das credenciais da instância.

## Pré-requisitos

1. Um control plane Coolify rodando a imagem do fork, com `PLATFORM_ENABLED=true` (veja `docs/platform.md`).
2. No Coolify, como time root:
   - **Keys & Tokens → Private Keys**: crie ou gere a chave que o Coolify usará nos nós e anote o uuid e a chave pública;
   - **Keys & Tokens → API tokens**: crie um token com permissões *read* e *write* para o Ansible.
3. Conta AWS com permissão para VPC, EC2, ELB, S3, IAM, DLM e Route53 (opcional), Terraform ≥ 1.6 e Ansible ≥ 9 (`pip install ansible`).
4. Na AWS, a saída `region_peering` do control plane. Fora dela, WireGuard no host do control plane (`wg genkey | tee priv | wg pubkey > pub`).

## 1. Infraestrutura (Terraform)

```bash
cd infra/terraform/regions/sa-east-1
cp terraform.tfvars.example terraform.tfvars   # chave SSH, CIDRs de admin, IP do control plane, domínios
terraform init
terraform plan -out region.tfplan
terraform apply region.tfplan
```

O que é criado:

- **Rede:** VPC 10.20.0.0/16 com sub-redes públicas e privadas em 2 AZs, NAT gateway e endpoint S3.
- **Instâncias:** nó de controle (`m6i.2xlarge`, EIP, 1 TB) e 2 nós de execução (`m6i.8xlarge`, volume de dados gp3 de 1 TB em `/data`), todos com IMDSv2 obrigatório e discos criptografados.
- **Load balancer:** NLB (80→10080, 443→10443) com health check em `:10082/ping`.
- **Security groups:** só o necessário (veja `security.tf`).
- **Backups:** bucket S3 versionado e criptografado, com lifecycle e replicação opcional para outra região, e snapshots diários dos volumes de dados (DLM, 7 retidos).
- **DNS (Route53, opcional):** `*.apps_domain` com latency routing e `edge_domain`.
- **Inventário do Ansible:** `infra/ansible/inventory/sa-east-1.yml`.

**Clientes Enterprise com VM própria** (isolamento por máquina virtual, wiki 3):

```hcl
dedicated_nodes = {
  acme = { team_id = 42, instance_type = "m6i.2xlarge" }
}
```

Cada entrada cria uma VM na mesma rede e no mesmo balanceador. O inventário marca a VM com `platform_dedicated_team_id`, e o `bootstrap-nodes.yml` a registra no Coolify como nó exclusivo do time 42; nenhum outro cliente é colocado nela. Para tirar o cliente da VM, remova a entrada e rode `terraform apply`.

**Traces dos clientes:** defina `otlp_domain` (por exemplo `otlp.sa-east-1.hostrema.com.br`). As portas 80/443 do nó de controle são abertas e, com Route53, o registro DNS é criado. O Ansible sobe o gateway com certificado Let's Encrypt.

Para habilitar bancos públicos dos tenants, use `public_database_access = true`: os nós vão para sub-redes públicas com EIP e a faixa 20000–29999 é aberta.
Sem Route53, aponte `*.apps.example.com` e `edge.sa-east-1.example.com` (CNAME) para o output `edge_lb_dns_name`.
O state remoto é configurado com `backend.tf.example`.

## 2. Segredos (Ansible Vault)

```bash
cd infra/ansible
cp inventory/group_vars/all/vault.yml.example inventory/group_vars/all/vault.yml
$EDITOR inventory/group_vars/all/vault.yml      # token da API, uuid/chave pública, tokens edge/metrics, WireGuard, Grafana
ansible-vault encrypt inventory/group_vars/all/vault.yml
$EDITOR inventory/group_vars/all/main.yml       # coolify_url, platform_apps_wildcard, alertas
```

`platform_edge_token` e `platform_metrics_token` têm de ser os valores mostrados em
/admin/platform → Configurações → Tokens internos (gerados na primeira subida, ou os do `.env`, se você definiu
`PLATFORM_EDGE_TOKEN` e `PLATFORM_METRICS_TOKEN`). Se gerar um token novo no painel, atualize o vault e rode o playbook.

## 3. Nó de controle e nós de execução (Ansible)

```bash
ansible-playbook -i inventory/sa-east-1.yml playbooks/site.yml --ask-vault-pass
# ou em etapas:
ansible-playbook -i inventory/sa-east-1.yml playbooks/control-node.yml --ask-vault-pass
ansible-playbook -i inventory/sa-east-1.yml playbooks/bootstrap-nodes.yml --ask-vault-pass
```

O playbook do controle (`control-node.yml`) roda os roles `common` (pacotes, sysctl, sshd, auditd, journald, montagem de `/data`), `docker`, `firewall`, `wireguard`, `registry-mirror` (cache do Docker Hub com TLS, mais um registry privado opcional) e `monitoring-stack` (o conteúdo de `infra/monitoring` com alvos gerados a partir do inventário).

O `bootstrap-nodes.yml` roda, em cada nó:

- `tenant-limits`: script `hostrema-tenant-limits`, slice `tenant.slice` (até 90% da memória do nó para os clientes) e timer que reaplica os limites a cada 5 minutos;
- `common`, `docker` (driver de cgroup do systemd, data-root em `/data/docker`, pools de endereço para uma rede por tenant, `no-new-privileges`, `live-restore`, logs limitados, mirror regional e gVisor opcional) e `firewall`;
- `monitoring-agent` (node_exporter, cAdvisor e Grafana Alloy, que manda os logs dos containers ao Loki com o label `tenant_id`; o Promtail chegou ao fim da vida em março de 2026 e é removido) e `edge`;
- `coolify-agent`, que cria os diretórios `/data/coolify`, autoriza a chave do control plane (lida do próprio Coolify, numa linha marcada `hostrema-coolify` e só a partir do nó de controle ou da rede do painel), cadastra o nó via `POST /api/v1/servers`, espera o Coolify validá-lo e então atribui região, wildcard e o endereço do Loki da região (histórico de logs) via `POST /api/v1/platform/servers/{uuid}/region`.

Na atribuição de região o Coolify também cria as redes `tenant-{id}` dos tenants já provisionados no novo nó.

### Conectar o control plane

**Na AWS (recomendado, e obrigatório com o painel em cluster):** cole a saída `region_peering` de `infra/terraform/control-plane` em `control_plane_peering` no `terraform.tfvars` da região e rode `terraform apply`. O Terraform cria o peering entre as duas VPCs, as rotas dos dois lados e a regra de SSH nos nós. O inventário gerado já desliga o WireGuard e autoriza a chave do Coolify a partir da rede do painel. Use um CIDR diferente para cada região (`10.20.0.0/16`, `10.21.0.0/16`…), que não se sobreponha ao do painel (`10.10.0.0/16`).

**Fora da AWS (WireGuard):** no host do control plane, crie `/etc/wireguard/wg-sa-east-1.conf`:

```ini
[Interface]
Address = 10.99.0.2/24
PrivateKey = <chave privada do control plane>

[Peer]
PublicKey = <chave pública do nó de controle (wg pubkey < vault_wireguard_private_key)>
Endpoint = <IP público do nó de controle>:51820
AllowedIPs = 10.20.0.0/16
PersistentKeepalive = 25
```

Depois rode `systemctl enable --now wg-quick@wg-sa-east-1`.

### Proxy dos nós (IP real do cliente)

O edge envia `X-Forwarded-For` com o IP do cliente e, no HTTPS, o PROXY protocol. Ao cadastrar cada nó, o Ansible manda a rede da região (`trusted_proxies`). O painel acrescenta ao Traefik do nó `--entrypoints.http.forwardedheaders.trustedips` e `--entrypoints.https.proxyprotocol.trustedips` com essa rede e reinicia o proxy. Não há nada a editar à mão.

Com todos os nós cadastrados, ligue `PLATFORM_EDGE_PROXY_PROTOCOL=true` (Configurações → Tokens internos → PROXY protocol) para preservar o IP também no HTTPS. O `infra/prod/hostrema-prod.sh` faz isso sozinho no fim.

## 4. Monitoramento

Grafana, Prometheus e Alertmanager escutam apenas no localhost do nó de controle:

```bash
ssh -L 3000:127.0.0.1:3000 -L 9090:127.0.0.1:9090 ubuntu@<ip do nó de controle>
```

Os dashboards *Platform overview* e *Tenant overview* (CPU, memória, egress, reservas e logs por `tenant_id`) são provisionados automaticamente.
As regras de alerta estão em `infra/monitoring/prometheus/rules/platform.yml` (nós, tenants, control plane, fila, cobrança e sondas).

## 5. Validação (checklist da wiki)

```bash
# no nó de controle
PLATFORM_EDGE_TOKEN=... ./region-smoke-test.sh https://cloud.example.com sa-east-1 \
  <edge_lb_dns_name> "10.20.128.10 10.20.144.10" https://teste.apps.example.com
```

- [ ] **Smoke deploy:** crie um tenant de teste, faça o deploy de uma aplicação em cada nó e confira o pull pelo mirror (`docker info | grep -A1 Mirrors`) e o health check.
- [ ] **Failover do LB:** pare o edge de um nó (`docker stop platform-edge-edge-1`); o NLB deve tirá-lo do pool em cerca de 30 s.
- [ ] **Imagens:** `infra/scripts/promote-to-mirror.sh` do registry central para o regional (registry privado ligado).
- [ ] **Backup e restore:** faça um backup agendado de um banco de teste para S3 e restaure; restaure um snapshot de volume (DLM) numa instância temporária.
- [ ] **Segurança:** `nmap` externo (só 80/443 no NLB e 22 no bastion); dentro de um container de tenant, `curl 169.254.169.254`, `curl <ip privado de outro nó>:9100` e `curl <ip do host>:22` precisam falhar.
- [ ] **WireGuard:** `wg show` no nó de controle com handshake recente, e todos os nós `online` no Coolify.

## Runbooks de emergência

### Falha de nó

1. O alerta `NodeDown` ou `PlatformNodeUnreachable` dispara, e o NLB remove o nó sozinho.
2. Se o nó não voltar, aumente `node_count` ou substitua a instância com `terraform apply -replace='module.region.aws_instance.node[N]'`.
3. Restaure o volume de dados a partir do último snapshot (DLM), anexe-o como `/dev/sdf` e ajuste `data_volume_id` se ele mudar.
4. Rode `ansible-playbook ... bootstrap-nodes.yml --limit <nó>`. O Coolify reconhece o nó pelo IP, e se o IP mudar ele é cadastrado de novo.
5. Redeploy dos recursos afetados (o console mostra os tenants do nó).

### Região fora do ar

O wildcard com latency routing e `evaluate_target_health` faz o Route53 responder com a próxima região saudável.
Os dados dos tenants não são replicados entre regiões automaticamente: restaure os backups do bucket replicado (`backup_replication_bucket_arn`).

### Registry indisponível

Sem o mirror, o Docker dos nós volta ao Docker Hub automaticamente (`registry-mirrors` é só cache). Corrija rodando `control-node.yml` de novo ou recriando o nó de controle.

### Rotação da chave SSH do Coolify

```bash
ansible-playbook -i inventory/sa-east-1.yml playbooks/rotate-coolify-key.yml --ask-vault-pass
# confira no Coolify que todos os servidores estão "reachable", depois, em cada região:
ansible-playbook -i inventory/sa-east-1.yml playbooks/rotate-coolify-key.yml -e rotation_phase=finish --ask-vault-pass
```

A primeira fase cria a chave nova, autoriza nos nós ao lado da atual, grava no Coolify com o mesmo uuid e valida todos os nós. A segunda revoga a antiga. A chave privada nova fica só em `infra/ansible/.rotation/` até o fim (apague depois da última região).

### Atualização do control plane

Crie a tag `platform-vX.Y.Z`. O workflow `platform-release.yml` faz build, scan, assinatura e replicação, e depois o job *deploy* (environment `production`, com aprovação) roda `upgrade-control-plane.sh`, que volta à imagem anterior se `/api/health` não responder em 5 minutos. Com o painel em cluster, `CONTROL_PLANE_HOST` lista todas as instâncias (saída `ssh_hosts` do Terraform): elas são atualizadas uma por vez, e uma falha faz todas voltarem à versão anterior.

## Escala

- **Mais nós:** aumente `node_count` e rode `terraform apply` + `bootstrap-nodes.yml --limit <novos nós>`. O alerta `NodeHighCpu` (> 85% por 15 min) é o gatilho sugerido.
- **Workers:** o control plane escala o Horizon pelo tamanho das filas (`platform_queue_length`).
