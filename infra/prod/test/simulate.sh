#!/usr/bin/env bash
# Runs hostrema-prod.sh against stand-ins of aws, terraform, ssh, docker, curl and Ansible, on a
# copy of infra/, and checks what it would send to AWS, to the dashboard and to Ansible. Nothing
# leaves this machine. Used by CI (.github/workflows/platform-ci.yml).
# shellcheck disable=SC2016 # the jq programs in single quotes have $variables of their own
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO=$(cd "$HERE/../../.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
SIM=$WORK/sim
COPY=$WORK/repo
mkdir -p "$SIM/bin" "$SIM/marks" "$SIM/tools/venv/bin" "$SIM/tools/collections/ansible_collections/community/docker" "$COPY/docker"

# Copy of the infrastructure code in a git repository of its own (the image tag is the commit).
cp -R "$REPO/infra" "$COPY/infra"
cp -R "$REPO/docker/platform" "$COPY/docker/platform"
cp "$REPO/.gitignore" "$COPY/.gitignore"
rm -rf "$COPY/infra/local/e2e/node_modules" "$COPY/infra/ansible/inventory/"*-*-[0-9].yml
git -C "$COPY" init -q
git -C "$COPY" -c user.email=ci@example.com -c user.name=ci add -A
git -C "$COPY" -c user.email=ci@example.com -c user.name=ci commit -q -m simulation
# Ignored by .gitignore: must never leave this machine.
echo 'APP_KEY=secret-of-this-machine' >"$COPY/.env"

failures=0
not() { ! "$@"; }
zip_has() { python3 -I -c 'import sys, zipfile; sys.exit(sys.argv[2] not in zipfile.ZipFile(sys.argv[1]).namelist())' "$1" "$2"; }
tar_has() { tar -tf "$1" | grep -x -- "$2" >/dev/null; }
check() {
    local what=$1
    shift
    if "$@" >/dev/null; then
        printf '  ok     %s\n' "$what"
    else
        printf '  FALHA  %s\n' "$what"
        failures=$((failures + 1))
    fi
}

for tool in python ansible ansible-playbook ansible-galaxy; do
    printf '#!/bin/sh\necho "[%s] $*" >> "%s/calls.log"\n' "$tool" "$SIM" >"$SIM/tools/venv/bin/$tool"
done
for tool in scp ssh-add; do
    printf '#!/bin/sh\necho "[%s] $*" >> "%s/calls.log"\n' "$tool" "$SIM" >"$SIM/bin/$tool"
done
cat >"$SIM/bin/docker" <<EOF
#!/usr/bin/env bash
echo "[docker] \$*" >> "$SIM/calls.log"
case "\$1" in
build) cat >"$SIM/marks/context.tar" ;;
push) echo "\${2##*:}" >>"$SIM/marks/pushed" ;;
esac
exit 0
EOF
# The waits of the script (DNS, first boot) pass instantly.
printf '#!/bin/sh\ntouch "%s/marks/slept"\n' "$SIM" >"$SIM/bin/sleep"
cat >"$SIM/bin/ssh-agent" <<EOF
#!/bin/sh
echo "[ssh-agent] \$*" >> "$SIM/calls.log"
[ "\$1" = -s ] && echo "SSH_AUTH_SOCK=/nonexistent; export SSH_AUTH_SOCK; SSH_AGENT_PID=1; export SSH_AGENT_PID;"
exit 0
EOF
cat >"$SIM/bin/aws" <<EOF
#!/usr/bin/env bash
M="$SIM/marks"
echo "[aws] \$*" >> "$SIM/calls.log"
case "\$*" in
*"sts get-caller-identity"*) echo 123456789012 ;;
*"s3api head-bucket"*) [ -f "\$M/bucket" ] || exit 1 ;;
*"s3api create-bucket"*) touch "\$M/bucket" ;;
*"s3api head-object"*) exit 1 ;;
*"s3 cp - s3://"*) cat >"\$M/state.tgz" ;;
*"s3 cp "*"/installer/source/"*) cp "\$3" "\$M/source.zip" ;;
*"route53 list-hosted-zones-by-name"*)
    if [ -f "\$M/zone" ]; then
        echo '{"HostedZones":[{"Id":"/hostedzone/ZSIM","Name":"hostrema-teste.com.br.","Config":{"PrivateZone":false}}]}'
    else
        echo '{"HostedZones":[]}'
    fi ;;
