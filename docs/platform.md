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

1. No `.env` do control plane, o Hostrema só precisa de `PLATFORM_ENABLED=true`. O resto do `.env` é o padrão do Coolify (`APP_KEY`, banco, Redis e realtime).
2. Dentro do container, rode `php artisan platform:install --email=voce@empresa.com --url=https://painel.seudominio.com` (por exemplo, `docker exec -it coolify php artisan platform:install ...`). O comando:
   - cria o owner do time root, com o e-mail já verificado (sem `--password`, a senha gerada aparece no fim);
   - reabre o cadastro de clientes, que o Coolify fecha depois do primeiro usuário, e liga a API;
   - cria a primeira região (`sa-east-1`, mude com `--region`);
   - aplica os padrões de produção: clientes no gVisor, que o Ansible instala nos nós (use `--without-gvisor` se os nós não tiverem).

   Pode rodar de novo quando quiser: ele só cria o que falta e não desfaz nada que você mudou no painel.
3. Entre com o owner, ative o 2FA (exigido do operador) e abra **/admin/platform → Configurações**. A lista **Pronto para vender?** mostra o que ainda falta (e-mail transacional, pagamentos, região e nós), com o link de cada tela.
4. Em **/admin/platform → Cobrança**, cole o Client ID, o Client Secret, o certificado (.crt) e a chave (.key) da integração criada no Inter Empresas e clique em **Salvar e conectar**. O painel testa a conexão e registra o webhook sozinho.

Os planos já vêm com preços e limites (Básico R$ 49,90/mês ou R$ 499/ano, Profissional R$ 149,90/mês ou R$ 1.499/ano, Enterprise sob consulta). Para mudar, use **Configurações → Planos**.

A partir daí o cliente escolhe o plano, informa os dados de cobrança e paga com Pix (confirmação em segundos) ou boleto. O plano é ativado sozinho quando o Inter confirma o pagamento.

### Ao atualizar uma instalação existente

Versões anteriores tinham um erro: com a plataforma instalada, o Coolify não criava as configurações de aplicações novas nem o volume de dados de bancos novos. Depois de atualizar, rode `php artisan platform:repair-resources` para listar os recursos afetados e, depois de fazer backup dos bancos listados, `php artisan platform:repair-resources --fix`. O volume novo é montado no próximo reinício do banco.

### Criar a integração no Banco Inter

1. No Internet Banking do Inter Empresas, vá em **Soluções para sua empresa → Nova integração** (ou "Integrações → Nova aplicação").
2. Marque a **API Cobrança (Boleto e Pix)** com as permissões de leitura e escrita (escopos `boleto-cobranca.read` e `boleto-cobranca.write`).
3. Baixe o arquivo zip: ele traz o certificado (`.crt`) e a chave (`.key`). O Client ID e o Client Secret aparecem na tela da integração.
4. O certificado vale 1 ano. Antes de vencer, gere outro e cole no console.

Para testar sem dinheiro real, crie a integração no sandbox do Inter, escolha **Sandbox (testes)** no console e use **Simular pagamento Pix** na lista de faturas.

## Testar em localhost (Docker)

`infra/local/` sobe o Hostrema inteiro na sua máquina para você testar antes de subir para o host. O ambiente usa a mesma imagem de produção, construída a partir deste checkout, e traz PostgreSQL, Redis, o realtime, o Mailpit (recebe todos os e-mails) e um nó local. O nó é um container com Docker próprio (Docker-in-Docker), acessado por SSH como os nós de uma região. As aplicações dos clientes, o Traefik e os builds rodam dentro dele, sem mexer nos containers da sua máquina.

Você precisa de Docker Desktop (Windows ou macOS) ou Docker Engine com o plugin compose (Linux), de alguns GB livres para as imagens e das portas 8000, 6001, 6002, 8025, 80 e 443 livres (todas mudam no `infra/local/.env`). No Windows, rode o script pelo WSL ou pelo Git Bash.

