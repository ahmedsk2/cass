#!/usr/bin/env bash
# Runs ON the production host:
#   ssh -i ~/.ssh/oci_server ubuntu@<host> "CASS_COOLIFY_APP_UUID=<uuid> bash -s" < tools/claude/deploy.sh
# Triggers a Coolify deploy of the CASS application and waits for a NEW healthy app container,
# stopping early if Coolify reports the deployment failed. Then, inside that container as user
# `app`: migrate:status; when anything is pending, cass-backup on the host first (no backup, no
# migration), then migrate --force; then migrate:status again. Then it probes the site through
# Traefik and runs cass:health. Every command's output is shown in full.
#
# DEPLOY_DONE is printed only when all of that passed. Every failure prints one line starting
# DEPLOY_FAILED: and exits with
#   1  missing configuration, or a command failed unexpectedly
#   2  no new healthy app container within CASS_DEPLOY_TIMEOUT seconds
#   3  the deploy was not queued, or Coolify reports it failed or cancelled
#   4  cass-backup failed; nothing was migrated
#   5  migrate:status or migrate --force failed, or a migration is still pending
#   6  a page answered with the wrong HTTP status
#   7  cass:health reported a failure
#
# Needs ~/.coolify-token on the host (mode 0600), python3 and sudo. Environment, with the
# production defaults: CASS_COOLIFY_APP_UUID (required), CASS_PUBLIC_HOST (cass.towardpcc.com),
# CASS_COOLIFY_API (http://localhost:8000/api/v1), CASS_COOLIFY_TOKEN_FILE (~/.coolify-token),
# CASS_BACKUP_CMD (/usr/local/bin/cass-backup), CASS_DEPLOY_TIMEOUT (1500 seconds) and
# CASS_DEPLOY_POLL (20 seconds). tools/claude/deploy.test.sh runs it against fakes.
set -euo pipefail

STEP='starting'
REPORTED=''

# fail <exit code> <reason>
fail() {
    REPORTED=yes
    echo "DEPLOY_FAILED: $2"
    exit "$1"
}

# Whatever set -e stops on gets the same one line, below that command's own error message.
report_unexpected_exit() {
    local rc=$?
    if [ "$rc" -ne 0 ] && [ -z "$REPORTED" ]; then
        echo "DEPLOY_FAILED: stopped by an unexpected exit $rc while $STEP"
        exit 1
    fi
}
trap report_unexpected_exit EXIT

# A failure inside $(...) stops the script too, instead of handing back partial output.
shopt -s inherit_errexit

APP_UUID="${CASS_COOLIFY_APP_UUID:-}"
PUBLIC_HOST="${CASS_PUBLIC_HOST:-cass.towardpcc.com}"
API="${CASS_COOLIFY_API:-http://localhost:8000/api/v1}"
TOKEN_FILE="${CASS_COOLIFY_TOKEN_FILE:-$HOME/.coolify-token}"
BACKUP_CMD="${CASS_BACKUP_CMD:-/usr/local/bin/cass-backup}"
DEPLOY_TIMEOUT="${CASS_DEPLOY_TIMEOUT:-1500}"
DEPLOY_POLL="${CASS_DEPLOY_POLL:-20}"

# Each page and the status it answers with, through Traefik, after a good release.
PROBES=(
    /=200
    /org/login=200
    /review/login=200
    /admin/login=200
    /vendor/livewire/livewire.min.js=200
    /c/nonexistent/none=404
    /q/NOPE=404
    /up=200
)

# POST /deploy answers {"deployments": [{"message", "resource_uuid", "deployment_uuid"}]}, with
# deployment_uuid only when Coolify queued something. Prints this application's, if any.
PY_DEPLOYMENT_UUID='
import json, sys
try:
    body = json.load(sys.stdin)
except ValueError:
    sys.exit(0)
items = body.get("deployments") if isinstance(body, dict) else None
for item in (items if isinstance(items, list) else []):
    if (isinstance(item, dict) and item.get("deployment_uuid")
            and item.get("resource_uuid", sys.argv[1]) == sys.argv[1]):
        sys.stdout.write(str(item["deployment_uuid"]))
        break
'

# GET /deployments/<uuid>: prints its status (queued, in_progress, finished, failed or
# cancelled-by-user) and nothing else, because for this token the body carries the whole
# build log as well.
PY_STATUS='
import json, sys
try:
    status = json.load(sys.stdin).get("status")
except (ValueError, AttributeError):
    sys.exit(1)
sys.stdout.write(str(status or "unknown"))
'

# api <method> <path>: one Coolify API call. The token reaches curl on stdin, never on its
# command line, where any account on the host could read it from the process list.
api() {
    curl -fsS --max-time 60 -X "$1" -H @- "$API$2" <<< "Authorization: Bearer $TOKEN"
}

deployment_status() {
    local body
    body=$(api GET "/deployments/$DEPLOYMENT") || return 1
    python3 -c "$PY_STATUS" <<< "$body"
}

# containers [docker ps options]: name<TAB>status of every container of this application,
# newest first. Coolify names them <service>-<application uuid>-<suffix>.
containers() {
    local listing
    listing=$(sudo docker ps "$@" --format '{{.Names}}\t{{.Status}}') || return 1
    awk -F '\t' -v uuid="$APP_UUID" 'index($1, uuid)' <<< "$listing"
}

# app_container_except <listing> <name>: the first app container in the listing that is not
# <name>, as name<TAB>status.
app_container_except() {
    awk -F '\t' -v prefix="app-${APP_UUID}-" -v not="$2" \
        'index($1, prefix) == 1 && $1 != not && !found { print; found = 1 }' <<< "$1"
}

# php artisan in the new container, as user app, with stderr in the same stream.
artisan() {
    sudo docker exec "$NEW" su-exec app php artisan "$@" 2>&1
}

