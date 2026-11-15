# Hostrema — plataforma multi-tenant

O Hostrema é este fork do Coolify transformado num PaaS pago e multi-tenant, conforme a wiki do projeto.
Tudo fica desligado até `PLATFORM_ENABLED=true`. Sem isso o fork se comporta como o Coolify original.

## Conceitos

| Conceito | No código |
| --- | --- |
| Operador | Time root (id 0). É dono de todos os nós e usa o Coolify normalmente. |
| Tenant | Qualquer outro time. Recebe um `PlatformTenant` (plano, status, cotas e período). |
| Região | `PlatformRegion` (ex.: `sa-east-1`). Agrupa os nós de execução. |
| Nó compartilhado | Servidor do time root com região e sem `platform_dedicated_team_id`. |
| Nó dedicado | Servidor reservado a um tenant (Enterprise). |
| Rede do tenant | `tenant-{id}`, uma rede Docker por tenant em cada nó (`StandaloneDocker` com `team_id`). |

Fluxo do tenant: assinatura paga com Pix ou boleto (Banco Inter), provisionamento das redes nos nós da região, onboarding
(projeto inicial, sem pedir servidor) e deploys nos nós compartilhados.

## Começo rápido

1. No `.env` do control plane: `PLATFORM_ENABLED=true`, `APP_NAME=Hostrema`, `APP_LOCALE=pt-br` e os tokens `PLATFORM_METRICS_TOKEN`/`PLATFORM_EDGE_TOKEN` (abaixo).
2. Entre com o usuário root e abra **/admin/platform → Cobrança**. Cole o Client ID, o Client Secret, o certificado (.crt) e a chave (.key) da integração criada no Inter Empresas e clique em **Salvar e conectar**. O painel testa a conexão e registra o webhook sozinho.
3. Confira os preços dos planos na mesma tela (padrão: Básico R$ 49,90/mês ou R$ 499/ano, Profissional R$ 149,90/mês ou R$ 1.499/ano, Enterprise sob consulta).

A partir daí o cliente escolhe o plano, informa os dados de cobrança e paga com Pix (confirmação em segundos) ou boleto. O plano é ativado sozinho quando o Inter confirma o pagamento.

### Criar a integração no Banco Inter

1. No Internet Banking do Inter Empresas, vá em **Soluções para sua empresa → Nova integração** (ou "Integrações → Nova aplicação").
2. Marque a **API Cobrança (Boleto e Pix)** com as permissões de leitura e escrita (escopos `boleto-cobranca.read` e `boleto-cobranca.write`).
3. Baixe o arquivo zip: ele traz o certificado (`.crt`) e a chave (`.key`). O Client ID e o Client Secret aparecem na tela da integração.
4. O certificado vale 1 ano. Antes de vencer, gere outro e cole no console.

Para testar sem dinheiro real, crie a integração no sandbox do Inter, escolha **Sandbox (testes)** no console e use **Simular pagamento Pix** na lista de faturas.

## Configuração (.env do control plane)

Mínimo para ligar a plataforma:

```dotenv
PLATFORM_ENABLED=true            # liga o modo plataforma (implica SELF_HOSTED=false)
PLATFORM_NAME=Hostrema
PLATFORM_SUPPORT_EMAIL=suporte@example.com
PLATFORM_SALES_URL=https://example.com/contato   # botão "Falar com vendas" do Enterprise
PLATFORM_CURRENCY=brl
PLATFORM_DEFAULT_REGION=sa-east-1
PLATFORM_BASE_DOMAIN=apps.example.com            # domínios padrão dos tenants (*.apps.example.com)
PLATFORM_RESERVED_HOSTS=www,api,app,admin,dashboard,grafana,prometheus,status,mail,smtp,registry

# Cobrança (padrão: Banco Inter). As credenciais podem ficar só no console (/admin/platform → Cobrança).
PLATFORM_BILLING_PROVIDER=inter
INTER_ENVIRONMENT=production            # ou sandbox
INTER_CLIENT_ID=...
INTER_CLIENT_SECRET=...
INTER_CERTIFICATE=/caminho/certificado.crt   # caminho, conteúdo PEM ou PEM em base64
INTER_PRIVATE_KEY=/caminho/chave.key
INTER_CONTA_CORRENTE=                   # só se a integração tiver mais de uma conta
PLATFORM_BILLING_DUE_DAYS=5             # prazo para pagar cada fatura
PLATFORM_BILLING_CANCEL_AFTER_DAYS=30   # dias após o vencimento até o banco baixar o boleto
PLATFORM_BILLING_FINE_PERCENT=2         # multa por atraso
PLATFORM_BILLING_INTEREST_PERCENT_MONTH=1
PLATFORM_BASIC_PRICE_MONTHLY=49.90      # preços também editáveis no console
PLATFORM_PRO_PRICE_MONTHLY=149.90

# Tokens dos endpoints internos (gere com: openssl rand -hex 32)
PLATFORM_METRICS_TOKEN=...      # Prometheus -> /api/v1/platform/metrics
PLATFORM_EDGE_TOKEN=...         # edge routers -> /api/v1/platform/edge/{região}
```