```bash
cd infra/local
./hostrema-local.sh up
```

Na primeira vez, o script gera o `infra/local/.env` (senhas e chaves aleatórias, fora do git), constrói as imagens, espera as migrations e roda `php artisan platform:install --local`. Esse comando:

- cria o owner e um cliente demo com o plano Profissional ativo;
- cria a região `local`, registra o nó por SSH (com uma chave própria, que o script autoriza no nó) e sobe o Traefik nele;
- deixa os pagamentos no modo **manual**, com o botão "Simular pagamento" ligado;
- desliga gVisor, slices e o 2FA obrigatório, porque o nó local não tem como rodar os dois primeiros;
- manda os e-mails para o Mailpit.

No fim aparecem os endereços e as senhas. Para ver de novo, rode `./hostrema-local.sh info`.

| O quê | Onde |
| --- | --- |
| Painel | http://localhost:8000 (owner `owner@hostrema.local`; configurações em `/admin/platform`) |
| Cliente demo | `demo@hostrema.local` |
| E-mails | http://localhost:8025 |
| Aplicações | `http://<nome>.localhost`. Chrome, Edge e Firefox resolvem `*.localhost` sozinhos; no Safari, acrescente o nome ao `/etc/hosts` |

Um roteiro de teste:

1. Cadastre um cliente novo pelo painel. O e-mail de verificação chega no Mailpit.
2. Escolha um plano, preencha os dados de cobrança (com um CPF válido) e clique em **Simular pagamento**.
3. Faça o deploy de uma aplicação. O domínio gerado é `http://<algo>.localhost`.
4. Como owner, mude preços e limites em **Configurações** e veja o efeito em `/pricing` e nas cotas do cliente.

Para testar o Banco Inter de verdade, escolha **Banco Inter** em Configurações → Cobrança e use credenciais do sandbox. O webhook do Inter não alcança o localhost, mas o botão **Consultar** e a conferência automática a cada 15 minutos atualizam as faturas.

| Comando | Para quê |
| --- | --- |
| `./hostrema-local.sh rebuild` | reconstrói a imagem depois de mudar o código |
| `./hostrema-local.sh logs [serviço]` | mostra os logs |
| `./hostrema-local.sh artisan <comando>` | roda um comando do Laravel |
| `./hostrema-local.sh shell` | abre um shell no container do Hostrema |
| `./hostrema-local.sh status` | mostra o estado dos containers |
| `./hostrema-local.sh down` | para tudo sem apagar os dados |
| `./hostrema-local.sh reset` | apaga todos os dados locais |

O ambiente local não tem HTTPS, gVisor, slices por cliente, Loki/Grafana, edge router nem portas públicas de bancos. As aplicações respondem só pelas portas 80 e 443 do nó.

## Configurações (painel do owner)

Quase tudo se configura em **/admin/platform → Configurações**, sem editar o `.env` e sem reiniciar nada. Só o owner do time root grava; os admins do time root veem a tela em modo leitura. Os valores ficam criptografados no banco e chegam a todas as instâncias do painel e aos workers em poucos segundos. Cada campo mostra se está no padrão ou personalizado e tem um botão **Voltar ao padrão**. Toda alteração entra no audit log, sem os valores secretos.

