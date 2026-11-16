# Modelo de segurança da plataforma

Páginas 3 e 7 da wiki. Premissa: **todo tenant é não confiável**. O código, as imagens, os labels,
os comandos e os domínios que ele envia são tratados como hostis.

## Objetivos

1. Um tenant não lê, altera nem derruba recursos de outro tenant.
2. Um tenant não alcança o host, o control plane, a rede privada da região nem as credenciais da nuvem.
3. O consumo é limitado pelo plano (CPU, memória, containers, recursos e deploys por dia), sem vizinho barulhento descontrolado.
4. Cobrança e bloqueios não podem ser burlados pelo tenant.
5. Toda ação relevante fica no audit log.

## Controles implementados

### Control plane (aplicação)

- **Destinos.** O `DestinationPolicy` garante que um tenant só usa destinos `tenant-{id}` do próprio time nos nós da região; servidores próprios ficam bloqueados por padrão. A checagem vale em UI, API, clone, promoção, "add server" e no job de deploy (defesa em profundidade).
- **IDORs corrigidos.** Logs de deploy por uuid (`/api/v1/deployments/{uuid}`), destinos adicionais (`network_id`/`server_id`), componentes Livewire com propriedades travadas (`#[Locked]`) em logs e import de banco, e o JSON do servidor (com token do Sentinel) que ia embutido na página de novo recurso.
- **Entradas.** O `TenantInputGuard` valida, nos modelos, comandos de build/start/install sem aspas, crases ou caracteres de controle; imagens e tags; portas, health checks e opções de `docker run` (lista permitida); caminhos dentro do diretório do recurso; nomes de arquivo de scripts init; credenciais de banco; endpoints S3 públicos; e nomes de variáveis.
- **Injeção de comando.** Os argumentos do Nixpacks (`--build-cmd`, `--env` etc.) usam `escapeshellarg`, e o plano roda via script em base64; o mesmo vale para import de banco e init scripts do PostgreSQL.
- **Domínios.** O `DomainPolicy` bloqueia domínios reservados (instância, `PLATFORM_BASE_DOMAIN`, `PLATFORM_RESERVED_HOSTS`) e domínios de outro tenant, e respeita a cota de domínios próprios.
- **Planos.** O `PlatformGate` aplica cotas e recursos por plano (bancos, docker compose, volumes, previews, bancos públicos, log drains). Há reserva transacional de CPU, memória e containers em `platform_allocations`.
- **Terminal web.** Fica desligado para tenants (`PLATFORM_TENANT_TERMINAL`), porque o servidor de terminal confia no comando SSH enviado pelo navegador.
- **Cobrança (Banco Inter).** As credenciais (Client Secret, certificado e chave) e os dados do pagador (CPF/CNPJ, endereço) ficam criptografados com a `APP_KEY` e nunca voltam para o navegador. O webhook exige um token secreto na URL e só serve de gatilho: o status da cobrança é sempre consultado na API do Inter com mTLS. A baixa da fatura é feita com trava de linha, então avisos repetidos não ativam o plano duas vezes.
- **Operador.** 2FA obrigatório para o time root (`PLATFORM_REQUIRE_2FA`) e tokens de API do time root aceitos só das redes de `PLATFORM_OPERATOR_API_CIDRS`.
- **Webhooks.** Só URLs HTTPS públicas: o endereço resolvido é conferido (privados, metadata, CGNAT, IPv6 local e mapeado) e a conexão vai para esse mesmo IP, sem seguir redirecionamentos. Cada entrega é assinada com HMAC-SHA256 e o segredo fica criptografado.
- **Histórico de logs.** O painel monta a consulta ao Loki com o time e o recurso fixos; o texto do cliente vira um filtro literal (sem crase nem caracteres de controle).
- **Credenciais.** Rotação da `APP_KEY` (`platform:rotate-app-key` com `APP_PREVIOUS_KEYS`) e da chave SSH dos nós (`rotate-coolify-key.yml`); os arquivos da chave SSH nas instâncias do painel são reescritos quando a chave muda no banco.
- **Audit log.** Registra criação e remoção de recursos, variáveis de ambiente (só a chave), tokens de API, convites, logins e falhas, mudanças de plano/status, ações do operador e eventos do Stripe.

