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

Fluxo do tenant: assinatura paga com Pix ou boleto (Efí Bank), provisionamento das redes nos nós da região, onboarding
(projeto inicial, sem pedir servidor) e deploys nos nós compartilhados.

## Começo rápido

O caminho mais curto para produção é o `infra/prod/hostrema-prod.sh` (seção *Produção na AWS com um comando*, abaixo): ele faz tudo o que está aqui e em `docs/region-onboarding.md`. Os passos a seguir são o mesmo processo à mão, para instalar fora da AWS ou em partes.

1. No `.env` do control plane, o Hostrema só precisa de `PLATFORM_ENABLED=true`. O resto do `.env` é o padrão do Coolify (`APP_KEY`, banco, Redis e realtime).
2. Dentro do container, rode `php artisan platform:install --email=voce@empresa.com --url=https://painel.seudominio.com` (por exemplo, `docker exec -it coolify php artisan platform:install ...`). O comando:
   - cria o owner do time root, com o e-mail já verificado (sem `--password`, a senha gerada aparece no fim);
   - reabre o cadastro de clientes, que o Coolify fecha depois do primeiro usuário, e liga a API;
   - cria a primeira região (`sa-east-1`, mude com `--region`);
   - aplica os padrões de produção: clientes no gVisor, que o Ansible instala nos nós (use `--without-gvisor` se os nós não tiverem).

   Pode rodar de novo quando quiser: ele só cria o que falta e não desfaz nada que você mudou no painel.
3. Entre com o owner, ative o 2FA (exigido do operador) e abra **/admin/platform → Configurações**. A lista **Pronto para vender?** mostra o que ainda falta (e-mail transacional, pagamentos, região e nós), com o link de cada tela.
4. Em **/admin/platform → Cobrança**, preencha o Client ID e o Client Secret da aplicação criada na Efí, envie o certificado (`.p12`) e informe a chave Pix da conta. Clique em **Salvar e conectar**: o painel testa as duas APIs e registra o webhook do Pix sozinho.

Os planos já vêm com preços e limites (Básico R$ 49,90/mês ou R$ 499/ano, Profissional R$ 149,90/mês ou R$ 1.499/ano, Enterprise sob consulta). Para mudar preços, limites e recursos, ou para criar e remover planos, use a aba **Planos** do console (seção *Planos*, abaixo).

A partir daí o cliente escolhe o plano, informa os dados de cobrança e paga com Pix (confirmação em segundos) ou boleto. O plano é ativado sozinho quando a Efí confirma o pagamento.

## Produção na AWS com um comando

```bash
./infra/prod/hostrema-prod.sh
```

No Windows, pelo PowerShell: `.\infra\prod\hostrema-prod.ps1`. Ele instala o Ubuntu do WSL na primeira vez e roda o script lá (o Ansible não roda direto no Windows).

O script só pergunta o que precisa vir de você:

- o login na AWS, se esta máquina ainda não tiver credenciais. Pelo navegador, com o mesmo login do console (pode ser o usuário root), sem criar chave de acesso. Precisa da AWS CLI 2.32 ou mais nova: o script instala a atual quando não há nenhuma e, com uma anterior, entra com a chave de acesso. Por SSH ou no WSL, o endereço do login abre em qualquer aparelho e você cola o código no terminal. Quem preferir responde `chave` e usa uma chave de acesso de um usuário administrador;
- o domínio principal (ex.: `hostrema.com.br`): o painel fica em `painel.<domínio>` e as aplicações em `<nome>.apps.<domínio>`;
- o seu e-mail (owner do painel, alertas e certificados);
- o tamanho inicial (`inicial`, `padrao` ou `grande`, com as máquinas de cada um na tela);
- se você quiser, as credenciais e o certificado da Efí Bank. Sem elas, a cobrança começa no modo manual.

O resto ele faz sozinho, nesta ordem:

1. Instala o que faltar nesta máquina (AWS CLI, Terraform, Ansible, jq) em `~/.hostrema/tools`, sem mexer no sistema quando dá. Se a região escolhida for uma das que a AWS entrega desligada (as *opt-in*), liga a região na conta.
2. Cria o bucket do estado do Terraform (versionado, criptografado e com trava). Guarda nele também uma cópia das respostas e senhas, para rodar de outra máquina.
   Confere a cota de vCPUs da região, que conta as máquinas comuns ligadas ao mesmo tempo (as que já rodam na conta também contam). Conta nova vem com 5 e o tamanho `inicial` usa 12: se faltar, o script pede o aumento à AWS logo aqui e só espera a aprovação na hora de criar as máquinas. Costuma sair em minutos; se a AWS abrir um caso no suporte, a resposta chega por e-mail, e rodar o script de novo continua de onde parou.