*"route53 create-hosted-zone"*) touch "\$M/zone"; echo /hostedzone/ZSIM ;;
*"route53 get-hosted-zone"*) printf 'ns-1.awsdns-01.com.\tns-2.awsdns-02.net.\tns-3.awsdns-03.org.\tns-4.awsdns-04.co.uk.\n' ;;
*"route53domains get-domain-detail"*) exit 1 ;;
*"route53 list-resource-record-sets"*) echo '{"ResourceRecordSets":[]}' ;;
*"route53 change-resource-record-sets"*) echo "\$*" >>"\$M/records" ;;
*"sesv2 get-email-identity"*) [ -f "\$M/ses" ] || exit 1; echo '{"DkimAttributes":{"Tokens":["tok1","tok2","tok3"]}}' ;;
*"sesv2 create-email-identity --email-identity hostrema-teste.com.br"*) touch "\$M/ses"; echo '{"DkimAttributes":{"Tokens":["tok1","tok2","tok3"]}}' ;;
*"iam get-user"*) exit 1 ;;
*"iam list-access-keys"*) echo "" ;;
*"iam create-access-key"*) printf 'AKIASIMULATION\twJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY\n' ;;
*"sesv2 get-account"*) echo False ;;
*"ecr describe-repositories"*) [ -f "\$M/ecr" ] || exit 1 ;;
*"ecr create-repository"*) touch "\$M/ecr" ;;
*"ecr describe-images"*)
    for argument in "\$@"; do case "\$argument" in imageTag=*) tag=\${argument#imageTag=} ;; esac; done
    grep -qx "\$tag" "\$M/pushed" 2>/dev/null || exit 1 ;;
*"iam get-role"*) [ -f "\$M/build-role" ] || exit 254 ;;
*"iam create-role"*) touch "\$M/build-role" ;;
*"codebuild batch-get-projects"*) [ -f "\$M/build-project.json" ] && echo hostrema-image || echo None ;;
*"codebuild create-project"* | *"codebuild update-project"*) printf '%s' "\$4" >"\$M/build-project.json" ;;
*"codebuild start-build"*)
    [ -f "\$M/no-codebuild" ] && exit 254
    for argument in "\$@"; do case "\$argument" in '['*) printf '%s' "\$argument" >"\$M/build-env.json" ;; esac; done
    echo hostrema-image:build-1 ;;
*"codebuild batch-get-builds"*)
    jq -r '.[] | select(.name == "IMAGE") | .value | split(":") | last' "\$M/build-env.json" >>"\$M/pushed"
    printf 'SUCCEEDED\tCOMPLETED\n' ;;
*"ecr get-login-password"*) echo password ;;
esac
exit 0
EOF
cat >"$SIM/bin/terraform" <<EOF
#!/usr/bin/env bash
echo "[terraform] \$*" >> "$SIM/calls.log"
dir=""
for argument in "\$@"; do case "\$argument" in -chdir=*) dir=\${argument#-chdir=} ;; esac; done
name=\$(basename "\$dir")
case "\$*" in
*"version -json"*) echo '{"terraform_version":"1.13.4"}' ;;
*" init "*) touch "\$dir/.terraform.lock.hcl" ;;
*" plan "*)
    for argument in "\$@"; do case "\$argument" in -var-file=*) cp "\${argument#-var-file=}" "$SIM/marks/\$name.vars.json" ;; esac; done
    if [ -f "$SIM/marks/applied-\$name" ]; then echo "No changes."; exit 0; fi
    echo "Plan: 42 to add, 0 to change, 0 to destroy."; exit 2 ;;