| Grupo | O que tem |
| --- | --- |
| Geral | Nome e descrição da plataforma, idioma das páginas (padrão pt-BR), e-mail de suporte, links de vendas, documentação, termos e privacidade, logo, moeda, domínio base das aplicações e hosts reservados. Também traz a lista **Pronto para vender?**. |
| Planos | Uma tabela com nome, preço mensal e anual e venda só pelo comercial de cada plano, mais as cotas (CPU, memória, disco, recursos, containers, deploys por dia, domínios, tráfego, processos, IOPS e banda) e os recursos (bancos, volumes, backups, log drains, previews, bancos públicos, Compose, servidor dedicado, SSO e traces). |
| Cobrança | Meio de pagamento (Banco Inter, manual ou Stripe), instruções do modo manual, prazo, baixa do boleto, multa, juros, cobrança mínima, preço do excedente, trial e chaves do Stripe. |
| Contas | Aviso de cota, dias até suspender e até parar os containers, servidores próprios e terminal para clientes. |
| Containers e isolamento | Runtime (gVisor) dos nós compartilhados e das VMs dedicadas, CPU e memória padrão, capabilities, OOM score, slices por cliente, logs por container, limite de disco e faixa de portas dos bancos públicos. |
| Logs, traces e medição | Loki padrão, tempo limite e linhas do histórico, endpoint OTLP, intervalo e retenção da medição. |
| Webhooks e eventos | Limites, tempo limite, retenção, desativação por falhas, HTTP simples, alvos privados do operador e stream do Redis. |
| Segurança | Quem precisa de 2FA e as redes permitidas para os tokens do operador. |
| Tokens internos e edge | Tokens do Prometheus e dos edge routers (gerados sozinhos na primeira subida; **Gerar novo** troca) e portas e PROXY protocol dos nós. |

Outras telas guardam o resto:

- **Credenciais do Banco Inter:** aba **Cobrança** do console.
- **Nome e Loki de cada região:** aba **Regiões e nós**.
- **E-mail transacional (SMTP ou Resend), cadastro aberto e API:** telas do próprio Coolify (*Settings*), com atalhos na lista **Pronto para vender?**.

### O que continua no `.env`

Fica no `.env` só o que o painel precisa para subir ou o que vem da infraestrutura:

- `PLATFORM_ENABLED`;
- `APP_KEY` e `APP_PREVIOUS_KEYS`;
- banco, Redis e realtime;
- `PLATFORM_CONTROL_PLANE_CLUSTER`, gerado pelo Terraform;
- `PLATFORM_TENANT_NETWORK_PREFIX`, que não deve mudar depois de existirem clientes.

As variáveis `PLATFORM_*` antigas continuam valendo como padrão, e o que for salvo no painel tem prioridade. A lista completa, com os padrões, está em `config/platform.php`. Os tokens internos podem vir do `.env` (`PLATFORM_METRICS_TOKEN` e `PLATFORM_EDGE_TOKEN`) ou ser gerados pelo painel. Em qualquer caso, os valores em uso aparecem em **Configurações → Tokens internos**, e é deles que o Ansible precisa (`docs/region-onboarding.md`).

### Imagem do fork no control plane

O Coolify instalado pelo script oficial usa `ghcr.io/coollabsio/coolify`. Para usar a imagem do fork
sem editar os arquivos originais, crie `/data/coolify/source/docker-compose.custom.yml`.
O script de upgrade do Coolify já respeita esse arquivo:

```yaml
services:
  coolify:
    image: "ghcr.io/bad-wolf/hostrema:platform-v1.0.0"
```

O `PLATFORM_ENABLED=true` vai no `/data/coolify/source/.env`; o resto se configura no painel. O workflow `platform-release.yml`
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

**Ativo/ativo:** com `instance_count = 2` (ou mais), o Terraform cria as instâncias em duas zonas atrás de um Application Load Balancer com certificado ACM. Cada instância se configura sozinha no primeiro boot: lê o `.env` do Parameter Store, usa RDS e ElastiCache e liga o realtime ao Redis. Todas atendem painel, API, filas e agendador. Com `PLATFORM_CONTROL_PLANE_CLUSTER=true` (já no `.env` gerado), cada tarefa agendada roda em uma instância só por vez, com trava no Redis. Se uma instância ou uma zona cair, as outras continuam. Defina `domain` e `route53_zone_id` (ou `certificate_arn`), e passe os tokens `PLATFORM_*` em `extra_env`. As instâncias falam com os nós das regiões pelo peering entre as VPCs: cole a saída `region_peering` em `control_plane_peering` de cada região (veja `docs/region-onboarding.md`).

