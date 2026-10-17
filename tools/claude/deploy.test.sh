#!/usr/bin/env bash
# Tests for tools/claude/deploy.sh, the release script that runs on the
# production host. It is what says a release is done, so how it ends is pinned
# here: a clean release with and without a pending migration, and each failure
# that must stop it before DEPLOY_DONE, with its own exit code.
#
# Runs with no host, no docker, no Coolify and no network. Fakes for sudo,
# docker, curl, sleep and cass-backup go first on PATH. Each one answers from
# the current scenario's directory ($FAKE) and appends every call it receives
# to $FAKE/calls, in order, so a test can say "the backup ran before migrate
# --force". The script is fed to `bash -s` on stdin, as it is on the host.
#
# Usage: bash tools/claude/deploy.test.sh
set -eu

HERE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
SCRIPT="$HERE/deploy.sh"
ROOT="$(mktemp -d "${TMPDIR:-/tmp}/cass-deploy-test.XXXXXX")"
trap 'rm -rf "$ROOT"' EXIT INT TERM

FAILURES=0
ok()  { printf '  ok    %s\n' "$1"; }
bad() { printf '  FAIL  %s\n' "$1" >&2; FAILURES=$((FAILURES + 1)); }

# The host the fakes describe. Coolify names a Docker Compose application's
# containers <service>-<application uuid>-<suffix>.
APP=vag136azl5q2bv87qqzgblo1
OLD="app-$APP-084500000001"
NEW="app-$APP-091500000002"
DB="mysql-$APP-084500000001"
OTHER=app-zz9otherapplication00000-1
DEPLOYMENT=d0e1f2a3b4c5d6e7f8a9b0c1
TOKEN=api-test-token-7f3a91c2

mkdir -p "$ROOT/bin" "$ROOT/home"
printf '%s\n' "$TOKEN" > "$ROOT/home/.coolify-token"

# ---------------------------------------------------------------------------
# The fakes.
# ---------------------------------------------------------------------------

cat > "$ROOT/bin/sudo" <<'SHIM'
#!/bin/sh
# Fake sudo: runs its arguments, and tells them they came through sudo.
FAKE_SUDO=yes
export FAKE_SUDO
exec "$@"
SHIM

cat > "$ROOT/bin/sleep" <<'SHIM'
#!/bin/sh
# Fake sleep: the pauses between polls are not what is under test.
exit 0
SHIM

cat > "$ROOT/bin/cass-backup" <<'SHIM'
#!/bin/sh
# Fake cass-backup: records that it ran and whether through sudo, and fails
# when the scenario holds a non-zero backup-rc.
set -eu
printf 'backup%s\n' "${FAKE_SUDO:+ via sudo}" >> "$FAKE/calls"
echo '[cass-backup] mysql container: mysql-vag136azl5q2bv87qqzgblo1-084500000001'
rc="$(cat "$FAKE/backup-rc" 2>/dev/null || echo 0)"
if [ "$rc" -ne 0 ]; then
    echo '[cass-backup] mysqldump failed' >&2
    exit "$rc"
fi
echo '[cass-backup] /srv/backups/cass/cass-2026-10-17-1200.sql.gz (5242880 bytes), keeping 14 days'
SHIM

cat > "$ROOT/bin/docker" <<'SHIM'
#!/bin/sh
# Fake docker: ps, inspect and exec, answered from the scenario in $FAKE.
set -eu
log()  { printf '%s\n' "$*" >> "$FAKE/calls"; }
knob() { cat "$FAKE/$1" 2>/dev/null || printf '%s\n' "$2"; }

sub="${1:-}"
if [ "$#" -gt 0 ]; then shift; fi

