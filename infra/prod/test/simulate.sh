#!/usr/bin/env bash
# Tests of infra/prod/hostrema-prod.sh. Each scenario runs the installer on a copy of the code
# against stand-ins of aws, terraform, ssh, scp, docker, sudo, curl and Ansible, and checks what it
# would have sent to AWS, to the dashboard and to Ansible. Nothing leaves this machine.
#
#   bash infra/prod/test/simulate.sh            every scenario
#   bash infra/prod/test/simulate.sh failures   only the scenarios whose name has "failures"
#
# Run by infra/test/run.sh and by CI (.github/workflows/platform-ci.yml).
# shellcheck disable=SC2016 # the jq programs in single quotes have $variables of their own
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO=$(cd "$HERE/../../.." && pwd)
# shellcheck source-path=SCRIPTDIR source=../../test/lib.sh
. "$REPO/infra/test/lib.sh"

ONLY=${1:-}
ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT
# Without a controlling terminal the installer never waits for an answer on /dev/tty.
SETSID=()
command -v setsid >/dev/null 2>&1 && SETSID=(setsid -w)

DOMAIN=hostrema-teste.com.br
ACCOUNT=123456789012

# sandbox <nome>: copy of the code (its own git repository, as the image is named after it) and
# fresh stand-ins. Sets SIM (stand-ins, marks, state), M (marks) and COPY (the code).
sandbox() {
    SIM=$ROOT/$1/sim
    COPY=$ROOT/$1/repo
    M=$SIM/marks
    T_LOG=$SIM/output.log
    mkdir -p "$SIM/bin" "$M" "$SIM/tools/venv/bin" "$SIM/tools/collections/ansible_collections/community/docker" "$COPY/docker"
    cp -R "$REPO/infra" "$COPY/infra"
    cp -R "$REPO/docker/platform" "$COPY/docker/platform"
    cp "$REPO/.gitignore" "$COPY/.gitignore"
    rm -rf "$COPY/infra/local/e2e/node_modules" "$COPY/infra/ansible/inventory/"*-*-[0-9].yml "$COPY/infra/terraform/"*/backend_override.tf
    git -C "$COPY" init -q
    git -C "$COPY" -c user.email=ci@example.com -c user.name=ci add -A
    git -C "$COPY" -c user.email=ci@example.com -c user.name=ci commit -q -m simulation
    # Ignored by .gitignore: must never leave this machine.
    echo 'APP_KEY=secret-of-this-machine' >"$COPY/.env"
    : >"$SIM/calls.log"
    write_stubs
}

# code_change: a commit that changes the application (a new image).
code_change() {
    mkdir -p "$COPY/app"
    echo "<?php // $1" >>"$COPY/app/Version.php"
    git -C "$COPY" add -A app
    git -C "$COPY" -c user.email=ci@example.com -c user.name=ci commit -qm "$1"
}

# infra_change: a commit that changes only the infrastructure code.
infra_change() {
    echo "# $1" >>"$COPY/infra/ansible/playbooks/site.yml"
    git -C "$COPY" -c user.email=ci@example.com -c user.name=ci commit -qam "$1"
}

write_stubs() {
    local tool
    for tool in python ansible ansible-galaxy; do
        printf '#!/bin/sh\necho "[%s] $*" >> "%s/calls.log"\n' "$tool" "$SIM" >"$SIM/tools/venv/bin/$tool"
    done
    cat >"$SIM/tools/venv/bin/ansible-playbook" <<EOF
#!/bin/sh
echo "[ansible-playbook] \$*" >> "$SIM/calls.log"
[ -f "$M/ansible-fails" ] && { echo "fatal: [hostrema-node-01]: UNREACHABLE!"; exit 4; }
exit 0
EOF
    for tool in scp ssh-add; do
        printf '#!/bin/sh\necho "[%s] $*" >> "%s/calls.log"\n' "$tool" "$SIM" >"$SIM/bin/$tool"
    done
    # sudo never escalates here: it runs the stand-in.
    printf '#!/bin/sh\necho "[sudo] $*" >> "%s/calls.log"\n[ "$1" = -n ] && shift\nexec "$@"\n' "$SIM" >"$SIM/bin/sudo"
    # Waits (DNS, first boot, builds) pass instantly; the first one is when the registrar changes
    # the DNS servers of the domain.
    printf '#!/bin/sh\ntouch "%s/slept"\n' "$M" >"$SIM/bin/sleep"
    cat >"$SIM/bin/ssh-agent" <<EOF
#!/bin/sh
echo "[ssh-agent] \$*" >> "$SIM/calls.log"
[ "\$1" = -s ] && echo "SSH_AUTH_SOCK=/nonexistent; export SSH_AUTH_SOCK; SSH_AGENT_PID=1; export SSH_AGENT_PID;"
exit 0
EOF
    cat >"$SIM/bin/docker" <<EOF
#!/usr/bin/env bash
echo "[docker] \$*" >> "$SIM/calls.log"
[ -f "$M/no-docker" ] && exit 1
case "\$1" in
build) cat >"$M/context.tar" ;;
push) echo "\${2##*:}" >>"$M/pushed" ;;
esac
exit 0
EOF
    cat >"$SIM/bin/aws" <<EOF
#!/usr/bin/env bash
M="$M"
echo "[aws] \$*" >> "$SIM/calls.log"
case "\$*" in
# First: their arguments carry the whole project, buildspec included.
*"codebuild create-project"* | *"codebuild update-project"*) printf '%s' "\$4" >"\$M/build-project.json" ;;
*"sts get-caller-identity"*)
    # The session ends once, after the first long wait (DNS).
    if [ -f "\$M/expire-after-wait" ] && [ -f "\$M/slept" ]; then
        rm -f "\$M/expire-after-wait" "\$M/logged-in"
        exit 255
    fi
    # Without credentials until an access key is saved or the console login (aws login) happens.
    if [ -f "\$M/no-credentials" ] && ! grep -q aws_access_key_id "\$M/profile" 2>/dev/null && [ ! -f "\$M/logged-in" ]; then
        exit 255
    fi
    [ -f "\$M/bad-credentials" ] && exit 255
    echo $ACCOUNT ;;
--version) [ -f "\$M/aws-cli-new" ] && echo "aws-cli/2.33.4 Python/3.13.9 Linux/6.8.0 exe/x86_64.ubuntu.24" || echo "aws-cli/2.31.0 Python/3.12.6 Linux/6.8.0 exe/x86_64.ubuntu.24" ;;
"login --profile"*) echo "\$*" >"\$M/login"; [ -f "\$M/login-fails" ] && exit 255; touch "\$M/logged-in" ;;
*"configure get login_session"*) [ -f "\$M/logged-in" ] || exit 1; echo "arn:aws:iam::$ACCOUNT:root" ;;
*"configure set"*) echo "\$*" >>"\$M/profile" ;;
*"account get-region-opt-status"*)
    if [ -f "\$M/region-disabling" ]; then echo DISABLING
    elif [ ! -f "\$M/region-disabled" ]; then echo ENABLED_BY_DEFAULT
    elif [ ! -f "\$M/region-enable-requested" ]; then echo DISABLED
    else
        polls=\$((\$(cat "\$M/region-polls" 2>/dev/null || echo 0) + 1)); echo "\$polls" >"\$M/region-polls"
        [ "\$polls" -le 1 ] && echo ENABLING || echo ENABLED
    fi ;;
*"account enable-region"*) echo "\$*" >"\$M/region-enable-requested" ;;
*"ec2 describe-instance-types"*)
    [ -f "\$M/no-instance-types" ] && exit 254
    for argument in "\$@"; do
        case "\$argument" in
        *.large) printf '%s\t2\n' "\$argument" ;;
        *.xlarge) printf '%s\t4\n' "\$argument" ;;
        *.2xlarge) printf '%s\t8\n' "\$argument" ;;
        *.4xlarge) printf '%s\t16\n' "\$argument" ;;
        *.8xlarge) printf '%s\t32\n' "\$argument" ;;
        esac
    done ;;
*"ec2 describe-instances"*)
    # Another workload of the account (4 standard vCPUs), one of ours and two of other quotas.
    [ -f "\$M/other-instances" ] && printf 'c5.xlarge\t2\t2\tNone\nm6i.large\t1\t2\thostrema\ng4dn.xlarge\t2\t2\tNone\ninf2.xlarge\t2\t2\tNone\n' ;;
