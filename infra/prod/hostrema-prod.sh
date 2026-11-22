#!/usr/bin/env bash
# Hostrema em produção na AWS com um comando. Veja docs/platform.md, "Produção na AWS com um comando".
#
#   ./hostrema-prod.sh              cria ou atualiza tudo (painel, região, nós, e-mail, cobrança)
#   ./hostrema-prod.sh plan         mostra o que seria criado ou mudado, sem mudar nada
#   ./hostrema-prod.sh info         endereços, senhas e situação
#   ./hostrema-prod.sh upgrade      publica no painel a versão atual deste código
#   ./hostrema-prod.sh reconfigure  pergunta de novo domínio, tamanho, e-mail e Efí, e aplica
#   ./hostrema-prod.sh ssh [host]   shell no painel (ou num host do inventário: control, node-01...)
#   ./hostrema-prod.sh artisan <comando...>
#   ./hostrema-prod.sh logs         logs do painel
#   ./hostrema-prod.sh destroy      apaga a instalação na AWS (pede confirmação)
#
# Opções: -y/--yes não pergunta antes de criar ou mudar recursos pagos.
#
# Só pergunta o que precisa vir de você (credenciais da AWS, domínio, seu e-mail e, se quiser, a Efí);
# o resto é gerado, guardado em ~/.hostrema/prod (com cópia no bucket de estado da AWS) e reaplicado
# a cada execução. Pode rodar de novo quando quiser: só cria o que falta e só muda o que mudou.
# Respostas também podem vir do ambiente (HOSTREMA_DOMAIN, HOSTREMA_OWNER_EMAIL, HOSTREMA_SIZE...).
set -euo pipefail
# Tudo o que este script grava (estado, chaves, variáveis) é só do seu usuário.
umask 077

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO=$(cd "$SCRIPT_DIR/../.." && pwd)
STATE_DIR=${HOSTREMA_STATE_DIR:-$HOME/.hostrema/prod}
TOOLS_DIR=${HOSTREMA_TOOLS_DIR:-$HOME/.hostrema/tools}
CONFIG_FILE=$STATE_DIR/config.env
SECRETS_FILE=$STATE_DIR/secrets.env
SSH_CONFIG=$STATE_DIR/ssh_config
SSH_KEY=$STATE_DIR/id_ed25519
CP_DIR=$REPO/infra/terraform/control-plane
TERRAFORM_MIN=1.10.0
export PATH="$TOOLS_DIR/bin:$TOOLS_DIR/venv/bin:$PATH"
# Git Bash (Windows) would rewrite the paths given to the remote commands.
export MSYS_NO_PATHCONV=1

# Answers and generated values kept between runs (secrets in a separate 0600 file).
CONFIG_KEYS=(AWS_PROFILE_NAME AWS_REGION ACCOUNT_ID DOMAIN PANEL_HOST APPS_DOMAIN OWNER_EMAIL SIZE
    EMAIL_MODE SMTP_HOST SMTP_PORT SMTP_USER SMTP_FROM ZONE_ID STATE_BUCKET IMAGE DEPLOYED_IMAGE
    EFI_ENVIRONMENT EFI_PIX_KEY EFI_CERTIFICATE_FILE EFI_STATUS BILLING_PROVIDER INSTALLED
    SES_PRODUCTION_REQUESTED EDGE_PROXY_PROTOCOL CP_HOSTS SETTINGS_HASH SMTP_HASH RECORDS_COPIED BUILD_ID
    BUILD_IMAGE)
SECRET_KEYS=(OWNER_PASSWORD GRAFANA_PASSWORD EDGE_TOKEN METRICS_TOKEN API_TOKEN NODE_KEY_UUID
    NODE_KEY_PUBLIC SES_ACCESS_KEY_ID SES_SMTP_PASSWORD SMTP_PASSWORD EFI_CLIENT_ID EFI_CLIENT_SECRET
    EFI_CERTIFICATE_PASSWORD)

ASSUME_YES=0
PLAN_ONLY=0
OS=
ARCH=

say() { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
note() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33mAtenção:\033[0m %s\n' "$*" >&2; }
fail() {
    printf '\033[1;31mErro:\033[0m %s\n' "$*" >&2
    exit 1
}

random_token() { openssl rand -hex "${1:-32}"; }
sha256() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -c1-64; else shasum -a 256 | cut -c1-64; fi
}
random_password() { openssl rand -base64 24 | tr -d '/+=\n' | cut -c1-"${1:-20}"; }

# ---------------------------------------------------------------------------------------------
# Perguntas (sempre pelo terminal, mesmo com a entrada redirecionada)
# ---------------------------------------------------------------------------------------------

has_tty() { [ -t 0 ] || { [ -r /dev/tty ] && (: </dev/tty) 2>/dev/null; }; }

# ask VAR "pergunta" [padrão] [secret|optional]: só pergunta se VAR ainda não tem valor (estado ou
# HOSTREMA_VAR no ambiente).
ask() {
    local var=$1 question=$2 default=${3:-} mode=${4:-} answer prompt env_name
    if [ -n "${!var:-}" ]; then
        return 0
    fi
    env_name="HOSTREMA_${var}"
    if [ -n "${!env_name:-}" ]; then
        printf -v "$var" '%s' "${!env_name}"
        return 0
    fi
    if ! has_tty; then
        [ "$mode" = optional ] && return 0
        fail "falta \"$question\": defina $env_name no ambiente e rode de novo."
    fi
    prompt="$question"
    [ -n "$default" ] && prompt="$prompt [$default]"
    while :; do
        printf '  %s: ' "$prompt" >/dev/tty
        if [ "$mode" = secret ]; then
            IFS= read -rs answer </dev/tty || answer=
            printf '\n' >/dev/tty
        else
            IFS= read -r answer </dev/tty || answer=
        fi
        answer=${answer:-$default}
        if [ -n "$answer" ] || [ "$mode" = optional ]; then
            break
        fi
        printf '    (obrigatório)\n' >/dev/tty
    done
    printf -v "$var" '%s' "$answer"
}

# confirm "pergunta": sim com -y; sem terminal, não.
confirm() {
    [ "$ASSUME_YES" = 1 ] && return 0
    has_tty || return 1
    local answer
    printf '  %s [s/N] ' "$1" >/dev/tty
    IFS= read -r answer </dev/tty || answer=
    case "$answer" in
    s | S | sim | SIM | y | Y | yes) return 0 ;;
    *) return 1 ;;
    esac
}

# ---------------------------------------------------------------------------------------------
# Estado
# ---------------------------------------------------------------------------------------------

load_state() {
    if [ -f "$CONFIG_FILE" ]; then
        # shellcheck disable=SC1090
        . "$CONFIG_FILE"
    fi
    if [ -f "$SECRETS_FILE" ]; then
        # shellcheck disable=SC1090
        . "$SECRETS_FILE"
    fi
}

write_vars() {
    local file=$1 key
    shift
    (
        umask 077
        {
            printf '# Gerado por hostrema-prod.sh. Não edite à mão: rode ./hostrema-prod.sh reconfigure.\n'
            for key in "$@"; do
                printf '%s=%q\n' "$key" "${!key:-}"
            done
        } >"$file.tmp"
        mv "$file.tmp" "$file"
    )
}

save_state() {
    mkdir -p "$STATE_DIR"
    chmod 700 "$STATE_DIR"
    write_vars "$CONFIG_FILE" "${CONFIG_KEYS[@]}"
    write_vars "$SECRETS_FILE" "${SECRET_KEYS[@]}"
    backup_state
}

# Cópia do estado no bucket de estado (criptografado, privado), para rodar de outra máquina.
backup_state() {
    [ -n "${STATE_BUCKET:-}" ] || return 0
    [ "$PLAN_ONLY" = 1 ] && return 0
    aws s3api head-bucket --bucket "$STATE_BUCKET" >/dev/null 2>&1 || return 0
    local files=(config.env secrets.env)
    [ -f "$SSH_KEY" ] && files+=(id_ed25519 id_ed25519.pub)
    tar -C "$STATE_DIR" -czf - "${files[@]}" 2>/dev/null |
        aws s3 cp - "s3://$STATE_BUCKET/installer/state.tgz" --sse AES256 --only-show-errors ||
        warn "não consegui copiar o estado para s3://$STATE_BUCKET (continua só em $STATE_DIR)."
}