**Outra região (recuperação de desastre):** com `dr_region = "us-east-1"` (por exemplo), o RDS copia os backups automáticos e os logs de transação do banco do painel para essa região. Se a região principal inteira cair, restaure o banco lá no ponto mais recente (`aws rds restore-db-instance-to-point-in-time --source-db-instance-automated-backups-arn <saída dr_backups_arn> --use-latest-restorable-time --target-db-instance-identifier hostrema-control-plane --region us-east-1`), suba o painel nessa região com o `DB_HOST` do `.env` apontando para o banco restaurado (mesma `APP_KEY`) e troque o DNS do painel. Os nós e os dados dos clientes ficam nas regiões deles e não dependem do painel para continuar no ar.

Com `worker_count` maior que zero, o cluster separa os papéis: as `instance_count` instâncias atrás do balanceador só atendem painel, API e realtime, e os workers (fora do balanceador) rodam as filas do Horizon e o agendador. Assim, deploys longos e checagens de servidor não disputam CPU com o painel, e cada lado escala sozinho (`worker_instance_type` define o tamanho dos workers).

Para o `platform-release.yml` atualizar o cluster, coloque em `CONTROL_PLANE_HOST` a saída `ssh_hosts` (todas as instâncias, separadas por espaço) e libere o SSH em `admin_cidrs`. O workflow atualiza uma instância por vez e, se uma falhar, volta todas para a versão anterior. A versão em uso fica gravada no Parameter Store (`/hostrema/control-plane/image`), e uma instância criada depois já sobe com ela. A primeira versão vem da variável `image` (padrão `ghcr.io/bad-wolf/hostrema:latest`, a última release publicada).

## Cobrança com Banco Inter

Cada fatura vira uma cobrança "Bolepix" no Inter (boleto com QR code Pix) pela API Cobrança v3, com OAuth2 e certificado mTLS. O cliente vê o QR code, o Pix copia e cola, a linha digitável e o PDF do boleto na própria página de planos e em *Plano e uso*.

- **Assinatura.** A primeira fatura cobre o primeiro período (mensal ou anual). O plano começa quando ela é paga.
- **Renovação.** No fim de cada período sai uma fatura com o próximo período e o uso excedente do período que fechou, com o prazo de pagamento definido em Configurações → Cobrança (padrão: 5 dias). O cliente continua usando durante o prazo.
- **Atraso.** Fatura vencida deixa o tenant em atraso: aviso por e-mail, deploys suspensos após 7 dias, containers parados após 30 dias (com backup antes). Ao pagar, tudo volta sozinho. O cliente pode gerar uma nova cobrança se o boleto expirar.
- **Mudanças de plano.** Upgrade é cobrado proporcionalmente ao resto do período e vale quando é pago. Downgrade e troca de mensal/anual valem na próxima renovação. O cancelamento encerra a assinatura no fim do período.
- **Confirmação.** O Inter avisa o webhook `https://<painel>/api/v1/billing/webhooks/inter/<token secreto>`, registrado automaticamente pelo console. O aviso só diz qual cobrança mudou: o status é sempre consultado de novo na API do Inter. A cada 15 minutos um job também confere as faturas em aberto, cobrindo avisos perdidos.
- **Operador.** Em /admin/platform → Cobrança é possível consultar uma fatura no banco, marcar como paga (pagamento recebido por fora), cancelar, emitir de novo e, no sandbox, simular o pagamento.
- **Dados do pagador.** Nome, CPF/CNPJ (validado), e-mail e endereço são exigidos pelo banco para registrar o boleto. Ficam criptografados no banco de dados, assim como as credenciais do Inter.

Não há SDK oficial do Inter para PHP. A integração usa um cliente próprio (`app/Services/Platform/Inter/`) com o cliente HTTP do Laravel, sem dependência nova no `composer.json`, o que também evita conflitos no `composer.lock` ao puxar o Coolify oficial.