has_pending() {
    grep -qw Pending <<< "$1"
}

main() {
    STEP='checking the configuration'
    [ -n "$APP_UUID" ] || fail 1 'set CASS_COOLIFY_APP_UUID to the Coolify application uuid'
    if [ ! -f "$TOKEN_FILE" ] || [ ! -r "$TOKEN_FILE" ]; then
        fail 1 "cannot read the Coolify API token file $TOKEN_FILE"
    fi
    TOKEN=$(< "$TOKEN_FILE")
    [ -n "$TOKEN" ] || fail 1 "the Coolify API token file $TOKEN_FILE is empty"
    [[ $DEPLOY_TIMEOUT =~ ^[0-9]+$ && $DEPLOY_POLL =~ ^[0-9]+$ ]] ||
        fail 1 "CASS_DEPLOY_TIMEOUT [$DEPLOY_TIMEOUT] and CASS_DEPLOY_POLL [$DEPLOY_POLL] must be whole seconds"
    local tool
    for tool in curl python3 sudo; do
        command -v "$tool" > /dev/null || fail 1 "$tool is not installed"
    done

    STEP='finding the running app container'
    local listing
    listing=$(containers)
    OLD=$(app_container_except "$listing" '')
    OLD=${OLD%%$'\t'*}
    echo "old container: ${OLD:-none}"

    STEP='queueing the deploy'
    local resp
    resp=$(api POST "/deploy?uuid=$APP_UUID&force=false") ||
        fail 3 'the Coolify deploy call failed (curl error above); check Coolify -> Deployments before running this again'
    echo "deploy response: $resp"
    DEPLOYMENT=$(python3 -c "$PY_DEPLOYMENT_UUID" "$APP_UUID" <<< "$resp") || DEPLOYMENT=''
    [ -n "$DEPLOYMENT" ] ||
        fail 3 'no deployment_uuid for this application in the response above; check Coolify -> Deployments before running this again'
    echo "deployment: $DEPLOYMENT"

    STEP='waiting for the new app container'
    local deadline=$((SECONDS + DEPLOY_TIMEOUT)) cur name state dstatus
    NEW=''
    while [ -z "$NEW" ]; do
        listing=$(containers)
        cur=$(app_container_except "$listing" "$OLD")
        name=${cur%%$'\t'*}
        state=${cur#*$'\t'}
        # A poll that fails is reported and the wait goes on: the deadline still bounds it.
        dstatus=$(deployment_status) || dstatus='unavailable'
        echo "$(date +%T) new=${name:-none} status=${state:-} deployment=$dstatus"
        case "$dstatus" in
            failed | cancelled-by-user)
                fail 3 "Coolify reports deployment $DEPLOYMENT $dstatus; its log is under Coolify -> Deployments" ;;
        esac
        if [ -n "$name" ] && [[ $state == *'(healthy)'* ]]; then
            NEW=$name
        elif [ "$SECONDS" -ge "$deadline" ]; then
            echo "TIMEOUT waiting for a new healthy container"
            containers -a || true
            fail 2 "no new healthy app container within ${DEPLOY_TIMEOUT}s"
        else
            sleep "$DEPLOY_POLL"
        fi
    done
    echo "$(date +%T) NEW HEALTHY: $NEW ($state)"

    STEP='reading the new image'
    echo "=== image ==="
    sudo docker inspect --format '{{.Config.Image}}' "$NEW"

    STEP='checking the migrations'
    echo "=== migrate:status ==="
    local migrations
    migrations=$(artisan migrate:status) || { printf '%s\n' "$migrations"; fail 5 "migrate:status failed in $NEW"; }
    printf '%s\n' "$migrations"
    if has_pending "$migrations"; then
        STEP='taking the backup'
        echo "=== backup: sudo $BACKUP_CMD ==="
        sudo "$BACKUP_CMD" || fail 4 "BACKUP FAILED, no migration run ($BACKUP_CMD exited $?)"
        STEP='migrating'
        echo "=== migrate --force ==="
        artisan migrate --force || fail 5 "migrate --force failed (exit $?); the backup above was taken just before it"
    else
        echo "nothing pending: no backup taken, no migration run"
    fi

    STEP='checking the migrations again'
    echo "=== migrate:status ==="
    migrations=$(artisan migrate:status) || { printf '%s\n' "$migrations"; fail 5 "migrate:status failed in $NEW"; }
    printf '%s\n' "$migrations"
    if has_pending "$migrations"; then
        fail 5 'a migration is still pending after migrate --force'
    fi

    STEP='listing the containers'
    echo "=== containers ==="
    containers -a

    STEP='probing the site'
    echo "=== http checks (via traefik, https) ==="
    local probe path want code wrong=''
    for probe in "${PROBES[@]}"; do
        path=${probe%=*}
        want=${probe##*=}
        # curl prints 000 when it cannot connect, and that is a mismatch like any other.
        code=$(curl -sk --max-time 30 -o /dev/null -w '%{http_code}' \
            --resolve "$PUBLIC_HOST:443:127.0.0.1" "https://$PUBLIC_HOST$path") || true
        echo "$path -> ${code:-000} (expected $want)"
        [ "${code:-000}" = "$want" ] || wrong="$wrong $path"
    done
    [ -z "$wrong" ] || fail 6 "unexpected HTTP status from:$wrong"

    STEP='running cass:health'
    echo "=== health ==="
    artisan cass:health || fail 7 "cass:health reported a failure (exit $?)"

    echo DEPLOY_DONE
}

# The whole run is the one call below. Under `bash -s` the script arrives on the same stdin its
# commands would inherit: this way bash has read all of it before the first command starts, and
# no command can read the rest of it as input.
main "$@" < /dev/null
