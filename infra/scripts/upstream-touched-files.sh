#!/usr/bin/env bash
# Files of the official Coolify changed by the platform: the only places where pulling upstream
# can conflict (wiki 9, "Atualizações Upstream"). Grouped like docs/security-model.md.
#
#   infra/scripts/upstream-touched-files.sh [ref]                     # print the list
#   infra/scripts/upstream-touched-files.sh --write <doc> [ref]       # update the block of the doc
#   infra/scripts/upstream-touched-files.sh --check <doc> [ref]       # CI: fail when the doc is outdated
#
# ref: upstream branch already fetched (default upstream/v4.x, then origin/v4.x).
set -euo pipefail

MODE=print
DOC=''
if [[ ${1:-} == --write || ${1:-} == --check ]]; then
  MODE=${1#--}
  DOC=${2:?usage: upstream-touched-files.sh --write|--check <doc> [ref]}
  shift 2
fi
REF=${1:-}
if [[ -z $REF ]]; then
  if git rev-parse --verify -q upstream/v4.x >/dev/null; then REF=upstream/v4.x; else REF=origin/v4.x; fi
fi
BASE=$(git merge-base HEAD "$REF")

render() {
  # Working tree against the upstream base (in CI the checkout is HEAD).
  git diff --no-renames --numstat "$BASE" | while IFS=$'\t' read -r added removed path; do
    git cat-file -e "$BASE:$path" 2>/dev/null || continue
    case $path in
      app/*) group=$(cut -d/ -f1-2 <<< "$path") ;;
      *) group=$(cut -d/ -f1 <<< "$path") ;;
    esac
    [[ $group == "$path" ]] && group=.
    printf '%s\t%s\t%s\t%s\n' "$group" "${path#"$group"/}" "$added" "$removed"
  done | sort -t $'\t' -k1,1 -k2,2 | awk -F '\t' '
    $1 != group { if (group != "") print line; group = $1; line = "- `" ($1 == "." ? "/" : $1) "`: "; sep = "" }
    { line = line sep "`" $2 "` (+" $3 "/-" $4 ")"; sep = ", "; total++ }
    END { if (group != "") print line; print ""; print "Total: " total " arquivos." }'
}

block=$(render)
if [[ $MODE == print ]]; then
  printf '%s\n' "$block"
  exit 0
fi

START='<!-- upstream-files:start -->'
END='<!-- upstream-files:end -->'
if ! grep -qF "$START" "$DOC" || ! grep -qF "$END" "$DOC"; then
  echo "markers $START/$END not found in $DOC" >&2
  exit 2
fi
updated=$(mktemp)
awk -v start="$START" -v end="$END" -v block="$block" '
  $0 == start { print; print block; skip = 1; next }
  $0 == end { skip = 0 }
  !skip { print }' "$DOC" > "$updated"

if [[ $MODE == write ]]; then
  mv "$updated" "$DOC"
  exit 0
fi
if ! diff -u "$DOC" "$updated"; then
  rm -f "$updated"
  echo "The platform changed files of the official Coolify that are not listed in $DOC." >&2
  echo "Keep the change in own files when possible, otherwise run: infra/scripts/upstream-touched-files.sh --write $DOC" >&2
  exit 1
fi
rm -f "$updated"
