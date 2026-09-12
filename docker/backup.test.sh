#!/bin/sh
# Container-detection tests for docker/backup.sh and docker/storage-backup.sh.
#
# Why this file exists: on the production host Coolify names the containers
# after its own project identifier - app-vag136azl5q2bv87qqzgblo1-1 and
# mysql-vag136azl5q2bv87qqzgblo1-1, with labels com.docker.compose.project=
# vag136azl5q2bv87qqzgblo1 and com.docker.compose.service=app|mysql. Nothing in
# either name or either label contains "cass", so the old
# `... --format '{{.Names}}' | grep -i cass | head -1` matched nothing and the
# nightly backup never ran at all. These tests pin the replacement: the
# container is identified by what its own environment says it is.
#
# Runs with no docker and no database: a fake `docker` shim is put first on
# PATH and answers `ps`, `inspect`, `exec` and `run` for a world that looks like
# the production host. POSIX sh only - CI runs it under dash.
#
# Usage: sh docker/backup.test.sh
set -eu

HERE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
ROOT="$(mktemp -d "${TMPDIR:-/tmp}/cass-backup-test.XXXXXX")"
trap 'rm -rf "$ROOT"' EXIT INT TERM

FAILURES=0
ok()   { printf '  ok    %s\n' "$1"; }
bad()  { printf '  FAIL  %s\n' "$1" >&2; FAILURES=$((FAILURES + 1)); }
skip() { printf '  skip  %s\n' "$1"; }

# eq <label> <expected> <actual>
eq() {
    if [ "$2" = "$3" ]; then ok "$1"; else bad "$1
          expected: [$2]
          actual:   [$3]"; fi
}

# contains <label> <needle> <file>
contains() {
    if grep -qF -- "$2" "$3"; then ok "$1"; else bad "$1: [$2] not found in $3
$(sed 's/^/          | /' "$3")"; fi
}

# ---------------------------------------------------------------------------
# The fake host.
# ---------------------------------------------------------------------------

mkdir -p "$ROOT/bin" "$ROOT/volumes/cass-vol/private/ab" \
         "$ROOT/volumes/cass-vol/public" "$ROOT/volumes/empty-vol"
echo 'a pdf'  > "$ROOT/volumes/cass-vol/private/ab/01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf"
echo 'a logo' > "$ROOT/volumes/cass-vol/public/logo.png"

# Fields: name|compose service label|environment (space separated)|mounts
# (space separated destination=volume). A container with an empty service field
# carries no compose labels, so `docker ps --filter label=...service=X` does not
# list it - that is how the two override targets below are reachable only
# through CASS_MYSQL_CONTAINER / CASS_APP_CONTAINER.
#
# The mysql service whose database is NOT cass is listed FIRST on purpose: a
# `head -1` over the label filter alone picks it, so any regression back to
# "first mysql container wins" turns these tests red.
cat > "$ROOT/world" <<'WORLD'
mysql-vag136azl5q2bv87qqzgblo1-101|mysql|MYSQL_USER=other MYSQL_PASSWORD=p1 MYSQL_DATABASE=cass_of_another_app|
mysql-vag136azl5q2bv87qqzgblo1-102|mysql|MYSQL_USER=cass MYSQL_PASSWORD=s3cret MYSQL_DATABASE=cass|
app-vag136azl5q2bv87qqzgblo1-103|app|APP_NAME=SomethingElse APP_ENV=production|/var/www/html/storage/app=empty-vol
app-vag136azl5q2bv87qqzgblo1-104|app|APP_NAME=CASS APP_ENV=production|/var/www/html/storage/app=cass-vol
db-installed-by-hand||MYSQL_DATABASE=cass|
app-installed-by-hand||APP_NAME=CASS|/var/www/html/storage/app=cass-vol
WORLD

cat > "$ROOT/bin/docker" <<'SHIM'
#!/bin/sh
# Fake docker for docker/backup.test.sh. Answers only the four subcommands the
# two backup scripts use, from the pipe-separated world in $FAKE_DOCKER_WORLD.
set -eu