3. Cria a zona DNS do domínio no Route53. Se o domínio já tem site ou e-mail, copia antes para a zona os registros que estão no ar (raiz, `www`, `mail`, MX, SPF, DMARC e as chaves DKIM mais comuns), para a troca não derrubar nada. Se o domínio estiver registrado fora da AWS (no registro.br, por exemplo), mostra os quatro servidores DNS para trocar no registro e espera a mudança aparecer. É o único passo fora da AWS; num domínio registrado na própria AWS, até isso é automático.
4. Configura o Amazon SES (domínio verificado com DKIM, MAIL FROM próprio em `bounce.<domínio>` com SPF, DMARC, supressão de endereços que voltam ou reclamam e credenciais SMTP) e pede à AWS o acesso de produção do SES. Se o seu e-mail não for do domínio, a AWS manda para ele um link de verificação: clique nele para já receber os e-mails do painel enquanto o acesso de produção não sai.
5. Constrói a imagem do painel com o código desta pasta no AWS CodeBuild (enquanto a troca do DNS acontece) e a guarda no Amazon ECR da conta. Só o código vai para a AWS (o que o `.gitignore` exclui, como o `.env`, fica aqui), e esta máquina não precisa de Docker. Numa conta em que o CodeBuild ainda não está liberado, o script usa o Docker desta máquina, se houver.
6. Cria o painel (`infra/terraform/control-plane`): balanceador com certificado, PostgreSQL Multi-AZ, Redis com failover e as instâncias, que se configuram sozinhas no primeiro boot. A rede fica nas duas primeiras zonas da região que têm os tipos de máquina do tamanho escolhido (nem toda zona tem todos), e continua nelas depois; um tamanho novo cujos tipos faltem numa dessas zonas é recusado antes de qualquer mudança. Se a preparação de uma instância falhar (rede ou AWS indisponível por um tempo), o script a refaz; se ainda assim o painel não subir, mostra o fim do log dela.
7. Prepara a plataforma (`platform:install`) e configura tudo o que seria feito no painel (`platform:configure`): domínio das aplicações, tokens internos, e-mail, cobrança, a chave SSH dos nós e o token da API do Ansible.
8. Cria a região (`infra/terraform/regions/<região>`, ligada ao painel por peering) e prepara os servidores com o Ansible. O Ansible cadastra os nós no painel, e o proxy de cada nó passa a confiar no balanceador de borda da região.
9. Liga o PROXY protocol da borda e confere o painel, os servidores e a borda.

No fim, o script mostra endereços e senhas (de novo com `./hostrema-prod.sh info`). As respostas e senhas ficam em `~/.hostrema/prod`.

Rodar de novo é seguro: ele só cria o que falta e só muda o que mudou, sem desfazer o que você ajustou no painel. Se o código da aplicação mudou, publica a nova versão no painel, com rollback automático (mudanças só em `infra/`, `docs/` ou `tests/` não geram imagem nova). Antes de criar ou mudar recursos pagos, mostra o plano e pede confirmação (`-y` dispensa).

O SSH do painel e dos servidores só aceita o IP público de quem roda o script. Se o seu IP mudar (internet com IP dinâmico), `up`, `upgrade`, `ssh`, `artisan` e `logs` liberam o IP novo antes de continuar.

| Comando | Para quê |
| --- | --- |
| `./hostrema-prod.sh plan` | mostra o que seria criado ou mudado, sem mudar nada |
| `./hostrema-prod.sh upgrade` | publica no painel a versão atual do código |
| `./hostrema-prod.sh reconfigure` | muda o domínio, o e-mail, o tamanho ou a Efí, e aplica |
| `./hostrema-prod.sh info` | endereços, senhas e situação do SES |
| `./hostrema-prod.sh ssh [host]` | shell no painel ou num servidor da região (`control`, `node-01`...) |
| `./hostrema-prod.sh artisan <comando>` | comando do Laravel no painel |
| `./hostrema-prod.sh logs` | logs do painel |
| `./hostrema-prod.sh destroy` | apaga a instalação, inclusive o usuário que envia pelo SES e a chave dele (pede para você digitar o domínio) |

Até a AWS liberar o SES (a resposta chega por e-mail, normalmente em até 24 horas), o painel só consegue enviar e-mail para endereços verificados, como o seu. O `info` mostra quando foi liberado e se a AWS já verificou o domínio.

Tudo o que a instalação precisa na AWS e dá para fazer pelo terminal, o script faz. Ficam de fora só:

- a troca dos servidores DNS no registro do domínio, quando ele não está registrado na própria AWS (o registro.br não tem uma API para isso);
- as respostas da AWS que passam por uma pessoa: o acesso de produção do SES, o aumento da cota de vCPUs quando vira caso no suporte e a liberação do CodeBuild numa conta nova (enquanto isso, a imagem é construída com o Docker desta máquina);
- o clique no link de verificação do SES, quando o seu e-mail não é do domínio. `infra/prod/test/simulate.sh` roda o script em todos os cenários (instalação, segunda execução, código novo, `upgrade`, `reconfigure`, `destroy` e a instalação seguinte, máquina nova, IP novo, domínio na AWS, SMTP próprio, Efí, outra região, região desligada, login pelo navegador, chave de acesso, cota de vCPUs, zonas sem o tipo de máquina, SES ainda em teste, respostas inválidas e cada falha possível) com substitutos da AWS, do Terraform, do SSH e do Ansible. `bash infra/test/run.sh` roda todos os testes de uma vez (scripts, Terraform, Ansible, a lista de arquivos do Coolify oficial que o fork altera e a suíte PHP da plataforma); o CI roda a parte dos scripts a cada mudança.

### Ao atualizar uma instalação existente