*"service-quotas get-service-quota"*) cat "\$M/quota" 2>/dev/null || echo 1152.0 ;;
*"service-quotas get-aws-default-service-quota"*) echo 5.0 ;;
*"service-quotas request-service-quota-increase"*)
    echo "\$*" >"\$M/quota-request"
    if [ -f "\$M/quota-request-exists" ]; then
        echo "An error occurred (ResourceAlreadyExistsException) when calling the RequestServiceQuotaIncrease operation: Only one open service quota increase request is allowed per quota." >&2
        exit 254
    fi
    if [ -f "\$M/quota-request-refused" ]; then
        echo "An error occurred (IllegalArgumentException) when calling the RequestServiceQuotaIncrease operation: Invalid desired value." >&2
        exit 254
    fi
    echo req-new ;;
*"service-quotas list-requested-service-quota-change-history-by-quota"*) [ -f "\$M/quota-request-exists" ] && echo req-console || echo None ;;
*"service-quotas get-requested-service-quota-change"*)
    # The first answer is "still open"; then AWS approves what was asked.
    polls=\$((\$(cat "\$M/quota-polls" 2>/dev/null || echo 0) + 1)); echo "\$polls" >"\$M/quota-polls"
    if [ -f "\$M/quota-denied" ]; then
        echo DENIED
    elif [ "\$polls" -le 1 ]; then
        [ -f "\$M/quota-case" ] && echo CASE_OPENED || echo PENDING
    else
        desired=\$(sed -nE 's/.*--desired-value ([0-9]+).*/\1/p' "\$M/quota-request" 2>/dev/null)
        echo "\${desired:-64}.0" >"\$M/quota"
        echo APPROVED
    fi ;;
*"ec2 describe-availability-zones"*) printf '%sa\t%sb\t%sc\n' "\$AWS_REGION" "\$AWS_REGION" "\$AWS_REGION" ;;
*"ec2 describe-instance-type-offerings"*)
    for argument in "\$@"; do case "\$argument" in Name=instance-type,Values=*) type=\${argument#Name=instance-type,Values=} ;; esac; done
    if grep -qx "\$type" "\$M/only-zone-a" 2>/dev/null; then
        printf '%sa\n' "\$AWS_REGION"
    elif grep -qx "\$type" "\$M/not-in-zone-b" 2>/dev/null; then
        printf '%sa\t%sc\n' "\$AWS_REGION" "\$AWS_REGION"
    elif grep -qx "\$type" "\$M/not-in-zone-c" 2>/dev/null; then
        printf '%sa\t%sb\n' "\$AWS_REGION" "\$AWS_REGION"
    else
        printf '%sa\t%sb\t%sc\n' "\$AWS_REGION" "\$AWS_REGION" "\$AWS_REGION"
    fi ;;
*"s3 cp s3://"*"terraform.tfstate -"*)
    # Terraform state of a part (control plane or region): resources once it was applied.
    case "\$*" in
    */control-plane/*) name=control-plane ;;
    *) name=\$(printf '%s' "\$*" | sed -nE 's#.*/regions/([^/]+)/terraform.tfstate.*#\1#p') ;;
    esac
    [ -f "\$M/applied-\$name" ] || exit 1
    echo '{"resources":[{"mode":"managed","type":"aws_vpc","name":"this"}]}' ;;
*"s3api head-bucket"*) [ -f "\$M/bucket" ] || exit 1 ;;
*"s3api create-bucket"*) echo "\$*" >"\$M/bucket" ;;
*"s3api head-object"*) [ -f "\$M/state-in-s3" ] || exit 1 ;;
*"s3 cp s3://"*"installer/state.tgz -"*) cat "\$M/state.tgz" ;;
*"s3 cp - s3://"*) cat >"\$M/state.tgz" ;;
*"s3 cp "*"/installer/source/"*) cp "\$3" "\$M/source.zip" ;;
*"route53 list-hosted-zones-by-name"*)
    if [ -f "\$M/zone" ]; then
        echo '{"HostedZones":[{"Id":"/hostedzone/ZSIM","Name":"$DOMAIN.","Config":{"PrivateZone":false}}]}'
    else
        echo '{"HostedZones":[]}'
    fi ;;
*"route53 create-hosted-zone"*) touch "\$M/zone"; echo /hostedzone/ZSIM ;;
*"route53 get-hosted-zone"*) printf 'ns-1.awsdns-01.com.\tns-2.awsdns-02.net.\tns-3.awsdns-03.org.\tns-4.awsdns-04.co.uk.\n' ;;
*"route53domains get-domain-detail"*) [ -f "\$M/route53-domain" ] || exit 254 ;;
*"route53domains update-domain-nameservers"*) echo "\$*" >"\$M/ns-updated" ;;
*"route53 list-resource-record-sets"*) echo '{"ResourceRecordSets":[]}' ;;
*"route53 change-resource-record-sets"*) echo "\$*" >>"\$M/records" ;;
*"sesv2 get-email-identity"*"VerifiedForSendingStatus"*)
    # SES sees the DKIM records once the domain points to Route53.
    if [ -f "\$M/ses-unverified" ]; then echo False
    elif [ -f "\$M/slept" ] || [ -f "\$M/ns-updated" ]; then echo True
    else echo False; fi ;;
*"sesv2 get-email-identity"*) [ -f "\$M/ses" ] || exit 254; echo '{"DkimAttributes":{"Tokens":["tok1","tok2","tok3"]}}' ;;
*"sesv2 create-email-identity --email-identity $DOMAIN"*) touch "\$M/ses"; echo '{"DkimAttributes":{"Tokens":["tok1","tok2","tok3"]}}' ;;
*"sesv2 create-email-identity"*) echo "\$*" >>"\$M/ses-addresses" ;;
*"sesv2 put-email-identity-mail-from-attributes"*) echo "\$*" >"\$M/mail-from"; [ -f "\$M/mail-from-fails" ] && exit 254 ;;
*"sesv2 put-account-suppression-attributes"*) echo "\$*" >"\$M/suppression" ;;
*"iam get-user"*) [ -f "\$M/ses-user" ] || exit 254 ;;
*"iam create-user"*) touch "\$M/ses-user" ;;
*"iam list-access-keys"*)
    if [ -s "\$M/ses-user-key" ]; then cat "\$M/ses-user-key"
    elif [ -f "\$M/ses-user-key" ]; then echo AKIAOLDKEY
    else echo ""; fi ;;
*"iam create-access-key"*) echo AKIASIMULATION >"\$M/ses-user-key"; printf 'AKIASIMULATION\twJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY\n' ;;
*"iam delete-user --user-name"*) rm -f "\$M/ses-user" "\$M/ses-user-key" ;;
*"sesv2 get-account"*) [ -f "\$M/ses-production" ] && echo True || echo False ;;
*"sesv2 put-account-details"*) echo "\$*" >"\$M/ses-request" ;;
*"iam get-role"*) [ -f "\$M/build-role" ] || exit 254 ;;
*"iam create-role"*) touch "\$M/build-role" ;;
*"ecr describe-repositories"*) [ -f "\$M/ecr" ] || exit 254 ;;
*"ecr create-repository"*) touch "\$M/ecr" ;;
*"ecr describe-images"*)
    for argument in "\$@"; do case "\$argument" in imageTag=*) tag=\${argument#imageTag=} ;; esac; done
    grep -qx "\$tag" "\$M/pushed" 2>/dev/null || exit 254 ;;
*"ecr get-login-password"*) echo password ;;
*"codebuild batch-get-projects"*) [ -f "\$M/build-project.json" ] && echo hostrema-image || echo None ;;
*"codebuild start-build"*)
    [ -f "\$M/no-codebuild" ] && { echo "AccountLimitExceededException: Cannot have more than 0 builds in queue" >&2; exit 254; }
    for argument in "\$@"; do case "\$argument" in '['*) printf '%s' "\$argument" >"\$M/build-env.json" ;; esac; done
    echo hostrema-image:build-1 ;;
*"codebuild batch-get-builds"*"logs.[groupName"*) printf '/aws/codebuild/hostrema-image\tbuild-1\n' ;;
*"codebuild batch-get-builds"*)
    if [ -f "\$M/build-fails" ]; then
        printf 'FAILED\tBUILD\n'
    else
        jq -r '.[] | select(.name == "IMAGE") | .value | split(":") | last' "\$M/build-env.json" >>"\$M/pushed"
        printf 'SUCCEEDED\tCOMPLETED\n'
    fi ;;
*"logs get-log-events"*) printf 'Step 9/30 : RUN npm ci\tnpm ERR! network request to registry.npmjs.org failed\n' ;;
esac
exit 0
EOF
    cat >"$SIM/bin/terraform" <<EOF
