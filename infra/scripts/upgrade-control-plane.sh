#!/usr/bin/env bash
# Upgrades the Coolify control plane to a platform image with automatic rollback
# (wiki 5, "Atualizações controladas do core (canary update do control-plane + rollback)").
# Runs on the control plane host:
#   upgrade-control-plane.sh ghcr.io/<owner>/vieiratechnologycoolify:platform-v1.2.3 [health url]
#
# The image is set in /data/coolify/source/docker-compose.custom.yml, the override file the
# Coolify upgrade script already honours, so the upstream compose files stay untouched.
set -euo pipefail

IMAGE=${1:?usage: upgrade-control-plane.sh <image> [health url]}
HEALTH_URL=${2:-http://127.0.0.1:8000/api/health}
SOURCE_DIR=${SOURCE_DIR:-/data/coolify/source}
CUSTOM="$SOURCE_DIR/docker-compose.custom.yml"
HERE=$(cd "$(dirname "$0")" && pwd)

compose() {
  docker compose --env-file "$SOURCE_DIR/.env" -f "$SOURCE_DIR/docker-compose.yml" \
    -f "$SOURCE_DIR/docker-compose.prod.yml" -f "$CUSTOM" "$@"
}

set_image() {
  local image=$1
  if [[ -f "$CUSTOM" ]] && grep -q '^    image:' "$CUSTOM"; then
    sed -i "0,/^    image:.*/s##    image: \"$image\"#" "$CUSTOM"
  else
    printf 'services:\n  coolify:\n    image: "%s"\n' "$image" > "$CUSTOM"
  fi
}

previous=$(grep -m1 '^    image:' "$CUSTOM" 2>/dev/null | sed -E 's/^    image: "?([^"]*)"?/\1/' || true)
echo "current image: ${previous:-upstream default}"
echo "pulling $IMAGE"
docker pull "$IMAGE"

set_image "$IMAGE"
compose up -d --no-deps --force-recreate coolify
if "$HERE/wait_for_health.sh" "$HEALTH_URL" 300 5; then
  echo "control plane upgraded to $IMAGE"
  exit 0
fi

echo "health check failed, rolling back" >&2
if [[ -n "$previous" ]]; then
  set_image "$previous"
else
  rm -f "$CUSTOM"
  printf 'services: {}\n' > "$CUSTOM"
fi
compose up -d --no-deps --force-recreate coolify
"$HERE/wait_for_health.sh" "$HEALTH_URL" 300 5 || true
exit 1