Versões anteriores tinham um erro: com a plataforma instalada, o Coolify não criava as configurações de aplicações novas nem o volume de dados de bancos novos. Depois de atualizar, rode `php artisan platform:repair-resources` para listar os recursos afetados e, depois de fazer backup dos bancos listados, `php artisan platform:repair-resources --fix`. O volume novo é montado no próximo reinício do banco.

### Criar a aplicação na Efí

A Efí aceita conta de pessoa física, sem CNPJ (Efí Pro), e de empresa (Efí Empresas). As duas têm as APIs.

1. Na conta Efí, vá em **API → Aplicações → Criar aplicação**.
2. Ative a **API Cobranças** (boletos) e a **API Pix** com os escopos `cobv.write`, `cobv.read`, `webhook.write` e `webhook.read`, em produção e, se for testar, em homologação.
3. Copie o **Client ID** e o **Client Secret** do ambiente que vai usar. Produção e homologação têm credenciais próprias.
4. Em **API → Meus certificados**, crie o certificado do mesmo ambiente e baixe o `.p12`. Envie o arquivo como veio: o painel abre também os `.p12` feitos com algoritmos antigos, que o PHP sozinho não lê. Um `.pem` com o certificado e a chave também serve.
5. Cadastre uma chave Pix na conta (CPF/CNPJ, e-mail, telefone ou aleatória) e informe-a no console.

Sem chave Pix ou sem certificado, toda fatura sai como boleto com QR Code Pix, e só as credenciais bastam.

Para testar sem dinheiro real, use as credenciais e o certificado de homologação, escolha **Homologação (testes)** no console e use **Simular pagamento** na lista de faturas. No boleto, a simulação faz a baixa pela API da Efí. No Pix, que a homologação não deixa pagar, o pagamento é registrado direto no painel.

## Testar em localhost (Docker)

`infra/local/` sobe o Hostrema inteiro na sua máquina para você testar antes de subir para o host. O ambiente usa a mesma imagem de produção, construída a partir deste checkout, e traz PostgreSQL, Redis, o realtime, o Mailpit (recebe todos os e-mails) e um nó local. O nó é um container com Docker próprio (Docker-in-Docker), acessado por SSH como os nós de uma região. As aplicações dos clientes, o Traefik e os builds rodam dentro dele, sem mexer nos containers da sua máquina.

Você precisa de Docker Desktop (Windows ou macOS) ou Docker Engine com o plugin compose (Linux), de alguns GB livres para as imagens e das portas 8000, 6001, 6002, 8025, 80 e 443 livres (todas mudam no `infra/local/.env`). No Windows, pelo PowerShell: `.\infra\local\hostrema-local.ps1` (aceita os mesmos comandos). Ele roda o script no Git Bash, que vem com o Git for Windows, ou, sem ele, no Ubuntu do WSL (com a integração do Docker Desktop ligada para ele). Se o Windows bloquear scripts do PowerShell, rode `powershell -ExecutionPolicy Bypass -File .\infra\local\hostrema-local.ps1`; vale o mesmo para o de produção.

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

O mesmo roteiro roda sozinho num navegador (Playwright), e o CI roda a cada mudança do painel:

```bash
cd infra/local/e2e
npm ci && npx playwright install chromium
set -a; . ../.env; set +a
E2E_NODE=1 E2E_OWNER_EMAIL="$HOSTREMA_OWNER_EMAIL" E2E_OWNER_PASSWORD="$HOSTREMA_OWNER_PASSWORD" npx playwright test
```

Ele cadastra um cliente, confirma o e-mail pelo Mailpit, abre o painel antes de pagar, escolhe o plano informando só nome, CPF e e-mail, simula o pagamento, cria o primeiro projeto, adiciona um repositório público do GitHub, faz o deploy de uma imagem Docker e abre o site em `http://<nome>.localhost`, cria um PostgreSQL e um serviço de um clique, e abre as abas do console do owner. Um segundo cliente, no plano Básico, adiciona um projeto Docker Compose como está no repositório (banco com volume, pasta do projeto montada e portas no host), troca o arquivo de `.yaml` para `.yml` e faz o deploy. Em cada tela confere que o nome Coolify não aparece. O relatório (com capturas de tela) fica em `infra/local/e2e/playwright-report`.

Para testar a Efí de verdade, rode `./hostrema-local.sh efi`. Ele pede o ambiente (homologação por padrão, sem dinheiro de verdade), o Client ID, o Client Secret, o caminho do certificado `.p12` e a chave Pix, conecta o painel, testa as duas APIs e diz o resultado, com o que falta e onde corrigir. Se nada conectar, os pagamentos continuam no modo manual. As credenciais ficam só no painel, criptografadas. Com as variáveis `HOSTREMA_EFI_CLIENT_ID`, `HOSTREMA_EFI_CLIENT_SECRET`, `HOSTREMA_EFI_CERTIFICATE_FILE`, `HOSTREMA_EFI_PIX_KEY` (e `HOSTREMA_EFI_ENVIRONMENT`, `HOSTREMA_EFI_CERTIFICATE_PASSWORD`) no ambiente, o `efi` não pergunta nada e o `up` já conecta a Efí sozinho. As notificações da Efí não alcançam o localhost, mas o botão **Consultar** e a conferência automática a cada 15 minutos atualizam as faturas.