#!/usr/bin/env bash
M="$M"
echo "[terraform] \$*" >> "$SIM/calls.log"
[ -z "\${AWS_PROFILE:-}" ] || echo "\$AWS_PROFILE" >>"\$M/terraform-profiles"
dir=""
for argument in "\$@"; do case "\$argument" in -chdir=*) dir=\${argument#-chdir=} ;; esac; done
name=\$(basename "\$dir")
case "\$*" in
*"version -json"*) echo '{"terraform_version":"1.13.4"}' ;;
*" init "*) touch "\$dir/.terraform.lock.hcl" ;;
*" plan "*)
    [ -f "\$M/tf-plan-fails" ] && { echo "Error: Invalid value for variable \"instance_type\""; exit 1; }
    for argument in "\$@"; do case "\$argument" in -var-file=*) cp "\${argument#-var-file=}" "\$M/\$name.vars.json" ;; esac; done
    if [ -f "\$M/applied-\$name" ]; then echo "No changes."; exit 0; fi
    echo "Plan: 42 to add, 0 to change, 0 to destroy."; exit 2 ;;
*" apply "*)
    touch "\$M/applied-\$name"
    if [ "\$name" != control-plane ]; then
        printf 'all:\n  children:\n    control:\n      hosts:\n        hostrema-%s-control:\n          ansible_host: 203.0.113.50\n    coolify_nodes:\n      vars:\n        ansible_ssh_common_args: "-o ProxyJump=ubuntu@203.0.113.50"\n      hosts:\n        hostrema-%s-node-01:\n          ansible_host: 10.20.128.10\n' "\$name" "\$name" >"\$dir/../../../ansible/inventory/\$name.yml"
    fi ;;
*" destroy "*) echo "\$name" >>"\$M/destroyed"; rm -f "\$M/applied-\$name" ;;
*" output -json"*)
    if [ "\$name" = control-plane ]; then
        [ -f "\$M/applied-control-plane" ] &&
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
M="$M"
echo "[curl] \$*" >> "$SIM/calls.log"
case "\$*" in
*checkip.amazonaws.com*) [ -f "\$M/new-ip" ] && echo 198.51.100.99 || echo 203.0.113.7 ;;
*"dns.google"*"type=NS"*)
    # The domain stays at registro.br until the operator changes its DNS servers (during the wait),
    # or the installer does it for a domain registered at AWS.
    if [ -f "\$M/slept" ] || [ -f "\$M/ns-updated" ]; then
        echo '{"Answer":[{"type":2,"data":"ns-1.awsdns-01.com."},{"type":2,"data":"ns-2.awsdns-02.net."},{"type":2,"data":"ns-3.awsdns-03.org."},{"type":2,"data":"ns-4.awsdns-04.co.uk."}]}'
    else
        echo '{"Answer":[{"type":2,"data":"a.dns.br."},{"type":2,"data":"b.dns.br."}]}'
    fi ;;
*"dns.google"*"name=$DOMAIN&type=MX"*) echo '{"Answer":[{"name":"$DOMAIN.","type":15,"data":"10 mx.provedor.com.br."}]}' ;;
*"dns.google"*"name=$DOMAIN&type=TXT"*) echo '{"Answer":[{"name":"$DOMAIN.","type":16,"data":"v=spf1 include:provedor.com.br ~all"}]}' ;;
*"dns.google"*"name=www.$DOMAIN&type=CNAME"*) echo '{"Answer":[{"name":"www.$DOMAIN.","type":5,"data":"site.provedor.com.br."}]}' ;;
*"dns.google"*) echo '{"Status":3}' ;;
*"/api/v1/servers"*) [ -f "\$M/token-invalid" ] && exit 22; echo '[{"name":"hostrema-sa-east-1-node-01"}]' ;;
*"/api/health"*) [ -f "\$M/panel-down" ] && exit 22; [ -f "\$M/booted" ] || exit 22 ;;
*"%{http_code}"*) [ -f "\$M/edge-502" ] && printf 502 || printf 404 ;;
esac
exit 0
EOF
    cat >"$SIM/bin/ssh" <<EOF
#!/usr/bin/env bash
M="$M"
echo "[ssh] \$*" >> "$SIM/calls.log"
case "\$*" in
*"cloud-init status"*) [ -f "\$M/booted" ] && echo "status: done" || echo "status: error" ;;
*"scripts/part-001"*) [ -f "\$M/boot-keeps-failing" ] && exit 1; touch "\$M/booted" ;;
*"tail -n 30 /var/log/hostrema-bootstrap.log"*) echo "Error response from daemon: pull access denied for hostrema" ;;
*"platform:configure --stdin --json"*)
    cat >"\$M/configure-\$(date +%s%N).json"
    if [ -f "\$M/configure-errors" ]; then
        echo '{"errors":["smtp.host: The host field is required."]}'
        exit 1
    fi
    echo '{"node_key":{"uuid":"keyuuid1","public_key":"ssh-ed25519 AAAAnode"},"api_token":"1|tokenSIM","efi":{"configured":true,"errors":{}},"errors":[]}' ;;
