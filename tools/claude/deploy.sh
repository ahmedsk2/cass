#!/usr/bin/env bash
# Runs ON the production host:
#   ssh -i ~/.ssh/oci_server ubuntu@<host> "CASS_COOLIFY_APP_UUID=<uuid> bash -s" < tools/claude/deploy.sh
# Triggers a Coolify deploy of the CASS application, waits for a NEW healthy app container,
# runs the migrations inside it as user `app`, then probes the site through Traefik.
# Needs ~/.coolify-token on the host (mode 0600) and python3.
set -u
APP_UUID="${CASS_COOLIFY_APP_UUID:?set CASS_COOLIFY_APP_UUID to the Coolify application uuid}"
PUBLIC_HOST="${CASS_PUBLIC_HOST:-cass.towardpcc.com}"
TOKEN=$(cat ~/.coolify-token)
API=http://localhost:8000/api/v1

old=$(sudo docker ps --format '{{.Names}}' | grep "^app-${APP_UUID}-" | head -1)
echo "old container: ${old:-none}"

resp=$(curl -s -H "Authorization: Bearer $TOKEN" "$API/deploy?uuid=$APP_UUID&force=false")
echo "deploy response: $resp"

deadline=$((SECONDS + 1500))
new=""
while [ $SECONDS -lt $deadline ]; do
  cur=$(sudo docker ps --format '{{.Names}}\t{{.Status}}' | grep "^app-${APP_UUID}-" | grep -v "^${old}" | head -1 || true)
  name=${cur%%$'\t'*}
  status=${cur#*$'\t'}
  if [ -n "$name" ] && echo "$status" | grep -q "(healthy)"; then
    new=$name
    echo "$(date +%T) NEW HEALTHY: $new ($status)"
    break
  fi
  echo "$(date +%T) new=${name:-none} status=${status:-}"
  sleep 20
done

if [ -z "$new" ]; then
  echo "TIMEOUT waiting for a new healthy container"
  sudo docker ps -a --format '{{.Names}}\t{{.Status}}' | grep "$APP_UUID"
  exit 2
fi

echo "=== image ==="
sudo docker inspect --format '{{.Config.Image}}' "$new"
echo "=== migrate ==="
sudo docker exec "$new" su-exec app php artisan migrate --force 2>&1 | tail -30
echo "=== containers ==="
sudo docker ps -a --format '{{.Names}}\t{{.Status}}' | grep "$APP_UUID"
echo "=== http checks (via traefik, https) ==="
for p in / /org/login /review/login /admin/login /vendor/livewire/livewire.min.js /c/nonexistent/none /q/NOPE /up; do
  code=$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$PUBLIC_HOST:443:127.0.0.1" "https://$PUBLIC_HOST$p")
  echo "$p -> $code"
done
echo "=== health ==="
sudo docker exec "$new" su-exec app php artisan cass:health 2>&1 | tail -12
echo DEPLOY_DONE