| Comando | Para quê |
| --- | --- |
| `./hostrema-local.sh rebuild` | reconstrói a imagem depois de mudar o código |
| `./hostrema-local.sh efi` | conecta a Efí Bank (pede as credenciais e o certificado) |
| `./hostrema-local.sh doctor` | confere Docker, containers, painel, filas, nó, proxy e domínios `*.localhost` |
| `./hostrema-local.sh test` | roda os testes de navegador neste ambiente (precisa do Node.js) |
| `./hostrema-local.sh logs [serviço]` | mostra os logs |
| `./hostrema-local.sh artisan <comando>` | roda um comando do Laravel |
| `./hostrema-local.sh shell` | abre um shell no container do Hostrema |
| `./hostrema-local.sh status` | mostra o estado dos containers |
| `./hostrema-local.sh down` | para tudo sem apagar os dados |
| `./hostrema-local.sh reset` | apaga todos os dados locais |

Se uma porta já estiver em uso na sua máquina, o `up` troca por outra livre, grava no `.env` e avisa (a 80 das aplicações vai para a 8080, e os endereços ficam `http://<nome>.localhost:8080`). No fim, o painel abre no navegador (`HOSTREMA_NO_OPEN=1` desliga isso).

O ambiente local não tem HTTPS, gVisor, slices por cliente, Loki/Grafana, edge router nem portas públicas de bancos. As aplicações respondem só pelas portas 80 e 443 do nó. Mesmo sem os slices, os containers de um cliente dividem o plano como em produção, então uma stack com vários containers cabe no plano Básico.

## Configurações (painel do owner)

Quase tudo se configura em **/admin/platform → Configurações**, sem editar o `.env` e sem reiniciar nada. Só o owner do time root grava; os admins do time root veem a tela em modo leitura. Os valores ficam criptografados no banco e chegam a todas as instâncias do painel e aos workers em poucos segundos. Cada campo mostra se está no padrão ou personalizado e tem um botão **Voltar ao padrão**. Toda alteração entra no audit log, sem os valores secretos.

| Grupo | O que tem |
| --- | --- |
| Geral | Nome e descrição da plataforma, idioma das páginas (padrão pt-BR), e-mail de suporte, links de vendas, documentação, termos e privacidade, logo, moeda, domínio base das aplicações e hosts reservados. Também traz a lista **Pronto para vender?**. |
| Cobrança | Meio de pagamento (Efí Bank, manual ou Stripe), instruções do modo manual, prazo, dias até a baixa da cobrança, multa, juros, cobrança mínima, preço do excedente, trial e chaves do Stripe. |
| Contas | Aviso de cota, dias até suspender e até parar os containers, servidores próprios e terminal para clientes. |
| Containers e isolamento | Runtime (gVisor) dos nós compartilhados e das VMs dedicadas, CPU e memória padrão, capabilities, OOM score, slices por cliente, divisão do plano entre os containers, logs por container, limite de disco e faixa de portas dos bancos públicos. |
| Logs, traces e medição | Loki padrão, tempo limite e linhas do histórico, endpoint OTLP, intervalo e retenção da medição. |
| Webhooks e eventos | Limites, tempo limite, retenção, desativação por falhas, HTTP simples, alvos privados do operador e stream do Redis. |
| Segurança | Quem precisa de 2FA e as redes permitidas para os tokens do operador. |
| Privacidade (LGPD) | Controlador (razão social, CNPJ ou CPF, endereço), e-mail de contato, encarregado, onde os dados ficam (no cluster, gerado a partir das regiões da instalação), textos extras da política e dos termos, e os prazos: registros de acesso (mínimo 6 meses), faturas de contas encerradas (mínimo 5 anos) e cadastros que nunca confirmaram o e-mail. |
| Tokens internos e edge | Tokens do Prometheus e dos edge routers (gerados sozinhos na primeira subida; **Gerar novo** troca) e portas e PROXY protocol dos nós. |

Outras telas guardam o resto:

- **Planos (preços, limites, recursos, ordem e visibilidade):** aba **Planos** do console.
- **Credenciais e certificado da Efí:** aba **Cobrança** do console.
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

## Planos

Os planos ficam na tabela `platform_plans` e se editam na aba **Planos** do console. Os três que vêm de fábrica:

| | Básico | Profissional | Enterprise |
| --- | --- | --- | --- |
| Preço | R$ 49,90/mês ou R$ 499/ano | R$ 149,90/mês ou R$ 1.499/ano | sob consulta |
| CPU e memória | 0,5 vCPU e 1,5 GB | 2 vCPU e 4 GB | 8 vCPU e 16 GB |
| Armazenamento | 10 GB | 50 GB | 250 GB |
| Recursos e containers | 3 e 6 | 15 e 30 | 100 e 200 |
| Aplicações, bancos, Docker Compose, volumes e backups dos bancos | sim | sim | sim |
| Previews de pull request, bancos públicos, exportação de logs e traces | não | sim | sim |
| Servidor dedicado e SSO | não | não | sim |

**Volumes, diretórios e backups estão em todos os planos.** Uma aplicação comum (um `docker-compose.yml` com banco e pasta do projeto, por exemplo) precisa deles, e o limite que vale é o armazenamento: o uso dos volumes, diretórios e backups é medido, o excedente entra na fatura seguinte e, com 100% da cota, novos deploys esperam até liberar espaço ou mudar de plano. Os demais recursos que o Básico não tem são os que custam mais para a plataforma ou só servem para equipes.