restore_state() {
    [ -f "$CONFIG_FILE" ] && return 0
    local bucket="hostrema-tfstate-${ACCOUNT_ID}-${AWS_REGION}"
    if aws s3api head-object --bucket "$bucket" --key installer/state.tgz >/dev/null 2>&1; then
        say "Recuperando o estado desta instalação de s3://$bucket"
        mkdir -p "$STATE_DIR"
        chmod 700 "$STATE_DIR"
        aws s3 cp "s3://$bucket/installer/state.tgz" - --only-show-errors | tar -C "$STATE_DIR" -xzf -
        chmod 600 "$STATE_DIR"/*
        load_state
    fi
}

# ---------------------------------------------------------------------------------------------
# Ferramentas (instaladas em ~/.hostrema/tools quando faltam, sem mexer no sistema se der)
# ---------------------------------------------------------------------------------------------

detect_platform() {
    OS=$(uname -s | tr '[:upper:]' '[:lower:]')
    case "$(uname -m)" in
    x86_64 | amd64) ARCH=amd64 ;;
    aarch64 | arm64) ARCH=arm64 ;;
    *) fail "processador $(uname -m) não suportado." ;;
    esac
    case "$OS" in
    linux | darwin) ;;
    mingw* | msys* | cygwin*) fail "no Windows, rode pelo WSL (Ubuntu): wsl.exe -d Ubuntu, depois ./infra/prod/hostrema-prod.sh" ;;
    *) fail "sistema $OS não suportado." ;;
    esac
}

pkg_install() {
    say "Instalando $* (pode pedir a sua senha do sistema)"
    if command -v apt-get >/dev/null 2>&1; then
        sudo apt-get update -qq && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@"
    elif command -v dnf >/dev/null 2>&1; then
        sudo dnf install -y -q "$@"
    elif command -v yum >/dev/null 2>&1; then
        sudo yum install -y -q "$@"
    elif command -v brew >/dev/null 2>&1; then
        brew install "$@"
    else
        return 1
    fi
}

need() {
    local command=$1 package=${2:-$1}
    command -v "$command" >/dev/null 2>&1 && return 0
    pkg_install "$package" || fail "instale \"$package\" e rode de novo."
    command -v "$command" >/dev/null 2>&1 || fail "\"$command\" não foi encontrado depois da instalação."
}

# unzip_to <arquivo.zip> <pasta>: com unzip ou, sem ele, com o Python (mantendo as permissões).
unzip_to() {
    if command -v unzip >/dev/null 2>&1; then
        unzip -q -o "$1" -d "$2"
        return
    fi
    python3 - "$1" "$2" <<'PY'
import os, sys, zipfile
archive = zipfile.ZipFile(sys.argv[1])
for info in archive.infolist():
    path = archive.extract(info, sys.argv[2])
    mode = (info.external_attr >> 16) & 0o777
    if mode:
        os.chmod(path, mode)
PY
}

version_at_least() {
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n 1)" = "$2" ]
}

install_jq() {
    command -v jq >/dev/null 2>&1 && return 0
    local name
    case "$OS-$ARCH" in
    linux-amd64) name=jq-linux-amd64 ;;
    linux-arm64) name=jq-linux-arm64 ;;
    darwin-amd64) name=jq-macos-amd64 ;;
    darwin-arm64) name=jq-macos-arm64 ;;
    esac
    say "Baixando o jq"
    mkdir -p "$TOOLS_DIR/bin"
    curl -fsSL "https://github.com/jqlang/jq/releases/download/jq-1.7.1/$name" -o "$TOOLS_DIR/bin/jq"
    chmod +x "$TOOLS_DIR/bin/jq"
}

install_terraform() {
    local current=""
    if command -v terraform >/dev/null 2>&1; then
        current=$(terraform version -json 2>/dev/null | jq -r '.terraform_version // empty' || true)
    fi
    if [ -n "$current" ] && version_at_least "$current" "$TERRAFORM_MIN"; then
        return 0
    fi
    local version tmp
    version=$(curl -fsSL https://checkpoint-api.hashicorp.com/v1/check/terraform | jq -r .current_version)
    [ -n "$version" ] && [ "$version" != null ] || fail "não consegui descobrir a versão atual do Terraform."
    say "Baixando o Terraform $version"
    tmp=$(mktemp -d)
    curl -fsSL "https://releases.hashicorp.com/terraform/${version}/terraform_${version}_${OS}_${ARCH}.zip" -o "$tmp/terraform.zip"
    unzip_to "$tmp/terraform.zip" "$tmp"
    mkdir -p "$TOOLS_DIR/bin"
    install -m 755 "$tmp/terraform" "$TOOLS_DIR/bin/terraform"
    rm -rf "$tmp"
}

install_aws_cli() {
    command -v aws >/dev/null 2>&1 && return 0
    say "Instalando a AWS CLI"
    local tmp
    tmp=$(mktemp -d)
    if [ "$OS" = linux ]; then
        curl -fsSL "https://awscli.amazonaws.com/awscli-exe-linux-$(uname -m).zip" -o "$tmp/awscli.zip"
        unzip_to "$tmp/awscli.zip" "$tmp"
        "$tmp/aws/install" -i "$TOOLS_DIR/aws-cli" -b "$TOOLS_DIR/bin" --update >/dev/null
    elif command -v brew >/dev/null 2>&1; then
        brew install awscli
    else
        "$TOOLS_DIR/venv/bin/pip" install -q awscli
    fi
    rm -rf "$tmp"
    command -v aws >/dev/null 2>&1 || fail "a AWS CLI não foi instalada."
}

install_python_tools() {
    if [ ! -x "$TOOLS_DIR/venv/bin/python" ]; then
        say "Preparando o Python das ferramentas (Ansible)"
        mkdir -p "$TOOLS_DIR"
        if ! python3 -m venv "$TOOLS_DIR/venv" >/dev/null 2>&1; then
            rm -rf "$TOOLS_DIR/venv"
            pkg_install python3-venv || true
            python3 -m venv "$TOOLS_DIR/venv" || fail "não consegui criar o ambiente Python (python3 -m venv)."
        fi
        "$TOOLS_DIR/venv/bin/pip" install -q --upgrade pip
    fi
    if [ ! -x "$TOOLS_DIR/venv/bin/ansible-playbook" ]; then
        say "Instalando o Ansible"
        "$TOOLS_DIR/venv/bin/pip" install -q 'ansible-core>=2.15'
    fi
    if [ ! -d "$TOOLS_DIR/collections/ansible_collections/community/docker" ]; then
        "$TOOLS_DIR/venv/bin/ansible-galaxy" collection install -r "$REPO/infra/ansible/requirements.yml" -p "$TOOLS_DIR/collections" >/dev/null
    fi
}

ensure_tools() {
    detect_platform
    need curl
    need git
    need tar
    need ssh openssh-client
    need ssh-keygen openssh-client
    need openssl
    need python3
    install_jq
    install_python_tools
    install_terraform
    install_aws_cli
}

ensure_docker() {
    command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1 && return 0
    if ! command -v docker >/dev/null 2>&1; then
        if [ "$OS" = linux ] && ! grep -qi microsoft /proc/version 2>/dev/null; then
            say "Instalando o Docker (para construir a imagem do painel)"
            curl -fsSL https://get.docker.com | sudo sh
            sudo usermod -aG docker "$USER" || true
        else
            warn "o Docker é usado para construir a imagem do painel."
            note "Instale o Docker Desktop (no Windows, com a integração do WSL ligada) e abra-o."
        fi
    fi
    say "Aguardando o Docker responder (abra o Docker Desktop, se ele estiver fechado)"
    local tries=120
    until docker info >/dev/null 2>&1 || sudo -n docker info >/dev/null 2>&1; do
        tries=$((tries - 1))
        [ "$tries" -gt 0 ] || fail "o Docker não respondeu. Abra o Docker e rode de novo."
        sleep 5
    done
}

docker_() {
    if docker info >/dev/null 2>&1; then docker "$@"; else sudo docker "$@"; fi
}

# ---------------------------------------------------------------------------------------------
# AWS
# ---------------------------------------------------------------------------------------------

ensure_aws_auth() {
    if [ -n "${AWS_PROFILE_NAME:-}" ]; then
        export AWS_PROFILE=$AWS_PROFILE_NAME
    fi
    ask AWS_REGION "Região da AWS" "sa-east-1"
    export AWS_REGION AWS_DEFAULT_REGION=$AWS_REGION
    if ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text 2>/dev/null); then
        return 0
    fi
    say "Credenciais da AWS"
    note "Crie uma chave de acesso de um usuário administrador: console da AWS → IAM → Usuários →"
    note "(seu usuário) → Credenciais de segurança → Criar chave de acesso → Command Line Interface."
    local key_id="" secret=""
    ask key_id "AWS Access Key ID"
    ask secret "AWS Secret Access Key" "" secret
    aws configure set aws_access_key_id "$key_id" --profile hostrema
    aws configure set aws_secret_access_key "$secret" --profile hostrema
    aws configure set region "$AWS_REGION" --profile hostrema
    AWS_PROFILE_NAME=hostrema
    export AWS_PROFILE=hostrema
    ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text 2>/dev/null) ||
        fail "a AWS recusou essas credenciais. Confira a chave e rode de novo."
}

region_name() {
    case "$1" in
    sa-east-1) echo "São Paulo" ;;
    us-east-1) echo "Virgínia do Norte" ;;
    us-east-2) echo "Ohio" ;;
    us-west-2) echo "Oregon" ;;
    eu-west-1) echo "Irlanda" ;;
    eu-central-1) echo "Frankfurt" ;;
    *) echo "$1" ;;
    esac
}

operator_cidr() {
    local ip
    ip=$(curl -fsS --max-time 10 https://checkip.amazonaws.com | tr -d '[:space:]') || true
    [[ $ip =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "não consegui descobrir o seu IP público (https://checkip.amazonaws.com)."
    printf '%s/32' "$ip"
}

# ---------------------------------------------------------------------------------------------
# Configuração (só o que precisa vir de você)
# ---------------------------------------------------------------------------------------------

ask_configuration() {
    say "Configuração"
    while :; do
        ask DOMAIN "Domínio principal da plataforma (ex.: hostrema.com.br)" "${PREV_DOMAIN:-}"
        DOMAIN=$(printf '%s' "$DOMAIN" | tr '[:upper:]' '[:lower:]' | sed -E 's#^https?://##; s#/.*$##')
        [[ $DOMAIN =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] && break
        warn "\"$DOMAIN\" não é um domínio válido."
        DOMAIN=
    done
    PANEL_HOST=${PANEL_HOST:-painel.$DOMAIN}
    APPS_DOMAIN=${APPS_DOMAIN:-apps.$DOMAIN}
    while :; do
        ask OWNER_EMAIL "Seu e-mail (login do owner, alertas e certificados)" "${PREV_OWNER_EMAIL:-}"
        [[ $OWNER_EMAIL =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] && break
        warn "\"$OWNER_EMAIL\" não é um e-mail válido."
        OWNER_EMAIL=
    done
    if [ -z "${SIZE:-}" ]; then
        note "Tamanho inicial (dá para mudar depois com ./hostrema-prod.sh reconfigure):"
        note "  inicial: painel 1x m6i.large, banco db.t4g.medium, 1 nó m6i.2xlarge (8 vCPU, 32 GB)"
        note "  padrao:  painel 1x m6i.xlarge, banco db.m6g.large, 2 nós m6i.4xlarge (16 vCPU, 64 GB)"
        note "  grande:  painel 2x m6i.xlarge + 1 worker, 2 nós m6i.8xlarge (32 vCPU, 128 GB)"
    fi
    while :; do
        ask SIZE "Tamanho (inicial, padrao ou grande)" "${PREV_SIZE:-inicial}"
        case "$SIZE" in inicial | padrao | grande) break ;; esac
        warn "escolha inicial, padrao ou grande."
        SIZE=
    done
    if [ -z "${EMAIL_MODE:-}" ]; then
        note "E-mail transacional (confirmação de cadastro, faturas): o Amazon SES é configurado sozinho."
    fi
    while :; do
        ask EMAIL_MODE "E-mail: ses (automático) ou smtp (seu servidor)" "${PREV_EMAIL_MODE:-ses}"
        case "$EMAIL_MODE" in ses | smtp) break ;; esac
        EMAIL_MODE=
    done
    if [ "$EMAIL_MODE" = smtp ]; then
        ask SMTP_HOST "Servidor SMTP"
        ask SMTP_PORT "Porta SMTP" "587"
        ask SMTP_USER "Usuário SMTP" "" optional
        ask SMTP_PASSWORD "Senha SMTP" "" secret
        ask SMTP_FROM "Remetente" "nao-responda@$DOMAIN"
    fi
    ask_efi
}

ask_efi() {
    if [ -n "${EFI_STATUS:-}" ] && [ -z "${HOSTREMA_EFI_CLIENT_ID:-}" ]; then
        return 0
    fi
    local wants=""
    if [ -z "${HOSTREMA_EFI_CLIENT_ID:-}" ]; then
        note "Cobrança: com as credenciais da Efí Bank os clientes pagam por Pix ou boleto sozinhos."
        note "Sem elas, as faturas ficam no modo manual (você confirma cada pagamento) até configurar."
        ask wants "Configurar a Efí Bank agora? (s/n)" "n" optional
        case "$wants" in s | S | sim | y | Y) ;; *)
            EFI_STATUS=${EFI_STATUS:-skipped}
            return 0
            ;;
        esac
    fi
    note "Na conta Efí: API → Aplicações → (sua aplicação) com API Cobranças e API Pix; o certificado"
    note "(.p12) fica em API → Meus certificados. Use as credenciais e o certificado do mesmo ambiente."
    ask EFI_ENVIRONMENT "Ambiente da Efí (production ou sandbox)" "production"
    ask EFI_CLIENT_ID "Client ID"
    ask EFI_CLIENT_SECRET "Client Secret" "" secret
    while :; do
        ask EFI_CERTIFICATE_FILE "Caminho do certificado .p12 (ou .pem) nesta máquina" "" optional
        if [ -z "$EFI_CERTIFICATE_FILE" ] || [ -f "$EFI_CERTIFICATE_FILE" ]; then
            break
        fi
        warn "arquivo não encontrado: $EFI_CERTIFICATE_FILE"
        EFI_CERTIFICATE_FILE=
    done
    ask EFI_PIX_KEY "Chave Pix da conta (vazio = só boleto)" "" optional
    EFI_STATUS=pending
}

apply_size() {
    case "$SIZE" in
    inicial)
        CP_COUNT=1 CP_WORKERS=0 CP_TYPE=m6i.large CP_DB=db.t4g.medium CP_REDIS=cache.t4g.small
        RG_CONTROL_TYPE=m6i.large RG_CONTROL_DISK=100 RG_NODES=1 RG_NODE_TYPE=m6i.2xlarge
        RG_NODE_DISK=200 RG_NODE_IOPS=3000 RG_NODE_THROUGHPUT=125
        ;;
    padrao)
        CP_COUNT=1 CP_WORKERS=0 CP_TYPE=m6i.xlarge CP_DB=db.m6g.large CP_REDIS=cache.m6g.large
        RG_CONTROL_TYPE=m6i.xlarge RG_CONTROL_DISK=500 RG_NODES=2 RG_NODE_TYPE=m6i.4xlarge
        RG_NODE_DISK=500 RG_NODE_IOPS=3000 RG_NODE_THROUGHPUT=250
        ;;
    grande)
        CP_COUNT=2 CP_WORKERS=1 CP_TYPE=m6i.xlarge CP_DB=db.m6g.large CP_REDIS=cache.m6g.large
        RG_CONTROL_TYPE=m6i.2xlarge RG_CONTROL_DISK=1000 RG_NODES=2 RG_NODE_TYPE=m6i.8xlarge
        RG_NODE_DISK=1000 RG_NODE_IOPS=6000 RG_NODE_THROUGHPUT=500
        ;;
    *) fail "tamanho desconhecido: $SIZE" ;;
    esac
}

ensure_generated() {
    OWNER_PASSWORD=${OWNER_PASSWORD:-$(random_password 20)}
    GRAFANA_PASSWORD=${GRAFANA_PASSWORD:-$(random_password 20)}
    EDGE_TOKEN=${EDGE_TOKEN:-$(random_token 32)}
    METRICS_TOKEN=${METRICS_TOKEN:-$(random_token 32)}
    STATE_BUCKET=${STATE_BUCKET:-hostrema-tfstate-${ACCOUNT_ID}-${AWS_REGION}}
    if [ ! -f "$SSH_KEY" ]; then
        mkdir -p "$STATE_DIR"
        ssh-keygen -q -t ed25519 -N '' -C "hostrema-prod" -f "$SSH_KEY"
    fi
    chmod 600 "$SSH_KEY"
    cat >"$SSH_CONFIG" <<EOF
Host *
    User ubuntu
    IdentityFile $SSH_KEY
    IdentitiesOnly yes
    UserKnownHostsFile $STATE_DIR/known_hosts
    StrictHostKeyChecking accept-new
    ServerAliveInterval 30
    ConnectTimeout 15
EOF
}

show_summary() {
    apply_size
    cat <<EOF

  Conta AWS ......... $ACCOUNT_ID ($AWS_REGION, $(region_name "$AWS_REGION"))
  Painel ............ https://$PANEL_HOST
  Aplicações ........ https://<nome>.$APPS_DOMAIN
  Owner ............. $OWNER_EMAIL
  Tamanho ........... $SIZE: painel ${CP_COUNT}x $CP_TYPE$([ "$CP_WORKERS" -gt 0 ] && printf ' + %sx worker' "$CP_WORKERS"), PostgreSQL $CP_DB (Multi-AZ), Redis $CP_REDIS
                      região: nó de controle $RG_CONTROL_TYPE, ${RG_NODES}x nó $RG_NODE_TYPE (${RG_NODE_DISK} GB)
  E-mail ............ $([ "$EMAIL_MODE" = ses ] && echo "Amazon SES (nao-responda@$DOMAIN)" || echo "SMTP $SMTP_HOST")
  Cobrança .......... $([ "${EFI_STATUS:-skipped}" = skipped ] && echo "manual (configure a Efí depois com: ./hostrema-prod.sh reconfigure)" || echo "Efí Bank (${EFI_ENVIRONMENT:-production})")
EOF
}

# ---------------------------------------------------------------------------------------------
# DNS, estado do Terraform, SES e imagem
# ---------------------------------------------------------------------------------------------

ensure_state_bucket() {
    if aws s3api head-bucket --bucket "$STATE_BUCKET" >/dev/null 2>&1; then
        return 0
    fi
    [ "$PLAN_ONLY" = 1 ] && return 0
    say "Criando o bucket do estado do Terraform (s3://$STATE_BUCKET)"
    if [ "$AWS_REGION" = us-east-1 ]; then
        aws s3api create-bucket --bucket "$STATE_BUCKET" >/dev/null
    else
        aws s3api create-bucket --bucket "$STATE_BUCKET" --create-bucket-configuration "LocationConstraint=$AWS_REGION" >/dev/null
    fi
    aws s3api put-public-access-block --bucket "$STATE_BUCKET" \
        --public-access-block-configuration BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true
    aws s3api put-bucket-versioning --bucket "$STATE_BUCKET" --versioning-configuration Status=Enabled
    aws s3api put-bucket-encryption --bucket "$STATE_BUCKET" \
        --server-side-encryption-configuration '{"Rules":[{"ApplyServerSideEncryptionByDefault":{"SSEAlgorithm":"AES256"}}]}'
}

zone_name_servers() {
    aws route53 get-hosted-zone --id "$ZONE_ID" --query 'DelegationSet.NameServers' --output text | tr '\t' '\n' | sed 's/\.$//' | sort
}

public_name_servers() {
    curl -fsS --max-time 10 "https://dns.google/resolve?name=$DOMAIN&type=NS" |
        jq -r '.Answer[]? | select(.type == 2) | .data' | sed 's/\.$//' | tr '[:upper:]' '[:lower:]' | sort
}

ensure_zone() {
    if [ -z "${ZONE_ID:-}" ]; then
        ZONE_ID=$(aws route53 list-hosted-zones-by-name --dns-name "$DOMAIN." --max-items 10 --output json |
            jq -r --arg name "$DOMAIN." '[.HostedZones[] | select(.Name == $name and (.Config.PrivateZone | not))][0].Id // empty' |
            sed 's#^/hostedzone/##')
    fi
    if [ -z "$ZONE_ID" ]; then
        [ "$PLAN_ONLY" = 1 ] && {
            note "a zona DNS de $DOMAIN será criada no Route53."
            return 0
        }
        say "Criando a zona DNS de $DOMAIN no Route53"
        ZONE_ID=$(aws route53 create-hosted-zone --name "$DOMAIN" --caller-reference "hostrema-$(date +%s)" \
            --hosted-zone-config Comment="Hostrema" --query HostedZone.Id --output text | sed 's#^/hostedzone/##')
    fi
    save_state
}

zone_delegated() {
    local expected actual
    expected=$(zone_name_servers | tr '[:upper:]' '[:lower:]')
    actual=$(public_name_servers || true)
    [ -n "$actual" ] && [ "$expected" = "$actual" ]
}

# O registro do domínio (registro.br etc.) precisa apontar para os servidores da zona: é a única
# coisa fora da AWS. Se o domínio foi registrado na própria AWS, isso também é feito aqui.
ensure_delegation() {
    [ -n "${ZONE_ID:-}" ] || return 0
    zone_delegated && return 0
    local servers server nameservers=()
    servers=$(zone_name_servers)
    if aws route53domains get-domain-detail --region us-east-1 --domain-name "$DOMAIN" >/dev/null 2>&1; then
        say "Apontando o domínio (registrado na AWS) para a zona do Route53"
        while read -r server; do
            [ -n "$server" ] && nameservers+=("Name=$server")
        done <<<"$servers"
        aws route53domains update-domain-nameservers --region us-east-1 --domain-name "$DOMAIN" \
            --nameservers "${nameservers[@]}" >/dev/null
    else
        say "Falta só um passo fora da AWS: apontar o domínio para o Route53"
        note "No site onde $DOMAIN foi registrado (registro.br, por exemplo: Domínios → $DOMAIN →"
        note "Alterar servidores DNS), troque os servidores DNS por estes quatro:"
        printf '%s\n' "$servers" | sed 's/^/        /'
        note "Este script espera a mudança aparecer (normalmente minutos; às vezes algumas horas)."
        note "Pode deixar a janela aberta; se fechar, rode de novo depois e ele continua daqui."
    fi
    local waited=0
    until zone_delegated; do
        sleep 30
        waited=$((waited + 30))
        if [ $((waited % 300)) -eq 0 ]; then
            note "ainda aguardando a delegação de $DOMAIN ($((waited / 60)) min)..."
        fi
    done
    note "delegação de $DOMAIN confirmada."
}

# Registros que o domínio já tem hoje (site, e-mail) vão para a zona nova antes da troca dos
# servidores DNS: com a troca, o que já funciona continua funcionando.
copy_public_records() {
    [ -n "${ZONE_ID:-}" ] && [ "$PLAN_ONLY" != 1 ] && [ -z "${RECORDS_COPIED:-}" ] || return 0
    if zone_delegated; then
        RECORDS_COPIED=1
        save_state
        return 0
    fi
    local name type code answer values batch copied=0 prefix
    for prefix in "" www. mail. webmail. autodiscover. _dmarc. google._domainkey. selector1._domainkey. \
        selector2._domainkey. default._domainkey. k1._domainkey.; do
        name="$prefix$DOMAIN"
        for type in CNAME A AAAA MX TXT; do
            [ -z "$prefix" ] && [ "$type" = CNAME ] && continue
            case "$type" in A) code=1 ;; AAAA) code=28 ;; CNAME) code=5 ;; MX) code=15 ;; TXT) code=16 ;; esac
            answer=$(curl -fsS --max-time 10 "https://dns.google/resolve?name=$name&type=$type" 2>/dev/null) || continue
            # TXT na forma do Route53: entre aspas, em pedaços de até 255 caracteres.
            values=$(jq -c --arg name "$name." --argjson code "$code" '
                [.Answer[]? | select(.type == $code and ((.name | ascii_downcase) == $name)) | .data
                 | if $code == 16 and (startswith("\"") | not)
                   then [range(0; length; 255) as $i | .[$i:$i + 255] | "\"" + gsub("\""; "\\\"") + "\""] | join(" ")
                   else . end] | unique' <<<"$answer" 2>/dev/null) || continue
            [ "$values" != "[]" ] || continue
            record_exists "$name" "$type" && continue
            batch=$(jq -nc --arg name "$name" --arg type "$type" --argjson values "$values" \
                '{Changes: [{Action: "CREATE", ResourceRecordSet: {Name: $name, Type: $type, TTL: 300, ResourceRecords: ($values | map({Value: .}))}}]}')
            if aws route53 change-resource-record-sets --hosted-zone-id "$ZONE_ID" --change-batch "$batch" >/dev/null 2>&1; then
                copied=$((copied + 1))
            fi
            # Um CNAME é o único registro do nome.
            [ "$type" = CNAME ] && break
        done
    done
    if [ "$copied" -gt 0 ]; then
        say "Copiei para a zona do Route53 os $copied registros que $DOMAIN já tinha (site e e-mail)"
        note "Se o domínio usa outros nomes, crie-os também na zona (Route53 → $DOMAIN) antes da troca."
    fi
    RECORDS_COPIED=1
    save_state
}

route53_upsert() {
    local name=$1 type=$2 value=$3
    local batch
    batch=$(jq -n --arg name "$name" --arg type "$type" --arg value "$value" \
        '{Changes: [{Action: "UPSERT", ResourceRecordSet: {Name: $name, Type: $type, TTL: 300, ResourceRecords: [{Value: $value}]}}]}')
    aws route53 change-resource-record-sets --hosted-zone-id "$ZONE_ID" --change-batch "$batch" >/dev/null
}

record_exists() {
    aws route53 list-resource-record-sets --hosted-zone-id "$ZONE_ID" --start-record-name "$1" --start-record-type "$2" \
        --max-items 1 --output json | jq -e --arg name "$1." --arg type "$2" \
        '.ResourceRecordSets[0] | select(.Name == $name and .Type == $type)' >/dev/null
}

# SMTP do SES: usuário IAM só com ses:SendRawEmail e a senha SMTP derivada da chave (algoritmo da AWS).
ses_smtp_password() {
    python3 - "$1" "$AWS_REGION" <<'PY'
import base64, hashlib, hmac, sys
def sign(key, message):
    return hmac.new(key, message.encode("utf-8"), hashlib.sha256).digest()
signature = sign(("AWS4" + sys.argv[1]).encode("utf-8"), "11111111")
for part in (sys.argv[2], "ses", "aws4_request", "SendRawEmail"):
    signature = sign(signature, part)
print(base64.b64encode(bytes([0x04]) + signature).decode("utf-8"))
PY
}

ensure_ses() {
    [ "$EMAIL_MODE" = ses ] || return 0
    [ "$PLAN_ONLY" = 1 ] && return 0
    say "Configurando o Amazon SES para $DOMAIN"
    local identity tokens token user secret
    if ! identity=$(aws sesv2 get-email-identity --email-identity "$DOMAIN" --output json 2>/dev/null); then
        identity=$(aws sesv2 create-email-identity --email-identity "$DOMAIN" --output json)
    fi
    tokens=$(printf '%s' "$identity" | jq -r '.DkimAttributes.Tokens[]? // empty')
    for token in $tokens; do
        route53_upsert "${token}._domainkey.$DOMAIN" CNAME "${token}.dkim.amazonses.com"
    done
    if ! record_exists "_dmarc.$DOMAIN" TXT; then
        route53_upsert "_dmarc.$DOMAIN" TXT "\"v=DMARC1; p=none; rua=mailto:$OWNER_EMAIL\""
    fi
    if [ -z "${SES_ACCESS_KEY_ID:-}" ] || [ -z "${SES_SMTP_PASSWORD:-}" ]; then
        user="hostrema-ses-smtp-$AWS_REGION"
        aws iam get-user --user-name "$user" >/dev/null 2>&1 || aws iam create-user --user-name "$user" >/dev/null
        aws iam put-user-policy --user-name "$user" --policy-name ses-send --policy-document \
            '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Action":"ses:SendRawEmail","Resource":"*"}]}'
        # Uma chave nova por vez: as antigas desta instalação saem.
        for old in $(aws iam list-access-keys --user-name "$user" --query 'AccessKeyMetadata[].AccessKeyId' --output text); do
            aws iam delete-access-key --user-name "$user" --access-key-id "$old"
        done
        read -r SES_ACCESS_KEY_ID secret < <(aws iam create-access-key --user-name "$user" \
            --query 'AccessKey.[AccessKeyId,SecretAccessKey]' --output text)
        SES_SMTP_PASSWORD=$(ses_smtp_password "$secret")
        unset secret
        save_state
    fi
    SMTP_HOST="email-smtp.$AWS_REGION.amazonaws.com"
    SMTP_PORT=587
    SMTP_USER=$SES_ACCESS_KEY_ID
    SMTP_PASSWORD=$SES_SMTP_PASSWORD
    SMTP_FROM="nao-responda@$DOMAIN"
    # Contas novas do SES só enviam para endereços verificados: o acesso de produção é pedido aqui
    # e a AWS responde por e-mail (normalmente em até 24 h).
    if [ -z "${SES_PRODUCTION_REQUESTED:-}" ]; then
        if [ "$(aws sesv2 get-account --query ProductionAccessEnabled --output text 2>/dev/null)" != True ]; then
            aws sesv2 put-account-details --production-access-enabled --mail-type TRANSACTIONAL \
                --website-url "https://$PANEL_HOST" --contact-language EN \
                --additional-contact-email-addresses "$OWNER_EMAIL" \
                --use-case-description "Transactional e-mail of the Hostrema hosting platform: sign-up confirmation, password reset, invoices and service notices sent only to our own customers, who sign up on https://$PANEL_HOST. Bounces and complaints are handled by SES suppression." \
                >/dev/null 2>&1 || warn "não consegui pedir o acesso de produção do SES (peça no console: SES → Account dashboard)."
            aws sesv2 create-email-identity --email-identity "$OWNER_EMAIL" >/dev/null 2>&1 || true
        fi
        SES_PRODUCTION_REQUESTED=1
    fi
    save_state
}

git_() { git -c safe.directory="$REPO" -c core.safecrlf=false -C "$REPO" "$@"; }

# Pastas que a imagem não usa: mudar só nelas não constrói nem publica outra imagem.
IMAGE_EXCLUDES=(infra docs tests .github)

# tree_of <index>: árvore git de um índice temporário, sem as pastas que a imagem não usa.
tree_of() {
    GIT_INDEX_FILE=$1 git_ rm -r -q --cached --ignore-unmatch -- "${IMAGE_EXCLUDES[@]}"
    GIT_INDEX_FILE=$1 git_ write-tree
}

# Árvore git do código desta pasta: o que está commitado, as mudanças ainda não commitadas e os
# arquivos novos que o .gitignore não exclui. A imagem é construída dela (com os finais de linha e as
# permissões do repositório, mesmo numa cópia do Windows) e o nome da imagem é o dela: o mesmo
# código dá sempre a mesma imagem.
source_tree() {
    local dir tree
    dir=$(mktemp -d)
    GIT_INDEX_FILE=$dir/index git_ read-tree HEAD
    GIT_INDEX_FILE=$dir/index git_ add -A
    tree=$(tree_of "$dir/index")
    rm -rf "$dir"
    printf '%s' "$tree"
}

# Versão mostrada no painel: o commit, com "-dirty" quando a imagem tem mudanças não commitadas.
platform_version() {
    local tree=$1 dir committed head
    head=$(git_ rev-parse --short=12 HEAD)
    dir=$(mktemp -d)
    GIT_INDEX_FILE=$dir/index git_ read-tree HEAD
    committed=$(tree_of "$dir/index")
    rm -rf "$dir"
    if [ "$tree" = "$committed" ]; then
        printf '%s' "$head"
    else
        printf '%s-dirty' "$head"
    fi
}

BUILD_PROJECT=hostrema-image
BUILD_ROLE=hostrema-image-build

# Projeto do AWS CodeBuild que constrói a imagem (Docker não é preciso nesta máquina).
ensure_build_project() {
    local source=$1 policy buildspec project tries
    if ! aws iam get-role --role-name "$BUILD_ROLE" >/dev/null 2>&1; then
        aws iam create-role --role-name "$BUILD_ROLE" \
            --description "Builds the Hostrema dashboard image (infra/prod/hostrema-prod.sh)" \
            --assume-role-policy-document '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"codebuild.amazonaws.com"},"Action":"sts:AssumeRole"}]}' >/dev/null ||
            return 1
    fi
    policy=$(jq -nc --arg region "$AWS_REGION" --arg account "$ACCOUNT_ID" --arg bucket "$STATE_BUCKET" --arg project "$BUILD_PROJECT" '{
        Version: "2012-10-17",
        Statement: [
            {Effect: "Allow", Action: ["logs:CreateLogGroup", "logs:CreateLogStream", "logs:PutLogEvents"],
             Resource: ["arn:aws:logs:\($region):\($account):log-group:/aws/codebuild/\($project)",
                        "arn:aws:logs:\($region):\($account):log-group:/aws/codebuild/\($project):*"]},
            {Effect: "Allow", Action: ["s3:GetObject", "s3:GetObjectVersion"], Resource: "arn:aws:s3:::\($bucket)/installer/source/*"},
            {Effect: "Allow", Action: ["s3:GetBucketAcl", "s3:GetBucketLocation"], Resource: "arn:aws:s3:::\($bucket)"},
            {Effect: "Allow", Action: "ecr:GetAuthorizationToken", Resource: "*"},
            {Effect: "Allow", Action: ["ecr:BatchCheckLayerAvailability", "ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer",
                                       "ecr:InitiateLayerUpload", "ecr:UploadLayerPart", "ecr:CompleteLayerUpload", "ecr:PutImage"],
             Resource: "arn:aws:ecr:\($region):\($account):repository/hostrema"}
        ]}')
    aws iam put-role-policy --role-name "$BUILD_ROLE" --policy-name build --policy-document "$policy" || return 1
    # Base images from GitHub Packages and Amazon ECR Public (Docker Hub as the last resort): the
    # addresses of CodeBuild are shared, and Docker Hub limits anonymous pulls per address.
    buildspec=$(
        cat <<'YAML'
version: 0.2
env:
  shell: bash
phases:
  install:
    commands:
      - 'docker buildx version >/dev/null 2>&1 || { mkdir -p ~/.docker/cli-plugins && curl -fsSL -o ~/.docker/cli-plugins/docker-buildx https://github.com/docker/buildx/releases/download/v0.19.3/buildx-v0.19.3.linux-amd64 && chmod +x ~/.docker/cli-plugins/docker-buildx; }'
  build:
    commands:
      - 'aws ecr get-login-password --region "$AWS_REGION" | docker login --username AWS --password-stdin "${IMAGE%%/*}"'
      - 'PHP_TAG=$(sed -n "s/^ARG SERVERSIDEUP_PHP_VERSION=//p" docker/platform/Dockerfile)'
      - 'PHP_IMAGE=ghcr.io/serversideup/php; docker pull -q "$PHP_IMAGE:$PHP_TAG" || PHP_IMAGE=serversideup/php'
      - 'NODE_IMAGE=public.ecr.aws/docker/library/node; docker pull -q "$NODE_IMAGE:24-alpine" || NODE_IMAGE=node'
      - 'docker buildx build --build-arg "PLATFORM_VERSION=$VERSION" --build-arg "PHP_IMAGE=$PHP_IMAGE" --build-arg "NODE_IMAGE=$NODE_IMAGE" -f docker/platform/Dockerfile -t "$IMAGE" .'
      - 'docker push "$IMAGE"'
YAML
    )
    project=$(jq -nc --arg name "$BUILD_PROJECT" --arg source "$source" --arg buildspec "$buildspec" \
        --arg role "arn:aws:iam::$ACCOUNT_ID:role/$BUILD_ROLE" '{
        name: $name,
        description: "Builds the Hostrema dashboard image (infra/prod/hostrema-prod.sh)",
        source: {type: "S3", location: $source, buildspec: $buildspec},
        artifacts: {type: "NO_ARTIFACTS"},
        environment: {type: "LINUX_CONTAINER", image: "aws/codebuild/standard:7.0",
                      computeType: "BUILD_GENERAL1_MEDIUM", privilegedMode: true},
        serviceRole: $role,
        timeoutInMinutes: 60,
        queuedTimeoutInMinutes: 60
    }')
    if [ "$(aws codebuild batch-get-projects --names "$BUILD_PROJECT" --query 'projects[0].name' --output text 2>/dev/null)" = "$BUILD_PROJECT" ]; then
        aws codebuild update-project --cli-input-json "$project" >/dev/null || return 1
        return 0
    fi
    # A role that has just been created takes a few seconds to be accepted by CodeBuild.
    tries=12
    until aws codebuild create-project --cli-input-json "$project" >/dev/null 2>"$STATE_DIR/codebuild.err"; do
        tries=$((tries - 1))
        if [ "$tries" -le 0 ]; then
            cat "$STATE_DIR/codebuild.err" >&2
            return 1
        fi
        sleep 10
    done
}

# Últimas linhas do log de um build do CodeBuild.
build_log_tail() {
    local logs group stream
    logs=$(aws codebuild batch-get-builds --ids "$1" --query 'builds[0].logs.[groupName,streamName]' --output text 2>/dev/null) || return 0
    read -r group stream <<<"$logs"
    [ -n "$stream" ] && [ "$stream" != None ] || return 0
    aws logs get-log-events --log-group-name "$group" --log-stream-name "$stream" --limit 40 \
        --query 'events[].message' --output text 2>/dev/null | tr '\t' '\n' >&2 || true
}

# start_build_in_aws <árvore> <versão>: 0 = build iniciado no CodeBuild (BUILD_ID); 1 = o CodeBuild não
# pôde ser usado (a imagem é construída aqui).
start_build_in_aws() {
    local tree=$1 version=$2 dir key
    dir=$(mktemp -d)
    key="installer/source/$tree.zip"
    git_ archive --format=zip -o "$dir/source.zip" "$tree"
    if ! aws s3 cp "$dir/source.zip" "s3://$STATE_BUCKET/$key" --sse AES256 --only-show-errors; then
        rm -rf "$dir"
        return 1
    fi
    rm -rf "$dir"
    # Fontes enviadas ficam só uma semana no bucket.
    aws s3api put-bucket-lifecycle-configuration --bucket "$STATE_BUCKET" --lifecycle-configuration \
        '{"Rules":[{"ID":"installer-sources","Filter":{"Prefix":"installer/source/"},"Status":"Enabled","Expiration":{"Days":7},"NoncurrentVersionExpiration":{"NoncurrentDays":1}}]}' >/dev/null 2>&1 || true
    ensure_build_project "$STATE_BUCKET/$key" || return 1
    BUILD_ID=$(aws codebuild start-build --project-name "$BUILD_PROJECT" --source-location-override "$STATE_BUCKET/$key" \
        --environment-variables-override "$(jq -nc --arg image "$IMAGE" --arg version "$version" \
            '[{name: "IMAGE", value: $image, type: "PLAINTEXT"}, {name: "VERSION", value: $version, type: "PLAINTEXT"}]')" \
        --query build.id --output text) || return 1
    BUILD_IMAGE=$IMAGE
    save_state
}

build_status() {
    aws codebuild batch-get-builds --ids "$1" --query 'builds[0].[buildStatus,currentPhase]' --output text 2>/dev/null
}

# Espera o build da imagem que ensure_image iniciou no CodeBuild (o resto segue enquanto isso).
wait_for_image() {
    [ -n "${BUILD_ID:-}" ] || return 0
    say "Aguardando o build da imagem do painel no AWS CodeBuild ($BUILD_ID)"
    local status phase last='' tries=360 result
    while :; do
        if result=$(build_status "$BUILD_ID"); then
            read -r status phase <<<"$result"
            case "$status" in
            SUCCEEDED)
                note "imagem pronta: $IMAGE"
                break
                ;;
            IN_PROGRESS)
                [ "$phase" = "$last" ] || note "etapa: $phase"
                last=$phase
                ;;
            *)
                warn "o build da imagem terminou com $status. Fim do log:"
                build_log_tail "$BUILD_ID"
                BUILD_ID='' BUILD_IMAGE=''
                save_state
                fail "a imagem do painel não foi construída (log completo no CodeBuild). Rode de novo para tentar outra vez."
                ;;
            esac
        fi
        tries=$((tries - 1))
        [ "$tries" -gt 0 ] || fail "o build $BUILD_ID não terminou em 90 minutos."
        sleep 15
    done
    BUILD_ID='' BUILD_IMAGE=''
    save_state
}

# Sem CodeBuild (conta nova ainda sem permissão para builds, por exemplo): Docker desta máquina.
build_locally() {
    local tree=$1 version=$2 registry=${IMAGE%%/*}
    ensure_docker
    aws ecr get-login-password | docker_ login --username AWS --password-stdin "$registry" >/dev/null
    git_ archive --format=tar "$tree" |
        docker_ build --platform linux/amd64 --build-arg "PLATFORM_VERSION=$version" -f docker/platform/Dockerfile -t "$IMAGE" -
    docker_ push "$IMAGE"
}

# Imagem do painel construída deste código e guardada no Amazon ECR da conta.
ensure_image() {
    local tree tag version
    git_ rev-parse --verify -q HEAD >/dev/null ||
        fail "rode o script de dentro de um clone git deste repositório (git clone), não de uma cópia sem o .git."
    tree=$(source_tree)
    tag="src-${tree:0:12}"
    IMAGE="$ACCOUNT_ID.dkr.ecr.$AWS_REGION.amazonaws.com/hostrema:$tag"
    if [ "$PLAN_ONLY" = 1 ]; then
        return 0
    fi
    if ! aws ecr describe-repositories --repository-names hostrema >/dev/null 2>&1; then
        aws ecr create-repository --repository-name hostrema --image-scanning-configuration scanOnPush=true \
            --encryption-configuration encryptionType=AES256 >/dev/null
        aws ecr put-lifecycle-policy --repository-name hostrema --lifecycle-policy-text \
            '{"rules":[{"rulePriority":1,"description":"Keep the last 15 images","selection":{"tagStatus":"any","countType":"imageCountMoreThan","countNumber":15},"action":{"type":"expire"}}]}' >/dev/null
    fi
    if aws ecr describe-images --repository-name hostrema --image-ids "imageTag=$tag" >/dev/null 2>&1; then
        BUILD_ID='' BUILD_IMAGE=''
        save_state
        return 0
    fi
    # Build desta mesma imagem ainda em andamento (de uma execução interrompida): espera por ele.
    if [ -n "${BUILD_ID:-}" ] && [ "${BUILD_IMAGE:-}" = "$IMAGE" ] && [ "$(build_status "$BUILD_ID" | cut -f1)" = IN_PROGRESS ]; then
        return 0
    fi
    BUILD_ID='' BUILD_IMAGE=''
    version=$(platform_version "$tree")
    say "Construindo a imagem do painel com este código (versão $version)"
    if start_build_in_aws "$tree" "$version"; then
        note "build $BUILD_ID no AWS CodeBuild (uns 10 minutos); enquanto isso, o resto continua."
    else
        warn "não consegui usar o AWS CodeBuild; construindo a imagem com o Docker desta máquina."
        build_locally "$tree" "$version"
    fi
    save_state
}

# ---------------------------------------------------------------------------------------------
# Terraform
# ---------------------------------------------------------------------------------------------

tf_init() {
    local dir=$1 key=$2
    cat >"$dir/backend_override.tf" <<EOF
# Gerado por infra/prod/hostrema-prod.sh: estado no S3 da conta (com trava).
terraform {
  backend "s3" {
    bucket       = "$STATE_BUCKET"
    key          = "$key"
    region       = "$AWS_REGION"
    encrypt      = true
    use_lockfile = true
  }
}
EOF
    terraform -chdir="$dir" init -input=false -migrate-state -force-copy >"$STATE_DIR/terraform-init.log" 2>&1 || {
        tail -n 40 "$STATE_DIR/terraform-init.log" >&2
        fail "terraform init falhou em $dir."
    }
}

# tf_apply <pasta> <arquivo de variáveis> <descrição>: plano, confirmação e aplicação.
tf_apply() {
    local dir=$1 vars=$2 what=$3 plan rc=0 summary
    plan="$STATE_DIR/$(basename "$dir").tfplan"
    terraform -chdir="$dir" plan -input=false -var-file="$vars" -out="$plan" -detailed-exitcode \
        >"$STATE_DIR/terraform-plan.log" 2>&1 || rc=$?
    if [ "$rc" = 1 ]; then
        tail -n 60 "$STATE_DIR/terraform-plan.log" >&2
        fail "terraform plan falhou ($what)."
    fi
    if [ "$rc" = 0 ]; then
        note "$what: nada a mudar."
        return 0
    fi
    summary=$(grep -E '^Plan: ' "$STATE_DIR/terraform-plan.log" | tail -n 1 || true)
    note "$what: ${summary:-mudanças a aplicar}"
    if printf '%s' "$summary" | grep -qE ' [1-9][0-9]* to destroy'; then
        warn "o plano remove ou recria recursos. Detalhes: $STATE_DIR/terraform-plan.log"
    fi
    if [ "$PLAN_ONLY" = 1 ]; then
        return 0
    fi
    confirm "Aplicar na conta AWS $ACCOUNT_ID ($what)?" || fail "nada foi aplicado."
    terraform -chdir="$dir" apply -input=false "$plan"
}

tf_output() {
    terraform -chdir="$1" output -json 2>/dev/null || printf '{}'
}

apply_control_plane() {
    say "Painel (control plane): rede, banco, Redis, balanceador e instâncias"
    apply_size
    local vars="$STATE_DIR/control-plane.tfvars.json" cidr
    cidr=$(operator_cidr)
    jq -n \
        --arg region "$AWS_REGION" --arg key "$(cat "$SSH_KEY.pub")" --arg cidr "$cidr" \
        --arg domain "$PANEL_HOST" --arg zone "${ZONE_ID:-}" --arg image "$IMAGE" \
        --arg type "$CP_TYPE" --arg db "$CP_DB" --arg redis "$CP_REDIS" \
        --argjson count "$CP_COUNT" --argjson workers "$CP_WORKERS" \
        '{region: $region, ssh_public_key: $key, admin_cidrs: [$cidr], domain: $domain, route53_zone_id: $zone,
          load_balancer: true, instance_count: $count, worker_count: $workers, instance_type: $type,
          db_instance_class: $db, redis_node_type: $redis, image: $image}' >"$vars"
    tf_init "$CP_DIR" control-plane/terraform.tfstate
    tf_apply "$CP_DIR" "$vars" "painel"
}

control_plane_hosts() {
    if [ -n "${CP_HOSTS:-}" ]; then
        tr ' ' '\n' <<<"$CP_HOSTS" | grep -v '^$' || true
        return 0
    fi
    tf_output "$CP_DIR" | jq -r '.ssh_hosts.value // empty' | tr ' ' '\n' | sed 's/^ubuntu@//' | grep -v '^$' || true
}

refresh_hosts() {
    CP_HOSTS=''
    CP_HOSTS=$(control_plane_hosts | tr '\n' ' ' | sed 's/ $//')
    save_state
}

first_host() {
    control_plane_hosts | head -n 1
}

remote() {
    local host=$1
    shift
    ssh -F "$SSH_CONFIG" "$host" "$@"
}

# remote_artisan <host> <argumentos...>: php artisan dentro do container do painel.
remote_artisan() {
    local host=$1
    shift
    remote "$host" "sudo docker exec -i coolify php artisan $(printf '%q ' "$@")"
}

# Instância cuja primeira inicialização falhou (rede ou credenciais indisponíveis por mais tempo que
# o normal): roda a preparação dela de novo, uma vez. A preparação pode ser repetida sem efeitos.
BOOT_RETRIED=''
retry_failed_boots() {
    local host status
    for host in $(control_plane_hosts); do
        case " $BOOT_RETRIED " in *" $host "*) continue ;; esac
        status=$(remote "$host" "cloud-init status" 2>/dev/null || true)
        case "$status" in
        *error*)
            BOOT_RETRIED="$BOOT_RETRIED $host"
            note "a preparação de $host falhou; tentando de novo (log em /var/log/hostrema-bootstrap.log)"
            remote "$host" "sudo bash /var/lib/cloud/instance/scripts/part-001" >/dev/null 2>&1 ||
                warn "a nova tentativa em $host também falhou."
            ;;
        esac
    done
}

# Sem painel depois da espera: mostra o motivo, sem precisar entrar nas instâncias.
panel_failed() {
    local host
    for host in $(control_plane_hosts); do
        warn "fim do log de preparação de $host (/var/log/hostrema-bootstrap.log):"
        remote "$host" "sudo tail -n 30 /var/log/hostrema-bootstrap.log; sudo docker ps -a --format '{{.Names}}: {{.Status}}'" >&2 2>/dev/null ||
            note "não consegui entrar por SSH em $host."
    done
    fail "o painel não respondeu em 40 minutos (motivo acima). Corrigido o problema, rode o script de novo: ele continua de onde parou."
}

wait_for_panel() {
    say "Aguardando o painel responder em https://$PANEL_HOST (instâncias novas levam alguns minutos)"
    local tries=120
    until curl -fsS --max-time 10 "https://$PANEL_HOST/api/health" >/dev/null 2>&1; do
        tries=$((tries - 1))
        [ "$tries" -gt 0 ] || panel_failed
        # A cada 3 minutos, procura instâncias cuja preparação falhou.
        [ $((tries % 9)) -ne 0 ] || retry_failed_boots
        sleep 20
    done
    local host
    host=$(first_host)
    [ -n "$host" ] || fail "não encontrei as instâncias do painel no estado do Terraform."
    tries=60
    until remote "$host" "sudo docker exec coolify php artisan --version" >/dev/null 2>&1; do
        tries=$((tries - 1))
        [ "$tries" -gt 0 ] || fail "não consegui entrar por SSH em $host."
        sleep 10
    done
}

# Nova versão do código: troca a imagem em cada instância, uma por vez, com rollback automático.
rollout_image() {
    [ "${DEPLOYED_IMAGE:-}" = "$IMAGE" ] && return 0
    if [ -z "${DEPLOYED_IMAGE:-}" ]; then
        DEPLOYED_IMAGE=$IMAGE
        save_state
        return 0
    fi
    say "Publicando a versão $IMAGE no painel"
    local host
    for host in $(control_plane_hosts); do
        note "instância $host"
        scp -F "$SSH_CONFIG" -q "$REPO/infra/scripts/upgrade-control-plane.sh" "$REPO/infra/scripts/wait_for_health.sh" "$host:/tmp/" ||
            fail "não consegui copiar o script de atualização para $host."
        remote "$host" "sudo bash /tmp/upgrade-control-plane.sh '$IMAGE'" ||
            fail "a atualização falhou em $host e foi revertida lá. Nada mais foi mudado."
    done
    DEPLOYED_IMAGE=$IMAGE
    save_state
}

# Seguro de rodar sempre: só cria o que falta. A senha do owner só é definida na primeira vez.
install_platform() {
    local host args
    host=$(first_host)
    say "Preparando a plataforma (owner, cadastro, região)"
    args=(platform:install --no-interaction --email="$OWNER_EMAIL" --url="https://$PANEL_HOST"
        --region="$AWS_REGION" --region-name="$(region_name "$AWS_REGION")")
    if [ -z "${INSTALLED:-}" ]; then
        args+=(--password="$OWNER_PASSWORD" --reset-password)
    fi
    remote_artisan "$host" "${args[@]}" >"$STATE_DIR/platform-install.log" 2>&1 || {
        tail -n 30 "$STATE_DIR/platform-install.log" >&2
        fail "platform:install falhou no painel."
    }
    INSTALLED=1
    save_state
}

# Tudo o que seria feito no painel: domínio das aplicações, tokens internos, e-mail, Efí, chave dos
# nós e o token da API do Ansible.
configure_platform() {
    say "Configurando o painel (domínios, e-mail, cobrança, chave dos nós)"
    local host settings smtp="null" efi="null" payload result errors certificate="" provider token=""
    local settings_hash smtp_hash send_settings="" send_smtp=""
    host=$(first_host)
    if [ "${EFI_STATUS:-skipped}" = skipped ] && [ "${BILLING_PROVIDER:-}" != efi ]; then
        provider=manual
    else
        provider=efi
    fi
    settings=$(jq -n \
        --arg apps "$APPS_DOMAIN" --arg support "$OWNER_EMAIL" --arg edge "$EDGE_TOKEN" --arg metrics "$METRICS_TOKEN" \
        --arg otlp "https://otlp.{region}.$DOMAIN" --arg provider "$provider" \
        --arg instructions "Pague por Pix ou transferência e envie o comprovante para $OWNER_EMAIL." \
        '{"platform.base_domain": $apps, "platform.support_email": $support, "platform.edge.token": $edge,
          "platform.metrics_token": $metrics, "platform.telemetry.endpoint": $otlp, "platform.billing.provider": $provider}
         + (if $provider == "manual" then {"platform.billing.manual_instructions": $instructions} else {} end)')
    if [ -n "${SMTP_HOST:-}" ]; then
        smtp=$(jq -n --arg host "$SMTP_HOST" --arg port "${SMTP_PORT:-587}" --arg user "${SMTP_USER:-}" \
            --arg password "${SMTP_PASSWORD:-}" --arg from "${SMTP_FROM:-nao-responda@$DOMAIN}" \
            '{host: $host, port: ($port | tonumber), encryption: "starttls", username: $user, password: $password,
              from_address: $from, from_name: "Hostrema"}')
    fi
    if [ "${EFI_STATUS:-}" = pending ]; then
        if [ -n "${EFI_CERTIFICATE_FILE:-}" ]; then
            certificate=$(base64 <"$EFI_CERTIFICATE_FILE" | tr -d '\n')
        fi
        efi=$(jq -n --arg env "${EFI_ENVIRONMENT:-production}" --arg id "${EFI_CLIENT_ID:-}" \
            --arg secret "${EFI_CLIENT_SECRET:-}" --arg pix "${EFI_PIX_KEY:-}" --arg cert "$certificate" \
            --arg cert_password "${EFI_CERTIFICATE_PASSWORD:-}" \
            '{environment: $env, client_id: $id, client_secret: $secret, pix_key: $pix}
             + (if $cert != "" then {certificate_base64: $cert, certificate_password: $cert_password} else {} end)')
    fi
    # Só o que mudou desde a última vez: o que o owner ajustar no painel não é desfeito.
    settings_hash=$(printf '%s' "$settings" | sha256)
    smtp_hash=$(printf '%s' "$smtp" | sha256)
    [ "$settings_hash" != "${SETTINGS_HASH:-}" ] && send_settings=1
    [ "$smtp" != null ] && [ "$smtp_hash" != "${SMTP_HASH:-}" ] && send_smtp=1
    [ -z "${API_TOKEN:-}" ] && token=ansible
    payload=$(jq -n --argjson settings "$settings" --argjson smtp "$smtp" --argjson efi "$efi" \
        --arg send_settings "$send_settings" --arg send_smtp "$send_smtp" --arg token "$token" \
        '{node_key: true}
         + (if $send_settings == "1" then {settings: $settings} else {} end)
         + (if $send_smtp == "1" then {smtp: $smtp} else {} end)
         + (if $efi != null then {efi: $efi} else {} end)
         + (if $token != "" then {api_token: $token} else {} end)')
    if ! result=$(printf '%s' "$payload" | remote "$host" "sudo docker exec -i coolify php artisan platform:configure --stdin --json" 2>&1 | tail -n 1); then
        errors=$(printf '%s' "$result" | jq -r '.errors[]? // empty' 2>/dev/null || true)
        fail "o painel recusou a configuração: ${errors:-$result}"
    fi
    NODE_KEY_UUID=$(printf '%s' "$result" | jq -r '.node_key.uuid')
    NODE_KEY_PUBLIC=$(printf '%s' "$result" | jq -r '.node_key.public_key')
    if [ -n "$(printf '%s' "$result" | jq -r '.api_token // empty')" ]; then
        API_TOKEN=$(printf '%s' "$result" | jq -r '.api_token')
    fi
    [ -n "$send_settings" ] && SETTINGS_HASH=$settings_hash
    [ -n "$send_smtp" ] && SMTP_HASH=$smtp_hash
    if [ "$efi" != null ] && [ "$(printf '%s' "$result" | jq -r '.efi.configured // false')" = true ]; then
        local efi_errors
        efi_errors=$(printf '%s' "$result" | jq -r '.efi.errors | to_entries[]? | "\(.key): \(.value)"')
        [ -z "$efi_errors" ] || warn "Efí: $efi_errors"
        EFI_STATUS=configured
        # As credenciais ficam só no painel (criptografadas).
        EFI_CLIENT_ID='' EFI_CLIENT_SECRET='' EFI_CERTIFICATE_PASSWORD=''
    fi
    BILLING_PROVIDER=$provider
    save_state
}

# O token do Ansible ainda vale? Senão, um novo é criado na próxima configuração.
check_api_token() {
    [ -n "${API_TOKEN:-}" ] || return 0
    if ! curl -fsS --max-time 15 -H "Authorization: Bearer $API_TOKEN" -H 'Accept: application/json' \
        "https://$PANEL_HOST/api/v1/servers" >/dev/null 2>&1; then
        API_TOKEN=
    fi
}

# ---------------------------------------------------------------------------------------------
# Região: nós de execução, nó de controle e Ansible
# ---------------------------------------------------------------------------------------------

region_dir() {
    printf '%s/infra/terraform/regions/%s' "$REPO" "$AWS_REGION"
}

# Outras regiões nascem da pasta de exemplo (sa-east-1), como diz docs/region-onboarding.md.
ensure_region_dir() {
    local dir
    dir=$(region_dir)
    [ -d "$dir" ] && return 0
    say "Criando a pasta da região $AWS_REGION a partir de regions/sa-east-1"
    mkdir -p "$dir"
    local file
    for file in main.tf variables.tf outputs.tf; do
        sed "s/sa-east-1/$AWS_REGION/g" "$REPO/infra/terraform/regions/sa-east-1/$file" >"$dir/$file"
    done
}

apply_region() {
    say "Região $AWS_REGION: rede, balanceador de borda, nó de controle e nós de execução"
    apply_size
    ensure_region_dir
    local dir vars cidr peering
    dir=$(region_dir)
    vars="$STATE_DIR/region-$AWS_REGION.tfvars.json"
    cidr=$(operator_cidr)
    peering=$(tf_output "$CP_DIR" | jq -c '.region_peering.value // null')
    [ "$peering" != null ] || [ "$PLAN_ONLY" = 1 ] || fail "o painel ainda não tem a rede criada (região sem peering)."
    jq -n \
        --arg key "$(cat "$SSH_KEY.pub")" --arg cidr "$cidr" --argjson peering "$peering" \
        --arg zone "${ZONE_ID:-}" --arg apps "$APPS_DOMAIN" --arg edge "edge.$AWS_REGION.$DOMAIN" \
        --arg otlp "otlp.$AWS_REGION.$DOMAIN" --arg nodes "$AWS_REGION.nodes.$DOMAIN" \
        --arg control_type "$RG_CONTROL_TYPE" --arg node_type "$RG_NODE_TYPE" \
        --argjson control_disk "$RG_CONTROL_DISK" --argjson node_count "$RG_NODES" --argjson node_disk "$RG_NODE_DISK" \
        --argjson iops "$RG_NODE_IOPS" --argjson throughput "$RG_NODE_THROUGHPUT" \
        '{ssh_public_key: $key, admin_cidrs: [$cidr], control_plane_cidrs: [], control_plane_peering: $peering,
          route53_zone_id: $zone, apps_domain: $apps, edge_domain: $edge, otlp_domain: $otlp, nodes_domain: $nodes,
          control_instance_type: $control_type, control_data_volume_gb: $control_disk,
          node_count: $node_count, node_instance_type: $node_type, node_data_volume_gb: $node_disk,
          node_data_volume_iops: $iops, node_data_volume_throughput: $throughput}' >"$vars"
    tf_init "$dir" "regions/$AWS_REGION/terraform.tfstate"
    tf_apply "$dir" "$vars" "região $AWS_REGION"
}

write_ansible_vars() {
    local smtp_smarthost=""
    [ -n "${SMTP_HOST:-}" ] && smtp_smarthost="$SMTP_HOST:${SMTP_PORT:-587}"
    (
        umask 077
        jq -n \
            --arg url "https://$PANEL_HOST" --arg region_name "$(region_name "$AWS_REGION")" \
            --arg wildcard "https://$APPS_DOMAIN" --arg token "$API_TOKEN" --arg key_uuid "$NODE_KEY_UUID" \
            --arg key_public "$NODE_KEY_PUBLIC" --arg edge "$EDGE_TOKEN" --arg metrics "$METRICS_TOKEN" \
            --arg grafana "$GRAFANA_PASSWORD" --arg grafana_url "https://grafana.$AWS_REGION.$DOMAIN" \
            --arg email "$OWNER_EMAIL" --arg smarthost "$smtp_smarthost" --arg smtp_from "alertas@$DOMAIN" \
            --arg smtp_user "${SMTP_USER:-}" --arg smtp_password "${SMTP_PASSWORD:-}" \
            --arg otlp "otlp.$AWS_REGION.$DOMAIN" \
            '{coolify_url: $url, platform_region_name: $region_name, platform_apps_wildcard: $wildcard,
              vault_coolify_api_token: $token, vault_coolify_private_key_uuid: $key_uuid,
              vault_coolify_ssh_public_key: $key_public, vault_platform_edge_token: $edge,
              vault_platform_metrics_token: $metrics, vault_grafana_admin_password: $grafana,
              grafana_root_url: $grafana_url, alert_email_to: $email, acme_email: $email,
              smtp_smarthost: $smarthost, smtp_from: $smtp_from, vault_smtp_username: $smtp_user,
              vault_smtp_password: $smtp_password, otlp_domain: $otlp,
              vault_wireguard_private_key: "", vault_wireguard_control_plane_public_key: ""}' \
            >"$STATE_DIR/ansible-vars.json"
    )
}

run_ansible() {
    local inventory="$REPO/infra/ansible/inventory/$AWS_REGION.yml"
    [ -f "$inventory" ] || fail "o Terraform não gerou o inventário $inventory."
    say "Preparando os servidores da região (Ansible: Docker, firewall, monitoramento, cadastro no painel)"
    write_ansible_vars
    (
        cd "$REPO/infra/ansible"
        export ANSIBLE_CONFIG="$REPO/infra/ansible/ansible.cfg"
        export ANSIBLE_ROLES_PATH="$REPO/infra/ansible/roles"
        export ANSIBLE_COLLECTIONS_PATH="$TOOLS_DIR/collections"
        export ANSIBLE_PRIVATE_KEY_FILE="$SSH_KEY"
        export ANSIBLE_SSH_ARGS="-F $SSH_CONFIG -o ControlMaster=auto -o ControlPersist=60s"
        # O nó de controle é o bastion dos nós: o agente leva a chave até eles.
        eval "$(ssh-agent -s)" >/dev/null
        trap 'ssh-agent -k >/dev/null 2>&1 || true' EXIT
        ssh-add -q "$SSH_KEY"
        ansible all -i "$inventory" -m ansible.builtin.wait_for_connection -a timeout=900 >/dev/null
        ansible-playbook -i "$inventory" playbooks/site.yml -e "@$STATE_DIR/ansible-vars.json"
    )
}

# Com todos os nós confiando no balanceador de borda, o IP real chega também no HTTPS.
finish_platform() {
    [ "${EDGE_PROXY_PROTOCOL:-}" = 1 ] && return 0
    remote_artisan "$(first_host)" platform:configure --set=platform.edge.proxy_protocol=1 >/dev/null
    EDGE_PROXY_PROTOCOL=1
    save_state
}

check_installation() {
    say "Conferindo a instalação"
    local ok=1 servers edge code
    if curl -fsS --max-time 10 "https://$PANEL_HOST/api/health" >/dev/null 2>&1; then
        note "painel: ok (https://$PANEL_HOST)"
    else
        ok=0
        warn "o painel não respondeu em https://$PANEL_HOST/api/health"
    fi
    servers=$(curl -fsS --max-time 15 -H "Authorization: Bearer $API_TOKEN" -H 'Accept: application/json' \
        "https://$PANEL_HOST/api/v1/servers" 2>/dev/null | jq -r '.[]? | .name' | grep -c . || true)
    note "servidores cadastrados no painel: ${servers:-0}"
    edge=$(tf_output "$(region_dir)" | jq -r '.edge_lb_dns_name.value // empty')
    if [ -n "$edge" ]; then
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 -H "Host: verificacao.$APPS_DOMAIN" "http://$edge/" || true)
        if [ "$code" = 404 ]; then
            note "borda da região: ok (responde pelos nós)"
        else
            ok=0
            warn "a borda da região respondeu $code para um host desconhecido (esperado 404)."
        fi
    fi
    [ "$ok" = 1 ] || warn "algo não respondeu; rode de novo daqui a alguns minutos (o script continua de onde parou)."
}

# ---------------------------------------------------------------------------------------------
# Comandos
# ---------------------------------------------------------------------------------------------

prepare() {
    ensure_tools
    load_state
    ensure_aws_auth
    restore_state
    ask_configuration
    ensure_generated
    apply_size
    save_state
}

cmd_plan() {
    prepare
    show_summary
    if ! aws s3api head-bucket --bucket "$STATE_BUCKET" >/dev/null 2>&1; then
        say "Nada foi criado ainda nesta conta: ./hostrema-prod.sh cria tudo o que está acima."
        return 0
    fi
    ensure_zone
    ensure_image
    apply_control_plane
    if [ -n "$(control_plane_hosts)" ]; then
        apply_region
    fi
    say "Plano completo (nada foi mudado)."
}

cmd_up() {
    prepare
    show_summary
    if [ -z "${DEPLOYED_IMAGE:-}" ]; then
        confirm "Criar esta instalação na conta AWS $ACCOUNT_ID? (gera custos na AWS)" || fail "nada foi criado."
        ASSUME_YES=1
    fi
    ensure_state_bucket
    save_state
    ensure_zone
    copy_public_records
    ensure_ses
    ensure_image
    ensure_delegation
    wait_for_image
    apply_control_plane
    refresh_hosts
    wait_for_panel
    rollout_image
    install_platform
    check_api_token
    configure_platform
    apply_region
    run_ansible
    finish_platform
    check_installation
    cmd_info
}

cmd_info() {
    load_state
    [ -n "${DOMAIN:-}" ] || fail "ainda não há instalação; rode ./hostrema-prod.sh"
    local ses_status="" grafana_host=""
    if [ "${EMAIL_MODE:-}" = ses ] && command -v aws >/dev/null 2>&1; then
        [ -n "${AWS_PROFILE_NAME:-}" ] && export AWS_PROFILE=$AWS_PROFILE_NAME
        if [ "$(aws sesv2 get-account --region "$AWS_REGION" --query ProductionAccessEnabled --output text 2>/dev/null)" = True ]; then
            ses_status="liberado"
        else
            ses_status="aguardando a AWS liberar o envio para qualquer endereço (pedido feito; resposta por e-mail)"
        fi
    fi
    grafana_host=$(tf_output "$(region_dir)" | jq -r '.control_node.value.public_ip // empty' 2>/dev/null || true)
    cat <<EOF

  Painel ........... https://$PANEL_HOST
  Owner ............ $OWNER_EMAIL / $OWNER_PASSWORD
  Aplicações ....... https://<nome>.$APPS_DOMAIN
  Cobrança ......... $([ "${BILLING_PROVIDER:-manual}" = efi ] && echo "Efí Bank" || echo "manual (confirme em /admin/platform → Cobrança)")
  E-mail ........... ${EMAIL_MODE:-} ${ses_status:+($ses_status)}
  Grafana .......... ssh -F $SSH_CONFIG -L 3000:127.0.0.1:3000 ${grafana_host:-<nó de controle>}
                     http://localhost:3000 (admin / $GRAFANA_PASSWORD)
  Estado ........... $STATE_DIR (cópia em s3://${STATE_BUCKET:-})

  Próximas versões do código: ./hostrema-prod.sh upgrade

EOF
}

# O SSH do painel e da região só aceita o IP de quem roda este script. Com IP dinâmico (ele muda
# quando o roteador reinicia, por exemplo), o IP novo é liberado antes de entrar.
ensure_operator_access() {
    local cidr vars changed=''
    cidr=$(operator_cidr 2>/dev/null) || return 0
    for vars in "$STATE_DIR/control-plane.tfvars.json" "$STATE_DIR/region-$AWS_REGION.tfvars.json"; do
        [ -f "$vars" ] || continue
        [ "$(jq -r '.admin_cidrs[0] // empty' "$vars")" = "$cidr" ] || changed=1
    done
    [ -n "$changed" ] || return 0
    say "Seu IP público mudou ($cidr): liberando o SSH do painel e da região para ele"
    ensure_tools
    ensure_aws_auth
    apply_control_plane
    refresh_hosts
    if [ -f "$STATE_DIR/region-$AWS_REGION.tfvars.json" ]; then
        apply_region
    fi
}

cmd_upgrade() {
    ensure_tools
    load_state
    [ -n "${DOMAIN:-}" ] || fail "ainda não há instalação; rode ./hostrema-prod.sh"
    ensure_aws_auth
    ensure_image
    wait_for_image
    ensure_operator_access
    rollout_image
    note "painel em $IMAGE"
}

use_saved_credentials() {
    if [ -n "${AWS_PROFILE_NAME:-}" ]; then
        export AWS_PROFILE=$AWS_PROFILE_NAME
    fi
    AWS_REGION=${AWS_REGION:-sa-east-1}
    export AWS_REGION AWS_DEFAULT_REGION=$AWS_REGION
}

cmd_ssh() {
    load_state
    use_saved_credentials
    ensure_operator_access
    local target=${1:-}
    if [ -z "$target" ]; then
        exec ssh -F "$SSH_CONFIG" "$(first_host)"
    fi
    local inventory="$REPO/infra/ansible/inventory/$AWS_REGION.yml" host jump
    host=$(awk -v name="$target" '$1 ~ name":$" {found=1} found && /ansible_host:/ {print $2; exit}' "$inventory")
    jump=$(awk '/ProxyJump=/ {sub(/.*ProxyJump=/, ""); sub(/".*/, ""); print; exit}' "$inventory")
    [ -n "$host" ] || fail "host \"$target\" não está em $inventory."
    if [ -n "$jump" ] && [[ $target != *control* ]]; then
        exec ssh -F "$SSH_CONFIG" -J "$jump" "$host"
    fi
    exec ssh -F "$SSH_CONFIG" "$host"
}