case "$sub" in
ps)
    # ps.0 is the host before the deploy is queued; ps.1, ps.2, ... are what
    # each later call sees, and the last one repeats. Real docker turns the
    # \t in --format into a tab, so the files hold name<TAB>status.
    status=no
    for a in "$@"; do
        case "$a" in *'{{.Status}}'*) status=yes ;; esac
    done
    if [ -f "$FAKE/triggered" ]; then
        n=$(( $(cat "$FAKE/polls") + 1 ))
        echo "$n" > "$FAKE/polls"
        while [ "$n" -gt 1 ] && [ ! -f "$FAKE/ps.$n" ]; do n=$((n - 1)); done
        list="$FAKE/ps.$n"
    else
        list="$FAKE/ps.0"
    fi
    log "docker ps"
    if [ "$status" = yes ]; then cat "$list"; else cut -f1 "$list"; fi
    ;;

inspect)
    name=''
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --format|-f) shift 2 ;;
            *) name="$1"; shift ;;
        esac
    done
    log "docker inspect $name"
    if [ -f "$FAKE/inspect-fails" ]; then
        echo "Error response from daemon: No such object: $name" >&2
        exit 1
    fi
    echo 'vag136azl5q2bv87qqzgblo1-app:c0ffee5e1ec7ed0000000000000000000000beef'
    ;;

exec)
    name="${1:-}"
    if [ "$#" -gt 0 ]; then shift; fi
    log "docker exec $name $*"
    cmd=''
    seen=no
    for a in "$@"; do
        if [ "$seen" = yes ]; then cmd="$a"; break; fi
        if [ "$a" = artisan ]; then seen=yes; fi
    done
    case "$cmd" in
    migrate:status)
        echo '  Migration name ............................................... Batch / Status'
        echo '  0001_01_01_000000_create_users_table ............................... [1] Ran'
        if [ -f "$FAKE/pending" ]; then
            echo '  2026_10_20_000000_create_widgets_table ............................. Pending'
        else
            echo '  2026_10_20_000000_create_widgets_table ............................. [2] Ran'
        fi
        ;;
    migrate)
        # Forty-one lines, the first one marked, so output cut short shows.
        echo '   INFO  Running migrations. (first line of the migrate output)'
        i=1
        while [ "$i" -le 40 ]; do
            printf '  2026_10_20_%06d_step_%02d ..................................... 3ms DONE\n' "$i" "$i"
            i=$((i + 1))
        done
        rc="$(knob migrate-rc 0)"
        if [ "$rc" -ne 0 ]; then
            printf '%s\n' '   Illuminate\Database\QueryException  SQLSTATE[42S01]: Base table or view already exists' >&2
            exit "$rc"
        fi
        if [ ! -f "$FAKE/migrate-keeps-pending" ]; then rm -f "$FAKE/pending"; fi
        ;;
    cass:health)
        # Fifteen lines, the first one marked, so output cut short shows.
        rc="$(knob health-rc 0)"
        echo '+--------------+--------+--------------------------------------+ (first line of the cass:health output)'
        echo '| Check        | Status | Detail                               |'
        echo '+--------------+--------+--------------------------------------+'
        echo '| database     | OK     | connection [mysql] answers           |'
        echo '| migrations   | OK     | 37 applied, none pending             |'
        if [ "$rc" -eq 0 ]; then
            echo '| queue        | OK     | no job waiting                       |'
        else
            echo '| queue        | FAIL   | oldest waiting job is 3 hours old    |'
        fi
        echo '| scheduler    | OK     | last heartbeat 2 minutes ago         |'
        echo '| private disk | OK     | wrote, read and deleted a probe file |'
        echo '| mail         | OK     | mailer [smtp]                        |'
        echo '+--------------+--------+--------------------------------------+'
        i=1
        while [ "$i" -le 5 ]; do echo "  (note $i)"; i=$((i + 1)); done
        exit "$rc"
        ;;
    *)
        echo "fake docker: unexpected exec [$*]" >&2
        exit 2
        ;;
    esac
    ;;

*)
    echo "fake docker: unsupported subcommand [$sub]" >&2
    exit 2
    ;;
esac
SHIM