### Pagamento manual (sem banco)

Com o meio de pagamento **Manual** (Configurações → Cobrança), cada fatura sai na hora com as instruções que você escrever (chave Pix, conta, para onde mandar o comprovante). O plano é liberado quando você clica em **Marcar como paga** na aba Cobrança. Atrasos, suspensões e avisos funcionam como no Inter. Para testes, a opção "Clientes podem simular o pagamento" mostra o botão **Simular pagamento** nas faturas em aberto. Nunca ligue essa opção em produção.

### Stripe (alternativa)

Com o meio de pagamento **Stripe** vale a integração anterior: assinaturas no Stripe, webhook em `/api/v1/billing/webhooks/stripe` com os eventos `customer.subscription.*`, `invoice.*` e `charge.refunded`, e o customer portal do Stripe. A chave secreta, o segredo do webhook e os IDs de preço ficam em Configurações (Cobrança e Planos).

## Medição, preço e faturas (wiki 8)

O fluxo é `usage_events` (bruto, a cada 5 min) → `aggregated_usage_hourly` (rollup) → `PricingEngine` → `platform_invoices`.

- `PlatformCollectUsageJob` roda `docker ps`, `docker stats` e `du` dos volumes em cada nó, no intervalo de Configurações → Logs, traces e medição (padrão: 5 minutos). Cada container é atribuído ao tenant pelo label `coolify.tenantId` (ou pelo uuid do recurso), gerando `cpu_seconds`, `memory_byte_seconds`, `egress_bytes` e `storage_bytes`.
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

### Serviços de um clique

Os clientes veem os templates que a plataforma consegue rodar isolados (`ServiceTemplates`), já ajustados
para o nó compartilhado, sem mudar o que o template faz:

- diretórios do host (`/jupyter:/home/jovyan/work`, `${FOUNDRY_DATA:-/data/foundryvtt}`) passam para o
  diretório do recurso, então cada stack tem os seus dados;
- binds de `/etc/localtime`, `/etc/timezone` e `/dev/(u)random` saem (o container já tem os dele);
- stacks com endereço web deixam as outras portas só na rede do cliente, em vez de publicá-las no nó
  (o SSH do Gitea, o SMTP do Mailpit e o AMQP do RabbitMQ continuam acessíveis pelos outros recursos do cliente).

Não aparecem os que precisam do socket do Docker (Portainer, Dozzle, Homarr, Supabase, Authentik, Windmill,
Jenkins, Pterodactyl...), de capabilities extras ou rede do host (WireGuard, Pi-hole, NetBird, Cloudflared,
AnythingLLM, Changedetection), de modo privilegiado (Glances) ou só de portas TCP públicas (Minecraft): 28 dos
265 templates desta versão. O mesmo teste que cria e valida para deploy cada template oferecido
(`PlatformJourneyTest`) roda a cada sincronização do upstream. Um compose escrito pelo cliente passa pelas
mesmas regras antes de qualquer coisa ser criada, e a recusa aparece com os motivos.

## Limites por cliente no nó (wiki 4)

Além do limite de cada container, o cliente inteiro tem um teto em cada nó. Todos os containers dele rodam no slice `tenant-<id>.slice` do systemd (`PLATFORM_TENANT_SLICES=true`), e o script `hostrema-tenant-limits` (role `tenant-limits` do Ansible) aplica as cotas do plano:

| Cota (por plano) | Básico | Profissional | Enterprise |
| --- | --- | --- | --- |
| CPU e memória | as do plano | as do plano | as do plano |
| Processos | 256 × containers | 512 × containers | 1024 × containers |
| Leitura/escrita em disco | 50/50 MB/s | 200/200 MB/s | sem limite |
| IOPS | 1.000 | 4.000 | sem limite |
| Banda (bridge do cliente) | 50 Mbit/s | 200 Mbit/s | sem limite |