*" apply "*)
    touch "$SIM/marks/applied-\$name"
    if [ "\$name" = sa-east-1 ]; then
        printf 'all:\n  children:\n    control:\n      hosts:\n        hostrema-sa-east-1-control:\n          ansible_host: 203.0.113.50\n    coolify_nodes:\n      vars:\n        ansible_ssh_common_args: "-o ProxyJump=ubuntu@203.0.113.50"\n      hosts:\n        hostrema-sa-east-1-node-01:\n          ansible_host: 10.20.128.10\n' >"\$dir/../../../ansible/inventory/sa-east-1.yml"
    fi ;;
*" output -json"*)
    if [ "\$name" = control-plane ]; then
        [ -f "$SIM/marks/applied-control-plane" ] &&
            echo '{"ssh_hosts":{"value":"ubuntu@198.51.100.10"},"region_peering":{"value":{"vpc_id":"vpc-1","cidr":"10.10.0.0/16","region":"sa-east-1","route_table_ids":["rtb-1"]}}}' ||
            echo '{}'
    else
        echo '{"edge_lb_dns_name":{"value":"edge.elb.amazonaws.com"},"control_node":{"value":{"public_ip":"203.0.113.50"}}}'
    fi ;;
esac
exit 0
EOF
cat >"$SIM/bin/curl" <<EOF
#!/usr/bin/env bash
echo "[curl] \$*" >> "$SIM/calls.log"
case "\$*" in
*checkip.amazonaws.com*) [ -f "$SIM/marks/new-ip" ] && echo 198.51.100.99 || echo 203.0.113.7 ;;
*"dns.google"*"type=NS"*)
    # O domínio está no registro.br até o operador trocar os servidores DNS (durante a espera).
    if [ -f "$SIM/marks/slept" ]; then
        echo '{"Answer":[{"type":2,"data":"ns-1.awsdns-01.com."},{"type":2,"data":"ns-2.awsdns-02.net."},{"type":2,"data":"ns-3.awsdns-03.org."},{"type":2,"data":"ns-4.awsdns-04.co.uk."}]}'
    else
        echo '{"Answer":[{"type":2,"data":"a.dns.br."},{"type":2,"data":"b.dns.br."}]}'
    fi ;;
*"dns.google"*"name=hostrema-teste.com.br&type=MX"*) echo '{"Answer":[{"name":"hostrema-teste.com.br.","type":15,"data":"10 mx.provedor.com.br."}]}' ;;
*"dns.google"*"name=hostrema-teste.com.br&type=TXT"*) echo '{"Answer":[{"name":"hostrema-teste.com.br.","type":16,"data":"v=spf1 include:provedor.com.br ~all"}]}' ;;
*"dns.google"*"name=www.hostrema-teste.com.br&type=CNAME"*) echo '{"Answer":[{"name":"www.hostrema-teste.com.br.","type":5,"data":"site.provedor.com.br."}]}' ;;
*"dns.google"*) echo '{"Status":3}' ;;
*"/api/v1/servers"*) echo '[{"name":"hostrema-sa-east-1-node-01"}]' ;;
*"/api/health"*) [ -f "$SIM/marks/booted" ] || exit 22 ;;
*"%{http_code}"*) printf 404 ;;
esac
exit 0
EOF
cat >"$SIM/bin/ssh" <<EOF
#!/usr/bin/env bash
echo "[ssh] \$*" >> "$SIM/calls.log"
case "\$*" in
*"cloud-init status"*) [ -f "$SIM/marks/booted" ] && echo "status: done" || echo "status: error" ;;
*"scripts/part-001"*) touch "$SIM/marks/booted" ;;
*"platform:configure --stdin --json"*)
    cat >"$SIM/marks/configure-\$(date +%s%N).json"
    echo '{"node_key":{"uuid":"keyuuid1","public_key":"ssh-ed25519 AAAAnode"},"api_token":"1|tokenSIM","errors":[]}' ;;