cat > "$ROOT/bin/curl" <<'SHIM'
#!/bin/sh
# Fake curl: the two Coolify API calls (POST /deploy, GET /deployments/<uuid>)
# and the page probes, answered from the scenario in $FAKE. Its command line
# goes to $FAKE/curl-argv and every header it is given to $FAKE/curl-headers,
# so a test can check where the token travelled.
set -eu
log()  { printf '%s\n' "$*" >> "$FAKE/calls"; }
knob() { cat "$FAKE/$1" 2>/dev/null || printf '%s\n' "$2"; }

printf '%s\n' "$*" >> "$FAKE/curl-argv"
method=GET
url=''
wfmt=''
fail=no
while [ "$#" -gt 0 ]; do
    case "$1" in
        -X|--request) method="$2"; shift 2 ;;
        -H|--header)
            if [ "$2" = @- ]; then
                cat >> "$FAKE/curl-headers"
            else
                printf '%s\n' "$2" >> "$FAKE/curl-headers"
            fi
            shift 2 ;;
        -w|--write-out) wfmt="$2"; shift 2 ;;
        -o|--output|--resolve|-m|--max-time|--connect-timeout|-d|--data) shift 2 ;;
        --fail) fail=yes; shift ;;
        --*) shift ;;
        -*) case "$1" in *f*) fail=yes ;; esac; shift ;;
        *) url="$1"; shift ;;
    esac
done

case "$url" in
*/deploy\?*)
    : > "$FAKE/triggered"
    log "curl $method $url"
    http="$(knob deploy-http 200)"
    if [ "$http" != 200 ]; then
        # What real curl does with an error status: exit 22 under -f, and
        # otherwise print the body and exit 0.
        if [ "$fail" = yes ]; then
            echo "curl: (22) The requested URL returned error: $http" >&2
            exit 22
        fi
        echo '{"message":"Unauthenticated."}'
        exit 0
    fi
    cat "$FAKE/deploy-response"
    ;;
*/deployments/*)
    n=$(( $(cat "$FAKE/status-polls") + 1 ))
    echo "$n" > "$FAKE/status-polls"
    while [ "$n" -gt 1 ] && [ ! -f "$FAKE/status.$n" ]; do n=$((n - 1)); done
    status="$(cat "$FAKE/status.$n")"
    log "curl $method $url -> $status"
    if [ "$status" = unreachable ]; then
        echo 'curl: (7) Failed to connect to localhost port 8000: Could not connect to server' >&2
        exit 7
    fi
    # To a token that may read secrets, as the host's may, Coolify sends the
    # whole build log along with the status.
    printf '{"id":7,"deployment_uuid":"%s","status":"%s","logs":"BUILD-LOG: composer install, npm ci, the whole build"}\n' "${url##*/}" "$status"
    ;;
*)
    rest="${url#*://}"
    case "$rest" in */*) path="/${rest#*/}" ;; *) path=/ ;; esac
    code="$(awk -v p="$path" '$1 == p { print $2 }' "$FAKE/codes")"
    log "curl probe $path -> ${code:-none}"
    if [ -n "$wfmt" ]; then printf '%s' "${code:-000}"; fi
    ;;
esac
SHIM

chmod +x "$ROOT/bin/sudo" "$ROOT/bin/sleep" "$ROOT/bin/cass-backup" "$ROOT/bin/docker" "$ROOT/bin/curl"

# Every fake must win the PATH lookup, or the script would reach the real sudo,
# docker and curl. A temporary directory whose path holds a colon splits PATH.
for f in sudo sleep docker curl; do
    if [ "$(PATH="$ROOT/bin:$PATH"; command -v "$f")" != "$ROOT/bin/$f" ]; then
        echo "deploy.test.sh: the fake $f in $ROOT/bin is not first on PATH; refusing to run" >&2
        exit 1
    fi
done