esac
exit 0
EOF
    chmod +x "$SIM"/bin/* "$SIM"/tools/venv/bin/*
}

# run <argumentos...>: the installer with the answers of a new operator (HOSTREMA_* in the
# environment). More answers, or different ones, come from the array EXTRA (NAME=value).
EXTRA=()
run() {
    local status=0
    # Nothing of this machine's AWS profile or terminal session reaches the installer.
    (cd "$COPY" && env -u AWS_PROFILE -u AWS_DEFAULT_PROFILE -u SSH_CONNECTION -u SSH_TTY -u DISPLAY -u WAYLAND_DISPLAY \
        HOSTREMA_STATE_DIR="$SIM/state" HOSTREMA_TOOLS_DIR="$SIM/tools" PATH="$SIM/bin:$PATH" \
        HOSTREMA_DOMAIN="$DOMAIN" HOSTREMA_OWNER_EMAIL="owner@$DOMAIN" HOSTREMA_SIZE=inicial \
        HOSTREMA_EMAIL_MODE=ses HOSTREMA_AWS_REGION=sa-east-1 "${EXTRA[@]+"${EXTRA[@]}"}" \
        timeout 300 "${SETSID[@]+"${SETSID[@]}"}" bash "$COPY/infra/prod/hostrema-prod.sh" "$@") </dev/null >"$SIM/output.log" 2>&1 || status=$?
    return $status
}

fails() { not run "$@"; }
payloads() { ls "$M"/configure-*.json 2>/dev/null || true; }
last_payload() { payloads | tail -n 1; }
fresh_calls() { : >"$SIM/calls.log"; }
zip_has() { python3 -I -c 'import sys, zipfile; sys.exit(sys.argv[2] not in zipfile.ZipFile(sys.argv[1]).namelist())' "$1" "$2"; }
tar_has() { tar -tf "$1" | grep -x -- "$2" >/dev/null; }
mode_of() { stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"; }
wanted() { [ -z "$ONLY" ] || [[ $1 == *"$ONLY"* ]]; }

scenario_main_flow() {
    sandbox main
    local cp_vars=$M/control-plane.vars.json rg_vars=$M/sa-east-1.vars.json first second

    section "Conta nova: plano"
    check "plan termina sem erro" run plan
    check "plan não cria nada" not grep -q 'create-bucket' "$SIM/calls.log"
    check "plan explica que nada existe ainda" contains "$T_LOG" 'Nada foi criado ainda'

    section "Primeira instalação"
    check "up --yes termina sem erro" run up --yes
    first=$(payloads | head -n 1)
    check "bucket do estado criado em sa-east-1" contains "$M/bucket" "--create-bucket-configuration LocationConstraint=sa-east-1"
    check "painel atrás do balanceador com o domínio" jq -e '.load_balancer == true and .domain == "painel.hostrema-teste.com.br" and .route53_zone_id == "ZSIM"' "$cp_vars"
    check "tamanho inicial: uma instância m6i.large" jq -e '.instance_count == 1 and .worker_count == 0 and .instance_type == "m6i.large" and .db_instance_class == "db.t4g.medium"' "$cp_vars"
    check "imagem do painel no ECR da conta" jq -e '.image | startswith("123456789012.dkr.ecr.sa-east-1.amazonaws.com/hostrema:src-")' "$cp_vars"
    check "SSH só a partir do IP de quem roda" jq -e '.admin_cidrs == ["203.0.113.7/32"]' "$cp_vars"
    check "região ligada ao painel pelo peering" jq -e '.control_plane_peering.vpc_id == "vpc-1" and .apps_domain == "apps.hostrema-teste.com.br" and .node_count == 1' "$rg_vars"
    check "domínios da região" jq -e '.edge_domain == "edge.sa-east-1.hostrema-teste.com.br" and .otlp_domain == "otlp.sa-east-1.hostrema-teste.com.br" and .nodes_domain == "sa-east-1.nodes.hostrema-teste.com.br"' "$rg_vars"
    check "registros DKIM do SES criados" contains "$M/records" 'tok1._domainkey.hostrema-teste.com.br'
    check "DMARC criado" contains "$M/records" '_dmarc.hostrema-teste.com.br'
    check "acesso de produção do SES pedido" contains "$M/ses-request" '--production-access-enabled'
    check "usuário SMTP do SES criado" test -f "$M/ses-user"
    check "e-mail atual do domínio copiado antes da troca" matches "$M/records" '"Type":"MX".*10 mx.provedor.com.br'
    check "SPF atual copiado entre aspas" contains "$M/records" '"Value":"\"v=spf1 include:provedor.com.br ~all\""'
    check "site atual (www) copiado" contains "$M/records" '"Name":"www.hostrema-teste.com.br","Type":"CNAME"'
    check "pede a troca dos servidores DNS e espera" contains "$T_LOG" 'Falta só um passo fora da AWS'
    check "mostra os quatro servidores DNS" contains "$T_LOG" 'ns-4.awsdns-04.co.uk'
    check "build da imagem começa antes da espera do DNS" bash -c "grep -n 'codebuild start-build\|type=NS' '$SIM/calls.log' | head -n 3 | grep -q 'codebuild start-build'"
    check "imagem construída no AWS CodeBuild" jq -e '.environment.privilegedMode == true and (.source.buildspec | contains("docker buildx build"))' "$M/build-project.json"
    check "build com a imagem do painel" jq -e --slurpfile vars "$cp_vars" '(.[] | select(.name == "IMAGE") | .value) == $vars[0].image' "$M/build-env.json"
    check "versão do painel é o commit" jq -e --arg head "$(git -C "$COPY" rev-parse --short=12 HEAD)" '(.[] | select(.name == "VERSION") | .value) == $head' "$M/build-env.json"
    check "fonte enviada com o Dockerfile" zip_has "$M/source.zip" docker/platform/Dockerfile
    check "fonte enviada sem o .env desta máquina" not zip_has "$M/source.zip" .env
    check "fonte enviada sem a infraestrutura" not zip_has "$M/source.zip" infra/prod/hostrema-prod.sh
    check "nada de Docker nesta máquina" not grep -q '^\[docker\]' "$SIM/calls.log"
    check "refaz a preparação de uma instância que falhou no boot" contains "$T_LOG" 'tentando de novo'
    check "owner criado com senha" matches "$SIM/calls.log" 'platform:install --no-interaction --email=owner@hostrema-teste.com.br .*--reset-password'
    check "configuração do painel completa" jq -e '.settings["platform.base_domain"] == "apps.hostrema-teste.com.br"
        and .settings["platform.billing.provider"] == "manual"
        and .settings["platform.telemetry.endpoint"] == "https://otlp.{region}.hostrema-teste.com.br"
        and .smtp.host == "email-smtp.sa-east-1.amazonaws.com" and .smtp.username == "AKIASIMULATION"
        and .api_token == "ansible" and .node_key == true and (has("efi") | not)' "$first"
    check "senha SMTP do SES derivada da chave (não a chave)" jq -e '.smtp.password | startswith("B") and (. != "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY")' "$first"
    check "Ansible com o token e a chave do painel" jq -e '.vault_coolify_api_token == "1|tokenSIM" and .vault_coolify_private_key_uuid == "keyuuid1" and .coolify_url == "https://painel.hostrema-teste.com.br"' "$SIM/state/ansible-vars.json"
    check "Ansible espera os servidores antes do playbook" matches "$SIM/calls.log" '^\[ansible\] all -i .* -m ansible.builtin.wait_for_connection'
    check "playbook da região" matches "$SIM/calls.log" '^\[ansible-playbook\] -i .*/inventory/sa-east-1.yml playbooks/site.yml'
    check "PROXY protocol ligado depois dos nós" contains "$SIM/calls.log" 'platform.edge.proxy_protocol=1'
    check "confere painel, servidores e borda" contains "$T_LOG" 'borda da região: ok'
    check "mostra o endereço e a senha do owner" matches "$T_LOG" 'Owner \.+ owner@hostrema-teste.com.br / [A-Za-z0-9]{20}'
    check "estado privado (pasta)" equals "$(mode_of "$SIM/state")" 700
    check "estado privado (segredos)" equals "$(mode_of "$SIM/state/secrets.env")" 600
    check "cópia do estado no bucket" test -s "$M/state.tgz"
    check "cota de vCPUs suficiente: nenhum pedido" not test -f "$M/quota-request"
    check "painel nas duas primeiras zonas que têm o tipo" jq -e '.availability_zones == ["sa-east-1a","sa-east-1b"]' "$cp_vars"
    check "região nas duas primeiras zonas que têm os tipos" jq -e '.availability_zones == ["sa-east-1a","sa-east-1b"]' "$rg_vars"
    check "zonas guardadas no estado" contains "$SIM/state/config.env" 'CP_ZONES=sa-east-1a\ sa-east-1b'
    check "MAIL FROM do próprio domínio no SES" contains "$M/mail-from" '--mail-from-domain bounce.hostrema-teste.com.br --behavior-on-mx-failure USE_DEFAULT_VALUE'
    check "MX do MAIL FROM" contains "$M/records" 'feedback-smtp.sa-east-1.amazonses.com'
    check "SPF do MAIL FROM" contains "$M/records" 'v=spf1 include:amazonses.com ~all'
    check "bounces e reclamações suprimidos" contains "$M/suppression" '--suppressed-reasons BOUNCE COMPLAINT'
    check "e-mail do owner no domínio: sem link de verificação" not test -f "$M/ses-addresses"
    check "logs do build guardados por 30 dias" contains "$SIM/calls.log" 'logs put-retention-policy --log-group-name /aws/codebuild/hostrema-image --retention-in-days 30'
    check "confere o domínio no SES" contains "$T_LOG" 'e-mail (SES): hostrema-teste.com.br verificado'
    check "Terraform com o perfil desta máquina (sem login do console)" not test -s "$M/terraform-profiles"

    section "Segunda execução (só a infraestrutura mudou)"
    infra_change 'ajuste do operador'
    fresh_calls
    check "up de novo termina sem erro" run up --yes
    second=$(last_payload)
    check "não reconstrói a imagem" not grep -Eq '^\[aws\] codebuild start-build|^\[docker\] build' "$SIM/calls.log"
    check "não redefine a senha do owner" not grep -q -- '--reset-password' "$SIM/calls.log"
    check "não reenvia configurações nem cria outro token" jq -e '. == {"node_key": true}' "$second"
    check "não aplica Terraform sem mudanças" not grep -q '^\[terraform\] .* apply ' "$SIM/calls.log"
    check "não pede de novo o acesso de produção do SES" not grep -q 'put-account-details' "$SIM/calls.log"
    check "não copia de novo os registros do domínio" not grep -q 'name=www' "$SIM/calls.log"
    check "não mexe de novo na supressão do SES" not grep -q 'put-account-suppression-attributes' "$SIM/calls.log"
    check "mantém as zonas" jq -e '.availability_zones == ["sa-east-1a","sa-east-1b"]' "$cp_vars"

    section "Mudança recusada sem confirmação"
    rm -f "$M/applied-control-plane"
    fresh_calls
    check "up sem -y e sem terminal não aplica" fails up
    check "diz que nada foi aplicado" contains "$T_LOG" 'nada foi aplicado'
    check "nenhum apply" not grep -q '^\[terraform\] .* apply ' "$SIM/calls.log"
    touch "$M/applied-control-plane"

    section "Código novo numa conta sem CodeBuild"
    echo 'notas do operador' >"$COPY/notes.txt"
    touch "$M/no-codebuild"
    fresh_calls
    check "up com código novo termina sem erro" run up --yes
    check "avisa que usa o Docker desta máquina" contains "$T_LOG" 'Docker desta máquina'
    check "constrói com o Docker desta máquina" matches "$SIM/calls.log" '^\[docker\] build --platform linux/amd64'
    check "versão marcada como modificada" matches "$SIM/calls.log" '^\[docker\] build .*PLATFORM_VERSION=[0-9a-f]*-dirty '
    check "contexto com o arquivo novo" tar_has "$M/context.tar" notes.txt
    check "contexto sem o .env desta máquina" not tar_has "$M/context.tar" .env
    check "publica a nova versão no painel" contains "$SIM/calls.log" 'sudo bash /tmp/upgrade-control-plane.sh'
    check "copia os scripts de atualização" matches "$SIM/calls.log" '^\[scp\] .*upgrade-control-plane.sh .*wait_for_health.sh'
    rm -f "$M/no-codebuild"

    section "upgrade"
    code_change 'versão 2'
    fresh_calls
    check "upgrade termina sem erro" run upgrade
    check "upgrade constrói no CodeBuild" grep -q 'codebuild start-build' "$SIM/calls.log"
    check "upgrade publica a versão" contains "$SIM/calls.log" 'sudo bash /tmp/upgrade-control-plane.sh'
    fresh_calls
    check "upgrade sem mudanças termina sem erro" run upgrade
    check "upgrade sem mudanças não constrói nem publica" not grep -Eq 'codebuild start-build|upgrade-control-plane' "$SIM/calls.log"
    check "diz a versão em uso" contains "$T_LOG" 'painel em 123456789012.dkr.ecr.sa-east-1.amazonaws.com/hostrema:src-'

    section "info, ssh, artisan, logs e ajuda"
    check "info termina sem erro" run info
    check "info mostra o painel" contains "$T_LOG" 'https://painel.hostrema-teste.com.br'
    check "info mostra o SES aguardando a AWS" contains "$T_LOG" 'aguardando a AWS liberar'
    check "info mostra como abrir o Grafana" contains "$T_LOG" '-L 3000:127.0.0.1:3000 203.0.113.50'
    fresh_calls
    check "ssh no painel" run ssh
    check "entra na primeira instância do painel" matches "$SIM/calls.log" '^\[ssh\] -F .* 198.51.100.10$'
    check "ssh num nó passa pelo nó de controle" run ssh node-01
    check "usa o nó de controle como salto" matches "$SIM/calls.log" '^\[ssh\] -F .* -J ubuntu@203.0.113.50 10.20.128.10$'
    check "ssh no nó de controle direto" run ssh control
    check "entra no nó de controle sem salto" matches "$SIM/calls.log" '^\[ssh\] -F [^ ]* 203.0.113.50$'
    check "host desconhecido é recusado" fails ssh node-99
    check "diz que o host não existe" contains "$T_LOG" 'host "node-99" não está'
    check "artisan com argumentos" run artisan tinker --execute='echo "ok";'
    check "argumentos do artisan chegam inteiros" contains "$SIM/calls.log" 'php artisan tinker --execute=echo\ \"ok\"\;'
    check "logs do painel" run logs
    check "segue os logs do container" contains "$SIM/calls.log" 'sudo docker logs -f --tail=200 coolify'
    check "--help mostra os comandos" run --help
    check "ajuda lista o destroy" contains "$T_LOG" 'destroy'
    check "comando desconhecido falha" fails voar
    check "comando desconhecido mostra a ajuda" contains "$T_LOG" 'upgrade'

    section "IP de quem roda mudou"
    touch "$M/new-ip"
    fresh_calls
    check "ssh termina sem erro" run ssh
    check "libera o SSH do painel para o IP novo" jq -e '.admin_cidrs == ["198.51.100.99/32"]' "$cp_vars"
    check "libera o SSH da região para o IP novo" jq -e '.admin_cidrs == ["198.51.100.99/32"]' "$rg_vars"
    check "entra no painel depois" matches "$SIM/calls.log" '^\[ssh\] -F .* 198.51.100.10$'

    section "Token do Ansible recusado pelo painel"
    touch "$M/token-invalid"
    fresh_calls
    check "up termina sem erro" run up --yes
    check "pede um token novo" jq -e '.api_token == "ansible"' "$(last_payload)"
    rm -f "$M/token-invalid"

    section "reconfigure (tamanho e SMTP próprio)"
    EXTRA=(HOSTREMA_SIZE=padrao HOSTREMA_EMAIL_MODE=smtp HOSTREMA_SMTP_HOST=smtp.provedor.com.br HOSTREMA_SMTP_PORT=465
        HOSTREMA_SMTP_USER=avisos HOSTREMA_SMTP_PASSWORD=segredo HOSTREMA_SMTP_FROM="avisos@$DOMAIN")
    fresh_calls
    check "reconfigure termina sem erro" run reconfigure --yes
    EXTRA=()
    check "novo tamanho no painel" jq -e '.instance_type == "m6i.xlarge" and .db_instance_class == "db.m6g.large"' "$cp_vars"
    check "novo tamanho na região" jq -e '.node_count == 2 and .node_instance_type == "m6i.4xlarge"' "$rg_vars"
    check "SMTP novo enviado ao painel" jq -e '.smtp.host == "smtp.provedor.com.br" and .smtp.port == 465 and .smtp.username == "avisos" and .smtp.from_address == "avisos@hostrema-teste.com.br"' "$(last_payload)"
    check "sem SES nesta configuração" not grep -q 'sesv2' "$SIM/calls.log"
    check "alertas do Ansible pelo SMTP novo" jq -e '.smtp_smarthost == "smtp.provedor.com.br:465" and .vault_smtp_username == "avisos"' "$SIM/state/ansible-vars.json"
    check "confere as zonas para os tipos novos" contains "$SIM/calls.log" 'Name=instance-type,Values=m6i.4xlarge'
    check "tamanho novo nas mesmas zonas" jq -e '.availability_zones == ["sa-east-1a","sa-east-1b"]' "$rg_vars"

    section "destroy"
    fresh_calls
    check "destroy sem confirmação não apaga" fails destroy
    check "diz que nada foi apagado" contains "$T_LOG" 'nada foi apagado'
    check "nada destruído" not test -f "$M/destroyed"
    EXTRA=(HOSTREMA_CONFIRM_DESTROY=outro-dominio.com.br)
    check "confirmação com outro domínio não apaga" fails destroy
    EXTRA=(HOSTREMA_CONFIRM_DESTROY="$DOMAIN")
    check "destroy confirmado termina sem erro" run destroy
    EXTRA=()
    check "apaga a região antes do painel" equals "$(tr '\n' ' ' <"$M/destroyed")" 'sa-east-1 control-plane '
    check "tira a proteção do banco antes" contains "$SIM/calls.log" 'rds modify-db-instance --db-instance-identifier hostrema-control-plane --no-deletion-protection'
    check "apaga o repositório de imagens" contains "$SIM/calls.log" 'ecr delete-repository --repository-name hostrema --force'
    check "apaga o projeto de build" contains "$SIM/calls.log" 'codebuild delete-project --name hostrema-image'
    check "apaga o papel do build" contains "$SIM/calls.log" 'iam delete-role --role-name hostrema-image-build'
    check "esquece a imagem publicada" not grep -Eq '^DEPLOYED_IMAGE=.*hostrema' "$SIM/state/config.env"
    check "apaga os logs do build" contains "$SIM/calls.log" 'logs delete-log-group --log-group-name /aws/codebuild/hostrema-image'
    check "apaga a chave SMTP do SES" contains "$SIM/calls.log" 'iam delete-access-key --user-name hostrema-ses-smtp-sa-east-1 --access-key-id AKIASIMULATION'
    check "apaga a política do usuário SMTP" contains "$SIM/calls.log" 'iam delete-user-policy --user-name hostrema-ses-smtp-sa-east-1 --policy-name ses-send'
    check "apaga o usuário SMTP do SES" contains "$SIM/calls.log" 'iam delete-user --user-name hostrema-ses-smtp-sa-east-1'
    check "esquece a chave SMTP do SES" grep -qx "SES_ACCESS_KEY_ID=''" "$SIM/state/secrets.env"
    check "esquece as zonas da rede apagada" grep -qx "CP_ZONES=''" "$SIM/state/config.env"
    check "diz o que ficou na conta" contains "$T_LOG" 'o domínio verificado no SES'

    section "Nova instalação depois do destroy"
    fresh_calls
    check "up termina sem erro" run up --yes
    check "reenvia as configurações e o SMTP ao painel novo" jq -e 'has("settings") and has("smtp") and .api_token == "ansible"' "$(last_payload)"
    check "cria o owner de novo" contains "$SIM/calls.log" '--reset-password'
    check "escolhe as zonas de novo" contains "$SIM/state/config.env" 'CP_ZONES=sa-east-1a\ sa-east-1b'
}