cmd_artisan() {
    load_state
    use_saved_credentials
    ensure_operator_access
    local host
    host=$(first_host)
    exec ssh -t -F "$SSH_CONFIG" "$host" "sudo docker exec -it coolify php artisan $(printf '%q ' "$@")"
}

cmd_logs() {
    load_state
    use_saved_credentials
    ensure_operator_access
    exec ssh -t -F "$SSH_CONFIG" "$(first_host)" "sudo docker logs -f --tail=200 coolify"
}

cmd_reconfigure() {
    load_state
    say "Responda de novo (Enter mantém o valor atual)"
    PREV_DOMAIN=${DOMAIN:-} PREV_OWNER_EMAIL=${OWNER_EMAIL:-} PREV_SIZE=${SIZE:-} PREV_EMAIL_MODE=${EMAIL_MODE:-}
    DOMAIN='' OWNER_EMAIL='' SIZE='' EMAIL_MODE=''
    EFI_STATUS='' SMTP_HOST='' SMTP_PASSWORD=''
    ensure_tools
    ensure_aws_auth
    ask_configuration
    if [ "$DOMAIN" != "$PREV_DOMAIN" ]; then
        PANEL_HOST="painel.$DOMAIN" APPS_DOMAIN="apps.$DOMAIN" ZONE_ID='' SES_PRODUCTION_REQUESTED=''
    fi
    save_state
    cmd_up
}