esac
exit 0
EOF
chmod +x "$SIM"/bin/* "$SIM"/tools/venv/bin/*

run() {
    HOSTREMA_STATE_DIR="$SIM/state" HOSTREMA_TOOLS_DIR="$SIM/tools" PATH="$SIM/bin:$PATH" \
        HOSTREMA_DOMAIN=hostrema-teste.com.br HOSTREMA_OWNER_EMAIL=owner@hostrema-teste.com.br \
        HOSTREMA_SIZE=inicial HOSTREMA_EMAIL_MODE=ses HOSTREMA_AWS_REGION=sa-east-1 \
        bash "$COPY/infra/prod/hostrema-prod.sh" "$@" </dev/null >"$SIM/output.log" 2>&1
}
payloads() { ls "$SIM"/marks/configure-*.json 2>/dev/null; }

echo "Simulação: plano numa conta nova"
check "plan termina sem erro" run plan
check "plan não cria nada" bash -c "! grep -q 'create-bucket' '$SIM/calls.log'"
check "plan explica que nada existe ainda" grep -q 'Nada foi criado ainda' "$SIM/output.log"

echo "Simulação: primeira instalação"
check "up --yes termina sem erro" run up --yes || tail -n 40 "$SIM/output.log"
cp_vars="$SIM/marks/control-plane.vars.json"
rg_vars="$SIM/marks/sa-east-1.vars.json"
first=$(payloads | head -n 1)
check "painel atrás do balanceador com o domínio" jq -e '.load_balancer == true and .domain == "painel.hostrema-teste.com.br" and .route53_zone_id == "ZSIM"' "$cp_vars"
check "imagem do painel no ECR da conta" jq -e '.image | startswith("123456789012.dkr.ecr.sa-east-1.amazonaws.com/hostrema:")' "$cp_vars"
check "SSH só a partir do IP de quem roda" jq -e '.admin_cidrs == ["203.0.113.7/32"]' "$cp_vars"
check "região ligada ao painel pelo peering" jq -e '.control_plane_peering.vpc_id == "vpc-1" and .apps_domain == "apps.hostrema-teste.com.br" and .node_count == 1' "$rg_vars"
check "registros DKIM do SES criados" grep -q 'tok1._domainkey.hostrema-teste.com.br' "$SIM/marks/records"
check "e-mail atual do domínio copiado antes da troca" grep -q '"Type":"MX".*10 mx.provedor.com.br' "$SIM/marks/records"
check "SPF atual copiado entre aspas" grep -qF '"Value":"\"v=spf1 include:provedor.com.br ~all\""' "$SIM/marks/records"
check "site atual (www) copiado" grep -q '"Name":"www.hostrema-teste.com.br","Type":"CNAME"' "$SIM/marks/records"
check "pede a troca dos servidores DNS e espera" grep -q 'Falta só um passo fora da AWS' "$SIM/output.log"
check "build da imagem começa antes da espera do DNS" bash -c "grep -n 'codebuild start-build\|type=NS' '$SIM/calls.log' | head -n 3 | grep -q 'codebuild start-build'"
check "imagem construída no AWS CodeBuild" jq -e '.environment.privilegedMode == true and (.source.buildspec | contains("docker buildx build"))' "$SIM/marks/build-project.json"
check "build com a imagem do painel" jq -e --slurpfile vars "$cp_vars" '(.[] | select(.name == "IMAGE") | .value) == $vars[0].image' "$SIM/marks/build-env.json"
check "versão do painel é o commit" jq -e --arg head "$(git -C "$COPY" rev-parse --short=12 HEAD)" '(.[] | select(.name == "VERSION") | .value) == $head' "$SIM/marks/build-env.json"
check "fonte enviada com o Dockerfile" zip_has "$SIM/marks/source.zip" docker/platform/Dockerfile
check "fonte enviada sem o .env desta máquina" not zip_has "$SIM/marks/source.zip" .env
check "fonte enviada sem a infraestrutura" not zip_has "$SIM/marks/source.zip" infra/prod/hostrema-prod.sh
check "nada de Docker nesta máquina" not grep -q '^\[docker\]' "$SIM/calls.log"
check "owner criado com senha" grep -q 'platform:install --no-interaction --email=owner@hostrema-teste.com.br .*--reset-password' "$SIM/calls.log"
check "configuração do painel completa" jq -e '.settings["platform.base_domain"] == "apps.hostrema-teste.com.br"
    and .settings["platform.billing.provider"] == "manual"
    and .smtp.host == "email-smtp.sa-east-1.amazonaws.com" and .smtp.username == "AKIASIMULATION"
    and .api_token == "ansible" and .node_key == true' "$first"
check "Ansible com o token e a chave do painel" jq -e '.vault_coolify_api_token == "1|tokenSIM" and .vault_coolify_private_key_uuid == "keyuuid1" and .coolify_url == "https://painel.hostrema-teste.com.br"' "$SIM/state/ansible-vars.json"
check "playbook da região" grep -q '^\[ansible-playbook\] -i .*/inventory/sa-east-1.yml playbooks/site.yml' "$SIM/calls.log"
check "PROXY protocol ligado depois dos nós" grep -q 'platform.edge.proxy_protocol=1' "$SIM/calls.log"
check "estado privado" bash -c "[ \"\$(stat -c %a '$SIM/state/secrets.env' 2>/dev/null || stat -f %Lp '$SIM/state/secrets.env')\" = 600 ]"
check "cópia do estado no bucket" test -s "$SIM/marks/state.tgz"
check "refaz a preparação de uma instância que falhou no boot" grep -q 'tentando de novo' "$SIM/output.log"