**CPU e memória são divididos entre os containers do cliente.** Em cada nó, todos os containers de um cliente rodam juntos no slice dele, limitado ao plano. Cada container pode usar até o plano inteiro, então os limites declarados não se somam contra o plano, e uma stack de quatro containers cabe no Básico. Um nó nunca conta mais que o plano; só recursos espalhados por mais de um nó somam além dele, e o deploy é recusado com o motivo. O uso que aparece para o cliente é o medido ("em uso"), não o reservado. Sem slices em produção, desligue "Os containers de um cliente dividem o plano em cada nó" (Configurações → Containers e isolamento) para que cada limite conte por inteiro.

**Portas do compose.** `ports` publicados no host viram `expose` na rede do cliente (o serviço é acessado pelo domínio), com um aviso no log do deploy. Um compose que monta uma pasta do próprio repositório (`.:/app`, por exemplo) precisa de **Preserve Repository During Deployment** ligado na aplicação, senão a pasta chega vazia; o log do deploy avisa.

Na aba **Planos** o owner:

- cria um plano do zero ou duplica um existente (a cópia nasce oculta);
- muda nome, descrição, preços mensal e anual, IDs de preço do Stripe, destaque ("Recomendado") e venda só pelo comercial;
- define cada limite (ou marca **Ilimitado**), liga ou desliga cada recurso e acrescenta itens livres (suporte, SLA...) com uma explicação;
- muda a ordem em que os planos aparecem e oculta um plano, que deixa de ser vendido mas continua atribuível a clientes;
- remove um plano. Se houver clientes nele, escolhe para qual plano eles vão; as faturas em aberto do plano removido são canceladas e a próxima renovação já cobra o novo.

As mudanças valem para todos os clientes do plano em poucos minutos (os limites dos nós são reaplicados), entram no audit log e aparecem nas páginas de preços: cada limite, recurso e item tem um ícone de informação que explica o que é, e o que o plano não inclui aparece riscado.

Para um cliente só, **Tenants → Editar** troca o plano (inclusive por um oculto) e ativa a conta na hora, adia o fim do período atual (dias grátis), marca a conta como cortesia (renova sem fatura), dá limites e recursos próprios (o campo vazio usa o do plano) e guarda notas que só os operadores veem.

**Acessar como este usuário.** No mesmo painel, cada membro do cliente tem o botão **Acessar como este usuário**: o operador entra no painel do cliente como aquele usuário, para ver o que ele vê ou resolver algo por ele. Uma faixa no topo mostra quem está sendo acessado e o botão **Voltar ao console do operador**. O início e o fim ficam no audit log, e cada ação feita nesse tempo leva o operador em `impersonated_by`. Só o owner e os admins do time root podem acessar, e nunca como outro usuário do time root.

## Cobrança com a Efí Bank

Com a chave Pix e o certificado configurados, cada fatura vira uma cobrança Pix com vencimento (API Pix da Efí, `cobv`, com multa e juros): o cliente informa só nome, CPF/CNPJ e e-mail e paga pelo QR code ou pelo Pix copia e cola. Quem prefere boleto escolhe isso nos dados de cobrança (ou em "Prefere pagar com boleto?" na fatura), informa um telefone com DDD e recebe um boleto com QR Code Pix (API Cobranças, `one-step`), pago pelo código de barras ou pelo Pix. Ninguém informa endereço. Sem chave Pix ou sem certificado, toda fatura é boleto. A página de pagamento se atualiza sozinha quando o pagamento é confirmado, mostra o link para voltar ao início e, depois do pagamento, o botão para o painel. Antes do primeiro pagamento o cliente já abre o painel, que mostra como ativar o plano; criar e publicar recursos só fica liberado com o plano ativo.