Os valores mudam em Configurações → Planos ou, para um cliente só, por ajuste de cota no console (`{"bandwidth_mbps": 500}`). Ao mudar o plano ou os ajustes, os limites são reaplicados em todos os nós. A banda limita o tráfego entre os containers e a internet; o tráfego entre o proxy e a aplicação (respostas HTTP) não passa por esse limite.

## Traces das aplicações (OpenTelemetry)

Nos planos Profissional e Enterprise, o cliente gera um token em *Plano e uso → Traces das aplicações* e configura no app:

```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.sa-east-1.hostrema.com.br
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer hrt_...
```

O gateway OTLP da região (Traefik no nó de controle, perfil `tenant-traces` do `infra/monitoring`) pergunta ao painel a quem pertence o token (`/api/v1/platform/otlp/auth`) e grava os traces no Tempo separados por cliente (`X-Scope-OrgID=tenant-<id>`). O cliente consulta os próprios traces no Grafana dele, com uma fonte Tempo apontando para `https://otlp.<região>.../tempo` e o mesmo token. Configure o endpoint OTLP em Configurações → Logs, traces e medição (padrão `https://otlp.{region}.hostrema.com.br`) e o `otlp_domain` do Terraform/Ansible.

## Histórico de logs

O Grafana Alloy de cada nó manda os logs de todos os containers ao Loki da região, com o label `tenant_id`. Em **Integrações → Histórico de logs** (`/platform/logs`), o cliente escolhe uma aplicação, um serviço ou um banco, o período (até 30 dias, 90 no Enterprise) e um texto opcional. O painel monta a consulta sozinho, sempre presa ao time e ao recurso, então ninguém lê log de outro cliente. O mesmo está na API (`GET /api/v1/platform/logs`). O endereço do Loki de cada região é registrado pelo Ansible junto com os nós (`logs_url`). Para regiões antigas, rode o `bootstrap-nodes.yml` de novo ou informe o Loki em **Regiões e nós → Editar** (ou o padrão em Configurações → Logs, traces e medição, onde `{region}` vira o slug da região).

## Eventos, webhooks e extensões (wiki 9)

Cada evento da plataforma (deploys, recursos criados e excluídos, containers que sobem ou caem, backups, plano, status da conta e faturas) sai por três caminhos:

- **Webhooks**: em **Integrações**, o admin do time cadastra uma URL HTTPS pública e escolhe os eventos. Cada entrega é um POST JSON assinado (`X-Hostrema-Signature: t=<unix>,v1=<HMAC-SHA256 de "<t>.<corpo>">`), com novas tentativas em erros temporários e histórico das últimas entregas. Endereços privados, metadata e redirecionamentos são recusados (sem SSRF). O operador pode receber os eventos de todos os clientes.
- **Stream no Redis** `hostrema:events` (sem prefixo), para serviços internos lerem com `XREADGROUP`.
- **Evento Laravel** `App\Events\PlatformEventOccurred`, para extensões do fork.

Exemplos de verificação da assinatura, leitura do stream e listener estão em `docs/extensions.md`. A API da plataforma está descrita em OpenAPI (`GET /api/v1/platform/openapi.json`).

## Segurança do operador e rotação de credenciais