Outras opções (todas com padrão em `config/platform.php`):

- **Cotas por plano.** `PLATFORM_{BASIC,PRO,ENTERPRISE}_{CPUS,MEMORY_MB,STORAGE_GB,RESOURCES,CONTAINERS,DEPLOYMENTS_PER_DAY,CUSTOM_DOMAINS,EGRESS_GB}`. O valor `0` significa ilimitado para deploys e domínios.
- **Excedentes.** `PLATFORM_OVERAGE_EGRESS_GB_CENTS` e `PLATFORM_OVERAGE_STORAGE_GB_MONTH_CENTS`.
- **Containers.** `PLATFORM_CONTAINER_DEFAULT_CPUS`, `..._DEFAULT_MEMORY_MB`, `..._CAP_ADD`, `..._OOM_SCORE_ADJ`, `..._LOG_MAX_SIZE/FILE`. Com `PLATFORM_CONTAINER_RUNTIME=runsc` os tenants rodam no gVisor (ative `docker_gvisor_enabled` no Ansible). `PLATFORM_ENFORCE_STORAGE_OPT=true` aplica limite de disco por container, o que exige overlay2 sobre xfs com pquota.
- **Bloqueios.** `PLATFORM_QUOTA_WARNING_PERCENT` (80), `PLATFORM_SUSPEND_AFTER_DAYS` (7) e `PLATFORM_HARD_SUSPEND_AFTER_DAYS` (30).
- **Medição.** `PLATFORM_METERING_INTERVAL_MINUTES` (5) e `PLATFORM_METERING_RAW_RETENTION_DAYS` (90).
- **Bancos públicos.** `PLATFORM_PUBLIC_PORT_MIN/MAX` (20000–29999) definem a faixa de portas públicas.
- **Opções do tenant.** `PLATFORM_ALLOW_TENANT_SERVERS=false` impede tenants de cadastrar servidores próprios. `PLATFORM_TENANT_TERMINAL=false` deixa o terminal web desligado para tenants.
- **Idioma.** Com `APP_LOCALE=pt-br`, as páginas da plataforma (planos, cobrança, avisos, onboarding e console) ficam em português, usando `lang/platform/pt-br.json`. O resto do painel do Coolify continua em inglês, exceto as telas de login, que o Coolify já traduz.

### Imagem do fork no control plane

O Coolify instalado pelo script oficial usa `ghcr.io/coollabsio/coolify`. Para usar a imagem do fork
sem editar os arquivos originais, crie `/data/coolify/source/docker-compose.custom.yml`.
O script de upgrade do Coolify já respeita esse arquivo:

```yaml
services:
  coolify:
    image: "ghcr.io/bad-wolf/hostrema:platform-v1.0.0"
```

As variáveis `PLATFORM_*` vão no `/data/coolify/source/.env`. O workflow `platform-release.yml`
publica a imagem e pode fazer o upgrade com rollback automático (`infra/scripts/upgrade-control-plane.sh`).

### Control plane em alta disponibilidade

`infra/terraform/control-plane/` cria o painel central conforme a página 6 da wiki:

- **Instância:** EC2 do Coolify com IP fixo (EIP), disco criptografado, recuperação automática da AWS em falha de hardware e snapshots diários.
- **PostgreSQL:** RDS Multi-AZ, com backup diário, recuperação para qualquer ponto no tempo (WAL) por 30 dias e proteção contra exclusão.
- **Redis:** ElastiCache com réplica e failover automático.

Depois do `terraform apply`:

1. Instale o Coolify na instância com o script oficial.
2. Acrescente ao `/data/coolify/source/.env` as linhas do output `terraform output -raw env`.
3. Copie `docker-compose.custom.yml.example` para `/data/coolify/source/docker-compose.custom.yml`. Ele usa a imagem do Hostrema e desliga o PostgreSQL e o Redis embutidos.
4. Rode `upgrade-control-plane.sh` (ou o workflow de release).

