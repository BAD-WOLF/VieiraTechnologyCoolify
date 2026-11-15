#!/usr/bin/env bash
# Copies an image between registries without pulling it locally (wiki 2 and 5, skopeo).
#   promote-to-mirror.sh <source image> <destination image> [<destination image> ...]
# Credentials: SRC_CREDS / DEST_CREDS as "user:password" (optional), DEST_CERT_DIR for a
# private CA (e.g. /opt/registry/certs on the region control node).
set -euo pipefail

SRC=${1:?usage: promote-to-mirror.sh <source> <destination> [<destination> ...]}
shift
if (($# == 0)); then
  echo "at least one destination is required" >&2
  exit 1
fi

args=(--retry-times 3 --all)
if [[ -n "${SRC_CREDS:-}" ]]; then
  args+=(--src-creds "$SRC_CREDS")
fi
if [[ -n "${DEST_CREDS:-}" ]]; then
  args+=(--dest-creds "$DEST_CREDS")
fi
if [[ -n "${DEST_CERT_DIR:-}" ]]; then
  args+=(--dest-cert-dir "$DEST_CERT_DIR")
else
  args+=(--dest-tls-verify=true)
fi

for DST in "$@"; do
  echo "copying docker://$SRC -> docker://$DST"
  skopeo copy "${args[@]}" "docker://$SRC" "docker://$DST"
done