scenario_route53_domain() {
    sandbox route53
    touch "$M/route53-domain"
    section "Domínio registrado na própria AWS"
    check "up termina sem erro" run up --yes
    check "aponta o domínio para a zona sozinho" contains "$M/ns-updated" '--nameservers Name=ns-1.awsdns-01.com Name=ns-2.awsdns-02.net Name=ns-3.awsdns-03.org Name=ns-4.awsdns-04.co.uk'
    check "não pede nada fora da AWS" not contains "$T_LOG" 'Falta só um passo fora da AWS'
}

scenario_options() {
    local certificate cp_vars rg_vars first
    sandbox options
    certificate=$SIM/efi.p12
    printf 'certificado-p12' >"$certificate"
    EXTRA=(HOSTREMA_AWS_REGION=us-east-1 HOSTREMA_SIZE=grande HOSTREMA_EMAIL_MODE=smtp
        HOSTREMA_SMTP_HOST=smtp.provedor.com.br HOSTREMA_SMTP_PASSWORD=segredo
        HOSTREMA_EFI_CLIENT_ID=Client_Id_x HOSTREMA_EFI_CLIENT_SECRET=Client_Secret_x HOSTREMA_EFI_ENVIRONMENT=sandbox
        HOSTREMA_EFI_CERTIFICATE_FILE="$certificate" HOSTREMA_EFI_PIX_KEY="pix@$DOMAIN")
    section "SMTP próprio, Efí Bank e tamanho grande em us-east-1"
    check "up termina sem erro" run up --yes
    EXTRA=()
    cp_vars=$M/control-plane.vars.json
    rg_vars=$M/us-east-1.vars.json
    first=$(payloads | head -n 1)
    check "bucket em us-east-1 sem LocationConstraint" not contains "$M/bucket" 'LocationConstraint'
    check "bucket com o nome da região" contains "$M/bucket" "hostrema-tfstate-$ACCOUNT-us-east-1"
    check "pasta da região gerada de sa-east-1" test -f "$COPY/infra/terraform/regions/us-east-1/main.tf"
    check "pasta gerada sem sa-east-1 dentro" not grep -q 'sa-east-1' "$COPY/infra/terraform/regions/us-east-1/main.tf"
    check "imagem no ECR de us-east-1" jq -e '.image | startswith("123456789012.dkr.ecr.us-east-1.amazonaws.com/hostrema:")' "$cp_vars"
    check "painel grande: 2 instâncias e 1 worker" jq -e '.instance_count == 2 and .worker_count == 1 and .instance_type == "m6i.xlarge"' "$cp_vars"
    check "região grande: 2 nós m6i.8xlarge com disco rápido" jq -e '.node_count == 2 and .node_instance_type == "m6i.8xlarge" and .node_data_volume_iops == 6000 and .node_data_volume_throughput == 500' "$rg_vars"
    check "nenhuma chamada ao SES" not grep -q 'sesv2' "$SIM/calls.log"
    check "SMTP próprio no painel (porta padrão 587)" jq -e '.smtp.host == "smtp.provedor.com.br" and .smtp.port == 587 and .smtp.password == "segredo"' "$first"
    check "cobrança pela Efí" jq -e '.settings["platform.billing.provider"] == "efi" and (.settings | has("platform.billing.manual_instructions") | not)' "$first"
    check "Efí com certificado e chave Pix" jq -e '.efi.environment == "sandbox" and .efi.client_id == "Client_Id_x" and .efi.pix_key == "pix@hostrema-teste.com.br" and (.efi.certificate_base64 | @base64d) == "certificado-p12"' "$first"
    check "credenciais da Efí não ficam no estado" not grep -q 'Client_Secret_x' "$SIM/state/secrets.env"
    check "Efí marcada como configurada" contains "$SIM/state/config.env" 'EFI_STATUS=configured'
    check "playbook do inventário de us-east-1" matches "$SIM/calls.log" '^\[ansible-playbook\] -i .*/inventory/us-east-1.yml'

    section "reconfigure mantendo o SMTP próprio e a Efí"
    # Only the size changes: the other answers keep what was saved (empty = not answered).
    EXTRA=(HOSTREMA_AWS_REGION=us-east-1 HOSTREMA_SIZE=padrao HOSTREMA_EMAIL_MODE=)
    fresh_calls
    check "reconfigure termina sem erro" run reconfigure --yes
    EXTRA=()
    check "SMTP continua o mesmo" contains "$SIM/state/config.env" 'SMTP_HOST=smtp.provedor.com.br'
    check "senha SMTP continua" contains "$SIM/state/secrets.env" 'SMTP_PASSWORD=segredo'
    check "SMTP não é reenviado" jq -e 'has("smtp") | not' "$(last_payload)"
    check "Efí continua configurada" contains "$SIM/state/config.env" 'EFI_STATUS=configured'
    check "cobrança continua pela Efí" not jq -e '.settings["platform.billing.provider"] == "manual"' "$(last_payload)"
    check "resumo mostra a Efí" contains "$T_LOG" 'Efí Bank'
    check "novo tamanho aplicado" jq -e '.node_count == 2 and .node_instance_type == "m6i.4xlarge"' "$rg_vars"

    section "destroy com a Efí configurada"
    EXTRA=(HOSTREMA_AWS_REGION=us-east-1 HOSTREMA_CONFIRM_DESTROY="$DOMAIN")
    check "destroy termina sem erro" run destroy
    EXTRA=()
    check "a próxima instalação pergunta de novo pela Efí" grep -qx "EFI_STATUS=''" "$SIM/state/config.env"
    check "e não fica presa à Efí sem credenciais" grep -qx "BILLING_PROVIDER=''" "$SIM/state/config.env"
    check "sem usuário SMTP do SES para apagar" not grep -q 'iam delete-user --user-name' "$SIM/calls.log"
    check "sem SES, não diz que ficou um domínio no SES" not contains "$T_LOG" 'no SES'
}

