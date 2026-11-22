# Small test harness shared by the tests of the shell scripts (infra/prod/test, infra/local/test).
# Source it, call `section` and `check` (or `expect_*`), and end with `finish`.
# shellcheck shell=bash

T_PASSED=0
T_FAILED=0
T_FAILURES=()
T_SECTION=''
T_LOG=''

section() {
    T_SECTION=$1
    printf '\n%s\n' "$1"
}

# check "descrição" comando...: passes when the command succeeds.
check() {
    local what=$1
    shift
    if "$@" >/dev/null 2>&1; then
        T_PASSED=$((T_PASSED + 1))
        printf '  ok     %s\n' "$what"
    else
        T_FAILED=$((T_FAILED + 1))
        T_FAILURES+=("$T_SECTION: $what")
        printf '  FALHA  %s\n' "$what"
        if [ -n "$T_LOG" ] && [ -f "$T_LOG" ]; then
            printf '         (últimas linhas de %s)\n' "$T_LOG"
            tail -n 15 "$T_LOG" | sed 's/^/         | /'
        fi
    fi
}

not() { ! "$@"; }

# contains <arquivo> <texto>: the file has the text (fixed string).
contains() { grep -qF -- "$2" "$1"; }

# matches <arquivo> <regex>: the file has a line matching the extended regex.
matches() { grep -qE -- "$2" "$1"; }

# count_lines <arquivo> <regex>: how many lines match.
count_lines() { grep -cE -- "$2" "$1" 2>/dev/null || true; }

# equals <a> <b>
equals() { [ "$1" = "$2" ]; }

finish() {
    printf '\n%d verificações passaram, %d falharam.\n' "$T_PASSED" "$T_FAILED"
    if [ "$T_FAILED" -gt 0 ]; then
        printf 'Falharam:\n'
        printf '  - %s\n' "${T_FAILURES[@]}"
        exit 1
    fi
}
