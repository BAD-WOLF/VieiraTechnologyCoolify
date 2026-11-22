#!/usr/bin/env bash
# Every test of the Hostrema tooling in one command:
#
#   bash infra/test/run.sh           shell scripts + infrastructure checks + platform PHP suite
#   bash infra/test/run.sh scripts   only the shell scripts and the infrastructure checks
#   bash infra/test/run.sh php       only the platform PHP suite (tests/Feature/Platform)
#
# Shell scripts: shellcheck of every script, the suites of infra/prod/hostrema-prod.sh and
# infra/local/hostrema-local.sh (stand-ins, nothing leaves the machine), the boot script of the
# dashboard rendered from its Terraform template, the HCL syntax (python-hcl2, when installed) and
# ansible-lint (when installed). The PHP suite needs the test database of phpunit.xml (DB_TEST_HOST,
# DB_TEST_DATABASE, REDIS_HOST in the environment when they are not the defaults).
set -uo pipefail

REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$REPO" || exit 1
WHAT=${1:-all}
FAILED=()

step() {
    local name=$1
    shift
    printf '\n\033[1;34m==> %s\033[0m\n' "$name"
    if "$@"; then
        printf '\033[1;32m    ok: %s\033[0m\n' "$name"
    else
        printf '\033[1;31m    FALHOU: %s\033[0m\n' "$name"
        FAILED+=("$name")
    fi
}

skip() { printf '\n\033[1;33m==> %s: pulado (%s)\033[0m\n' "$1" "$2"; }

shell_scripts() {
    {
        git ls-files -co --exclude-standard -- '*.sh'
        echo infra/ansible/roles/tenant-limits/files/hostrema-tenant-limits
    } | grep -E '^(infra/|docker/platform/)' | grep -v '/node_modules/' | sort -u
}

run_shellcheck() {
    # shellcheck disable=SC2046 # one argument per file
    shellcheck -x $(shell_scripts)
}

# The boot script of the dashboard instances is a Terraform template: render it with sample values
# and check the shell it becomes.
boot_template() {
    local rendered
    rendered=$(mktemp)
    python3 -I - "$REPO/infra/terraform/control-plane/templates/bootstrap.sh.tftpl" >"$rendered" <<'PY' || return 1
import re, sys
text = open(sys.argv[1]).read()
lines = [line for line in text.split('\n') if not re.match(r'^%\{.*\}$', line.strip())]
text = '\n'.join(lines).replace('$${', '\x00')
text = re.sub(r'\$\{(\w+)\}', lambda m: 'VALUE_' + m.group(1), text)
print(text.replace('\x00', '${'))
PY
    bash -n "$rendered" && shellcheck -S warning "$rendered"
    local status=$?
    rm -f "$rendered"
    return $status
}

hcl_syntax() {
    python3 -I - <<'PY'
import glob, sys
import hcl2
bad = 0
for path in sorted(glob.glob('infra/terraform/**/*.tf', recursive=True)):
    if '/.terraform/' in path or path.endswith('_override.tf'):
        continue
    try:
        with open(path) as handle:
            hcl2.load(handle)
    except Exception as error:
        bad += 1
        print(f'{path}: {error}')
sys.exit(1 if bad else 0)
PY
}

ansible_checks() {
    (
        cd infra/ansible || exit 1
        export ANSIBLE_CONFIG="$PWD/ansible.cfg"
        ansible-playbook --syntax-check -i inventory/example.yml playbooks/site.yml >/dev/null &&
            ansible-lint --offline playbooks roles
    )
}

php_suite() {
    php artisan test tests/Feature/Platform --compact
}

# Arquivos do Coolify oficial que o fork altera: a lista em docs/security-model.md precisa
# acompanhar (o CI faz a mesma conferência contra origin/v4.x).
upstream_files() {
    bash infra/scripts/upstream-touched-files.sh --check docs/security-model.md origin/v4.x
}

if [ "$WHAT" = all ] || [ "$WHAT" = scripts ]; then
    if command -v shellcheck >/dev/null 2>&1; then
        step "shellcheck dos scripts" run_shellcheck
        step "script de boot do painel (template do Terraform)" boot_template
    else
        skip "shellcheck" "instale com: pip install shellcheck-py"
    fi
    step "hostrema-prod.sh (todos os cenários)" bash infra/prod/test/simulate.sh
    step "hostrema-local.sh (todos os cenários)" bash infra/local/test/run.sh
    if python3 -c 'import hcl2' 2>/dev/null; then
        step "sintaxe do Terraform (HCL)" hcl_syntax
    else
        skip "sintaxe do Terraform" "instale com: pip install python-hcl2 (o CI roda terraform validate)"
    fi
    if command -v ansible-lint >/dev/null 2>&1; then
        step "Ansible (sintaxe e ansible-lint)" ansible_checks
    else
        skip "Ansible" "instale com: pip install ansible ansible-lint (o CI roda)"
    fi
    if git rev-parse --verify --quiet origin/v4.x >/dev/null; then
        step "arquivos do Coolify alterados (docs/security-model.md)" upstream_files
    else
        skip "arquivos do Coolify alterados" "busque o oficial com: git fetch origin v4.x (o CI confere)"
    fi
fi

if [ "$WHAT" = all ] || [ "$WHAT" = php ]; then
    if command -v php >/dev/null 2>&1 && [ -f vendor/autoload.php ]; then
        step "plataforma (PHP, tests/Feature/Platform)" php_suite
    else
        skip "plataforma (PHP)" "precisa do PHP e do composer install"
    fi
fi

printf '\n'
if [ "${#FAILED[@]}" -gt 0 ]; then
    printf '\033[1;31m%d etapa(s) falharam:\033[0m\n' "${#FAILED[@]}"
    printf '  - %s\n' "${FAILED[@]}"
    exit 1
fi
printf '\033[1;32mTudo passou.\033[0m\n'
