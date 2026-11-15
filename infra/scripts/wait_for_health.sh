#!/usr/bin/env bash
# Waits until an URL answers with a 2xx/3xx status (wiki 5, post-deploy validation).
#   wait_for_health.sh https://app.example.com/health [timeout_seconds] [interval_seconds]
set -euo pipefail

URL=${1:?usage: wait_for_health.sh <url> [timeout_seconds] [interval_seconds]}
TIMEOUT=${2:-120}
INTERVAL=${3:-2}
deadline=$((SECONDS + TIMEOUT))

while ((SECONDS < deadline)); do
  if curl -fsS --max-time 10 -o /dev/null "$URL"; then
    echo "healthy: $URL"
    exit 0
  fi
  sleep "$INTERVAL"
done

echo "timeout waiting for $URL" >&2
exit 2