scenario_credentials() {
    sandbox credentials
    touch "$M/no-credentials"
    section "Sem credenciais da AWS nesta máquina"
    EXTRA=(HOSTREMA_key_id=AKIAOPERADOR HOSTREMA_secret=segredo-do-operador)
    check "plan termina sem erro" run plan
    check "guarda a chave no perfil hostrema" contains "$M/profile" 'configure set aws_access_key_id AKIAOPERADOR --profile hostrema'
    check "guarda a região no perfil" contains "$M/profile" 'configure set region sa-east-1 --profile hostrema'
    check "lembra o perfil" contains "$SIM/state/config.env" 'AWS_PROFILE_NAME=hostrema'
    touch "$M/bad-credentials"
    rm -f "$SIM/state/config.env"
    check "credenciais recusadas param o script" fails plan
    check "diz que a AWS recusou" contains "$T_LOG" 'a AWS recusou essas credenciais'
    EXTRA=()
    rm -f "$M/bad-credentials" "$M/profile"
    check "sem terminal e sem chave, diz o que falta" fails plan
    check "diz qual variável definir" contains "$T_LOG" 'HOSTREMA_key_id'
}

scenario_invalid_answers() {
    sandbox invalid
    section "Respostas inválidas vindas do ambiente"
    EXTRA=(HOSTREMA_DOMAIN="não é domínio")
    check "domínio inválido para o script" fails plan
    check "diz qual valor está errado" contains "$T_LOG" 'HOSTREMA_DOMAIN'
    EXTRA=(HOSTREMA_OWNER_EMAIL=owner)
    check "e-mail inválido para o script" fails plan
    check "diz que o e-mail é inválido" contains "$T_LOG" 'não é um e-mail válido'
    EXTRA=(HOSTREMA_SIZE=gigante)
    check "tamanho inválido para o script" fails plan
    check "diz os tamanhos possíveis" contains "$T_LOG" 'escolha inicial, padrao ou grande'
    EXTRA=(HOSTREMA_EMAIL_MODE=pombo)
    check "modo de e-mail inválido para o script" fails plan
    check "diz as opções de e-mail" contains "$T_LOG" 'escolha ses ou smtp'
    EXTRA=(HOSTREMA_EFI_CLIENT_ID=x HOSTREMA_EFI_CLIENT_SECRET=y HOSTREMA_EFI_CERTIFICATE_FILE=/nao/existe.p12)
    check "certificado inexistente para o script" fails plan
    check "diz que o arquivo não existe" contains "$T_LOG" 'arquivo não encontrado: /nao/existe.p12'
    EXTRA=(HOSTREMA_DOMAIN="https://Hostrema-Teste.com.br/painel")
    check "domínio digitado como endereço é aceito" run plan
    check "e vira só o domínio" contains "$SIM/state/config.env" "DOMAIN=$DOMAIN"
    EXTRA=()
}

