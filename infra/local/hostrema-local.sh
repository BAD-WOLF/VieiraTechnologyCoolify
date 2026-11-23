#!/usr/bin/env bash
# Ambiente local de testes do Hostrema (Docker). Veja docs/platform.md, "Testar em localhost".
#
#   ./hostrema-local.sh up        cria o .env, constrói a imagem, sobe tudo e prepara (padrão)
#   ./hostrema-local.sh setup     prepara de novo: owner, nó local, proxy e cliente demo
#   ./hostrema-local.sh rebuild   reconstrói a imagem depois de mudar o código e reinicia
#   ./hostrema-local.sh info      mostra endereços e senhas
#   ./hostrema-local.sh efi       conecta a Efí Bank (pede as credenciais de homologação)
#   ./hostrema-local.sh doctor    confere Docker, portas, painel, nó, proxy e domínios *.localhost
#   ./hostrema-local.sh test      roda os testes de navegador (Playwright) neste ambiente
#   ./hostrema-local.sh status    estado dos containers
#   ./hostrema-local.sh logs [serviço]
#   ./hostrema-local.sh artisan <comando...>
#   ./hostrema-local.sh shell     shell no container do Hostrema
#   ./hostrema-local.sh down      para tudo (mantém os dados)
#   ./hostrema-local.sh reset     para e apaga todos os dados locais (pergunta antes; -y pula)
#
# Uma porta já ocupada na sua máquina é trocada sozinha por outra livre (e gravada no .env).
# HOSTREMA_NO_OPEN=1 não abre o navegador no fim. Com HOSTREMA_EFI_CLIENT_ID (e as outras
# HOSTREMA_EFI_*) no ambiente, o up também conecta a Efí, sem perguntar.
set -euo pipefail

cd "$(dirname "$0")"
# Git Bash (Windows) would rewrite the container paths given to docker compose exec.
export MSYS_NO_PATHCONV=1

ENV_FILE=.env

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() {
    printf '\033[1;31mErro:\033[0m %s\n' "$*" >&2
    exit 1
}

compose() { docker compose "$@"; }

warn() { printf '\033[1;33mAtenção:\033[0m %s\n' "$*" >&2; }

PORT_VARS=(HOSTREMA_PORT HOSTREMA_REALTIME_PORT HOSTREMA_TERMINAL_PORT HOSTREMA_MAIL_PORT HOSTREMA_APPS_HTTP_PORT HOSTREMA_APPS_HTTPS_PORT)

random_hex() { od -An -tx1 -N"${1:-16}" /dev/urandom | tr -d ' \n'; }

check_requirements() {
    if ! command -v docker >/dev/null 2>&1; then
        if grep -qi microsoft /proc/version 2>/dev/null; then
            fail "o Docker não aparece neste WSL: no Docker Desktop, ligue Settings → Resources → WSL integration para esta distribuição (ou rode pelo Git Bash)."
        fi
        fail "instale o Docker (Docker Desktop no Windows/macOS ou Docker Engine no Linux)."
    fi
    docker compose version >/dev/null 2>&1 || fail "o plugin 'docker compose' (v2) não foi encontrado."
    docker info >/dev/null 2>&1 || fail "o Docker não está rodando (ou seu usuário não tem acesso a ele)."
}

ensure_env() {
    if [ -f "$ENV_FILE" ]; then
        return
    fi
    say "Criando $ENV_FILE com senhas e chaves novas"
    local app_key
    app_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    (
        umask 077
        cat >"$ENV_FILE" <<EOF
# Gerado por hostrema-local.sh: ambiente LOCAL de testes. Não use estes valores em produção.
APP_ID=hostrema-local
APP_NAME=Hostrema
APP_KEY=${app_key}
APP_DEBUG=false

PLATFORM_ENABLED=true
SELF_HOSTED=false

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=hostrema
DB_USERNAME=hostrema
DB_PASSWORD=$(random_hex 16)

REDIS_HOST=redis
REDIS_PASSWORD=$(random_hex 16)

PUSHER_APP_ID=$(random_hex 8)
PUSHER_APP_KEY=$(random_hex 16)
PUSHER_APP_SECRET=$(random_hex 16)

# Portas na sua máquina (mude se alguma já estiver em uso e rode ./hostrema-local.sh up de novo).
HOSTREMA_PORT=8000
HOSTREMA_REALTIME_PORT=6001
HOSTREMA_TERMINAL_PORT=6002
HOSTREMA_MAIL_PORT=8025
HOSTREMA_APPS_HTTP_PORT=80
HOSTREMA_APPS_HTTPS_PORT=443

# Contas criadas pelo setup.
HOSTREMA_OWNER_EMAIL=owner@hostrema.local
HOSTREMA_OWNER_PASSWORD=$(random_hex 10)
HOSTREMA_DEMO_EMAIL=demo@hostrema.local
HOSTREMA_DEMO_PASSWORD=$(random_hex 8)
EOF
    )
}