# deploy.sh calls python3. Where that name is missing or is not Python 3 (on
# Windows it is a Microsoft Store stub), point it at `python`.
if ! python3 -c 'import sys; sys.exit(sys.version_info[0] != 3)' > /dev/null 2>&1; then
    if python -c 'import sys; sys.exit(sys.version_info[0] != 3)' > /dev/null 2>&1; then
        printf '#!/bin/sh\nexec python "$@"\n' > "$ROOT/bin/python3"
        chmod +x "$ROOT/bin/python3"
    else
        echo 'deploy.test.sh: needs python3' >&2
        exit 1
    fi
fi

# A script that loops forever fails its scenario instead of hanging the run.
WATCHDOG=''
if command -v timeout > /dev/null 2>&1; then WATCHDOG='timeout 30'; fi

# ---------------------------------------------------------------------------
# Scenarios and assertions.
# ---------------------------------------------------------------------------

N=0
# scenario <title>: a fresh $S holding a clean release with nothing pending;
# each test then changes only what it is about.
scenario() {
    N=$((N + 1))
    S="$ROOT/s$N"
    mkdir -p "$S"
    echo "  - $1"
    # The other application's healthy container and the old container come
    # first on purpose: a script that takes the first healthy app container it
    # sees picks the wrong one.
    printf '%s\t%s\n' \
        "$OTHER" 'Up 2 days (healthy)' \
        "$OLD" 'Up 3 weeks (healthy)' \
        "$DB" 'Up 3 weeks (healthy)' > "$S/ps.0"
    printf '%s\t%s\n' \
        "$OTHER" 'Up 2 days (healthy)' \
        "$OLD" 'Up 3 weeks (healthy)' \
        "$NEW" 'Up 4 seconds (health: starting)' \
        "$DB" 'Up 3 weeks (healthy)' > "$S/ps.1"
    printf '%s\t%s\n' \
        "$OTHER" 'Up 2 days (healthy)' \
        "$NEW" 'Up 41 seconds (healthy)' \
        "$DB" 'Up 3 weeks (healthy)' > "$S/ps.2"
    echo in_progress > "$S/status.1"
    echo finished > "$S/status.2"
    printf '{"deployments":[{"message":"Application cass deployment queued.","resource_uuid":"%s","deployment_uuid":"%s"}]}\n' \
        "$APP" "$DEPLOYMENT" > "$S/deploy-response"
    printf '%s\n' '/ 200' '/org/login 200' '/review/login 200' '/admin/login 200' \
        '/vendor/livewire/livewire.min.js 200' '/c/nonexistent/none 404' '/q/NOPE 404' \
        '/up 200' > "$S/codes"
    echo 0 > "$S/polls"
    echo 0 > "$S/status-polls"
    : > "$S/calls"
    : > "$S/curl-argv"
    : > "$S/curl-headers"
}

# Coolify queued nothing: the host stays as it was.
nothing_deploys() {
    cp "$S/ps.0" "$S/ps.1"
    rm -f "$S/ps.2"
}

# run_deploy [VAR=VALUE ...]: runs the script against the fakes. Sets RC;
# the output (stdout and stderr) is $S/out.
run_deploy() {
    RC=0
    env PATH="$ROOT/bin:$PATH" HOME="$ROOT/home" FAKE="$S" \
        CASS_COOLIFY_APP_UUID="$APP" \
        CASS_COOLIFY_API=http://coolify.test/api/v1 \
        CASS_COOLIFY_TOKEN_FILE="$ROOT/home/.coolify-token" \
        CASS_PUBLIC_HOST=cass.test \
        CASS_BACKUP_CMD="$ROOT/bin/cass-backup" \
        CASS_DEPLOY_TIMEOUT=5 \
        CASS_DEPLOY_POLL=0 \
        "$@" \
        $WATCHDOG bash -s < "$SCRIPT" > "$S/out" 2>&1 || RC=$?
}

show() { tail -n 40 "$1" | sed 's/^/          | /'; }