world() { cat "$FAKE_DOCKER_WORLD"; }
log()   { printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"; }

cmd="${1:-}"
if [ "$#" -gt 0 ]; then shift; fi

case "$cmd" in
ps)
    log "ps $*"
    svc=''
    for a in "$@"; do
        case "$a" in
            label=com.docker.compose.service=*) svc="${a##*=}" ;;
        esac
    done
    world | while IFS='|' read -r name service env mounts; do
        [ -n "$name" ] || continue
        if [ -n "$svc" ] && [ "$service" != "$svc" ]; then continue; fi
        printf '%s\n' "$name"
    done
    ;;

inspect)
    name="${1:-}"
    if [ "$#" -gt 0 ]; then shift; fi
    fmt=''
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --format) fmt="${2:-}"; shift 2 ;;
            *) shift ;;
        esac
    done
    line="$(world | grep "^${name}|" || true)"
    if [ -z "$line" ]; then
        echo "Error: No such object: $name" >&2
        exit 1
    fi
    env="$(printf '%s' "$line" | cut -d'|' -f3)"
    mounts="$(printf '%s' "$line" | cut -d'|' -f4)"
    case "$fmt" in
        *Config.Env*)
            for e in $env; do printf '%s\n' "$e"; done
            ;;
        *.Destination*)
            dest="$(printf '%s' "$fmt" | sed -n 's/.*eq \.Destination "\([^"]*\)".*/\1/p')"
            for m in $mounts; do
                if [ "${m%%=*}" = "$dest" ]; then printf '%s' "${m#*=}"; fi
            done
            printf '\n'
            ;;
        *)
            echo "fake docker: unsupported inspect format [$fmt]" >&2
            exit 2
            ;;
    esac
    ;;

exec)
    name="${1:-}"
    if [ "$#" -gt 0 ]; then shift; fi
    while [ "$#" -gt 0 ]; do
        case "$1" in -*) shift ;; *) break ;; esac
    done
    log "exec $name $*"
    # A dump big enough to clear the script's 4 KB floor after gzip -9, which
    # rules out compressible filler: base64 of random bytes is incompressible.
    echo "-- MySQL dump (fake) of $name"
    head -c 30000 /dev/urandom | base64
    echo "-- Dump completed on 2026-09-13  3:17:04"
    ;;

run)
    log "run $*"
    out=''; data=''
    while [ "$#" -gt 0 ]; do
        case "$1" in
            -v) spec="${2:-}"; shift 2
                src="${spec%%:*}"; rest="${spec#*:}"; dst="${rest%%:*}"
                case "$dst" in
                    /out)  out="$src" ;;
                    /data) data="$FAKE_DOCKER_VOLUMES/$src" ;;
                esac ;;
            tar) shift; break ;;
            *) shift ;;
        esac
    done
    archive=''
    while [ "$#" -gt 0 ]; do
        case "$1" in /out/*) archive="$out/${1#/out/}" ;; esac
        shift
    done
    if [ -z "$archive" ]; then
        echo "fake docker run: no /out/ path in the tar command" >&2
        exit 2
    fi
    # A volume name that does not exist is the production failure this guards:
    # `docker run -v <wrong-name>:/data` silently creates a new EMPTY volume.
    # The fake reproduces that rather than erroring, so the script's own guard
    # is what has to catch it.
    [ -d "$data" ] || mkdir -p "$data"
    tar czf "$archive" -C "$data" .
    ;;

*)
    echo "fake docker: unsupported subcommand [$cmd]" >&2
    exit 2
    ;;
esac
SHIM
chmod +x "$ROOT/bin/docker"

# Does this filesystem honour chmod? MSYS/Windows may not, and the 0700/0600
# assertions are only meaningful where it does. CI runs on ubuntu.
MODES=no
: > "$ROOT/.modeprobe"
chmod 600 "$ROOT/.modeprobe"
if [ "$(stat -c %a "$ROOT/.modeprobe" 2>/dev/null || echo '')" = "600" ]; then MODES=yes; fi

N=0
# run_script <script> <destdir> [VAR=VALUE ...]  -> sets RC, writes $ROOT/log-N,
# $ROOT/out-N (stdout+stderr). $LOG / $OUT point at them afterwards.
run_script() {
    _script="$1"; _dest="$2"; shift 2
    N=$((N + 1))
    LOG="$ROOT/log-$N"; OUT="$ROOT/out-$N"
    : > "$LOG"
    RC=0
    env PATH="$ROOT/bin:$PATH" \
        FAKE_DOCKER_WORLD="${WORLD_FILE:-$ROOT/world}" \
        FAKE_DOCKER_LOG="$LOG" \
        FAKE_DOCKER_VOLUMES="$ROOT/volumes" \
        "$@" \
        sh "$HERE/$_script" "$_dest" > "$OUT" 2>&1 || RC=$?
}

# chosen <log> - the container name the script actually acted on.
chosen()      { awk '/^exec /{print $2; exit}' "$1"; }
chosen_vol()  { sed -n 's/.*-v \([^:]*\):\/data.*/\1/p' "$1" | head -1; }

