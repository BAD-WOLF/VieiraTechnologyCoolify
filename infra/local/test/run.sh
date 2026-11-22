#!/usr/bin/env bash
# Tests of infra/local/hostrema-local.sh against stand-ins of docker (and docker compose), curl,
# sleep, npm and npx, on a copy of infra/local. No container is started.
#
#   bash infra/local/test/run.sh           every scenario
#   bash infra/local/test/run.sh portas    only the scenarios whose name has "portas"
#
# Run by infra/test/run.sh and by CI (.github/workflows/platform-ci.yml).
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO=$(cd "$HERE/../../.." && pwd)
# shellcheck source-path=SCRIPTDIR source=../../test/lib.sh
. "$REPO/infra/test/lib.sh"

ONLY=${1:-}
ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT

# sandbox <nome>: copy of infra/local and fresh stand-ins. Sets SIM, M (marks) and LOCAL.
sandbox() {
    SIM=$ROOT/$1/sim
    LOCAL=$ROOT/$1/local
    M=$SIM/marks
    T_LOG=$SIM/output.log
    mkdir -p "$SIM/bin" "$M" "$LOCAL"
    cp "$REPO/infra/local/hostrema-local.sh" "$LOCAL/"
    mkdir -p "$LOCAL/e2e"
    : >"$SIM/calls.log"
    write_stubs
}

write_stubs() {
    printf '#!/bin/sh\nexit 0\n' >"$SIM/bin/sleep"
    cat >"$SIM/bin/docker" <<EOF
#!/usr/bin/env bash
M="$M"
echo "[docker] \$*" >> "$SIM/calls.log"
[ "\$1" = info ] && { [ -f "\$M/docker-down" ] && exit 1; exit 0; }
[ "\$1" = compose ] || exit 0
shift
case "\$*" in
version) [ -f "\$M/no-compose" ] && exit 1; echo "Docker Compose version v2.30.0" ;;
"up -d --build")
    for busy in "\$M"/busy-*; do
        [ -e "\$busy" ] || continue
        port=\${busy##*busy-}
        rm -f "\$busy"
        if [ "\$port" = 8000 ]; then
            echo "Error response from daemon: Ports are not available: exposing port TCP 0.0.0.0:8000 -> 0.0.0.0:0: listen tcp 0.0.0.0:8000: bind: address already in use"
        else
            echo "Error response from daemon: driver failed programming external connectivity on endpoint local-hostrema-node-1: Bind for 0.0.0.0:\$port failed: port is already allocated"
        fi
        exit 1
    done
    [ -f "\$M/up-fails" ] && { echo "failed to solve: write /var/lib/docker/tmp: no space left on device"; exit 1; }
    cp .env "\$M/env-at-up"
    touch "\$M/running" ;;
"ps --status running -q "*)
    service=\${!#}
    { [ -f "\$M/running" ] && [ ! -f "\$M/stopped-\$service" ]; } && echo "id-\$service" ;;
"exec -T hostrema php artisan horizon:status") [ -f "\$M/horizon-down" ] && exit 1; echo "Horizon is running." ;;
"exec -T hostrema-node docker info") [ -f "\$M/node-docker-down" ] && exit 1; exit 0 ;;
"exec -T hostrema php -r "*) exit 0 ;;
"exec -T hostrema php artisan platform:install --node-public-key") [ -f "\$M/no-key" ] || printf 'Chave do nó local:\r\nssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAlocal hostrema\r\n' ;;
"exec -T hostrema-node sh -c "*) cat >"\$M/authorized_keys" ;;
"exec -T hostrema php artisan platform:install --local"*) echo "\$*" >"\$M/install" ;;
"exec -T hostrema-node docker ps --filter name=coolify-proxy"*) [ -f "\$M/proxy-down" ] || echo "Up 5 minutes" ;;
"logs --tail=80 "*) echo "hostrema-1  | Waiting for the database..." ;;
"down -v --remove-orphans") rm -f "\$M/running"; touch "\$M/wiped" ;;
esac
exit 0
EOF
    cat >"$SIM/bin/curl" <<EOF