# eq <label> <expected> <actual>
eq() {
    if [ "$2" = "$3" ]; then ok "$1"; else bad "$1
          expected: [$2]
          actual:   [$3]
$(show "$S/out")"; fi
}

# has / lacks <label> <text>: whether the script's output contains <text>.
has() {
    if grep -qF -- "$2" "$S/out"; then ok "$1"; else bad "$1: [$2] is not in the output
$(show "$S/out")"; fi
}
lacks() {
    if grep -qF -- "$2" "$S/out"; then bad "$1: [$2] is in the output
$(show "$S/out")"; else ok "$1"; fi
}

# The number of DEPLOY_FAILED lines: exactly one on every failure path.
failed_lines() { grep -c '^DEPLOY_FAILED: ' "$S/out" || true; }

# count <text>: how many recorded calls contain <text>. first / last <text>:
# the position of the first or last such call, 0 when there is none.
count() { awk -v s="$1" 'index($0, s) { n++ } END { print n + 0 }' "$S/calls"; }
first() { awk -v s="$1" 'index($0, s) { print NR; found = 1; exit } END { if (!found) print 0 }' "$S/calls"; }
last()  { awk -v s="$1" 'index($0, s) { n = NR } END { print n + 0 }' "$S/calls"; }

# in_order <label> <position> <position>: both calls happened, in that order.
in_order() {
    if [ "$2" -gt 0 ] && [ "$3" -gt "$2" ]; then ok "$1"; else bad "$1 (calls $2 and $3)
$(show "$S/calls")"; fi
}

ARTISAN="docker exec $NEW su-exec app php artisan"

# ---------------------------------------------------------------------------
echo 'tools/claude/deploy.sh'
# ---------------------------------------------------------------------------

scenario 'S1 nothing pending: no backup, no migration, DEPLOY_DONE'
run_deploy
eq 'exit status' 0 "$RC"
has 'prints DEPLOY_DONE' 'DEPLOY_DONE'
eq 'no DEPLOY_FAILED line' 0 "$(failed_lines)"
eq 'queues the deploy with POST, as the Coolify API documents it' 1 \
    "$(count "curl POST http://coolify.test/api/v1/deploy?uuid=$APP&force=false")"
in_order 'follows the deployment Coolify queued' \
    "$(first 'curl POST')" "$(first "curl GET http://coolify.test/api/v1/deployments/$DEPLOYMENT")"
if grep -qxF "Authorization: Bearer $TOKEN" "$S/curl-headers"; then ok 'sends the API token as a header'; else bad 'sends the API token as a header'; fi
if grep -qF -- "$TOKEN" "$S/curl-argv"; then bad 'keeps the token off every command line'; else ok 'keeps the token off every command line'; fi
lacks 'never prints the token' "$TOKEN"
lacks 'never prints the build log from the deployment body' 'BUILD-LOG'
has 'names the image it deployed' 'vag136azl5q2bv87qqzgblo1-app:c0ffee5e1ec7ed'
eq 'checks the migrations in the NEW container, as user app' 2 "$(count "$ARTISAN migrate:status")"
eq 'never runs anything in another container' 0 "$(grep -F 'docker exec ' "$S/calls" | grep -cvF "docker exec $NEW " || true)"
eq 'no backup' 0 "$(count backup)"
eq 'no migrate --force' 0 "$(count 'migrate --force')"
has 'says nothing was pending' 'nothing pending'
eq 'probes all eight pages' 8 "$(count 'curl probe ')"
has 'prints each probe with its expected status' '/c/nonexistent/none -> 404 (expected 404)'
in_order 'runs cass:health after the probes' "$(last 'curl probe ')" "$(first "$ARTISAN cass:health")"
has 'shows all of the cass:health output' '(first line of the cass:health output)'