# ---------------------------------------------------------------------------
echo 'docker/backup.sh'
# ---------------------------------------------------------------------------

echo '  - picks the mysql container whose own environment holds MYSQL_DATABASE=cass'
DEST="$ROOT/db1"
mkdir -p "$DEST"
# A dump from before the retention window, to prove rotation still runs.
: > "$DEST/cass-2020-01-01-0000.sql.gz"
touch -t 202001010000 "$DEST/cass-2020-01-01-0000.sql.gz"
run_script backup.sh "$DEST"
eq 'exit status' 0 "$RC"
eq 'container' 'mysql-vag136azl5q2bv87qqzgblo1-102' "$(chosen "$LOG")"
eq 'one dump written' 1 "$(find "$DEST" -name 'cass-*.sql.gz' -newermt '2021-01-01' -type f | wc -l | tr -d ' ')"
eq 'no .partial left behind' 0 "$(find "$DEST" -name '*.partial' | wc -l | tr -d ' ')"
eq '14-day rotation still runs' 0 "$(find "$DEST" -name 'cass-2020-*' | wc -l | tr -d ' ')"
contains 'mysqldump keeps --no-tablespaces' '--no-tablespaces' "$LOG"
contains 'mysqldump keeps --single-transaction' '--single-transaction' "$LOG"
contains 'password still comes from the container environment' 'MYSQL_PWD="$MYSQL_PASSWORD"' "$LOG"
contains 'names the container it chose' 'mysql-vag136azl5q2bv87qqzgblo1-102' "$OUT"
DUMP="$(find "$DEST" -name 'cass-*.sql.gz' -newermt '2021-01-01' -type f | head -1)"
if [ "$MODES" = yes ] && [ -n "$DUMP" ]; then
    eq 'destination is 0700' '700' "$(stat -c %a "$DEST")"
    eq 'dump is 0600' '600' "$(stat -c %a "$DUMP")"
else
    skip 'mode assertions (this filesystem does not honour chmod)'
fi

echo '  - CASS_MYSQL_CONTAINER overrides the detection'
run_script backup.sh "$ROOT/db2" CASS_MYSQL_CONTAINER=db-installed-by-hand
eq 'exit status' 0 "$RC"
eq 'container' 'db-installed-by-hand' "$(chosen "$LOG")"

echo '  - an override that is not running warns and falls back to the detection'
run_script backup.sh "$ROOT/db3" CASS_MYSQL_CONTAINER=mysql-that-was-recreated
eq 'exit status' 0 "$RC"
eq 'container' 'mysql-vag136azl5q2bv87qqzgblo1-102' "$(chosen "$LOG")"
contains 'warns about the stale override' 'mysql-that-was-recreated' "$OUT"

