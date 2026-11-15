#!/usr/bin/env bash
# Registers an execution node in Coolify and assigns it to a platform region, the same steps
# the coolify-agent Ansible role runs (for nodes provisioned by hand).
#
#   COOLIFY_URL=https://cloud.example.com COOLIFY_TOKEN=... COOLIFY_PRIVATE_KEY_UUID=... \
#   register-node.sh <name> <private ip> <region> [wildcard url] [dedicated team id]
set -euo pipefail

NAME=${1:?usage: register-node.sh <name> <private ip> <region> [wildcard url] [dedicated team id]}
IP=${2:?private ip required}
REGION=${3:?region required}
WILDCARD=${4:-}
DEDICATED=${5:-}
: "${COOLIFY_URL:?set COOLIFY_URL}" "${COOLIFY_TOKEN:?set COOLIFY_TOKEN}" "${COOLIFY_PRIVATE_KEY_UUID:?set COOLIFY_PRIVATE_KEY_UUID}"

api() {
  local method=$1 path=$2 body=${3:-}
  curl -fsS -X "$method" "$COOLIFY_URL/api/v1$path" \
    -H "Authorization: Bearer $COOLIFY_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
    ${body:+--data "$body"}
}

uuid=$(api GET /servers | jq -r --arg ip "$IP" '.[] | select(.ip == $ip) | .uuid' | head -n1)
if [[ -z "$uuid" ]]; then
  body=$(jq -n --arg name "$NAME" --arg ip "$IP" --arg key "$COOLIFY_PRIVATE_KEY_UUID" --arg region "$REGION" \
    '{name: $name, description: ($region + " execution node"), ip: $ip, port: 22, user: "root", private_key_uuid: $key, is_build_server: false, instant_validate: true, proxy_type: "traefik"}')
  uuid=$(api POST /servers "$body" | jq -r '.uuid')
  echo "created server $uuid"
fi

for _ in $(seq 1 60); do
  settings=$(api GET "/servers/$uuid" | jq -c '.settings | {is_reachable, is_usable}')
  if [[ "$settings" == '{"is_reachable":true,"is_usable":true}' ]]; then
    echo "node $NAME ($uuid) is online"
    online=1
    break
  fi
  sleep 15
done

if [[ -z "${online:-}" ]]; then
  echo "node $NAME ($uuid) was not validated in time: check Servers > $NAME in Coolify" >&2
  exit 2
fi

# After the validation, so the tenant networks can be created on the node right away.
body=$(jq -n --arg region "$REGION" --arg wildcard "$WILDCARD" --arg dedicated "$DEDICATED" \
  '{region: $region} + (if $wildcard != "" then {wildcard_domain: $wildcard} else {} end) + (if $dedicated != "" then {dedicated_team_id: ($dedicated | tonumber)} else {} end)')
api POST "/platform/servers/$uuid/region" "$body" >/dev/null
echo "assigned $uuid to region $REGION"

