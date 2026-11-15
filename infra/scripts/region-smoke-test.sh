#!/usr/bin/env bash
# Validation of a region (wiki 2, "Testes de validação"): edge routers, load balancer,
# control plane edge configuration and an optional application URL.
#
#   PLATFORM_EDGE_TOKEN=... region-smoke-test.sh <coolify url> <region> <lb dns name> "<node ip> ..." [app url]
# Run it from the control node (the node health ports are private).
set -euo pipefail

COOLIFY_URL=${1:?usage: region-smoke-test.sh <coolify url> <region> <lb dns> "<node ips>" [app url]}
REGION=${2:?region required}
LB=${3:?load balancer dns name required}
NODES=${4:?node ips required}
APP_URL=${5:-}
EDGE_HEALTH_PORT=${EDGE_HEALTH_PORT:-10082}
failures=0

check() {
  local label=$1
  shift
  if "$@" >/dev/null 2>&1; then
    echo "ok    $label"
  else
    echo "FAIL  $label"
    failures=$((failures + 1))
  fi
}

check "control plane health" curl -fsS --max-time 10 "$COOLIFY_URL/api/health"
if [[ -n "${PLATFORM_EDGE_TOKEN:-}" ]]; then
  check "edge configuration for $REGION" curl -fsS --max-time 10 -H "Authorization: Bearer $PLATFORM_EDGE_TOKEN" "$COOLIFY_URL/api/v1/platform/edge/$REGION"
fi
for node in $NODES; do
  check "edge router on $node" curl -fsS --max-time 5 "http://$node:$EDGE_HEALTH_PORT/ping"
done
check "load balancer resolves ($LB)" getent hosts "$LB"
check "load balancer answers on :80" curl -sS --max-time 10 -o /dev/null "http://$LB/"
if [[ -n "$APP_URL" ]]; then
  check "application $APP_URL" curl -fsS --max-time 15 -o /dev/null "$APP_URL"
fi

if ((failures > 0)); then
  echo "$failures check(s) failed" >&2
  exit 1
fi
echo "region $REGION looks healthy"