echo '  - refuses when no mysql container holds the cass database'
grep -v 'MYSQL_DATABASE=cass|' "$ROOT/world" > "$ROOT/world-nocass"
WORLD_FILE="$ROOT/world-nocass" run_script backup.sh "$ROOT/db4"
WORLD_FILE=''
eq 'exit status' 1 "$RC"
eq 'nothing was dumped' '' "$(chosen "$LOG")"
contains 'says what it looked for' 'MYSQL_DATABASE=cass' "$OUT"
contains 'names the override' 'CASS_MYSQL_CONTAINER' "$OUT"

# ---------------------------------------------------------------------------
echo 'docker/storage-backup.sh'
# ---------------------------------------------------------------------------

echo '  - picks the app container whose environment holds APP_NAME=CASS, and its volume'
DEST="$ROOT/st1"
run_script storage-backup.sh "$DEST"
eq 'exit status' 0 "$RC"
eq 'volume' 'cass-vol' "$(chosen_vol "$LOG")"
ARCHIVE="$(find "$DEST" -name 'cass-storage-*.tar.gz' -type f 2>/dev/null | head -1)"
eq 'one archive written' 1 "$(find "$DEST" -name 'cass-storage-*.tar.gz' -type f 2>/dev/null | wc -l | tr -d ' ')"
eq 'no .partial left behind' 0 "$(find "$DEST" -name '*.partial' 2>/dev/null | wc -l | tr -d ' ')"
if [ -n "$ARCHIVE" ]; then
    tar tzf "$ARCHIVE" > "$ROOT/listing"
    contains 'the archive holds the uploaded file' '01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf' "$ROOT/listing"
else
    bad 'the archive holds the uploaded file: no archive was written'
fi
if [ "$MODES" = yes ] && [ -n "$ARCHIVE" ]; then
    eq 'destination is 0700' '700' "$(stat -c %a "$DEST")"
    eq 'archive is 0600' '600' "$(stat -c %a "$ARCHIVE")"
else
    skip 'mode assertions (this filesystem does not honour chmod)'
fi

echo '  - CASS_APP_CONTAINER overrides the detection'
run_script storage-backup.sh "$ROOT/st2" CASS_APP_CONTAINER=app-installed-by-hand
eq 'exit status' 0 "$RC"
eq 'volume' 'cass-vol' "$(chosen_vol "$LOG")"

echo '  - refuses an archive of the wrong (empty) volume instead of rotating a good one away'
# Only the APP_NAME=SomethingElse container is left, so detection falls through
# to "an app service with a mount at storage/app" and lands on empty-vol. Its
# archive is well under the old 10 KB size guard AND well over the byte size of
# a genuinely small but correct store, which is why the guard is now an entry
# count.
grep -v 'APP_NAME=CASS' "$ROOT/world" > "$ROOT/world-noapp"
DEST="$ROOT/st3"
WORLD_FILE="$ROOT/world-noapp" run_script storage-backup.sh "$DEST"
WORLD_FILE=''
eq 'exit status' 1 "$RC"
eq 'volume it tried' 'empty-vol' "$(chosen_vol "$LOG")"
eq 'no archive kept' 0 "$(find "$DEST" -name 'cass-storage-*.tar.gz' -type f 2>/dev/null | wc -l | tr -d ' ')"
eq 'no .partial kept' 0 "$(find "$DEST" -name '*.partial' 2>/dev/null | wc -l | tr -d ' ')"
contains 'says the archive is empty' 'entr' "$OUT"

echo '  - refuses when no app container can be identified'
grep -v '|app|' "$ROOT/world" | grep -v 'APP_NAME=CASS' > "$ROOT/world-noapps"
WORLD_FILE="$ROOT/world-noapps" run_script storage-backup.sh "$ROOT/st4"
WORLD_FILE=''
eq 'exit status' 1 "$RC"
contains 'names the override' 'CASS_APP_CONTAINER' "$OUT"

# ---------------------------------------------------------------------------
echo
if [ "$FAILURES" -eq 0 ]; then
    echo 'backup.test.sh: all assertions passed'
    exit 0
fi
echo "backup.test.sh: $FAILURES assertion(s) failed" >&2
exit 1