cmd_destroy() {
    ensure_tools
    load_state
    [ -n "${DOMAIN:-}" ] || fail "não há instalação para apagar."
    ensure_aws_auth
    warn "isto apaga o painel, a região, os nós e os dados dos clientes desta instalação na AWS."
    note "O banco do painel guarda um snapshot final; o bucket de estado e a zona DNS ficam."
    local typed=""
    if has_tty; then
        printf '  Para confirmar, digite o domínio (%s): ' "$DOMAIN" >/dev/tty
        IFS= read -r typed </dev/tty || typed=
    fi
    [ "$typed" = "$DOMAIN" ] || fail "nada foi apagado."
    local dir
    dir=$(region_dir)
    if [ -d "$dir" ]; then
        tf_init "$dir" "regions/$AWS_REGION/terraform.tfstate"
        say "Apagando a região $AWS_REGION"
        terraform -chdir="$dir" destroy -input=false -auto-approve -var-file="$STATE_DIR/region-$AWS_REGION.tfvars.json"
    fi
    tf_init "$CP_DIR" control-plane/terraform.tfstate
    aws rds modify-db-instance --db-instance-identifier hostrema-control-plane --no-deletion-protection \
        --apply-immediately >/dev/null 2>&1 || true
    say "Apagando o painel"
    terraform -chdir="$CP_DIR" destroy -input=false -auto-approve -var-file="$STATE_DIR/control-plane.tfvars.json"
    aws ecr delete-repository --repository-name hostrema --force >/dev/null 2>&1 || true
    aws codebuild delete-project --name "$BUILD_PROJECT" >/dev/null 2>&1 || true
    aws iam delete-role-policy --role-name "$BUILD_ROLE" --policy-name build >/dev/null 2>&1 || true
    aws iam delete-role --role-name "$BUILD_ROLE" >/dev/null 2>&1 || true
    DEPLOYED_IMAGE='' INSTALLED='' API_TOKEN='' EDGE_PROXY_PROTOCOL='' NODE_KEY_UUID='' NODE_KEY_PUBLIC=''
    save_state
    say "Instalação apagada. Ficaram: a zona DNS de $DOMAIN, o bucket s3://$STATE_BUCKET e o estado em $STATE_DIR."
}

usage() {
    sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'
}

main() {
    local args=()
    while [ $# -gt 0 ]; do
        case "$1" in
        -y | --yes) ASSUME_YES=1 ;;
        -h | --help)
            usage
            exit 0
            ;;
        *) args+=("$1") ;;
        esac
        shift
    done
    set -- "${args[@]+"${args[@]}"}"
    local command=${1:-up}
    [ $# -gt 0 ] && shift
    case "$command" in
    up) cmd_up ;;
    plan)
        PLAN_ONLY=1
        cmd_plan
        ;;
    info) cmd_info ;;
    upgrade) cmd_upgrade ;;
    reconfigure) cmd_reconfigure ;;
    ssh) cmd_ssh "$@" ;;
    artisan) cmd_artisan "$@" ;;
    logs) cmd_logs ;;
    destroy) cmd_destroy ;;
    *)
        usage
        exit 1
        ;;
    esac
}

main "$@"