scenario 'S2 a migration pending: backup, then migrate, then a clean status, DEPLOY_DONE'
: > "$S/pending"
run_deploy
eq 'exit status' 0 "$RC"
has 'prints DEPLOY_DONE' 'DEPLOY_DONE'
eq 'no DEPLOY_FAILED line' 0 "$(failed_lines)"
eq 'one backup, run through sudo' 1 "$(count 'backup via sudo')"
in_order 'status checked before the backup' "$(first "$ARTISAN migrate:status")" "$(first backup)"
in_order 'backup before migrate --force' "$(first backup)" "$(first "$ARTISAN migrate --force")"
in_order 'status checked again after migrate --force' "$(first 'migrate --force')" "$(last 'migrate:status')"
in_order 'cass:health after that' "$(last 'migrate:status')" "$(first 'cass:health')"
has 'shows all of the backup output' '[cass-backup] /srv/backups/cass/'
has 'shows all of the migrate output' '(first line of the migrate output)'

scenario 'S3 the backup fails: no migration, exit 4'
: > "$S/pending"
echo 1 > "$S/backup-rc"
run_deploy
eq 'exit status' 4 "$RC"
has 'says so' 'BACKUP FAILED, no migration run'
eq 'one DEPLOY_FAILED line' 1 "$(failed_lines)"
lacks 'no DEPLOY_DONE' 'DEPLOY_DONE'
eq 'the backup was attempted' 1 "$(count backup)"
eq 'no migrate --force' 0 "$(count 'migrate --force')"
eq 'stops there: no probe' 0 "$(count 'curl probe ')"

scenario 'S4 still pending after migrate --force: exit 5'
: > "$S/pending"
: > "$S/migrate-keeps-pending"
run_deploy
eq 'exit status' 5 "$RC"
eq 'one DEPLOY_FAILED line' 1 "$(failed_lines)"
lacks 'no DEPLOY_DONE' 'DEPLOY_DONE'
has 'says what is wrong' 'still pending'
eq 'migrated once' 1 "$(count 'migrate --force')"
eq 'no cass:health' 0 "$(count 'cass:health')"

scenario 'S5 cass:health fails: exit 7'
echo 1 > "$S/health-rc"
run_deploy
eq 'exit status' 7 "$RC"
eq 'one DEPLOY_FAILED line' 1 "$(failed_lines)"
lacks 'no DEPLOY_DONE' 'DEPLOY_DONE'
has 'shows all of the cass:health output' '(first line of the cass:health output)'
has 'including the failing check' '| queue        | FAIL'

scenario 'S6 /up answers 503: exit 6'
awk '$1 == "/up" { $2 = 503 } { print }' "$S/codes" > "$S/codes.new"
mv "$S/codes.new" "$S/codes"
run_deploy
eq 'exit status' 6 "$RC"
eq 'one DEPLOY_FAILED line' 1 "$(failed_lines)"
lacks 'no DEPLOY_DONE' 'DEPLOY_DONE'
has 'shows the wrong status next to the expected one' '/up -> 503 (expected 200)'
eq 'still probes all eight pages' 8 "$(count 'curl probe ')"
eq 'no cass:health' 0 "$(count 'cass:health')"

scenario 'S7 Coolify reports the deployment failed: exit 3 before any migration'
nothing_deploys
echo failed > "$S/status.1"
run_deploy
eq 'exit status' 3 "$RC"
eq 'one DEPLOY_FAILED line' 1 "$(failed_lines)"
lacks 'no DEPLOY_DONE' 'DEPLOY_DONE'
has 'names the status' 'failed'
eq 'nothing ran in any container' 0 "$(count 'php artisan')"