### Containers (TenantContainerPolicy)

- Sem `privileged`, `devices`, `cgroup_parent`, `userns_mode`/`uts`/`pid`/`ipc` do host, `network_mode: host`, GPUs, `ports` diretos nem `docker.sock`.
- Aplicados `cap_drop: ALL` + capabilities mínimas, `no-new-privileges`, `pids_limit`, `mem_limit`/`cpus` dentro da cota, `oom_score_adj` e logs `json-file` limitados.
- Bind mounts só dentro de `/data/coolify/{applications,services,databases}/{uuid}`; volumes nomeados com o uuid do recurso.
- Labels do Traefik e do Caddy passam por lista permitida: só hosts do recurso, roteadores/serviços com o uuid, sem `forwardauth`, `errors`, plugins nem TCP/UDP. `tenant_id`, `tenant_plan` e `coolify.tenantId` são impostos.
- Rede única `tenant-{id}`; builds sem `--network host`.
- Runtime opcional gVisor (`PLATFORM_CONTAINER_RUNTIME=runsc`).

### Host e rede (infra/)

- **Firewall.** O role `firewall` impede que containers alcancem 169.254.0.0/16 (metadata), a VPC, o WireGuard e as portas de gerência do host (22, exporters, edge e Alloy). As regras são reaplicadas a cada restart do Docker.
- **Nuvem.** IMDSv2 obrigatório com hop limit 1, discos criptografados, security groups mínimos, nós em sub-rede privada, SSH só pelo bastion e pelo túnel WireGuard (porta 22 apenas), e a chave do Coolify restrita por `from=`.
- **Docker.** `no-new-privileges`, `live-restore`, `userland-proxy: false`, `icc: false`, logs limitados e pools de endereço fora da VPC.
- **Sistema operacional.** sysctl endurecido (kptr/dmesg restrict, BPF não privilegiado desligado, rp_filter), sshd sem senha, auditd vigiando Docker/SSH/sudo e atualizações de segurança automáticas.

### Cadeia de suprimentos (CI)

- Em cada PR: pint, testes, `terraform validate`, Checkov, ansible-lint, shellcheck e promtool/amtool.
- No release: Trivy (bloqueia CVE crítico corrigível), SBOM CycloneDX, assinatura cosign keyless, proveniência do build e upgrade com rollback.

## Riscos residuais (aceitos ou a tratar antes do go-live)

1. **Kernel compartilhado.** Nos nós compartilhados, um escape de container (CVE de kernel/runc) dá acesso ao nó e aos clientes vizinhos. Mitigações: gVisor (`PLATFORM_CONTAINER_RUNTIME=runsc`), kernel sempre atualizado e, no Enterprise, VM própria por cliente (`dedicated_nodes` no Terraform).
2. **Builds.** O build roda no container `coolify-helper`, que tem o `docker.sock` do nó. Os passos `RUN` do Dockerfile do tenant rodam em containers de build do BuildKit (sem o socket), mas uma falha no BuildKit ou no helper expõe o nó. Recomendado: servidores de build dedicados e isolados dos nós de execução.
3. **SSRF a partir do control plane.** Os webhooks da plataforma e os endpoints S3 recusam endereços privados. Notificações do Coolify (Slack, Discord, Telegram) e URLs de provedores Git próprios continuam sem esse filtro: aplique filtro de egress nas instâncias do painel (bloquear RFC1918 e metadata).
4. **Domínios sem prova de posse.** Um domínio próprio é aceito se estiver livre; não há verificação por TXT. Sugestão: exigir registro TXT antes de emitir o certificado.
5. **Disco e banda.** Leitura, escrita, IOPS e banda têm teto por cliente em cada nó (slice do systemd e tc). Continuam sem limite rígido o espaço em disco por container, a não ser com `PLATFORM_ENFORCE_STORAGE_OPT` (que exige xfs com pquota; o excesso leva o cliente a `limited`), e as respostas HTTP servidas pelo proxy. Egress anômalo gera alerta (`TenantHighEgress`).
6. **Operador.** O time root tem acesso total. O 2FA já é obrigatório e os tokens podem ficar presos à origem (`PLATFORM_OPERATOR_API_CIDRS`); mantenha poucos membros e tokens com escopo mínimo.
7. **Upstream.** Funcionalidades novas do Coolify que criem entradas ou labels podem precisar de regras novas no `TenantInputGuard`/`LabelPolicy`. Revise cada merge do upstream com os testes de `tests/Feature/Platform`.