Para migrar uma instalação existente, faça antes um dump do banco embutido e restaure no RDS.

**Ativo/ativo:** com `instance_count = 2` (ou mais), o Terraform cria as instâncias em duas zonas atrás de um Application Load Balancer com certificado ACM. Cada instância se configura sozinha no primeiro boot: lê o `.env` do Parameter Store, usa RDS e ElastiCache e liga o realtime ao Redis. Todas atendem painel, API, filas e agendador. Com `PLATFORM_CONTROL_PLANE_CLUSTER=true` (já no `.env` gerado), cada tarefa agendada roda em uma instância só por vez, com trava no Redis. Se uma instância ou uma zona cair, as outras continuam. Defina `domain` e `route53_zone_id` (ou `certificate_arn`), e passe os tokens `PLATFORM_*` em `extra_env`.

## Cobrança com Banco Inter

Cada fatura vira uma cobrança "Bolepix" no Inter (boleto com QR code Pix) pela API Cobrança v3, com OAuth2 e certificado mTLS. O cliente vê o QR code, o Pix copia e cola, a linha digitável e o PDF do boleto na própria página de planos e em *Plano e uso*.

- **Assinatura.** A primeira fatura cobre o primeiro período (mensal ou anual). O plano começa quando ela é paga.
- **Renovação.** No fim de cada período sai uma fatura com o próximo período e o uso excedente do período que fechou, com vencimento em `PLATFORM_BILLING_DUE_DAYS` dias. O cliente continua usando durante o prazo.
- **Atraso.** Fatura vencida deixa o tenant em atraso: aviso por e-mail, deploys suspensos após 7 dias, containers parados após 30 dias (com backup antes). Ao pagar, tudo volta sozinho. O cliente pode gerar uma nova cobrança se o boleto expirar.
- **Mudanças de plano.** Upgrade é cobrado proporcionalmente ao resto do período e vale quando é pago. Downgrade e troca de mensal/anual valem na próxima renovação. O cancelamento encerra a assinatura no fim do período.
- **Confirmação.** O Inter avisa o webhook `https://<painel>/api/v1/billing/webhooks/inter/<token secreto>`, registrado automaticamente pelo console. O aviso só diz qual cobrança mudou: o status é sempre consultado de novo na API do Inter. A cada 15 minutos um job também confere as faturas em aberto, cobrindo avisos perdidos.
- **Operador.** Em /admin/platform → Cobrança é possível consultar uma fatura no banco, marcar como paga (pagamento recebido por fora), cancelar, emitir de novo e, no sandbox, simular o pagamento.
- **Dados do pagador.** Nome, CPF/CNPJ (validado), e-mail e endereço são exigidos pelo banco para registrar o boleto. Ficam criptografados no banco de dados, assim como as credenciais do Inter.

Não há SDK oficial do Inter para PHP. A integração usa um cliente próprio (`app/Services/Platform/Inter/`) com o cliente HTTP do Laravel, sem dependência nova no `composer.json`, o que também evita conflitos no `composer.lock` ao puxar o Coolify oficial.

### Stripe (alternativa)

Com `PLATFORM_BILLING_PROVIDER=stripe` vale a integração anterior: assinaturas no Stripe (`STRIPE_API_KEY`, `STRIPE_WEBHOOK_SECRET`, `PLATFORM_STRIPE_PRICE_*`), webhook em `/api/v1/billing/webhooks/stripe` com os eventos `customer.subscription.*`, `invoice.*` e `charge.refunded`, e o customer portal do Stripe.

## Medição, preço e faturas (wiki 8)

O fluxo é `usage_events` (bruto, a cada 5 min) → `aggregated_usage_hourly` (rollup) → `PricingEngine` → `platform_invoices`.

- `PlatformCollectUsageJob` roda `docker ps`, `docker stats` e `du` dos volumes em cada nó, a cada `PLATFORM_METERING_INTERVAL_MINUTES`. Cada container é atribuído ao tenant pelo label `coolify.tenantId` (ou pelo uuid do recurso), gerando `cpu_seconds`, `memory_byte_seconds`, `egress_bytes` e `storage_bytes`.
- `PlatformRollupUsageJob` agrega por hora (minuto 7). Contadores são somados; armazenamento usa a média.
- O `PricingEngine` cobra egress acima da franquia do plano (por GB) e armazenamento acima da cota (GB-mês). CPU e memória aparecem nas linhas da fatura, sem custo, porque já estão no plano.
- `PlatformDailyMaintenanceJob` (03:17) gera faturas de uso faltantes, reprovisiona redes e apaga eventos brutos antigos.