load_env() {
    [ -f "$ENV_FILE" ] || fail "o $ENV_FILE ainda não existe; rode ./hostrema-local.sh up"
    set -a
    # shellcheck disable=SC1090
    . "./$ENV_FILE"
    set +a
}

# wait_for <descrição> <serviço> <comando...>: repete o comando até dar certo (até 10 minutos).
wait_for() {
    local what=$1 service=$2
    shift 2
    local tries=200
    printf '    aguardando %s' "$what"
    until "$@" >/dev/null 2>&1; do
        tries=$((tries - 1))
        if [ "$tries" -le 0 ]; then
            printf '\n'
            compose logs --tail=80 "$service" >&2 || true
            fail "$what não ficou pronto (logs acima). Mais detalhes: ./hostrema-local.sh logs $service"
        fi
        printf '.'
        sleep 3
    done
    printf ' ok\n'
}

wait_ready() {
    [ -n "$(compose ps --status running -q hostrema 2>/dev/null)" ] || fail "o Hostrema não está rodando; use ./hostrema-local.sh up"
    # Horizon só sobe depois das migrations, do seeder e do app:init da imagem.
    wait_for "o Hostrema (migrations e filas)" hostrema compose exec -T hostrema php artisan horizon:status
    wait_for "o Docker do nó local" hostrema-node compose exec -T hostrema-node docker info
    # O painel precisa enxergar o SSH do nó pela rede do compose (lê o banner "SSH-...").
    # shellcheck disable=SC2016
    wait_for "o SSH do nó local" hostrema-node compose exec -T hostrema php -r '$s = @fsockopen("hostrema-node", 22, $n, $m, 3); exit($s && str_starts_with((string) fgets($s, 64), "SSH-") ? 0 : 1);'
}

# set_env <variável> <valor>: grava no .env e no ambiente atual.
set_env() {
    local tmp
    tmp=$(mktemp)
    if grep -q "^$1=" "$ENV_FILE"; then
        sed "s|^$1=.*|$1=$2|" "$ENV_FILE" >"$tmp"
    else
        cat "$ENV_FILE" >"$tmp"
        printf '%s=%s\n' "$1" "$2" >>"$tmp"
    fi
    cat "$tmp" >"$ENV_FILE"
    rm -f "$tmp"
    export "$1=$2"
}

port_answers() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }

port_taken_by_env() {
    local var
    for var in "${PORT_VARS[@]}"; do
        [ "${!var:-}" = "$1" ] && return 0
    done
    return 1
}

# Próxima porta livre: 80/443 das aplicações vão para 8080/8443 em diante.
next_free_port() {
    local port=$1
    case "$port" in
    80) port=8080 ;;
    443) port=8443 ;;
    *) port=$((port + 1)) ;;
    esac
    while port_answers "$port" || port_taken_by_env "$port"; do
        port=$((port + 1))
    done
    printf '%s' "$port"
}