scenario_failures() {
    section "Falhas: Terraform recusa o plano"
    sandbox failures-terraform
    touch "$M/tf-plan-fails"
    check "up para" fails up --yes
    check "mostra o erro do Terraform" contains "$T_LOG" 'Invalid value for variable'
    check "diz em qual parte falhou" contains "$T_LOG" 'terraform plan falhou (painel)'

    section "Falhas: build da imagem"
    sandbox failures-build
    touch "$M/build-fails"
    check "up para" fails up --yes
    check "mostra o fim do log do build" contains "$T_LOG" 'npm ERR! network request to registry.npmjs.org failed'
    check "não cria o painel com imagem quebrada" not grep -q 'control-plane apply' "$SIM/calls.log"
    check "esquece o build que falhou" not grep -Eq '^BUILD_ID=.*build' "$SIM/state/config.env"
    rm -f "$M/build-fails"
    check "na próxima execução constrói de novo e segue" run up --yes

    section "Falhas: painel não sobe"
    sandbox failures-panel
    touch "$M/panel-down" "$M/booted"
    check "up para" fails up --yes
    check "mostra o fim do log de preparação" contains "$T_LOG" 'pull access denied for hostrema'
    check "diz que pode rodar de novo" contains "$T_LOG" 'rode o script de novo'

    section "Falhas: preparação da instância falha de novo"
    sandbox failures-boot
    touch "$M/boot-keeps-failing"
    check "up para" fails up --yes
    check "tentou preparar a instância de novo" contains "$T_LOG" 'tentando de novo'
    check "avisa que a nova tentativa falhou" contains "$T_LOG" 'a nova tentativa em 198.51.100.10 também falhou'

    section "Falhas: painel recusa a configuração"
    sandbox failures-configure
    touch "$M/configure-errors"
    check "up para" fails up --yes
    check "mostra o motivo" contains "$T_LOG" 'smtp.host: The host field is required.'
    check "não prepara a região sem configuração" not grep -q 'ansible-playbook' "$SIM/calls.log"

    section "Falhas: Ansible"
    sandbox failures-ansible
    touch "$M/ansible-fails"
    check "up para" fails up --yes
    check "não liga o PROXY protocol sem os nós" not grep -q 'platform.edge.proxy_protocol=1' "$SIM/calls.log"
    rm -f "$M/ansible-fails"
    check "na próxima execução termina" run up --yes
    check "e liga o PROXY protocol" grep -q 'platform.edge.proxy_protocol=1' "$SIM/calls.log"

    section "Falhas: borda responde errado"
    sandbox failures-edge
    touch "$M/edge-502"
    check "up termina (o resto está pronto)" run up --yes
    check "avisa da borda" contains "$T_LOG" 'a borda da região respondeu 502'

    section "Falhas: sem CodeBuild e sem Docker"
    sandbox failures-docker
    touch "$M/no-codebuild" "$M/no-docker"
    check "up para" fails up --yes
    check "explica que precisa do Docker" contains "$T_LOG" 'o Docker não respondeu'
}

scenario_new_machine() {
    local saved status=0
    sandbox machine-a
    run up --yes || true
    saved=$M/state.tgz
    sandbox machine-b
    cp "$saved" "$M/state.tgz"
    touch "$M/state-in-s3" "$M/booted" "$M/zone" "$M/applied-control-plane" "$M/applied-sa-east-1"
    section "Máquina nova (estado no bucket)"
    (cd "$COPY" && env HOSTREMA_STATE_DIR="$SIM/state" HOSTREMA_TOOLS_DIR="$SIM/tools" PATH="$SIM/bin:$PATH" HOSTREMA_AWS_REGION=sa-east-1 \
        timeout 120 "${SETSID[@]+"${SETSID[@]}"}" bash "$COPY/infra/prod/hostrema-prod.sh" info) </dev/null >"$T_LOG" 2>&1 || status=$?
    check "info sem nenhuma outra resposta termina sem erro" equals "$status" 0
    check "recupera o estado do bucket" contains "$T_LOG" 'Recuperando o estado desta instalação'
    check "mostra o painel da instalação" contains "$T_LOG" 'https://painel.hostrema-teste.com.br'
    check "estado recuperado é privado" equals "$(mode_of "$SIM/state/secrets.env")" 600
}

scenario_ses_ready() {
    sandbox ses-ready
    touch "$M/ses" "$M/ses-production" "$M/ses-user" "$M/ses-user-key"
    section "SES já liberado e usuário SMTP existente"
    check "up termina sem erro" run up --yes
    check "não cria a identidade de novo" not grep -q "sesv2 create-email-identity --email-identity $DOMAIN" "$SIM/calls.log"
    check "não pede acesso de produção" not test -f "$M/ses-request"
    check "troca a chave antiga do usuário SMTP" contains "$SIM/calls.log" 'iam delete-access-key --user-name hostrema-ses-smtp-sa-east-1 --access-key-id AKIAOLDKEY'
    check "info termina sem erro" run info
    check "info mostra o SES liberado" contains "$T_LOG" '(liberado)'
}

scenario_not_a_clone() {
    sandbox no-git
    rm -rf "$COPY/.git"
    section "Fora de um clone git"
    check "up para" fails up --yes
    check "explica que precisa de um clone" contains "$T_LOG" 'clone git deste repositório'
}

scenario_login() {
    sandbox login
    touch "$M/no-credentials" "$M/aws-cli-new"
    section "Login do console pelo navegador (aws login)"
    EXTRA=(HOSTREMA_AWS_LOGIN=navegador SSH_CONNECTION="198.51.100.1 50000 10.0.0.5 22")
    check "plan termina sem erro" run plan
    check "entra pelo aws login num perfil próprio" contains "$M/login" 'login --profile hostrema-login'
    check "por SSH, o login é aberto em outro aparelho" contains "$M/login" '--remote'
    check "região no perfil do login" contains "$M/profile" 'configure set region sa-east-1 --profile hostrema-login'
    check "nenhuma chave de acesso" not grep -q 'aws_access_key_id' "$M/profile"
    check "lembra o perfil do login" contains "$SIM/state/config.env" 'AWS_PROFILE_NAME=hostrema-login'
    check "Terraform recebe a sessão pela AWS CLI" contains "$M/profile" 'configure export-credentials --profile hostrema-login --format process --profile hostrema-login-terraform'
    check "a sessão não vira um perfil com credential_process" not grep -q 'credential_process.* --profile hostrema-login$' "$M/profile"
    check "up termina sem erro" run up --yes
    check "Terraform usa o perfil que pede as credenciais à CLI" grep -qx hostrema-login-terraform "$M/terraform-profiles"
    check "Terraform nunca usa o perfil do login direto" not grep -qx hostrema-login "$M/terraform-profiles"
    EXTRA=()

    section "Sessão do login venceu (12 horas)"
    rm -f "$M/logged-in" "$M/login"
    EXTRA=(HOSTREMA_AWS_LOGIN=navegador DISPLAY=:0)
    check "plan termina sem erro" run plan
    check "pede o login de novo no mesmo perfil" contains "$M/login" 'login --profile hostrema-login'
    if ! grep -qi microsoft /proc/version 2>/dev/null; then
        check "com navegador nesta máquina, abre o navegador direto" not contains "$M/login" '--remote'
    fi
    EXTRA=()

    section "Login que não termina"
    rm -f "$M/logged-in"
    touch "$M/login-fails"
    EXTRA=(HOSTREMA_AWS_LOGIN=navegador)
    check "plan para" fails plan
    check "diz que o login não terminou" contains "$T_LOG" 'o login na AWS não terminou'
    EXTRA=(HOSTREMA_AWS_LOGIN=pombo)
    check "opção inválida para o script" fails plan
    check "diz as opções de login" contains "$T_LOG" 'escolha navegador ou chave'
    EXTRA=()
    rm -f "$M/login-fails"

    section "Sessão do login acaba no meio da instalação"
    sandbox login-expiry
    touch "$M/no-credentials" "$M/aws-cli-new" "$M/expire-after-wait"
    EXTRA=(HOSTREMA_AWS_LOGIN=navegador)
    check "up termina sem erro" run up --yes
    EXTRA=()
    check "pede o login de novo depois da espera do DNS" contains "$T_LOG" 'A sessão da AWS acabou'
    check "e segue até o fim" contains "$T_LOG" 'borda da região: ok'
    sandbox key-expiry
    touch "$M/expire-after-wait"
    check "credenciais que deixam de valer param o script" fails up --yes
    check "diz para rodar de novo" contains "$T_LOG" 'as credenciais da AWS deixaram de valer'
    check "rodando de novo, termina" run up --yes

    sandbox login-old-cli
    touch "$M/no-credentials"
    section "AWS CLI anterior à 2.32"
    EXTRA=(HOSTREMA_AWS_LOGIN=navegador HOSTREMA_key_id=AKIAOPERADOR HOSTREMA_secret=segredo-do-operador)
    check "plan termina sem erro" run plan
    check "avisa que o login pelo navegador precisa da CLI nova" contains "$T_LOG" 'precisa da AWS CLI 2.32 ou mais nova (esta é a 2.31.0)'
    check "entra com a chave de acesso" contains "$M/profile" 'configure set aws_access_key_id AKIAOPERADOR --profile hostrema'
    check "passa a usar o perfil da chave" contains "$SIM/state/config.env" 'AWS_PROFILE_NAME=hostrema'
    EXTRA=()
}