## Bloqueios (wiki 4)

| Situação | Ação |
| --- | --- |
| Uso ≥ 80% de uma cota | E-mail e aviso no painel (uma vez por período) |
| Acima de 100% (CPU, memória, containers, recursos) ou armazenamento cheio | Status `limited`: sem novos recursos nem deploys, e o que já roda continua |
| Fatura vencida há mais de 7 dias | Status `suspended`: deploys bloqueados |
| Mais de 30 dias, ou assinatura cancelada há mais de 7 dias | Status `hard_suspended`: backups disparados e containers parados depois do aviso |
| Pagamento confirmado | Reativação, com os recursos parados iniciados de novo |

O `PlatformEnforcementJob` avalia os tenants a cada 10 minutos. O operador também pode suspender ou reativar manualmente no console.

## Isolamento (wiki 3 e 7)

Todo compose gerado para um tenant (aplicações, serviços, bancos e previews) passa pelo
`TenantContainerPolicy`, que:

- remove `privileged`, `devices`, `cap_add` fora da lista, `network_mode: host`, `pid`/`ipc` do host e `userns_mode`;
- aplica `cap_drop: ALL` + lista mínima de capabilities, `no-new-privileges`, `pids_limit`, CPU e memória dentro da cota (reservados de forma transacional em `platform_allocations`) e logs limitados;
- restringe bind mounts aos diretórios do recurso (`/data/coolify/{applications,services,databases}/{uuid}`) e bloqueia `docker.sock`;
- filtra labels do Traefik e do Caddy (só os hosts do recurso, só middlewares seguros) e adiciona `tenant_id`, `tenant_plan` e `coolify.tenantId`;
- conecta apenas à rede `tenant-{id}`, e os builds deixam de usar `--network host`.

Também há guardas nos modelos para todas as entradas (UI, API e webhooks): validação de comandos,
portas, imagens, caminhos, domínios (sem domínio reservado ou de outro tenant), recursos por
plano (bancos, docker compose, volumes, previews, bancos públicos e log drains) e o destino do recurso
(só destinos do próprio tenant).

## Limites por cliente no nó (wiki 4)

Além do limite de cada container, o cliente inteiro tem um teto em cada nó. Todos os containers dele rodam no slice `tenant-<id>.slice` do systemd (`PLATFORM_TENANT_SLICES=true`), e o script `hostrema-tenant-limits` (role `tenant-limits` do Ansible) aplica as cotas do plano:

| Cota (por plano) | Básico | Profissional | Enterprise |
| --- | --- | --- | --- |
| CPU e memória | as do plano | as do plano | as do plano |
| Processos | 256 × containers | 512 × containers | 1024 × containers |
| Leitura/escrita em disco | 50/50 MB/s | 200/200 MB/s | sem limite |
| IOPS | 1.000 | 4.000 | sem limite |
| Banda (bridge do cliente) | 50 Mbit/s | 200 Mbit/s | sem limite |

Os valores podem mudar por `PLATFORM_<PLANO>_DISK_READ_MBPS`, `..._DISK_WRITE_MBPS`, `..._DISK_IOPS`, `..._BANDWIDTH_MBPS` ou por ajuste de cota no console (`{"bandwidth_mbps": 500}`). Ao mudar o plano ou os ajustes, os limites são reaplicados em todos os nós. A banda limita o tráfego entre os containers e a internet; o tráfego entre o proxy e a aplicação (respostas HTTP) não passa por esse limite.

## Traces das aplicações (OpenTelemetry)

Nos planos Profissional e Enterprise, o cliente gera um token em *Plano e uso → Traces das aplicações* e configura no app:

```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.sa-east-1.hostrema.com.br
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer hrt_...
```

O gateway OTLP da região (Traefik no nó de controle, perfil `tenant-traces` do `infra/monitoring`) pergunta ao painel a quem pertence o token (`/api/v1/platform/otlp/auth`) e grava os traces no Tempo separados por cliente (`X-Scope-OrgID=tenant-<id>`). O cliente consulta os próprios traces no Grafana dele, com uma fonte Tempo apontando para `https://otlp.<região>.../tempo` e o mesmo token. Configure `PLATFORM_OTLP_ENDPOINT` (padrão `https://otlp.{region}.hostrema.com.br`) e o `otlp_domain` do Terraform/Ansible.

