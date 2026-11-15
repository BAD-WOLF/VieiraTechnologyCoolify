#!/usr/bin/env bash
# Ambiente local de testes do Hostrema (Docker). Veja docs/platform.md, "Testar em localhost".
#
#   ./hostrema-local.sh up        cria o .env, constrói a imagem, sobe tudo e prepara (padrão)
#   ./hostrema-local.sh setup     prepara de novo: owner, nó local, proxy e cliente demo
#   ./hostrema-local.sh rebuild   reconstrói a imagem depois de mudar o código e reinicia
#   ./hostrema-local.sh info      mostra endereços e senhas
#   ./hostrema-local.sh status    estado dos containers
#   ./hostrema-local.sh logs [serviço]
#   ./hostrema-local.sh artisan <comando...>
#   ./hostrema-local.sh shell     shell no container do Hostrema
#   ./hostrema-local.sh down      para tudo (mantém os dados)
#   ./hostrema-local.sh reset     para e apaga todos os dados locais (pergunta antes; -y pula)
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

random_hex() { od -An -tx1 -N"${1:-16}" /dev/urandom | tr -d ' \n'; }

check_requirements() {
    command -v docker >/dev/null 2>&1 || fail "instale o Docker (Docker Desktop no Windows/macOS ou Docker Engine no Linux)."
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

EOF
}

cmd_up() {
    check_requirements
    ensure_env
    load_env
    say "Construindo e subindo os containers (a primeira vez demora alguns minutos)"
    compose up -d --build
    wait_ready
    cmd_setup
    cmd_info
}

cmd_rebuild() {
    check_requirements
    load_env
    say "Reconstruindo a imagem do Hostrema com o código atual"
    compose build hostrema
    compose up -d hostrema
    wait_ready
    cmd_setup
    cmd_info
}

cmd_reset() {
    if [ "${1:-}" != "-y" ]; then
        printf 'Apagar TODOS os dados locais (banco, aplicações do nó, senhas)? [s/N] '
        read -r answer
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
status) compose ps ;;
logs) compose logs -f --tail=200 "$@" ;;
artisan) compose exec hostrema php artisan "$@" ;;
shell) compose exec hostrema sh ;;
down) compose down ;;
reset) cmd_reset "$@" ;;
*)
    sed -n '2,13p' "$0"
    exit 1
    ;;
esac