scenario_quota() {
    sandbox quota
    echo 5.0 >"$M/quota"
    touch "$M/other-instances"
    section "Conta nova: cota de 5 vCPUs"
    check "plan termina sem erro" run plan
    check "plan avisa da cota (com as máquinas que já rodam na conta)" contains "$T_LOG" 'a cota de vCPUs da conta em sa-east-1 é 5 e este tamanho precisa de 16'
    check "plan não pede nada" not test -f "$M/quota-request"
    check "up termina sem erro" run up --yes
    check "pede o aumento do que falta" contains "$M/quota-request" '--service-code ec2 --quota-code L-1216C47A --desired-value 16'
    check "pede antes de esperar o DNS" bash -c "grep -n 'request-service-quota-increase\|type=NS' '$SIM/calls.log' | head -n 1 | grep -q request-service-quota-increase"
    check "espera a aprovação antes de criar o painel" bash -c "grep -n 'get-requested-service-quota-change\|control-plane apply' '$SIM/calls.log' | head -n 1 | grep -q get-requested-service-quota-change"
    check "diz a cota nova" contains "$T_LOG" 'cota de vCPUs: 16.'
    check "esquece o pedido atendido" grep -qx "QUOTA_REQUEST_ID=''" "$SIM/state/config.env"
    fresh_calls
    check "up de novo termina sem erro" run up --yes
    check "não pede de novo" not grep -q 'request-service-quota-increase' "$SIM/calls.log"

    section "Cota: pedido já aberto (pelo console)"
    sandbox quota-open
    echo 5.0 >"$M/quota"
    touch "$M/quota-request-exists"
    check "up termina sem erro" run up --yes
    check "espera o pedido que já existia" contains "$SIM/calls.log" 'get-requested-service-quota-change --request-id req-console'

    section "Cota: a AWS abre um caso no suporte"
    sandbox quota-case
    echo 5.0 >"$M/quota"
    touch "$M/quota-case"
    check "up termina sem erro" run up --yes
    check "explica o caso e que pode rodar de novo" contains "$T_LOG" 'a AWS abriu um caso no suporte para este pedido'

    section "Cota: aumento negado"
    sandbox quota-denied
    echo 5.0 >"$M/quota"
    touch "$M/quota-denied"
    check "up para" fails up --yes
    check "explica a recusa e o que fazer" contains "$T_LOG" 'a AWS não aprovou o aumento da cota de vCPUs (DENIED'
    check "não cria o painel" not grep -q 'control-plane apply' "$SIM/calls.log"
    check "esquece o pedido negado" grep -qx "QUOTA_REQUEST_ID=''" "$SIM/state/config.env"

    section "Cota: pedido recusado na hora"
    sandbox quota-refused
    echo 5.0 >"$M/quota"
    touch "$M/quota-request-refused"
    check "up para" fails up --yes
    check "mostra o motivo da AWS" contains "$T_LOG" 'a AWS recusou o pedido de mais vCPUs: An error occurred (IllegalArgumentException)'

    section "Cota: sem como conferir"
    sandbox quota-unknown
    touch "$M/no-instance-types"
    check "up termina sem erro" run up --yes
    check "avisa que não conferiu" contains "$T_LOG" 'não consegui conferir a cota de vCPUs da conta'
}

scenario_zones() {
    local cp_vars rg_vars
    sandbox zones
    cp_vars=$M/control-plane.vars.json
    rg_vars=$M/sa-east-1.vars.json
    echo m6i.2xlarge >"$M/not-in-zone-b"
    section "Zonas: uma zona sem o tipo dos nós"
    check "up termina sem erro" run up --yes
    check "painel nas duas primeiras zonas" jq -e '.availability_zones == ["sa-east-1a","sa-east-1b"]' "$cp_vars"
    check "região pula a zona sem o tipo dos nós" jq -e '.availability_zones == ["sa-east-1a","sa-east-1c"]' "$rg_vars"
    check "zonas da região guardadas" contains "$SIM/state/config.env" 'RG_ZONES=sa-east-1a\ sa-east-1c'
    rm -f "$M/not-in-zone-b"
    check "up de novo termina sem erro" run up --yes
    check "mantém as zonas da rede criada" jq -e '.availability_zones == ["sa-east-1a","sa-east-1c"]' "$rg_vars"

    section "Zonas: tamanho novo sem o tipo numa delas"
    echo m6i.4xlarge >"$M/not-in-zone-c"
    EXTRA=(HOSTREMA_SIZE=padrao)
    fresh_calls
    check "reconfigure para antes de mudar qualquer coisa" fails reconfigure --yes
    EXTRA=()
    check "diz qual zona não tem o tipo" contains "$T_LOG" 'a zona sa-east-1c de sa-east-1, onde esta instalação já está, não tem todos estes tipos: m6i.xlarge m6i.4xlarge'
    check "nenhum apply" not grep -q '^\[terraform\] .* apply ' "$SIM/calls.log"

    section "Zonas: instalação de antes da escolha"
    sandbox zones-legacy
    cp_vars=$M/control-plane.vars.json
    rg_vars=$M/sa-east-1.vars.json
    run up --yes || true
    sed -i.bak -e "s/^CP_ZONES=.*/CP_ZONES=''/" -e "s/^RG_ZONES=.*/RG_ZONES=''/" "$SIM/state/config.env"
    check "up termina sem erro" run up --yes
    check "painel continua nas zonas de antes (padrão do Terraform)" jq -e '.availability_zones == []' "$cp_vars"
    check "região também" jq -e '.availability_zones == []' "$rg_vars"
    check "não guarda zonas que não escolheu" grep -qx "CP_ZONES=''" "$SIM/state/config.env"
    echo m6i.large >"$M/not-in-zone-b"
    check "tipo que falta numa zona de antes para o script" fails up --yes
    check "diz qual zona" contains "$T_LOG" 'a zona sa-east-1b de sa-east-1, onde esta instalação já está, não tem todos estes tipos: m6i.large'

    section "Zonas: menos de duas com o tipo"
    sandbox zones-single
    echo m6i.2xlarge >"$M/only-zone-a"
    check "up para" fails up --yes
    check "explica o que escolher" contains "$T_LOG" 'em sa-east-1, menos de duas zonas têm todos estes tipos: m6i.large m6i.2xlarge'
    check "nada aplicado" not grep -q '^\[terraform\] .* apply ' "$SIM/calls.log"
}

scenario_ses_sandbox() {
    sandbox ses-sandbox
    touch "$M/ses-unverified" "$M/mail-from-fails"
    section "SES ainda em sandbox, owner fora do domínio"
    EXTRA=(HOSTREMA_OWNER_EMAIL=dono@exemplo.com.br)
    check "up termina sem erro" run up --yes
    EXTRA=()
    check "verifica o e-mail do owner no SES" contains "$M/ses-addresses" 'create-email-identity --email-identity dono@exemplo.com.br'
    check "explica o link de verificação" contains "$T_LOG" 'para dono@exemplo.com.br um link de verificação'
    check "avisa do MAIL FROM que não deu" contains "$T_LOG" 'não consegui configurar o MAIL FROM bounce.hostrema-teste.com.br'
    check "diz que a AWS ainda verifica o domínio" contains "$T_LOG" 'a AWS ainda está verificando hostrema-teste.com.br (DKIM)'
    check "info termina sem erro" run info
    check "info mostra a verificação pendente" contains "$T_LOG" 'a AWS ainda está verificando o domínio (DKIM)'
}

scenario_region_optin() {
    sandbox optin
    touch "$M/region-disabled"
    section "Região desligada na conta (opt-in)"
    check "plan termina sem erro" run plan
    check "plan avisa que vai ligar a região" contains "$T_LOG" 'a região sa-east-1 está desligada nesta conta'
    check "plan não liga nada" not test -f "$M/region-enable-requested"
    check "up termina sem erro" run up --yes
    check "liga a região" contains "$M/region-enable-requested" '--region-name sa-east-1'
    check "espera a AWS ligar" contains "$T_LOG" 'região sa-east-1 ligada.'
    check "liga antes de criar o bucket" bash -c "grep -n 'account enable-region\|s3api create-bucket' '$SIM/calls.log' | head -n 1 | grep -q enable-region"
    sandbox optin-disabling
    touch "$M/region-disabling"
    check "região sendo desligada para o script" fails plan
    check "diz para esperar" contains "$T_LOG" 'está sendo desligada nesta conta'
}

for scenario in main_flow route53_domain options credentials login quota zones ses_sandbox region_optin invalid_answers failures new_machine ses_ready not_a_clone; do
    if wanted "$scenario"; then
        "scenario_$scenario"
    fi
done
finish