## Checklist de pentest (antes de abrir para clientes)

- [ ] Com dois tenants A e B, B tenta, via UI e API, ler logs, variáveis, deployments, backups, terminais e destinos de A (trocar uuids e ids nas requisições Livewire e REST).
- [ ] Compose malicioso: `privileged`, `pid: host`, `network_mode: host`, `cap_add: SYS_ADMIN`, bind de `/`, `/var/run/docker.sock`, `../`, labels Traefik para o host de outro tenant ou para o dashboard.
- [ ] Comandos de build/start com `$(...)`, crase, `;`, quebra de linha e aspas; nomes de arquivo de init script com `../`.
- [ ] Dentro do container de um tenant: `curl 169.254.169.254`, IPs privados da VPC, portas 22/9100/9280 do host, outras redes `tenant-*` e tentativas de escape (montagens, `/proc`, capabilities).
- [ ] Limites: fork bomb (`pids_limit`), estouro de memória (OOM só no container), consumo de CPU (cota), muitos deploys (limite diário) e disco cheio (status `limited`).
- [ ] Domínios: tomar domínio reservado, domínio de outro tenant ou wildcard; certificado para domínio alheio.
- [ ] Cobrança: webhook do Inter sem token, com token errado ou com payload forjado ("RECEBIDO" falso), replay; troca de plano, upgrade proporcional, cancelamento e reativação; outro tenant tentando baixar boleto ou CSV de uso por uuid.
- [ ] Endpoints internos: `/api/v1/platform/metrics` e `/api/v1/platform/edge/*` sem token ou com token errado (404), e rate limit.
- [ ] Traces: enviar e consultar pelo gateway OTLP com token de outro cliente, sem token ou com `X-Scope-OrgID` forjado (o gateway sempre substitui pelo do token).
- [ ] Limites por cliente: vários containers do mesmo cliente somando mais CPU, memória, disco ou banda que o plano.
- [ ] Webhooks para `169.254.169.254`, `10.x`, `[::1]`, hosts que resolvem para IP privado, DNS rebinding e redirecionamento para endereço interno.
- [ ] Histórico de logs: uuid de recurso de outro cliente e filtros com crase, `}` e quebras de linha, pela tela e pela API.
- [ ] Operador sem 2FA e token do time root usado fora de `PLATFORM_OPERATOR_API_CIDRS`.
- [ ] Varredura externa (nmap) do NLB, do bastion e do control plane.

## Arquivos do Coolify alterados

Pontos de possível conflito ao fazer merge do upstream (`git fetch upstream v4.0.x && git merge upstream/v4.0.x`;
o upstream trocou o branch `v4.x` pelos branches `v4.0.x`, `v4.1.x`...). A comparação é feita com o `v4.x` deste
fork, a base de onde a plataforma partiu. São mudanças pequenas e localizadas. Todo o resto da plataforma está em
arquivos novos. A lista é gerada por `infra/scripts/upstream-touched-files.sh --write docs/security-model.md`, e o
CI falha se um arquivo original for alterado sem aparecer aqui.