- **Assinatura.** A primeira fatura cobre o primeiro período (mensal ou anual). O plano começa quando ela é paga.
- **Renovação.** No fim de cada período sai uma fatura com o próximo período e o uso excedente do período que fechou, com o prazo de pagamento definido em Configurações → Cobrança (padrão: 5 dias). O cliente continua usando durante o prazo.
- **Atraso.** Fatura vencida deixa o tenant em atraso: aviso por e-mail, deploys suspensos após 7 dias, containers parados após 30 dias (com backup antes). Ao pagar, tudo volta sozinho. O cliente pode gerar uma nova cobrança se a anterior expirar.
- **Mudanças de plano.** Upgrade é cobrado proporcionalmente ao resto do período e vale quando é pago. Downgrade e troca de mensal/anual valem na próxima renovação. O cancelamento encerra a assinatura no fim do período.
- **Confirmação.** O Pix da chave chega em `https://<painel>/api/v1/billing/webhooks/efi/<token secreto>/pix`, registrado pelo console (sem exigir certificado do nosso lado: o token secreto autentica a chamada). Cada boleto leva o endereço `.../efi/<token secreto>/charges`, que a Efí chama com um token de notificação. Nenhum aviso é aceito como pagamento: o painel consulta de novo a cobrança na Efí antes de liberar o plano. A cada 15 minutos um job também confere as faturas em aberto, cobrindo avisos perdidos.
- **Operador.** Em /admin/platform → Cobrança é possível consultar uma fatura no banco, marcar como paga (pagamento recebido por fora), cancelar, emitir de novo e, na homologação, simular o pagamento.
- **Teste de conexão.** "Salvar e conectar" e "Testar conexão" testam cada forma de pagamento do ambiente em uso e mostram, abaixo do formulário, o resultado de cada uma com o que fazer: **Boleto** (Client ID e Client Secret), **Pix** (as mesmas credenciais, o certificado e os escopos `cobv.write`/`cobv.read` da aplicação) e **Avisos de pagamento** (o webhook da chave Pix). HTTP 401 é credencial errada; HTTP 403 no Pix com o boleto conectado é certificado que não é deste ambiente (o de Homologação não serve em Produção) ou aplicação sem a API Pix; certificado vencido é recusado antes de chamar a Efí. O resultado fica salvo por ambiente, com a data, e vira "não testado" quando as credenciais mudam. O formulário mostra para quem cada certificado foi emitido, a validade e a impressão digital, e avisa quando os dois ambientes têm o mesmo certificado.
- **Localhost.** A Efí só chama um endereço público com HTTPS, então em `http://localhost`, em `*.local`/`*.test`, em IP privado ou sem HTTPS os avisos de pagamento não são registrados (o teste diz o motivo) e o Pix funciona do mesmo jeito: a fatura aberta é conferida na Efí a cada 20 segundos enquanto o cliente olha a página de pagamento e a cada 15 minutos pelo ciclo de cobrança. Para testar ponta a ponta use o ambiente **Homologação** com as credenciais e o certificado de homologação e **Simular pagamento**.
- **Dados do pagador.** Nome, CPF/CNPJ (validado), e-mail e, só para boleto, telefone. Ficam criptografados no banco de dados, assim como o Client Secret, o certificado e a chave da Efí, que nunca voltam para o navegador.

A integração usa um cliente próprio (`app/Services/Platform/Efi/`) com o cliente HTTP do Laravel, sem o SDK da Efí nem dependência nova no `composer.json`, o que também evita conflitos no `composer.lock` ao puxar o Coolify oficial. A API Pix usa OAuth2 com o certificado (mTLS); a API Cobranças, só o Client ID e o Client Secret. Instalações que usavam o Banco Inter passam para a Efí na migração `2025_10_20_000011`: as faturas em aberto perdem a cobrança antiga e são emitidas de novo pela Efí no ciclo seguinte, e as credenciais antigas são apagadas.

### Pagamento manual (sem banco)

Com o meio de pagamento **Manual** (Configurações → Cobrança), cada fatura sai na hora com as instruções que você escrever (chave Pix, conta, para onde mandar o comprovante). O plano é liberado quando você clica em **Marcar como paga** na aba Cobrança. Atrasos, suspensões e avisos funcionam como na Efí. Para testes, a opção "Clientes podem simular o pagamento" mostra o botão **Simular pagamento** nas faturas em aberto. Nunca ligue essa opção em produção.

### Stripe (alternativa)

Com o meio de pagamento **Stripe** vale a integração anterior: assinaturas no Stripe, webhook em `/api/v1/billing/webhooks/stripe` com os eventos `customer.subscription.*`, `invoice.*` e `charge.refunded`, e o customer portal do Stripe. A chave secreta e o segredo do webhook ficam em Configurações → Cobrança; os IDs de preço, em cada plano da aba **Planos**.

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
| Acima de 100% (containers e recursos; CPU e memória só quando os containers não dividem o plano) ou armazenamento cheio | Status `limited`: sem novos recursos nem deploys, e o que já roda continua |
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
- conecta apenas à rede `tenant-{id}`, e os builds deixam de usar `--network host`;
- num compose com redes próprias (`site`, `db`...), prende o Traefik à rede do recurso (`traefik.docker.network`), a que o proxy sempre alcança. Sem isso, o Traefik escolhe uma das redes do container ao acaso e o domínio responde 502 ou 504 de vez em quando.

Também há guardas nos modelos para todas as entradas (UI, API e webhooks): validação de comandos,
portas, imagens, caminhos, domínios (sem domínio reservado ou de outro tenant), recursos por
plano (bancos, Docker Compose, backups, previews, bancos públicos e exportação de logs) e o destino do
recurso (só destinos do próprio tenant).

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

Os valores mudam na aba **Planos** ou, para um cliente só, em **Tenants → Editar** (limites do cliente). Ao mudar o plano ou os ajustes, os limites são reaplicados em todos os nós. A banda limita o tráfego entre os containers e a internet; o tráfego entre o proxy e a aplicação (respostas HTTP) não passa por esse limite.

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
| Painel unificado e marca | O seletor de times do Coolify separa os clientes; o nome e o logo da plataforma substituem os do Coolify nas telas, e-mails e notificações (veja "Marca"). |

## Console do operador

`/admin/platform`, apenas para o time root, tem oito abas:

- **Tenants:** plano, status, uso, suspender (manual ou total), reativar e provisionar. **Editar** abre tudo o que é do cliente: plano (inclusive os ocultos), fim do período atual (adiar dá dias grátis), conta cortesia (nunca cobrada), limites e recursos próprios, notas internas e os membros, cada um com **Acessar como este usuário** (veja *Planos*);
- **Planos:** preços, limites, recursos e itens extras de cada plano, ordem, destaque e visibilidade; criar, duplicar e remover planos;
- **Cobrança:** credenciais e certificado da Efí, teste de conexão por forma de pagamento, webhook do Pix e faturas de todos os tenants (consultar, marcar como paga, cancelar, emitir de novo);
- **Avisos:** mensagens do owner (novidade, promoção, atualização, manutenção, importante) para todos, para os clientes, para a equipe da plataforma, para os clientes de um plano ou para um cliente, na hora ou agendadas (no fuso da instância), com fim opcional. Mostra quantos usuários o aviso alcança antes de enviar e quantos já leram; encerrar tira o aviso do sino sem apagá-lo;
- **Regiões e nós:** região, nó dedicado e reprovisionamento; também cria, edita (nome e Loki) e desativa regiões;
- **Configurações:** tudo o que antes ficava no `.env` (seção *Configurações* acima). Só o owner grava;
- **Privacidade (LGPD):** o que falta na política, solicitações dos titulares para responder, exclusão de contas, incidentes de segurança, nova versão da política e o registro das operações (seção *Privacidade e LGPD* abaixo). Admins do time root veem; só o owner age;
- **Audit log:** ações de usuários, do sistema e da cobrança.

## Sino de notificações e mensagens

Todo usuário tem um sino no canto superior direito do painel. Nele ficam os avisos do owner (aba **Avisos** do console) e os eventos da conta: fatura disponível, pagamento confirmado ou recusado, assinatura cancelada, limites do plano, suspensões e reativação (os mesmos que vão por e-mail aos donos e admins, aqui para todos os membros). O número vermelho conta o que o usuário ainda não leu; cada usuário tem a sua leitura e "Marcar todas como lidas". Avisos publicados antes de alguém entrar aparecem, mas não contam como novos. Um aviso novo atualiza os painéis abertos na hora (websocket do Coolify: canal público `platform-notifications` para avisos, canal privado do time para eventos da conta, sem o conteúdo na mensagem).

As mensagens do sistema seguem três regras:

- **Estado** (uma integração que não conecta, um plano inativo, um certificado vencido) fica na própria tela, perto do que precisa ser corrigido, dizendo o que aconteceu, por quê e como resolver, até ser resolvido;
- **Resultado de um clique** vira notificação: verde quando deu certo, amarela quando deu certo em parte, vermelha quando falhou. O título diz o que falhou e o texto, o motivo; avisos e erros ficam na tela o tempo de ler (até 30 segundos, pausando com o mouse em cima);
- **Campo inválido** aparece embaixo do campo. Recusas da plataforma listam um motivo por linha e, quando é o plano que impede, levam aos planos e ao uso da conta.

## Privacidade e LGPD

O que a plataforma faz para cumprir a Lei nº 13.709/2018 (LGPD), artigo por artigo:

- **Política de privacidade e termos de uso públicos** (`/privacidade` e `/termos`; arts. 9º e 41): controlador, CNPJ ou CPF, endereço, encarregado e contato vêm de Configurações → Privacidade (LGPD); finalidades, bases legais e prazos vêm do registro das operações; quem recebe os dados (hospedagem, e-mail, Efí ou Stripe, com a transferência internacional do art. 33) e os critérios das suspensões automáticas (art. 20) acompanham a configuração em uso. A aba Privacidade do console avisa o que falta preencher.
- **Cadastro** (arts. 8º e 14): aceitar a política e os termos e declarar ter 18 anos ou mais é obrigatório; promoções só com uma caixa que a pessoa marca. Cada escolha fica registrada com data, versão da política, IP e navegador, como prova do consentimento (art. 8º, § 2º).
- **Novas versões da política** (art. 8º, § 6º): o owner publica uma versão dizendo o que mudou; todos recebem o aviso no sino e aceitam no próximo acesso. Contas de antes disso também declaram a idade e escolhem sobre promoções nesse aviso.
- **Privacidade e dados** (`/platform/privacy`; arts. 18 e 19): resumo dos dados na hora; cópia completa em JSON (portabilidade); atalhos para corrigir; consentimento de promoções, que se dá e retira a qualquer momento (sem ele, promoções e novidades não aparecem no sino nem contam no alcance dos avisos); com quem os dados são compartilhados; solicitações para cada direito, inclusive oposição e revisão de decisão automatizada (o banner de suspensão leva direto a ela), respondidas em até 15 dias, com e-mail e aviso no sino para o controlador e a resposta por e-mail ao titular; e a exclusão da conta. A página abre para qualquer conta, mesmo sem plano pago, no onboarding ou com o e-mail ainda não confirmado (art. 18, § 5º); a política e os termos abrem até antes de ligar o 2FA.
- **Exclusão da conta** (art. 18, VI; arts. 15 e 16): pela própria pessoa (senha e a palavra EXCLUIR; bloqueada enquanto houver fatura em aberto ou pagamento atrasado) ou pelo owner (por e-mail na aba Privacidade, ou em Tenants → Editar → membros), que cancela as faturas em aberto. Na hora, a pessoa sai de todas as sessões, perde os tokens de API e a senha, e recebe por e-mail o que foi excluído e o que fica guardado. Em segundo plano: o cliente na Stripe é excluído, o que cancela a assinatura (se a Stripe falhar, nada é excluído e o owner tenta de novo na aba Privacidade); os times em que só ela estava são encerrados (aplicações, bancos e serviços saem dos nós); os times compartilhados ficam com as outras pessoas, e o e-mail de quem paga, aqui e na Stripe, passa para o próximo responsável, que é avisado para conferir os dados de quem paga (art. 18, § 6º). Ficam guardados: as faturas, com os dados de quem pagou criptografados, por 5 anos (lei fiscal); os registros de acesso por 12 meses (no mínimo 6, Marco Civil da Internet, art. 15); e o registro da exclusão pelo mesmo prazo dos registros de acesso. A conta raiz e o único owner da plataforma não podem ser excluídos.
- **Retenção** (art. 16): a manutenção diária apaga registros de acesso e de auditoria vencidos, faturas de contas encerradas depois do prazo fiscal, registros de exclusão vencidos, provas de consentimento de contas excluídas e solicitações respondidas há mais de 5 anos, e cadastros que nunca confirmaram o e-mail depois de 30 dias (sem mandar e-mail, porque o endereço pode não ser de quem se cadastrou).
- **Incidentes de segurança** (art. 48; Resolução CD/ANPD nº 15/2024): todo incidente com dados pessoais é registrado (a resolução pede no mínimo 5 anos; o registro não é apagado). Quando pode causar risco ou dano relevante, o console mostra o prazo de 3 dias úteis, contado no fuso da instalação (feriados contam como dias úteis, para o prazo mostrado nunca passar do legal), comunica os titulares por e-mail individual e no sino com tudo o que a resolução pede, e guarda a data e o protocolo da comunicação à ANPD, feita no formulário dela.
- **Registro das operações de tratamento** (art. 37): aba Privacidade, com download em CSV.
- **Encarregado** (art. 41): nome e e-mail em Configurações; agentes de pequeno porte podem deixar vazio e usar o e-mail de contato como canal (Resolução CD/ANPD nº 2/2022, art. 11).
- **Cookies:** só os necessários (sessão e CSRF); as preferências de tela ficam no navegador.