- **2FA obrigatório para o operador.** Quem é do time root só navega depois de ativar a autenticação em dois fatores no perfil. Em Configurações → Segurança dá para exigir de todos os usuários ou desligar.
- **Tokens do operador presos à origem.** Com redes em Configurações → Segurança (ex.: `203.0.113.0/24, 10.0.0.0/8`), tokens de API do time root só funcionam a partir delas (CI, VPN, máquina do Ansible). Tokens dos clientes não mudam.
- **Rotação da APP_KEY** (criptografa variáveis de ambiente, chaves SSH, credenciais S3/Git, dados de cobrança e segredos de webhook). Instância única: gere a nova chave (`php artisan key:generate --show`), coloque a atual em `APP_PREVIOUS_KEYS`, a nova em `APP_KEY`, reinicie e rode `php artisan platform:rotate-app-key`; depois tire `APP_PREVIOUS_KEYS` e reinicie. Em cluster, faça pelo Terraform em três aplicações, rodando o release depois de cada uma: (1) `app_previous_keys = ["<nova>"]`; (2) `app_key = "<nova>"` e `app_previous_keys = ["<antiga>"]`, e então `platform:rotate-app-key` em uma instância; (3) `app_previous_keys = []`. A cada rollout as instâncias releem o `.env` do Parameter Store.
- **Rotação da chave SSH dos nós.** `ansible-playbook playbooks/rotate-coolify-key.yml` cria uma chave nova, autoriza nos nós ao lado da atual, grava no Coolify (mesmo uuid) e valida todos os nós; depois, `-e rotation_phase=finish` revoga a antiga. As instâncias do painel trocam o arquivo da chave sozinhas quando ela muda no banco.

## Limitações do Coolify (wiki 9) e como foram tratadas

| Limitação | Como ficou |
| --- | --- |
| Arquitetura monolítica | Painel em cluster atrás de balanceador, com instâncias web e workers separados (`worker_count`); execução isolada nos nós (rede, slice, gVisor) e VM própria no Enterprise. |
| Banco de dados único | Um schema por cliente exigiria reescrever o Coolify. Os metadados ficam num PostgreSQL Multi-AZ, isolados por time em todas as entradas (políticas, guardas e testes de acesso cruzado); os bancos dos clientes já são containers e volumes próprios. |
| API interna | O Coolify já tem API REST documentada; a plataforma soma a sua (OpenAPI), webhooks assinados e o stream de eventos, sem ninguém ler o banco por fora. |
| Escalabilidade horizontal | Cluster ativo/ativo (ALB com sessão persistente, RDS, ElastiCache, tarefas agendadas em uma instância por vez) em vez de réplicas somente leitura. |
| Multi-região | Regiões com DNS por latência, backups em S3 com replicação entre regiões e cópia dos backups do banco do painel em outra região (`dr_region`). |
| Logs e monitoramento | Alloy (o Promtail chegou ao fim da vida em 2026) → Loki, Prometheus com métricas próprias, Grafana; histórico de logs e traces por cliente. |
| Isolamento de containers | Rede por cliente, firewall para metadata/VPC/painel, seccomp e AppArmor padrão do Docker sem como desligar, capabilities mínimas, slices por cliente e gVisor. |
| Gestão de credenciais | Segredos criptografados (AES-256) com a APP_KEY guardada fora do banco (Parameter Store no cluster), rotação da APP_KEY e da chave SSH, variáveis compartilhadas do Coolify como referência central de segredos. |
| Permissões e shell | Painel em instâncias próprias; chave SSH restrita por `from=` e rede privada; 2FA obrigatório e tokens restritos por origem para o operador; terminal desligado para clientes. |
| Plugins e hooks | Evento Laravel, stream no Redis e webhooks (acima). |
| Atualizações do upstream | Sincronização semanal por pull request e checagem no CI da lista de arquivos originais alterados (`infra/scripts/upstream-touched-files.sh`), em vez de patches soltos. |
| Painel unificado e marca | O seletor de times do Coolify separa os clientes; o nome Hostrema substitui o Coolify em tempo de compilação das telas, sem editar os arquivos originais. |

## Console do operador

`/admin/platform`, apenas para o time root, tem cinco abas:

