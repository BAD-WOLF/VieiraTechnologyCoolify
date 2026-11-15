#!/usr/bin/env bash
# Upgrades the Coolify control plane to a platform image with automatic rollback
# (wiki 5, "Atualizações controladas do core (canary update do control-plane + rollback)").
# Runs on the control plane host (on each instance of a cluster, one at a time):
#   upgrade-control-plane.sh ghcr.io/<owner>/hostrema:platform-v1.2.3 [health url]
#   upgrade-control-plane.sh --rollback [health url]   # back to the image before the last upgrade
#
# The image is set in /data/coolify/source/docker-compose.custom.yml, the override file the
# Coolify upgrade script already honours, so the upstream compose files stay untouched.
# On a cluster instance (/etc/hostrema/control-plane.env, written by the Terraform bootstrap),
# the .env is read again from Parameter Store (so changes there, such as an APP_KEY rotation,
# reach every instance with the next rollout) and the deployed image is recorded there, so
# instances created later start with it.
set -euo pipefail

if [[ ${1:-} == --rollback ]]; then
  MODE=rollback
  shift
else
  MODE=upgrade
  IMAGE=${1:?usage: upgrade-control-plane.sh <image>|--rollback [health url]}
  shift
fi
HEALTH_URL=${1:-http://127.0.0.1:8000/api/health}
SOURCE_DIR=${SOURCE_DIR:-/data/coolify/source}
CLUSTER_CONF=${CLUSTER_CONF:-/etc/hostrema/control-plane.env}
CUSTOM="$SOURCE_DIR/docker-compose.custom.yml"
PREVIOUS_FILE="$SOURCE_DIR/.hostrema-previous-image"
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

refresh_env() {
  local AWS_REGION='' ENV_PARAMETER='' ROLE='' tmp
  [[ -f "$CLUSTER_CONF" ]] || return 0
  # shellcheck disable=SC1090
  source "$CLUSTER_CONF"
  [[ -n "$ENV_PARAMETER" ]] || return 0
  tmp=$(mktemp)
  if ! aws ssm get-parameter --region "$AWS_REGION" --name "$ENV_PARAMETER" --with-decryption \
    --query Parameter.Value --output text > "$tmp"; then
    echo "warning: could not read $ENV_PARAMETER, keeping the current .env" >&2
    rm -f "$tmp"
    return 0
  fi
  sed -i '/^HORIZON_ENABLED=/d; /^SCHEDULER_ENABLED=/d' "$tmp"
  case "$ROLE" in
    web) printf 'HORIZON_ENABLED=false\nSCHEDULER_ENABLED=false\n' >> "$tmp" ;;
    worker) printf 'HORIZON_ENABLED=true\nSCHEDULER_ENABLED=true\n' >> "$tmp" ;;
  esac
  install -m 600 "$tmp" "$SOURCE_DIR/.env"
  rm -f "$tmp"
}

record_image() {
  local image=$1 AWS_REGION='' IMAGE_PARAMETER=''
  [[ -f "$CLUSTER_CONF" ]] || return 0
  # shellcheck disable=SC1090
  source "$CLUSTER_CONF"
  [[ -n "$IMAGE_PARAMETER" ]] || return 0
  aws ssm put-parameter --region "$AWS_REGION" --name "$IMAGE_PARAMETER" --type String \
    --overwrite --value "$image" >/dev/null ||
    echo "warning: could not record $image in $IMAGE_PARAMETER" >&2
}

previous=$(grep -m1 '^    image:' "$CUSTOM" 2>/dev/null | sed -E 's/^    image: "?([^"]*)"?/\1/' || true)
if [[ $MODE == rollback ]]; then
  if [[ ! -s "$PREVIOUS_FILE" ]]; then
    echo "no previous image recorded in $PREVIOUS_FILE" >&2
    exit 1
  fi
  IMAGE=$(<"$PREVIOUS_FILE")
fi
echo "current image: ${previous:-upstream default}"
echo "pulling $IMAGE"
docker pull "$IMAGE"

refresh_env
set_image "$IMAGE"
compose up -d --no-deps --force-recreate coolify
if "$HERE/wait_for_health.sh" "$HEALTH_URL" 300 5; then
  if [[ $MODE == rollback ]]; then
    rm -f "$PREVIOUS_FILE"
  elif [[ -n "$previous" && "$previous" != "$IMAGE" ]]; then
    printf '%s\n' "$previous" > "$PREVIOUS_FILE"
  fi
  record_image "$IMAGE"
  echo "control plane running $IMAGE"
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