scenario 'S8 no new healthy container before the timeout: exit 2'
printf '%s\t%s\n' "$OTHER" 'Up 2 days (healthy)' "$NEW" 'Up 9 seconds (health: starting)' > "$S/ps.1"
printf '%s\t%s\n' "$OTHER" 'Up 2 days (healthy)' "$NEW" 'Up 25 minutes (unhealthy)' > "$S/ps.2"
echo in_progress > "$S/status.2"
run_deploy CASS_DEPLOY_TIMEOUT=0
eq 'exit status' 2 "$RC"
eq 'one DEPLOY_FAILED line' 1 "$(failed_lines)"
lacks 'no DEPLOY_DONE' 'DEPLOY_DONE'
has 'says it timed out' 'TIMEOUT'
has 'lists the containers it was waiting on' "$NEW	Up 25 minutes (unhealthy)"
eq 'nothing ran in any container' 0 "$(count 'php artisan')"

scenario 'S9 the deploy response carries no deployment uuid: exit 3'
nothing_deploys
printf '{"deployments":[{"message":"Deployment already queued for this commit.","resource_uuid":"%s"}]}\n' "$APP" > "$S/deploy-response"
run_deploy
eq 'exit status' 3 "$RC"
eq 'one DEPLOY_FAILED line' 1 "$(failed_lines)"
lacks 'no DEPLOY_DONE' 'DEPLOY_DONE'
has 'shows what Coolify said' 'Deployment already queued for this commit.'
eq 'no deployment polled' 0 "$(count '/deployments/')"
eq 'nothing ran in any container' 0 "$(count 'php artisan')"

scenario 'S10 migrate --force fails: exit 5'
: > "$S/pending"
echo 1 > "$S/migrate-rc"
run_deploy
eq 'exit status' 5 "$RC"
eq 'one DEPLOY_FAILED line' 1 "$(failed_lines)"
lacks 'no DEPLOY_DONE' 'DEPLOY_DONE'
in_order 'the backup ran first' "$(first backup)" "$(first 'migrate --force')"
has 'shows all of the migrate output' '(first line of the migrate output)'
has 'and its error' 'Base table or view already exists'
eq 'no cass:health' 0 "$(count 'cass:health')"

scenario 'S11 the deploy call fails (HTTP 401): exit 3'
nothing_deploys
echo 401 > "$S/deploy-http"
run_deploy
eq 'exit status' 3 "$RC"
eq 'one DEPLOY_FAILED line' 1 "$(failed_lines)"
lacks 'no DEPLOY_DONE' 'DEPLOY_DONE'
eq 'no deployment polled' 0 "$(count '/deployments/')"
eq 'nothing ran in any container' 0 "$(count 'php artisan')"

scenario 'S12 a command fails unexpectedly (docker inspect): exit 1, still one DEPLOY_FAILED line'
: > "$S/inspect-fails"
run_deploy
eq 'exit status' 1 "$RC"
eq 'one DEPLOY_FAILED line' 1 "$(failed_lines)"
lacks 'no DEPLOY_DONE' 'DEPLOY_DONE'
has 'the failing command is in the output' 'No such object'
eq 'nothing ran in any container' 0 "$(count 'php artisan')"

scenario 'S13 one deployment poll fails: the wait goes on, DEPLOY_DONE'
echo unreachable > "$S/status.1"
run_deploy
eq 'exit status' 0 "$RC"
has 'prints DEPLOY_DONE' 'DEPLOY_DONE'
has 'reports the poll it could not make' 'deployment=unavailable'

scenario 'S14 no token file: exit 1 before any call'
mkdir -p "$S/home"
run_deploy HOME="$S/home" CASS_COOLIFY_TOKEN_FILE="$S/home/.coolify-token"
eq 'exit status' 1 "$RC"
eq 'one DEPLOY_FAILED line' 1 "$(failed_lines)"
lacks 'no DEPLOY_DONE' 'DEPLOY_DONE'
eq 'no API call, no deploy queued' 0 "$(count 'curl ')"

# ---------------------------------------------------------------------------
echo
if [ "$FAILURES" -eq 0 ]; then
    echo 'deploy.test.sh: all assertions passed'
    exit 0
fi
echo "deploy.test.sh: $FAILURES assertion(s) failed" >&2
exit 1