# Sobe os containers; se o Docker disser que uma porta da sua máquina já está em uso, troca
# essa porta por uma livre no .env e tenta de novo.
compose_up() {
    local log port var
    log=$(mktemp)
    for _ in 1 2 3 4 5 6; do
        if compose up -d --build 2>&1 | tee "$log"; then
            rm -f "$log"
            return 0
        fi
        port=$(grep -iE 'already allocated|already in use|ports are not available|bind:' "$log" |
            grep -oE '(0\.0\.0\.0|127\.0\.0\.1|\[::\]):[0-9]+' | head -n 1 | sed 's/.*://' || true)
        [ -n "$port" ] || break
        var=''
        local candidate
        for candidate in "${PORT_VARS[@]}"; do
            if [ "${!candidate:-}" = "$port" ]; then
                var=$candidate
            fi
        done
        [ -n "$var" ] || break
        local new_port
        new_port=$(next_free_port "$port")
        warn "a porta $port da sua máquina já está em uso; o Hostrema passa a usar a $new_port ($var no .env)."
        set_env "$var" "$new_port"
    done
    rm -f "$log"
    fail "não consegui subir os containers (mensagens acima). Rode ./hostrema-local.sh doctor para um diagnóstico."
}

open_dashboard() {
    [ -t 1 ] && [ "${HOSTREMA_NO_OPEN:-}" != 1 ] || return 0
    local url=$1
    {
        if command -v wslview; then
            wslview "$url"
        elif grep -qi microsoft /proc/version 2>/dev/null && command -v cmd.exe; then
            cmd.exe /c start "" "$url"
        elif [ "$(uname -s)" = Darwin ]; then
            open "$url"
        elif command -v start && [ -n "${WINDIR:-}" ]; then
            start "" "$url"
        elif command -v xdg-open && [ -n "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ]; then
            xdg-open "$url"
        fi
    } >/dev/null 2>&1 || true
}

cmd_setup() {
    load_env
    say "Autorizando a chave SSH do painel no nó local"
    local key
    key="$(compose exec -T hostrema php artisan platform:install --node-public-key | tr -d '\r' | grep -E '^ssh-(ed25519|rsa) ' | tail -n 1 || true)"
    [ -n "$key" ] || fail "não consegui ler a chave SSH do nó local (php artisan platform:install --node-public-key)."
    printf '%s\n' "$key" | compose exec -T hostrema-node sh -c 'umask 077; mkdir -p /root/.ssh; cat > /root/.ssh/authorized_keys'

    say "Configurando o Hostrema (owner, região local, nó, proxy e cliente demo)"
    compose exec -T hostrema php artisan platform:install --local --no-interaction \
        --email="$HOSTREMA_OWNER_EMAIL" --password="$HOSTREMA_OWNER_PASSWORD" --reset-password \
        --node-host=hostrema-node \
        --demo --demo-email="$HOSTREMA_DEMO_EMAIL" --demo-password="$HOSTREMA_DEMO_PASSWORD"
}

cmd_info() {
    load_env
    local apps_port=""
    if [ "${HOSTREMA_APPS_HTTP_PORT:-80}" != "80" ]; then
        apps_port=":${HOSTREMA_APPS_HTTP_PORT}"
    fi
    cat <<EOF

  Painel ........... http://localhost:${HOSTREMA_PORT:-8000}
  Owner ............ ${HOSTREMA_OWNER_EMAIL} / ${HOSTREMA_OWNER_PASSWORD}
                     (configurações: /admin/platform -> Configurações)
  Cliente demo ..... ${HOSTREMA_DEMO_EMAIL} / ${HOSTREMA_DEMO_PASSWORD} (plano Profissional ativo)
  E-mails .......... http://localhost:${HOSTREMA_MAIL_PORT:-8025} (Mailpit: verificação, faturas, avisos)
  Aplicações ....... http://<nome>.localhost${apps_port} (Chrome, Edge e Firefox resolvem *.localhost)

  Pagamentos ficam no modo manual: o cliente usa "Simular pagamento" na fatura,
  ou você confirma em /admin/platform -> Cobrança -> "Marcar como paga".
  Para testar a Efí Bank de verdade (homologação): ./hostrema-local.sh efi

  Depois de mudar o código: ./hostrema-local.sh rebuild (e faça o deploy de novo
  das aplicações que já existiam). Diagnóstico: ./hostrema-local.sh doctor

EOF
}

# ask VAR "pergunta" [padrão] [secret|optional|secret-optional]: o valor de HOSTREMA_VAR, ou o que
# você digitar aqui.
ask() {
    local var=$1 question=$2 default=${3:-} mode=${4:-} env_name="HOSTREMA_$1" answer prompt
    if [ -n "${!env_name:-}" ]; then
        printf -v "$var" '%s' "${!env_name}"
        return 0
    fi
    prompt=$question
    [ -z "$default" ] || prompt="$prompt [$default]"
    while :; do
        printf '  %s: ' "$prompt"
        answer=''
        if [[ $mode == *secret* ]] && [ -t 0 ]; then
            IFS= read -rs answer || true
            printf '\n'
        else
            IFS= read -r answer || true
        fi
        answer=${answer:-$default}
        if [ -n "$answer" ] || [[ $mode == *optional* ]]; then
            break
        fi
        [ -t 0 ] || fail "falta \"$question\": digite ou defina $env_name e rode de novo."
        echo '    (obrigatório)'
    done
    printf -v "$var" '%s' "$answer"
}

# Texto como string JSON (aspas, barras e quebras de linha escapadas).
json_string() {
    local value=$1
    value=${value//\\/\\\\}
    value=${value//\"/\\\"}
    value=${value//$'\n'/\\n}
    value=${value//$'\r'/\\r}
    value=${value//$'\t'/\\t}
    printf '"%s"' "$value"
}

# A Efí Bank de verdade no ambiente local. Pede o que só você tem (as credenciais e o certificado da
# aplicação criada na Efí), conecta o painel e diz o resultado do teste das duas APIs. As
# credenciais ficam só no painel, criptografadas.
cmd_efi() {
    load_env
    [ -n "$(compose ps --status running -q hostrema 2>/dev/null)" ] || fail "o Hostrema não está rodando; use ./hostrema-local.sh up"
    say "Conectando a Efí Bank"
    echo "    Na conta Efí: API → Aplicações → (sua aplicação) com API Cobranças e API Pix, e o certificado"
    echo "    (.p12) em API → Meus certificados. Use as credenciais e o certificado do mesmo ambiente."
    local EFI_ENVIRONMENT='' EFI_CLIENT_ID='' EFI_CLIENT_SECRET='' EFI_CERTIFICATE_FILE='' EFI_CERTIFICATE_PASSWORD='' EFI_PIX_KEY=''
    local payload result label billing="http://localhost:${HOSTREMA_PORT:-8000}/admin/platform?tab=billing"
    while :; do
        ask EFI_ENVIRONMENT "Ambiente da Efí (sandbox = homologação, sem dinheiro; production = cobra de verdade)" sandbox
        case "$EFI_ENVIRONMENT" in sandbox | production) break ;; esac
        [ -z "${HOSTREMA_EFI_ENVIRONMENT:-}" ] || fail "\"$EFI_ENVIRONMENT\" não é um ambiente da Efí: use sandbox ou production (HOSTREMA_EFI_ENVIRONMENT)."
        [ -t 0 ] || fail "\"$EFI_ENVIRONMENT\" não é um ambiente da Efí: use sandbox ou production."
        warn "\"$EFI_ENVIRONMENT\" não é um ambiente da Efí: use sandbox ou production."
    done
    label=$([ "$EFI_ENVIRONMENT" = sandbox ] && echo homologação || echo produção)
    [ "$EFI_ENVIRONMENT" = sandbox ] || warn "em produção, a Efí cobra de verdade: as faturas deste ambiente local viram cobranças reais."
    ask EFI_CLIENT_ID "Client ID"
    ask EFI_CLIENT_SECRET "Client Secret" "" secret
    while :; do
        ask EFI_CERTIFICATE_FILE "Caminho do certificado .p12 (ou .pem) nesta máquina (vazio = só boleto)" "" optional
        if [ -z "$EFI_CERTIFICATE_FILE" ] || [ -f "$EFI_CERTIFICATE_FILE" ]; then
            break
        fi
        [ -z "${HOSTREMA_EFI_CERTIFICATE_FILE:-}" ] && [ -t 0 ] || fail "arquivo não encontrado: $EFI_CERTIFICATE_FILE"
        warn "arquivo não encontrado: $EFI_CERTIFICATE_FILE"
    done
    if [ -n "$EFI_CERTIFICATE_FILE" ]; then
        ask EFI_CERTIFICATE_PASSWORD "Senha do certificado (quase sempre vazia)" "" secret-optional
    fi
    ask EFI_PIX_KEY "Chave Pix da conta (vazio = só boleto)" "" optional

    payload="{\"settings\":{\"platform.billing.provider\":\"efi\"},\"efi\":{\"environment\":$(json_string "$EFI_ENVIRONMENT"),\"client_id\":$(json_string "$EFI_CLIENT_ID"),\"client_secret\":$(json_string "$EFI_CLIENT_SECRET"),\"pix_key\":$(json_string "$EFI_PIX_KEY")"
    if [ -n "$EFI_CERTIFICATE_FILE" ]; then
        payload="$payload,\"certificate_base64\":\"$(base64 <"$EFI_CERTIFICATE_FILE" | tr -d '\r\n')\",\"certificate_password\":$(json_string "$EFI_CERTIFICATE_PASSWORD")"
    fi
    payload="$payload}}"
    result=$(printf '%s' "$payload" | compose exec -T hostrema php artisan platform:configure --stdin --json 2>&1 | tr -d '\r' | tail -n 1 || true)

    if printf '%s' "$result" | grep -qE '"registered":\[[^]]+\],"errors":\[\]'; then
        say "Efí conectada ($label): as faturas saem como Pix e boleto da Efí"
        [ "$EFI_ENVIRONMENT" != sandbox ] || echo "    Em homologação, pague as faturas de teste com \"Simular pagamento\"."
        echo "    As notificações da Efí não chegam ao localhost: o painel consulta as faturas abertas sozinho."
        return 0
    fi
    if printf '%s' "$result" | grep -qE '"registered":\[[^]]+\]'; then
        say "Efí conectada em parte ($label)"
        printf '%s' "$result" | sed -nE 's/.*"errors":\{([^}]*)\}.*/\1/p' | awk '{ gsub(/","/, "\"\n\""); print }' | sed -E 's/^"([^"]*)":"(.*)"$/    \1: \2/'
        echo "    O que falta e como corrigir: $billing"
        return 0
    fi
    # Nada conectou: os pagamentos continuam no modo manual.
    compose exec -T hostrema php artisan platform:configure --set=platform.billing.provider=manual >/dev/null 2>&1 || true
    printf '%s' "$result" | sed -nE 's/.*"errors":\{([^}]*)\}.*/\1/p' | awk '{ gsub(/","/, "\"\n\""); print }' | sed -E 's/^"([^"]*)":"(.*)"$/    \1: \2/' >&2
    printf '%s' "$result" | sed -nE 's/.*"errors":\[([^]]+)\]\}$/\1/p' | awk '{ gsub(/","/, "\"\n\""); print }' | sed -E 's/^"(.*)"$/    \1/' >&2
    fail "a Efí não conectou; os pagamentos continuam no modo manual. Detalhes e como corrigir: $billing"
}

# check <descrição> <comando...>: uma linha de ok/falha no diagnóstico.
check() {
    local what=$1
    shift
    if "$@" >/dev/null 2>&1; then
        printf '  \033[1;32mok\033[0m    %s\n' "$what"
        return 0
    fi
    printf '  \033[1;31mfalha\033[0m %s\n' "$what"
    return 1
}

apps_answer_404() {
    [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H 'Host: verificacao.localhost' "http://127.0.0.1:${HOSTREMA_APPS_HTTP_PORT:-80}/")" = 404 ]
}

cmd_doctor() {
    local problems=0
    say "Diagnóstico do ambiente local"
    check "Docker instalado" command -v docker || problems=$((problems + 1))
    check "Docker rodando" docker info || {
        problems=$((problems + 1))
        printf '        abra o Docker Desktop (ou: sudo systemctl start docker)\n'
    }
    check "docker compose v2" docker compose version || problems=$((problems + 1))
    if [ ! -f "$ENV_FILE" ]; then
        printf '  ainda não há ambiente: rode ./hostrema-local.sh up\n'
        return 0
    fi
    load_env
    local service
    for service in hostrema postgres redis soketi mailpit hostrema-node; do
        check "container $service rodando" test -n "$(compose ps --status running -q "$service" 2>/dev/null)" || problems=$((problems + 1))
    done
    check "painel em http://localhost:${HOSTREMA_PORT:-8000}" curl -fsS --max-time 10 "http://localhost:${HOSTREMA_PORT:-8000}/api/health" || problems=$((problems + 1))
    check "Mailpit em http://localhost:${HOSTREMA_MAIL_PORT:-8025}" curl -fsS --max-time 10 "http://localhost:${HOSTREMA_MAIL_PORT:-8025}/" || problems=$((problems + 1))
    check "filas do painel (Horizon)" compose exec -T hostrema php artisan horizon:status || problems=$((problems + 1))
    check "Docker do nó local" compose exec -T hostrema-node docker info || problems=$((problems + 1))
    check "proxy (Traefik) do nó" sh -c 'docker compose exec -T hostrema-node docker ps --filter name=coolify-proxy --format "{{.Status}}" | grep -q Up' || {
        problems=$((problems + 1))
        printf '        rode ./hostrema-local.sh setup para iniciar o proxy do nó\n'
    }
    local apps_port=""
    [ "${HOSTREMA_APPS_HTTP_PORT:-80}" = 80 ] || apps_port=":${HOSTREMA_APPS_HTTP_PORT}"
    check "aplicações em http://<nome>.localhost${apps_port}" apps_answer_404 || problems=$((problems + 1))
    if [ "$problems" -eq 0 ]; then
        say "Tudo certo."
    else
        warn "$problems verificação(ões) falharam. Logs: ./hostrema-local.sh logs [serviço]"
        return 1
    fi
}

cmd_test() {
    load_env
    command -v npm >/dev/null 2>&1 || fail "os testes de navegador precisam do Node.js 20+ (npm)."
    say "Testes de navegador (Playwright) contra http://localhost:${HOSTREMA_PORT:-8000}"
    (
        cd e2e
        npm ci --no-audit --no-fund
        npx playwright install chromium
        HOSTREMA_URL="http://localhost:${HOSTREMA_PORT:-8000}" MAILPIT_URL="http://localhost:${HOSTREMA_MAIL_PORT:-8025}" \
            E2E_NODE=1 E2E_OWNER_EMAIL="$HOSTREMA_OWNER_EMAIL" E2E_OWNER_PASSWORD="$HOSTREMA_OWNER_PASSWORD" \
            npx playwright test "$@"
    )
}

cmd_up() {
    check_requirements
    ensure_env
    load_env
    say "Construindo e subindo os containers (a primeira vez demora alguns minutos)"
    compose_up
    wait_ready
    cmd_setup
    if [ -n "${HOSTREMA_EFI_CLIENT_ID:-}" ]; then
        cmd_efi
    fi
    cmd_info
    if apps_answer_404; then
        open_dashboard "http://localhost:${HOSTREMA_PORT:-8000}"
    else
        warn "as aplicações ainda não respondem em http://<nome>.localhost; rode ./hostrema-local.sh doctor"
    fi
}

cmd_rebuild() {
    check_requirements
    load_env
    say "Reconstruindo a imagem do Hostrema com o código atual"
    compose build hostrema hostrema-node
    compose_up
    wait_ready
    cmd_setup
    cmd_info
}

cmd_reset() {
    if [ "${1:-}" != "-y" ]; then
        printf 'Apagar TODOS os dados locais (banco, aplicações do nó, senhas)? [s/N] '
        read -r answer || true
        case "$answer" in
        s | S | y | Y) ;;
        *)
            echo "Nada foi apagado."
            return
            ;;
        esac
    fi
    compose down -v --remove-orphans
    rm -f "$ENV_FILE"
    say "Ambiente local apagado. Rode ./hostrema-local.sh up para começar de novo."
}

command=${1:-up}
[ $# -gt 0 ] && shift
case "$command" in
up) cmd_up ;;
setup)
    wait_ready
    cmd_setup
    cmd_info
    ;;
rebuild) cmd_rebuild ;;
info) cmd_info ;;
efi) cmd_efi ;;
doctor) cmd_doctor ;;
test) cmd_test "$@" ;;
status) compose ps ;;
logs) compose logs -f --tail=200 "$@" ;;
artisan) compose exec hostrema php artisan "$@" ;;
shell) compose exec hostrema sh ;;
down) compose down ;;
reset) cmd_reset "$@" ;;
*)
    sed -n '2,21p' "$0"
    exit 1
    ;;
esac