echo "Simulação: segunda execução (só a infraestrutura mudou)"
echo '# ajuste do operador' >>"$COPY/infra/ansible/playbooks/site.yml"
: >"$SIM/calls.log"
check "up de novo termina sem erro" run up --yes || tail -n 40 "$SIM/output.log"
second=$(payloads | tail -n 1)
check "não reconstrói a imagem" not grep -Eq '^\[aws\] codebuild start-build|^\[docker\] build' "$SIM/calls.log"
check "não redefine a senha do owner" bash -c "! grep -q -- '--reset-password' '$SIM/calls.log'"
check "não reenvia configurações nem cria outro token" jq -e '. == {"node_key": true}' "$second"
check "não aplica Terraform sem mudanças" bash -c "! grep -q '^\[terraform\] .* apply ' '$SIM/calls.log'"

echo "Simulação: código novo numa conta sem CodeBuild"
echo 'notas do operador' >"$COPY/notes.txt"
touch "$SIM/marks/no-codebuild"
: >"$SIM/calls.log"
check "up com código novo termina sem erro" run up --yes || tail -n 40 "$SIM/output.log"
check "constrói com o Docker desta máquina" grep -q '^\[docker\] build --platform linux/amd64' "$SIM/calls.log"
check "versão marcada como modificada" grep -q '^\[docker\] build .*PLATFORM_VERSION=[0-9a-f]*-dirty ' "$SIM/calls.log"
check "contexto com o arquivo novo" tar_has "$SIM/marks/context.tar" notes.txt
check "contexto sem o .env desta máquina" not tar_has "$SIM/marks/context.tar" .env
check "publica a nova versão no painel" grep -q 'sudo bash /tmp/upgrade-control-plane.sh' "$SIM/calls.log"

echo "Simulação: o IP de quem roda o script mudou"
touch "$SIM/marks/new-ip"
: >"$SIM/calls.log"
check "ssh termina sem erro" run ssh || tail -n 40 "$SIM/output.log"
check "libera o SSH do painel para o IP novo" jq -e '.admin_cidrs == ["198.51.100.99/32"]' "$cp_vars"
check "libera o SSH da região para o IP novo" jq -e '.admin_cidrs == ["198.51.100.99/32"]' "$rg_vars"
check "entra no painel" grep -q '^\[ssh\] -F .* 198.51.100.10$' "$SIM/calls.log"

if [ "$failures" -gt 0 ]; then
    echo "$failures verificação(ões) falharam. Saída da última execução:"
    tail -n 60 "$SIM/output.log"
    exit 1
fi
echo "Simulação ok."