## Marca (white-label) e página pública

Com a plataforma ligada, nem o cliente nem o operador veem o nome ou o logo do Coolify:

- **Telas.** O nome da plataforma (Hostrema) entra no lugar de "Coolify" quando os templates são compilados, sem editar as telas originais, inclusive nos títulos dados em tempo de execução, nas dicas dos campos e nos exemplos (`app.example.com`). Links para a documentação do Coolify apontam para a URL de documentação configurada; sem ela, e nos demais links para sites do Coolify, fica só o texto, e as menções à comunidade do Coolify (Discord) saem. Um teste compila todas as telas assim e confere que o PHP gerado é válido, e outros dois abrem todas as telas do cliente e do operador e conferem que o nome e os links do Coolify não aparecem.
- **Logo e favicon.** O logo configurado ou, sem ele, uma marca com a inicial do nome da plataforma (`/platform/brand/logo.svg`).
- **Mensagens.** As notificações do painel (com os links), os erros de validação dos servidores, os títulos dos logs do proxy e do Sentinel, as descrições que o sistema cria ("Created by Hostrema"), os assuntos e o rodapé dos e-mails, as notificações por Discord, Slack, Telegram e Pushover e a mensagem do Coolify nos logs de deploy usam o nome da plataforma.
- **Versão e atualizações.** O operador vê a versão da plataforma (a tag `platform-vX.Y.Z` da release; `dev` nas imagens locais), não a do Coolify. A plataforma não usa o atualizador do Coolify, o changelog do Coolify nem os templates do CDN do Coolify (nem na inicialização): as mudanças do upstream chegam pela sincronização e saem nas releases. A tela Configurações → Updates some.
- **Fora do alcance do cliente.** O link de patrocínio some. O formulário "Feedback" vai para o e-mail de suporte da plataforma (ou para o dono do time da plataforma), com o cliente como resposta; no Coolify ele ia para a equipe do Coolify.

Ficam como estão os identificadores técnicos (labels `coolify.*` dos containers, variáveis `COOLIFY_*`, `/data/coolify`, a rede `coolify`, os containers `coolify-proxy` e `coolify-sentinel`, as imagens auxiliares `coollabsio/*` e o nome da CA interna dos certificados): são interfaces internas, e mudá-las quebraria os deploys e as atualizações do upstream. O arquivo `LICENSE` (Apache 2.0) e os avisos de copyright do código também ficam: a licença exige que sejam mantidos, e ela não dá direito de usar a marca "Coolify", por isso a marca sai das telas.

Nome, descrição, logo, documentação e os links de termos e privacidade ficam em Configurações → Geral. Quando eles mudam, cada instância recompila as telas sozinha no acesso seguinte.

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
| `POST /api/v1/billing/webhooks/efi/{token}/pix` | Efí Bank | Pix recebido na chave da conta (a cobrança é consultada de novo na API Pix) |
| `POST /api/v1/billing/webhooks/efi/{token}/charges` | Efí Bank | Token de notificação de um boleto (as mudanças são lidas na API Cobranças) |
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