#!/usr/bin/env bash
M="$M"
echo "[curl] \$*" >> "$SIM/calls.log"
case "\$*" in
*"%{http_code}"*) [ -f "\$M/apps-down" ] && printf 502 || printf 404 ;;
*"/api/health"*) [ -f "\$M/panel-down" ] && exit 7; exit 0 ;;
*) [ -f "\$M/mailpit-down" ] && exit 7; exit 0 ;;
esac
EOF
    # Only the basic tools, to run the script on a machine without Docker or Node.js.
    mkdir -p "$SIM/minimal"
    local tool
    for tool in bash dirname cat sed grep tr head od base64 mktemp rm cp mv tee date basename env chmod sort; do
        ln -sf "$(command -v "$tool")" "$SIM/minimal/$tool"
    done
    for tool in npm npx; do
        # shellcheck disable=SC2016 # expanded by the stand-in, not here
        printf '#!/bin/sh\necho "[%s] $* | HOSTREMA_URL=$HOSTREMA_URL MAILPIT_URL=$MAILPIT_URL E2E_OWNER_EMAIL=$E2E_OWNER_EMAIL E2E_NODE=$E2E_NODE dir=$(basename "$PWD")" >> "%s/calls.log"\n' "$tool" "$SIM" >"$SIM/bin/$tool"
    done
    chmod +x "$SIM"/bin/*
}

# run <argumentos...>: the script with the stand-ins first in PATH and nothing on its input.
run() {
    run_input '' "$@"
}

# run_input <entrada> <argumentos...>: the same, typing <entrada>.
run_input() {
    local input=$1 status=0
    shift
    printf '%s' "$input" | env PATH="$SIM/bin:$PATH" HOSTREMA_NO_OPEN=1 timeout 120 bash "$LOCAL/hostrema-local.sh" "$@" >"$T_LOG" 2>&1 || status=$?
    return $status
}

# run_bare <argumentos...>: the script on a machine with only the basic tools and the stand-ins
# left in $SIM/bin.
run_bare() {
    local status=0
    env PATH="$SIM/bin:$SIM/minimal" HOSTREMA_NO_OPEN=1 "$SIM/minimal/bash" "$LOCAL/hostrema-local.sh" "$@" </dev/null >"$T_LOG" 2>&1 || status=$?
    return $status
}

fails() { not run "$@"; }
env_value() { sed -n "s/^$1=//p" "$LOCAL/.env"; }
mode_of() { stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"; }
wanted() { [ -z "$ONLY" ] || [[ $1 == *"$ONLY"* ]]; }
without() { PATH=$(printf '%s' "$PATH" | tr ':' '\n' | grep -v "^$1$" | paste -sd: -); }

scenario_requisitos() {
    sandbox requisitos
    section "Requisitos"
    touch "$M/docker-down"
    check "Docker parado: up para" fails up
    check "diz que o Docker não está rodando" contains "$T_LOG" 'o Docker não está rodando'
    rm -f "$M/docker-down"
    touch "$M/no-compose"
    check "sem docker compose v2: up para" fails up
    check "diz que falta o compose" contains "$T_LOG" "o plugin 'docker compose' (v2) não foi encontrado"
    rm -f "$M/no-compose"
    rm -f "$SIM/bin/docker"
    check "sem Docker: up para" not run_bare up
    check "diz para instalar o Docker" contains "$T_LOG" 'instale o Docker'
    check "nenhum .env criado sem Docker" not test -f "$LOCAL/.env"
}

scenario_up() {
    sandbox up
    section "Primeira subida"
    check "up termina sem erro" run up
    check "cria o .env" test -f "$LOCAL/.env"
    check ".env só para o dono" equals "$(mode_of "$LOCAL/.env")" 600
    check "APP_KEY nova" matches "$LOCAL/.env" '^APP_KEY=base64:[A-Za-z0-9+/]{43}=$'
    check "senhas aleatórias" matches "$LOCAL/.env" '^DB_PASSWORD=[0-9a-f]{32}$'
    check "senha do owner aleatória" matches "$LOCAL/.env" '^HOSTREMA_OWNER_PASSWORD=[0-9a-f]{20}$'
    check "sobe com build" contains "$SIM/calls.log" '[docker] compose up -d --build'
    check "espera as filas do painel" contains "$SIM/calls.log" 'compose exec -T hostrema php artisan horizon:status'
    check "espera o Docker do nó" contains "$SIM/calls.log" 'compose exec -T hostrema-node docker info'
    check "autoriza a chave do painel no nó (sem \\r)" equals "$(cat "$M/authorized_keys")" 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAlocal hostrema'
    check "prepara owner, nó e cliente demo" contains "$M/install" "--email=owner@hostrema.local --password=$(env_value HOSTREMA_OWNER_PASSWORD) --reset-password --node-host=hostrema-node --demo"
    check "mostra o painel" contains "$T_LOG" 'http://localhost:8000'
    check "mostra o owner com a senha" contains "$T_LOG" "owner@hostrema.local / $(env_value HOSTREMA_OWNER_PASSWORD)"
    check "mostra as aplicações sem porta" contains "$T_LOG" 'http://<nome>.localhost (Chrome'
    check "não avisa de aplicações fora do ar" not contains "$T_LOG" 'as aplicações ainda não respondem'
    local key
    key=$(env_value APP_KEY)
    check "up de novo termina sem erro" run up
    check "não troca as senhas do .env" equals "$(env_value APP_KEY)" "$key"

    section "Aplicações ainda não respondem"
    touch "$M/apps-down"
    check "up termina" run up
    check "avisa e sugere o doctor" contains "$T_LOG" 'as aplicações ainda não respondem em http://<nome>.localhost; rode ./hostrema-local.sh doctor'
    rm -f "$M/apps-down"
}

scenario_portas() {
    sandbox portas
    section "Porta 80 ocupada"
    touch "$M/busy-80"
    check "up termina sem erro" run up
    local apps
    apps=$(env_value HOSTREMA_APPS_HTTP_PORT)
    check "troca a porta das aplicações por 8080 ou seguinte" test "$apps" -ge 8080
    check "grava a porta nova no .env" not equals "$apps" 80
    check "sobe de novo com a porta nova" contains "$M/env-at-up" "HOSTREMA_APPS_HTTP_PORT=$apps"
    check "avisa qual porta mudou" contains "$T_LOG" "a porta 80 da sua máquina já está em uso; o Hostrema passa a usar a $apps (HOSTREMA_APPS_HTTP_PORT no .env)"
    check "endereço das aplicações com a porta" contains "$T_LOG" "http://<nome>.localhost:$apps"
    check "doctor confere a porta nova" run doctor
    check "doctor mostra a porta nova" contains "$T_LOG" "http://<nome>.localhost:$apps"

    section "Porta do painel ocupada (Docker Desktop) e a seguinte já usada pelo Hostrema"
    sandbox portas-painel
    check "primeira subida" run up
    sed -i.bak 's/^HOSTREMA_REALTIME_PORT=.*/HOSTREMA_REALTIME_PORT=8001/' "$LOCAL/.env"
    touch "$M/busy-8000"
    check "up termina sem erro" run up
    local panel
    panel=$(env_value HOSTREMA_PORT)
    check "não usa a porta de outro serviço do Hostrema" not equals "$panel" 8001
    check "usa uma porta acima da 8001" test "$panel" -gt 8001
    check "painel no endereço novo" contains "$T_LOG" "http://localhost:$panel"

    section "Várias portas ocupadas"
    sandbox portas-varias
    touch "$M/busy-80" "$M/busy-443" "$M/busy-8025"
    check "up termina sem erro" run up
    check "porta HTTPS das aplicações trocada" test "$(env_value HOSTREMA_APPS_HTTPS_PORT)" -ge 8443
    check "porta do Mailpit trocada" test "$(env_value HOSTREMA_MAIL_PORT)" -gt 8025

    section "Falha que não é de porta"
    sandbox portas-outra
    touch "$M/up-fails"
    check "up para" fails up
    check "mostra a mensagem do Docker" contains "$T_LOG" 'no space left on device'
    check "sugere o doctor" contains "$T_LOG" 'Rode ./hostrema-local.sh doctor'
    check "tenta uma vez só" equals "$(grep -c 'compose up -d --build' "$SIM/calls.log")" 1
}