## Console do operador

`/admin/platform`, apenas para o time root, tem quatro abas:

- **Tenants:** plano, status, uso, overrides de cota (JSON), suspender (manual ou total), reativar e provisionar;
- **Nós:** região, nó dedicado e reprovisionamento; também permite criar e desativar regiões;
- **Cobrança:** credenciais do Inter, preços dos planos, webhook e faturas de todos os tenants;
- **Audit log:** ações de usuários, do sistema e da cobrança.

## Marca (white-label) e página pública

Com a plataforma ligada, o painel mostra `PLATFORM_NAME` (Hostrema) no lugar de "Coolify", inclusive nos títulos e nos assuntos de e-mail. A troca acontece quando os templates são compilados, sem editar as telas originais. Depois de mudar o nome, rode `php artisan view:clear`. Opcionalmente defina `PLATFORM_FAVICON_URL`, `PLATFORM_DESCRIPTION`, `PLATFORM_TERMS_URL` e `PLATFORM_PRIVACY_URL`.

A página pública `/pricing` lista planos, cotas e preços, com os links de cadastro, termos de uso e privacidade.

## APIs

Todos os endpoints usam `Authorization: Bearer <token>`.

| Endpoint | Quem | Para quê |
| --- | --- | --- |
| `GET /api/v1/platform/plans` | qualquer token | Planos, cotas e preços |
| `GET /api/v1/platform/usage` | tenant | Uso atual e cotas |
| `GET /api/v1/platform/invoices` | tenant | Faturas de uso |
| `GET /api/v1/platform/audit-logs` | tenant/root | Audit log do time |
| `POST /api/v1/platform/servers/{uuid}/region` | root | Região, nó dedicado e wildcard do nó (`region`, `region_name`, `dedicated_team_id`, `wildcard_domain`) |
| `POST /api/v1/platform/tenants/{teamId}/provision` | root | (Re)provisiona as redes do tenant |
| `POST /api/v1/billing/webhooks/inter/{token}` | Banco Inter | Aviso de mudança de cobrança (o status é consultado na API do Inter) |
| `POST /api/v1/rollback` | token com `deploy` | Volta uma aplicação para a imagem de um deploy anterior (`uuid` e, opcionalmente, `commit` ou `deployment_uuid`) |
| `GET /api/v1/platform/metrics` | `PLATFORM_METRICS_TOKEN` | Métricas Prometheus do control plane |
| `GET /api/v1/platform/edge/{região}` | `PLATFORM_EDGE_TOKEN` | Configuração dinâmica dos edge routers (Traefik) |

O endpoint `/api/v1/servers` também responde aos tenants: lista os nós que eles podem usar, sem IPs.
`POST /applications|databases|services` aceitam `destination_uuid`.

### Pipeline de um tenant (wiki 5)

```yaml
# .github/workflows/deploy.yml no repositório do tenant
- name: Deploy
  run: |
    curl -fsS -X POST "https://cloud.example.com/api/v1/deploy?uuid=$APP_UUID&force=false" \
      -H "Authorization: Bearer ${{ secrets.COOLIFY_TOKEN }}"
- name: Validate
  run: ./wait_for_health.sh https://minha-app.apps.example.com/health 120 || \
       curl -fsS -X POST https://cloud.example.com/api/v1/rollback \
         -H "Authorization: Bearer ${{ secrets.COOLIFY_TOKEN }}" -H "Content-Type: application/json" \
         -d "{\"uuid\":\"$APP_UUID\"}"
```

Em aplicações sem portas mapeadas no host e sem nome de container fixo, o Coolify faz *rolling update*: o novo container só recebe tráfego depois do health check e o antigo é removido em seguida, o que equivale a um blue-green por recurso.
Canary com divisão de tráfego não existe no Coolify; veja a página 9 da wiki.

## Rotas e arquivos próprios (sem conflito no pull)

As rotas da plataforma ficam em `routes/platform/{api,web}.php` e são carregadas pelo
`PlatformServiceProvider`, antes das rotas originais. A configuração fica em `config/platform.php`,
as traduções em `lang/platform/*.json`, e o resto em `app/**/Platform*`, `infra/`, `docs/` e
`.github/workflows/platform-*.yml`. O workflow `platform-upstream-sync.yml` traz as novidades do Coolify oficial toda semana num pull request (ou abre uma issue listando os conflitos). Os arquivos originais do Coolify que ainda foram alterados
estão listados em `docs/security-model.md` (seção *Arquivos do Coolify alterados*).