- **Tenants:** plano, status, uso, overrides de cota (JSON), suspender (manual ou total), reativar e provisionar;
- **Cobrança:** credenciais do Inter, preços dos planos, webhook e faturas de todos os tenants (consultar, marcar como paga, cancelar, emitir de novo);
- **Regiões e nós:** região, nó dedicado e reprovisionamento; também cria, edita (nome e Loki) e desativa regiões;
- **Configurações:** tudo o que antes ficava no `.env` (seção *Configurações* acima). Só o owner grava;
- **Audit log:** ações de usuários, do sistema e da cobrança.

## Marca (white-label) e página pública

Com a plataforma ligada, o painel mostra o nome da plataforma (Hostrema) no lugar de "Coolify", inclusive nos títulos e nos assuntos de e-mail. A troca acontece quando os templates são compilados, sem editar as telas originais. Nome, descrição, logo e os links de termos e privacidade ficam em Configurações → Geral. Quando eles mudam, cada instância recompila as telas sozinha no acesso seguinte.

A página pública `/pricing` lista planos, cotas e preços, com os links de cadastro, termos de uso e privacidade.

## APIs

Todos os endpoints usam `Authorization: Bearer <token>`.

| Endpoint | Quem | Para quê |
| --- | --- | --- |
| `GET /api/v1/platform/plans` | qualquer token | Planos, cotas e preços |
| `GET /api/v1/platform/usage` | tenant | Uso atual e cotas |
| `GET /api/v1/platform/invoices` | tenant | Faturas de uso |
| `GET /api/v1/platform/audit-logs` | tenant/root | Audit log do time |
| `GET /api/v1/platform/logs` | tenant | Histórico de logs de um recurso (`resource_uuid`, `range`, `filter`, `limit`) |
| `GET /api/v1/platform/event-types` | qualquer token | Tipos de evento dos webhooks |
| `GET/POST /api/v1/platform/webhooks`, `DELETE .../{uuid}`, `POST .../{uuid}/test` | tenant/root | Webhooks do time (o segredo só aparece na criação) |
| `GET /api/v1/platform/openapi.json` | público | Este conjunto de endpoints em OpenAPI 3.1 |
| `POST /api/v1/platform/servers/{uuid}/region` | root | Região, nó dedicado e wildcard do nó (`region`, `region_name`, `dedicated_team_id`, `wildcard_domain`) |
| `POST /api/v1/platform/tenants/{teamId}/provision` | root | (Re)provisiona as redes do tenant |
| `POST /api/v1/billing/webhooks/inter/{token}` | Banco Inter | Aviso de mudança de cobrança (o status é consultado na API do Inter) |
| `POST /api/v1/rollback` | token com `deploy` | Volta uma aplicação para a imagem de um deploy anterior (`uuid` e, opcionalmente, `commit` ou `deployment_uuid`) |
| `GET /api/v1/platform/metrics` | token do Prometheus (Configurações → Tokens internos) | Métricas Prometheus do control plane |
| `GET /api/v1/platform/edge/{região}` | token dos edge routers (Configurações → Tokens internos) | Configuração dinâmica dos edge routers (Traefik) |

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

As rotas da plataforma ficam em `routes/platform/{api,web}.php` (com o OpenAPI delas em `routes/platform/openapi.yaml`) e são carregadas pelo
`PlatformServiceProvider`, antes das rotas originais. A configuração fica em `config/platform.php`,
as traduções em `lang/platform/*.json`, e o resto em `app/**/Platform*`, `infra/`, `docs/` e
`.github/workflows/platform-*.yml`. As configurações do owner usam `config/platform.php` só como padrão (`app/Services/Platform/PlatformConfig.php`). O workflow `platform-upstream-sync.yml` traz as novidades do Coolify oficial toda semana num pull request (ou abre uma issue listando os conflitos). Ele acompanha o branch `v4.0.x`, que substituiu o antigo `v4.x` no repositório oficial; para subir para uma linha mais nova (`v4.1.x` e seguintes), dispare o workflow à mão e informe o branch. Os arquivos originais do Coolify que ainda foram alterados
estão listados em `docs/security-model.md` (seção *Arquivos do Coolify alterados*).