A imagem da plataforma sai do `docker/platform/Dockerfile` (arquivo novo); o `docker/production/Dockerfile`
oficial fica intocado. A base dele é uma recompilação privada da versão 4 do `serversideup/php`
(`ghcr.io/coollabsio/serversideup-php`), e as tags públicas da versão 4 não recebem mais correções. O
Dockerfile da plataforma usa o `serversideup/php` 5 público e reaproveita a configuração do
`docker/production`: registra os serviços S6 do Coolify em `user-bundles.d` (onde o S6 Overlay 3.2.3.2 da
versão 5 procura), deixa o PHP-FPM só em `127.0.0.1` (na versão 5 ele escuta em todas as interfaces) e
mantém o OPcache conferindo a data dos arquivos. O `platform-base-image.yml` abre toda semana um pull
request com a versão 5.x mais nova, com o CI e o ambiente local rodando nele, e um teste confere que tudo o
que o Dockerfile oficial copia para a imagem também vai para a da plataforma.

Mudanças em arquivos originais que não são só ganchos:

- `bootstrap/helpers/shared.php`: `get_service_templates()` entrega aos clientes o catálogo da plataforma
  (`ServiceTemplates`), o mesmo na tela, na criação e na API.
- `app/Jobs/PullTemplatesFromCDN.php`: a plataforma não baixa templates do CDN do Coolify; eles chegam com a
  sincronização do upstream e passam pelos testes antes de ir para produção.
- `app/Livewire/SettingsOauth.php`: correção de um bug do upstream (a rota `home` não existe; quem não é
  administrador da instância vai para o painel, como nas outras telas de configuração).

<!-- upstream-files:start -->
- `app/Actions`: `Application/StopApplication.php` (+2/-0), `Application/StopApplicationOneServer.php` (+2/-0), `Database/StartClickhouse.php` (+2/-0), `Database/StartDatabase.php` (+3/-0), `Database/StartDragonfly.php` (+2/-0), `Database/StartKeydb.php` (+2/-0), `Database/StartMariadb.php` (+2/-0), `Database/StartMongodb.php` (+2/-0), `Database/StartMysql.php` (+2/-0), `Database/StartPostgresql.php` (+7/-0), `Database/StartRedis.php` (+2/-0), `Database/StopDatabase.php` (+2/-0), `Service/StartService.php` (+6/-0), `Service/StopService.php` (+2/-0)
- `app/Http`: `Controllers/Api/ApplicationsController.php` (+7/-3), `Controllers/Api/DatabasesController.php` (+7/-4), `Controllers/Api/DeployController.php` (+13/-2), `Controllers/Api/ServersController.php` (+19/-0), `Controllers/Api/ServicesController.php` (+13/-7)
- `app/Jobs`: `ApplicationDeploymentJob.php` (+69/-35), `PullTemplatesFromCDN.php` (+2/-1), `StripeProcessJob.php` (+9/-0)
- `app/Livewire`: `Boarding/Index.php` (+3/-0), `DeploymentsIndicator.php` (+10/-4), `Project/Application/DeploymentNavbar.php` (+3/-2), `Project/CloneMe.php` (+2/-3), `Project/Database/Heading.php` (+11/-2), `Project/Database/Import.php` (+33/-2), `Project/Database/Postgresql/General.php` (+16/-4), `Project/Index.php` (+1/-1), `Project/New/Select.php` (+23/-3), `Project/Service/Heading.php` (+18/-6), `Project/Shared/Destination.php` (+29/-7), `Project/Shared/GetLogs.php` (+33/-0), `Project/Shared/ResourceOperations.php` (+3/-1), `SettingsOauth.php` (+1/-1)
- `app/Models`: `Service.php` (+8/-1)
- `bootstrap`: `helpers/shared.php` (+1/-1), `helpers/subscriptions.php` (+1/-0)
- `config`: `app.php` (+2/-1), `constants.php` (+1/-1)
- `resources`: `views/components/navbar.blade.php` (+21/-1), `views/layouts/app.blade.php` (+1/-0), `views/livewire/dashboard.blade.php` (+4/-0), `views/livewire/project/clone-me.blade.php` (+1/-1), `views/livewire/project/new/select.blade.php` (+12/-7), `views/livewire/project/shared/resource-operations.blade.php` (+2/-2), `views/livewire/subscription/index.blade.php` (+6/-2), `views/livewire/subscription/show.blade.php` (+10/-4)

Total: 49 arquivos.
<!-- upstream-files:end -->