scenario_espera() {
    sandbox espera
    section "O painel não fica pronto"
    touch "$M/horizon-down"
    check "up para" fails up
    check "mostra os logs do serviço" contains "$T_LOG" 'Waiting for the database'
    check "diz o que não ficou pronto" contains "$T_LOG" 'o Hostrema (migrations e filas) não ficou pronto'
    check "esperou bastante antes" test "$(grep -c 'horizon:status' "$SIM/calls.log")" -ge 200

    section "Sem a chave do nó"
    sandbox sem-chave
    touch "$M/no-key"
    check "up para" fails up
    check "explica que não leu a chave" contains "$T_LOG" 'não consegui ler a chave SSH do nó local'

    section "setup sem os containers"
    sandbox setup-parado
    printf 'HOSTREMA_PORT=8000\n' >"$LOCAL/.env"
    check "setup para" fails setup
    check "diz para subir antes" contains "$T_LOG" 'o Hostrema não está rodando; use ./hostrema-local.sh up'
}

scenario_comandos() {
    sandbox comandos
    section "Comandos do dia a dia"
    check "info sem ambiente para" fails info
    check "diz para rodar o up" contains "$T_LOG" 'rode ./hostrema-local.sh up'
    check "up" run up
    : >"$SIM/calls.log"
    check "rebuild termina sem erro" run rebuild
    check "rebuild constrói as duas imagens" contains "$SIM/calls.log" '[docker] compose build hostrema hostrema-node'
    check "rebuild sobe de novo e prepara" contains "$M/install" '--local'
    check "rebuild lembra do deploy das aplicações" contains "$T_LOG" 'faça o deploy de novo'
    check "setup termina sem erro" run setup
    check "info termina sem erro" run info
    check "info mostra o Mailpit" contains "$T_LOG" 'http://localhost:8025'
    check "info mostra o cliente demo" contains "$T_LOG" "demo@hostrema.local / $(env_value HOSTREMA_DEMO_PASSWORD)"
    check "status" run status
    check "status lista os containers" contains "$SIM/calls.log" '[docker] compose ps'
    check "logs de um serviço" run logs hostrema
    check "segue os logs" contains "$SIM/calls.log" '[docker] compose logs -f --tail=200 hostrema'
    check "artisan" run artisan migrate:status
    check "artisan no container" contains "$SIM/calls.log" '[docker] compose exec hostrema php artisan migrate:status'
    check "shell" run shell
    check "shell no container" contains "$SIM/calls.log" '[docker] compose exec hostrema sh'
    check "down" run down
    check "down mantém os dados" contains "$SIM/calls.log" '[docker] compose down'
    check "comando desconhecido falha" fails voar
    check "comando desconhecido mostra a ajuda" contains "$T_LOG" './hostrema-local.sh doctor'
}

scenario_doctor() {
    sandbox doctor
    section "doctor"
    check "sem ambiente: diz para rodar o up" run doctor
    check "explica que ainda não há ambiente" contains "$T_LOG" 'ainda não há ambiente'
    check "up" run up
    check "tudo certo" run doctor
    check "diz que está tudo certo" contains "$T_LOG" 'Tudo certo.'
    touch "$M/panel-down" "$M/proxy-down" "$M/apps-down" "$M/stopped-soketi" "$M/mailpit-down"
    check "com problemas, falha" fails doctor
    check "aponta o container parado" matches "$T_LOG" 'falha.* container soketi rodando'
    check "aponta o painel" matches "$T_LOG" 'falha.* painel em http://localhost:8000'
    check "aponta o Mailpit" matches "$T_LOG" 'falha.* Mailpit'
    check "aponta o proxy e diz o que fazer" contains "$T_LOG" 'rode ./hostrema-local.sh setup para iniciar o proxy do nó'
    check "aponta as aplicações" matches "$T_LOG" 'falha.* aplicações em http://<nome>.localhost'
    check "conta as falhas" contains "$T_LOG" '5 verificação(ões) falharam'
    touch "$M/docker-down"
    check "Docker parado também aparece" fails doctor
    check "sugere abrir o Docker" contains "$T_LOG" 'abra o Docker Desktop'
}

scenario_test() {
    sandbox testes
    check "up" run up
    section "Testes de navegador"
    check "test termina sem erro" run test --grep billing
    check "instala as dependências dos testes" contains "$SIM/calls.log" '[npm] ci --no-audit --no-fund'
    check "instala o Chromium" contains "$SIM/calls.log" '[npx] playwright install chromium'
    check "roda os testes com os argumentos dados" contains "$SIM/calls.log" '[npx] playwright test --grep billing'
    check "contra o painel e o Mailpit deste ambiente" contains "$SIM/calls.log" 'HOSTREMA_URL=http://localhost:8000 MAILPIT_URL=http://localhost:8025 E2E_OWNER_EMAIL=owner@hostrema.local E2E_NODE=1 dir=e2e'
    rm -f "$SIM/bin/npm"
    check "sem Node.js: para" not run_bare test
    check "diz que precisa do Node.js" contains "$T_LOG" 'precisam do Node.js'
}

scenario_reset() {
    sandbox reset
    check "up" run up
    section "reset"
    check "sem resposta não apaga" run_input '' reset
    check "diz que nada foi apagado" contains "$T_LOG" 'Nada foi apagado.'
    check "respondendo não, não apaga" run_input 'n' reset
    check ".env continua" test -f "$LOCAL/.env"
    check "nada apagado no Docker" not test -f "$M/wiped"
    check "respondendo sim, apaga" run_input 's' reset
    check "apaga volumes e containers" test -f "$M/wiped"
    check "apaga o .env" not test -f "$LOCAL/.env"
    check "up de novo" run up
    rm -f "$M/wiped"
    check "reset -y não pergunta" run reset -y
    check "apaga com -y" test -f "$M/wiped"
}

for scenario in requisitos up portas espera comandos doctor test reset; do
    if wanted "$scenario"; then
        "scenario_$scenario"
    fi
done
finish
