#!/usr/bin/env bash
# Tests for tools/backup/cass-backup-offsite.sh, the off-host copy that runs as root on the
# production host every night. Pinned here: which files it sends and under which names; that a
# file goes only when it is a whole gzip, encrypted with age, uploaded in one part and found
# again in a listing with its size and MD5; that nothing in plain text is written outside the
# backup directory; that the heartbeat is pinged only when everything is off-host and the
# newest dump is fresh, and never with the push URL on a command line; and each failure's exit
# code, with the one line every run ends with.
#
# Runs with no network, no docker and no OCI account. Fakes for oci, age, curl and flock are
# written into a temporary bin directory: age, curl and flock go first on PATH, and the CLI is
# reached by its path, through CASS_OCI_BIN, as on the host. The oci fake holds the host's
# rights in the bucket and nothing more (create objects in one part, never over an existing
# name, and list them) and answers as the real CLI does, so a script that asks for more fails
# here. Each fake answers from the current scenario's directory ($FAKE) and appends every call
# it receives to $FAKE/calls. The scenario directory also stands in for /srv/backups/cass
# (dest/), root's home, the temporary folder and the bucket.
#
# After every run six checks hold whatever the scenario: one summary line, the last; no plain
# text outside dest/; nothing asked of OCI that the host may not do; at most one ping, only on
# exit 0 or 6, the push URL never on curl's command line or in the output; nothing left in
# .offsite-work/; and every object the manifest names is in the bucket, an age file with the
# SHA-256 its name carries, with no backup in plain text among the objects.
#
# CASS_OFFSITE_SCRIPT tests another copy of the script instead (a mutant, say).
#
# Usage: bash tools/backup/cass-backup-offsite.test.sh
set -eu

HERE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
SCRIPT="${CASS_OFFSITE_SCRIPT:-$HERE/cass-backup-offsite.sh}"
ROOT="$(mktemp -d "${TMPDIR:-/tmp}/cass-offsite-test.XXXXXX")"
# A run makes home/, tmp/ and cwd/ read-only; the cleanup gives the rights back first.
trap 'chmod -R u+w "$ROOT" 2> /dev/null || true; rm -rf "$ROOT"' EXIT INT TERM

FAILURES=0
PASSES=0
SKIPS=0
ok()   { printf '  ok    %s\n' "$1"; PASSES=$((PASSES + 1)); }
bad()  { printf '  FAIL  %s\n' "$1" >&2; FAILURES=$((FAILURES + 1)); }
skip() { printf '  skip  %s\n' "$1"; SKIPS=$((SKIPS + 1)); }

# A real flock, where this machine has one (CI's ubuntu does): S24 holds the lock with it.
REAL_FLOCK="$(command -v flock 2> /dev/null || true)"

mkdir -p "$ROOT/bin"

# Does this filesystem honour chmod? MSYS/Windows may not, and the 0600/0700 assertions are
# only meaningful where it does (docker/backup.test.sh probes the same way).
MODES=no
: > "$ROOT/.modeprobe"
chmod 600 "$ROOT/.modeprobe"
if [ "$(stat -c %a "$ROOT/.modeprobe" 2> /dev/null || echo '')" = 600 ]; then MODES=yes; fi
# Does a directory made 0555 refuse a write? Not under MSYS, and never for root.
READONLY=no
mkdir "$ROOT/.roprobe"
chmod 555 "$ROOT/.roprobe"
if ! ( : > "$ROOT/.roprobe/probe" ) 2> /dev/null; then READONLY=yes; fi
chmod 755 "$ROOT/.roprobe"
# Does ln -s make a link? MSYS copies the target instead, and fails when there is none.
LINKS=no
if ln -s /etc/hostname "$ROOT/.linkprobe" 2> /dev/null && [ -L "$ROOT/.linkprobe" ]; then LINKS=yes; fi

# ---------------------------------------------------------------------------
# The fakes.
# ---------------------------------------------------------------------------

cat > "$ROOT/bin/oci" <<'SHIM'
#!/usr/bin/env bash
# Fake OCI CLI with the host's rights in the bucket and nothing more, OBJECT_CREATE and
# OBJECT_INSPECT: `os object put` in one part and never over an existing name, and
# `os object list`. Anything else is refused as OCI refuses it. The objects are files under
# $FAKE/bucket. Knobs, files in $FAKE: put-fails, put-truncates, put-hangs, list-empty,
# list-md5-differs; expected-region, expected-ns and expected-bucket hold the destination.
set -eu
log() { printf '%s\n' "$*" >> "$FAKE/calls"; }
# The base64 MD5 of stdin, as OCI reports it.
md5_b64() { printf '%b' "$(md5sum | cut -c 1-32 | sed 's/../\\x&/g')" | base64; }
# usage <message>: refused by the CLI itself, before any request, as click refuses: exit 2.
usage() { printf 'fake oci: %s\n' "$1" >&2; exit 2; }
# service_error <code> <message> <status>: what the CLI prints when the service says no.
service_error() {
    printf 'ServiceError:\n{"code": "%s", "message": "%s", "status": %s}\n' "$1" "$2" "$3" >&2
    exit 1
}

case "${1:-} ${2:-} ${3:-}" in
    'os object put') verb=put ;;
    'os object list') verb=list ;;
    *)
        log "oci FORBIDDEN ${1:-} ${2:-} ${3:-}"
        service_error NotAuthorizedOrNotFound 'Authorization failed or requested resource not found.' 404
        ;;
esac
shift 3

auth='' region='' namespace='' bucket='' name='' file='' md5='' prefix='' fields=''
multipart=yes no_overwrite=no force=no
while [ "$#" -gt 0 ]; do
    case "$verb $1" in
        'put --no-multipart') multipart=no; shift; continue ;;
        'put --no-overwrite') no_overwrite=yes; shift; continue ;;
        'put --force') force=yes; shift; continue ;;
        *' --auth'|*' --region'|*' --namespace'|*' -ns'|*' --bucket-name'|*' -bn') ;;
        'put --name'|'put --file'|'put --content-md5'|'list --prefix'|'list --fields') ;;
        *) usage "Error: No such option: $1" ;;
    esac
    [ "$#" -ge 2 ] || usage "Error: Option '$1' requires an argument."
    case "$1" in
        --auth) auth=$2 ;;
        --region) region=$2 ;;
        --namespace|-ns) namespace=$2 ;;
        --bucket-name|-bn) bucket=$2 ;;
        --name) name=$2 ;;
        --file) file=$2 ;;
        --content-md5) md5=$2 ;;
        --prefix) prefix=$2 ;;
        --fields) fields=$2 ;;
    esac
    shift 2
done

[ "$auth" = instance_principal ] || usage "--auth is [$auth], expected [instance_principal]: the host has no API key"
want=$(cat "$FAKE/expected-region")
[ "$region" = "$want" ] || usage "--region is [$region], expected [$want]"
want=$(cat "$FAKE/expected-ns")
[ "$namespace" = "$want" ] || usage "--namespace is [$namespace], expected [$want]"
want=$(cat "$FAKE/expected-bucket")
[ "$bucket" = "$want" ] || usage "--bucket-name is [$bucket], expected [$want]"

if [ "$verb" = put ]; then
    if [ -f "$FAKE/put-hangs" ]; then sleep 10; fi
    [ "$multipart" = no ] || usage 'multipart needs OBJECT_READ and OBJECT_OVERWRITE, which the host does not have'
    [ "$no_overwrite" = yes ] || usage 'without --no-overwrite the CLI would ask before overwriting'
    [ "$force" = no ] || usage '--force overwrites, and the host may not overwrite'
    [ -n "$name" ] || usage 'Error: Missing option(s) --name.'
    if [ -z "$file" ] || [ ! -f "$file" ]; then usage "Error: Invalid value for '--file': '$file' does not exist."; fi
    # The service refuses a body whose MD5 is not the one the request announced.
    if [ "$md5" != "$(md5_b64 < "$file")" ]; then
        log "oci put $name refused: UnmatchedContentMD5"
        service_error UnmatchedContentMD5 'The computed MD5 of the request body does not match the Content-MD5 header.' 400
    fi
    if [ -f "$FAKE/put-fails" ]; then
        log "oci put $name failed"
        service_error InternalServerError 'Internal server error.' 500
    fi
    target="$FAKE/bucket/$name"
    # --no-overwrite: the real CLI says so and exits 0, and the bucket keeps what it had.
    if [ -e "$target" ]; then
        log "oci put $name exists"
        echo 'The object already exists and was not overwritten' >&2
        exit 0
    fi
    mkdir -p "$(dirname "$target")"
    if [ -f "$FAKE/put-truncates" ]; then
        head -c "$(( $(stat -c %s "$file") / 2 ))" "$file" > "$target"
    else
        cp "$file" "$target"
    fi
    log "oci put $name"
    printf '{"etag": "fake-etag", "last-modified": "Fri, 06 Nov 2026 04:17:01 GMT", "opc-content-md5": "%s"}\n' "$md5"
    exit 0
fi

# list: the objects under --prefix, with the fields asked for; with none, no "data" at all.
log "oci list $prefix"
if [ -f "$FAKE/list-empty" ]; then echo '{"prefixes": []}'; exit 0; fi
items=$(find "$FAKE/bucket" -type f 2> /dev/null | LC_ALL=C sort | while IFS= read -r path; do
    obj=${path#"$FAKE/bucket/"}
    case "$obj" in "$prefix"*) ;; *) continue ;; esac
    size=null
    sum=null
    case ",$fields," in *,size,*) size=$(stat -c %s "$path") ;; esac
    case ",$fields," in
        *,md5,*)
            if [ -f "$FAKE/list-md5-differs" ]; then sum='"AAAAAAAAAAAAAAAAAAAAAA=="'
            else sum="\"$(md5_b64 < "$path")\""; fi
            ;;
    esac
    printf '{"name": "%s", "size": %s, "md5": %s}\n' "$obj" "$size" "$sum"
done | paste -sd , -)
if [ -z "$items" ]; then echo '{"prefixes": []}'; else printf '{"data": [%s], "prefixes": []}\n' "$items"; fi
SHIM

cat > "$ROOT/bin/age" <<'SHIM'
#!/usr/bin/env bash
# Fake age: encrypts to a recipients file, the one way the host calls it. Its output is the
# age header, two stanza lines and the input with its letters rotated by 13, so the same input
# always gives the same bytes. Knobs, files in $FAKE: age-fails; age-fails-on and age-removes,
# each holding a file's base name; age-plain.
set -eu
log() { printf '%s\n' "$*" >> "$FAKE/calls"; }
refuse() { printf 'fake age: %s is not how the host calls age\n' "$1" >&2; exit 2; }
recipients='' out='' in=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        -e|--encrypt) shift ;;
        -R|--recipients-file) [ "$#" -ge 2 ] || refuse "$1 with no file"; recipients=$2; shift 2 ;;
        -o|--output) [ "$#" -ge 2 ] || refuse "$1 with no file"; out=$2; shift 2 ;;
        -*) refuse "$1" ;;
        *) [ -z "$in" ] || refuse 'a second input'; in=$1; shift ;;
    esac
done
if [ -z "$recipients" ] || [ ! -r "$recipients" ]; then refuse 'encrypting without a readable --recipients-file'; fi
[ -n "$out" ] || refuse 'writing to stdout'
[ -n "$in" ] || refuse 'reading stdin'
log "age -R $recipients -o $out $in"

# A rotation mid-run: the first call deletes that file from the input's folder.
if [ -f "$FAKE/age-removes" ] && [ ! -e "$FAKE/age-removed" ]; then
    : > "$FAKE/age-removed"
    rm -f -- "$(dirname -- "$in")/$(cat "$FAKE/age-removes")"
fi
if [ -f "$FAKE/age-fails" ] || { [ -f "$FAKE/age-fails-on" ] && [ "${in##*/}" = "$(cat "$FAKE/age-fails-on")" ]; }; then
    printf 'partial' > "$out"
    printf 'age: error: failed to encrypt %s\n' "$in" >&2
    exit 1
fi
# A broken age: what it writes is the input itself.
if [ -f "$FAKE/age-plain" ]; then cp "$in" "$out"; exit 0; fi
{
    printf 'age-encryption.org/v1\n'
    printf -- '-> X25519 RmFrZSBzdGFuemEgZm9yIHRoZSB0ZXN0\n'
    printf -- '--- RmFrZSBtYWMgZm9yIHRoZSB0ZXN0\n'
    tr 'A-Za-z' 'N-ZA-Mn-za-m' < "$in"
} > "$out"
SHIM

cat > "$ROOT/bin/curl" <<'SHIM'
#!/usr/bin/env bash
# Fake curl: the heartbeat ping. Its command line goes to $FAKE/curl-argv, so a test can check
# that the push URL never travels on it: it belongs in the -K file. Knob: curl-fails.
set -eu
printf '%s\n' "$*" >> "$FAKE/curl-argv"
config=''
previous=''
for a in "$@"; do
    case "$previous" in -K|--config) config=$a ;; esac
    previous=$a
done
if [ -n "$config" ]; then
    printf 'curl -K %s\n' "$config" >> "$FAKE/calls"
else
    printf 'curl without -K\n' >> "$FAKE/calls"
fi
if [ -f "$FAKE/curl-fails" ]; then
    echo 'curl: (22) The requested URL returned error: 404' >&2
    exit 22
fi
SHIM

cat > "$ROOT/bin/flock" <<'SHIM'
#!/usr/bin/env bash
# Fake flock: the lock is free unless the scenario holds lock-held.
printf 'flock %s\n' "$*" >> "$FAKE/calls"
if [ -f "$FAKE/lock-held" ]; then exit 1; fi
exit 0
SHIM

chmod +x "$ROOT/bin/oci" "$ROOT/bin/age" "$ROOT/bin/curl" "$ROOT/bin/flock"

# age, curl and flock must win the PATH lookup, or the script would reach the real ones. A
# temporary directory whose path holds a colon splits PATH.
for f in age curl flock; do
    if [ "$(PATH="$ROOT/bin:$PATH"; command -v "$f")" != "$ROOT/bin/$f" ]; then
        echo "cass-backup-offsite.test.sh: the fake $f in $ROOT/bin is not first on PATH; refusing to run" >&2
        exit 1
    fi
done

# The script calls python3. Where that name is missing or is not Python 3 (on Windows it is a
# Microsoft Store stub), point it at `python`, without the \r that Windows' python ends every
# printed line with: the host's python3 never writes one.
if ! python3 -c 'import sys; sys.exit(sys.version_info[0] != 3)' > /dev/null 2>&1; then
    if python -c 'import sys; sys.exit(sys.version_info[0] != 3)' > /dev/null 2>&1; then
        cat > "$ROOT/bin/python3" <<'SHIM'
#!/usr/bin/env bash
set -o pipefail
python "$@" | tr -d '\r'
SHIM
        chmod +x "$ROOT/bin/python3"
    else
        echo 'cass-backup-offsite.test.sh: needs python3' >&2
        exit 1
    fi
fi

# A script that loops forever fails its scenario instead of hanging the run.
WATCHDOG=''
if command -v timeout > /dev/null 2>&1; then WATCHDOG='timeout 30'; fi

# ---------------------------------------------------------------------------
# Scenarios and assertions.
# ---------------------------------------------------------------------------

H='[cass-backup-offsite]'
PUBLIC_KEY="age1$(printf 'q%.0s' $(seq 58))"
N=0
S=''
MARKER=''
SEQ=0
RC=0
CALLS_AT=0

sha() { sha256sum | cut -d ' ' -f 1; }
md5_b64() { printf '%b' "$(md5sum | cut -c 1-32 | sed 's/../\\x&/g')" | base64; }
# hours_ago <n>: a time for touch -d, n hours before now.
hours_ago() { printf '@%s' "$(( $(date +%s) - $1 * 3600 ))"; }

# The entries directly in /tmp and /var/tmp, one path per line.
tmp_names() {
    local d
    for d in /tmp /var/tmp; do
        if [ -d "$d" ]; then find "$d" -mindepth 1 -maxdepth 1 -printf "$d/%f\n" 2> /dev/null || true; fi
    done
}

# make_backup <name> <hours old>: a finished backup in dest/, a gzip of three SQL lines that
# hold the scenario's marker, its SHA-256 in fixture-sums. Each call writes other bytes.
make_backup() {
    SEQ=$((SEQ + 1))
    printf -- "-- %s, test fixture %s (%s)\nINSERT INTO organizations VALUES (1, '%s');\n-- Dump completed on 2026-11-06  3:17:42 %s\n" \
        "$MARKER" "$1" "$SEQ" "$MARKER" "$MARKER" | gzip -n > "$S/dest/$1"
    touch -d "$(hours_ago "$2")" "$S/dest/$1"
    sha < "$S/dest/$1" >> "$S/fixture-sums"
}

# scenario <title>: a fresh $S with the three backups of a normal night and the decoys beside
# them; each test then changes only what it is about.
scenario() {
    N=$((N + 1))
    S="$ROOT/s$N"
    mkdir -p "$S/dest" "$S/home" "$S/tmp" "$S/cwd" "$S/bucket"
    echo "  - $1"
    printf '%s\n' '# the destination, as on the host' namespace=testns bucket=test-bucket region=xx-test-1 > "$S/conf"
    echo testns > "$S/expected-ns"
    echo test-bucket > "$S/expected-bucket"
    echo xx-test-1 > "$S/expected-region"
    printf '# the public key of the test pair\n%s\n' "$PUBLIC_KEY" > "$S/recipient"
    printf '%s\n' 'url = "https://uptime.test/api/push/PUSHTOKEN7f3a?status=up&msg=OK&ping="' > "$S/ping"
    MARKER="CASS-PLAINTEXT-s$N-$$-$RANDOM$RANDOM"
    : > "$S/calls"
    : > "$S/curl-argv"
    : > "$S/fixture-sums"
    make_backup cass-2026-11-05-0317.sql.gz 25
    make_backup cass-2026-11-06-0317.sql.gz 1
    make_backup cass-storage-2026-11-01.tar.gz 120
    # Decoys, a day old, none of which may be sent: a dump still being written, a stray file,
    # a directory and a link named like dumps.
    printf -- "-- %s, still being written\nINSERT INTO organizations VALUES (1, '%s');\n" "$MARKER" "$MARKER" |
        gzip -n > "$S/dest/cass-2026-11-06-0400.sql.partial"
    sha < "$S/dest/cass-2026-11-06-0400.sql.partial" >> "$S/fixture-sums"
    printf 'notes on the backups, %s\n' "$MARKER" > "$S/dest/notes.txt"
    sha < "$S/dest/notes.txt" >> "$S/fixture-sums"
    mkdir "$S/dest/cass-2026-11-03-0317.sql.gz"
    if [ "$LINKS" = yes ]; then ln -s /etc/hostname "$S/dest/cass-2026-11-04-0317.sql.gz"; fi
    local d
    for d in cass-2026-11-06-0400.sql.partial notes.txt cass-2026-11-03-0317.sql.gz cass-2026-11-04-0317.sql.gz; do
        if [ -e "$S/dest/$d" ] || [ -L "$S/dest/$d" ]; then touch -h -d "$(hours_ago 24)" "$S/dest/$d"; fi
    done
    tmp_names > "$S/tmp-before"
    touch "$S/.start"
}

# run_offsite [VAR=VALUE ...]: runs the script against the fakes, from cwd/, with HOME and
# TMPDIR in the scenario. Sets RC; the output (stdout and stderr) is $S/out. Then the six
# checks every run gets.
run_offsite() {
    RC=0
    CALLS_AT=$(wc -l < "$S/calls")
    if [ "$READONLY" = yes ]; then chmod 555 "$S/home" "$S/tmp" "$S/cwd"; fi
    (
        cd "$S/cwd"
        exec env PATH="$ROOT/bin:$PATH" HOME="$S/home" TMPDIR="$S/tmp" FAKE="$S" \
            CASS_OFFSITE_CONFIG="$S/conf" \
            CASS_OFFSITE_RECIPIENTS="$S/recipient" \
            CASS_OFFSITE_PING_FILE="$S/ping" \
            CASS_OCI_BIN="$ROOT/bin/oci" \
            "$@" \
            $WATCHDOG bash "$SCRIPT" "$S/dest"
    ) > "$S/out" 2>&1 || RC=$?
    if [ "$READONLY" = yes ]; then chmod 755 "$S/home" "$S/tmp" "$S/cwd"; fi
    after_run
}

show() { tail -n 40 "$1" 2> /dev/null | sed 's/^/          | /'; }

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

# starts <label> <text>: the last line of the output begins with <text>.
starts() {
    local last
    last=$(tail -n 1 "$S/out")
    case "$last" in
        "$2"*) ok "$1" ;;
        *) bad "$1
          expected a last line starting: [$2]
          actual:                         [$last]
$(show "$S/out")" ;;
    esac
}

# count <text>: how many recorded calls contain <text>.
count() { awk -v s="$1" 'index($0, s) { n++ } END { print n + 0 }' "$S/calls"; }
# The manifest's rows, its header aside, and their number.
rows() { grep -v '^#' "$S/dest/offsite-manifest.tsv" 2> /dev/null || true; }
row_count() { rows | grep -c . || true; }
# The objects in the bucket, by name, and their number.
objects() { (cd "$S/bucket" && find . -type f | sed 's|^\./||' | LC_ALL=C sort); }
object_count() { objects | grep -c . || true; }

# The files a run may have written outside dest/: every file under $S whose status changed
# after .start (a copy made with cp -p, tar or rsync -t keeps the backup's mtime, never its
# ctime), and every entry of /tmp and /var/tmp whose name was not there before, or the files
# under such a directory. /tmp is never walked beyond those new names, and what cannot be read
# is passed over.
written_outside() {
    find "$S" -path "$S/dest" -prune -o -type f -cnewer "$S/.start" -print 2> /dev/null || true
    tmp_names | awk 'NR == FNR { seen[$0] = 1; next } !($0 in seen)' "$S/tmp-before" - |
        while IFS= read -r e; do
            if [ -d "$e" ]; then find "$e" -type f 2> /dev/null || true
            elif [ -f "$e" ]; then printf '%s\n' "$e"; fi
        done
}

# Each of those files that is a backup byte for byte, or holds the marker as it is or gunzipped.
plain_text_outside() {
    local c sum
    written_outside | while IFS= read -r c; do
        [ -r "$c" ] || continue
        sum=$(sha 2> /dev/null < "$c" || true)
        if [ -n "$sum" ] && grep -qxF -- "$sum" "$S/fixture-sums"; then echo "$c (a backup, byte for byte)"
        elif grep -qaF -- "$MARKER" "$c" 2> /dev/null; then echo "$c (holds the marker)"
        elif gzip -dc 2> /dev/null < "$c" | grep -qaF -- "$MARKER"; then echo "$c (holds the marker, gzipped)"
        fi
    done
}

# What is wrong with the bucket against the manifest: an object a row names that is missing,
# is not an age file or lacks the SHA-256 its name carries, and any object that is a backup.
bucket_problems() {
    local obj f want
    rows | awk -F '\t' '{ print $6 }' | while IFS= read -r obj; do
        [ -n "$obj" ] || continue
        f="$S/bucket/$obj"
        if [ ! -f "$f" ]; then echo "$obj is not in the bucket"; continue; fi
        if [ "$(head -c 21 "$f")" != 'age-encryption.org/v1' ]; then echo "$obj is not an age file"; fi
        want=$(printf '%s\n' "$obj" | sed -nE 's/.*\.([0-9a-f]{64})\.age$/\1/p')
        if [ -z "$want" ] || [ "$want" != "$(sha < "$f")" ]; then echo "$obj does not have the SHA-256 its name carries"; fi
    done
    find "$S/bucket" -type f 2> /dev/null | while IFS= read -r f; do
        if grep -qxF -- "$(sha < "$f")" "$S/fixture-sums"; then echo "${f#"$S/bucket/"} is a backup in plain text"; fi
    done
}

# The six checks every run gets, whatever its scenario.
after_run() {
    local summary_re='^\[cass-backup-offsite\] (OK|FAILED)' n last found pings
    n=$(grep -cE "$summary_re" "$S/out" || true)
    last=$(tail -n 1 "$S/out")
    if [ "$n" = 1 ] && [[ "$last" =~ $summary_re ]]; then ok 'after the run: one summary line, the last'
    else bad "after the run: one summary line, the last ($n found)
$(show "$S/out")"; fi

    found=$(plain_text_outside)
    if [ -z "$found" ]; then ok 'after the run: no plain text outside the backup directory'
    else bad "after the run: no plain text outside the backup directory, but
$(printf '%s\n' "$found" | sed 's/^/          | /')"; fi

    if grep -qF 'oci FORBIDDEN' "$S/calls"; then bad "after the run: nothing asked of OCI that the host may not do
$(show "$S/calls")"
    else ok 'after the run: nothing asked of OCI that the host may not do'; fi

    pings=$(tail -n +"$((CALLS_AT + 1))" "$S/calls" | grep -c '^curl ' || true)
    if [ "$pings" -gt 1 ]; then bad "after the run: at most one ping ($pings)"
    elif [ "$pings" -eq 1 ] && [ "$RC" -ne 0 ] && [ "$RC" -ne 6 ]; then bad "after the run: no ping on exit $RC"
    elif grep -qF PUSHTOKEN7f3a "$S/curl-argv" "$S/out"; then bad "after the run: the push URL on curl's command line or in the output
$(show "$S/curl-argv")"
    else ok 'after the run: at most one ping, only on exit 0 or 6, the push URL kept off the command line'; fi

    found=''
    if [ -d "$S/dest/.offsite-work" ]; then found=$(find "$S/dest/.offsite-work" -mindepth 1 ! -type d 2> /dev/null || true); fi
    if [ -z "$found" ]; then ok 'after the run: nothing left in .offsite-work'
    else bad "after the run: nothing left in .offsite-work, but
$(printf '%s\n' "$found" | sed 's/^/          | /')"; fi

    found=$(bucket_problems)
    if [ -z "$found" ]; then ok "after the run: the manifest's objects in the bucket, each an age file with the SHA-256 its name carries; no backup among them"
    else bad "after the run: the bucket against the manifest
$(printf '%s\n' "$found" | sed 's/^/          | /')"; fi
}

# ---------------------------------------------------------------------------
echo "$SCRIPT"
# ---------------------------------------------------------------------------

if [ "$READONLY" = no ]; then
    skip 'home/, tmp/ and cwd/ stay writable during the runs: a directory made 0555 takes writes here'
fi

echo '  - the file cron runs'
FIRST_LINE="$(head -n 1 "$SCRIPT" 2> /dev/null || true)"
if [ "$FIRST_LINE" = '#!/usr/bin/env bash' ]; then ok 'its first line is #!/usr/bin/env bash'
else bad "its first line is #!/usr/bin/env bash, not [$FIRST_LINE]"; fi
# -U: grep under MSYS drops a CR before it matches unless told the file is binary; GNU grep
# elsewhere ignores the option.
CR=$'\r'
if [ ! -f "$SCRIPT" ]; then bad "it holds no CR: there is no file at $SCRIPT"
elif grep -qU -- "$CR" "$SCRIPT"; then bad 'it holds no CR, which cron would run as part of every line'
else ok 'it holds no CR'; fi

scenario 'S1 first run: the three backups go, the decoys stay'
run_offsite
eq 'exit status' 0 "$RC"
starts 'the last line' "$H OK: 3 uploaded, 0 already off-host; newest dump cass-2026-11-06-0317.sql.gz"
eq 'exactly three objects, each named after its file and the SHA-256 of its encrypted bytes' \
    "db/cass-2026-11-05-0317.sql.gz.<sha256>.age
db/cass-2026-11-06-0317.sql.gz.<sha256>.age
storage/cass-storage-2026-11-01.tar.gz.<sha256>.age" \
    "$(objects | sed -E 's/\.[0-9a-f]{64}\.age$/.<sha256>.age/')"
eq 'three age calls, each to the recipients file' 3 "$(count "age -R $S/recipient -o ")"
eq 'each writing into .offsite-work in the backup directory' 3 "$(count " -o $S/dest/.offsite-work/")"
eq 'three puts' 3 "$(count 'oci put ')"
eq 'three listings' 3 "$(count 'oci list ')"
eq 'three manifest rows' 3 "$(row_count)"
eq 'of eight tab-separated fields each' 0 "$(rows | awk -F '\t' 'NF != 8' | grep -c . || true)"
eq 'each for this destination' 3 "$(rows | awk -F '\t' '$2 == "xx-test-1/testns/test-bucket"' | grep -c . || true)"
eq 'each with its UTC time, the file, its bytes and mtime, the object, its bytes and MD5' '' \
    "$(rows | while IFS= read -r row; do
        IFS=$'\t' read -r t d f b m o ob md <<< "$row"
        [[ "$t" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || echo "$f: time [$t]"
        [ "$b" = "$(stat -c %s "$S/dest/$f" 2> /dev/null)" ] || echo "$f: bytes [$b]"
        [ "$m" = "$(stat -c %Y "$S/dest/$f" 2> /dev/null)" ] || echo "$f: mtime [$m]"
        case "$o" in */"$f".*.age) ;; *) echo "$f: object [$o]" ;; esac
        [ "$ob" = "$(stat -c %s "$S/bucket/$o" 2> /dev/null)" ] || echo "$f: object bytes [$ob]"
        [ -f "$S/bucket/$o" ] && [ "$md" = "$(md5_b64 < "$S/bucket/$o")" ] || echo "$f: MD5 [$md]"
    done)"
eq 'one ping, through the ping file' 1 "$(count "curl -K $S/ping")"
eq 'the decoys are where they were' yes \
    "$(if [ -f "$S/dest/cass-2026-11-06-0400.sql.partial" ] && [ -f "$S/dest/notes.txt" ] \
        && [ -d "$S/dest/cass-2026-11-03-0317.sql.gz" ]; then echo yes; else echo no; fi)"
if [ "$MODES" = yes ]; then
    eq 'the manifest is 0600' 600 "$(stat -c %a "$S/dest/offsite-manifest.tsv" 2> /dev/null)"
    eq '.offsite-work is 0700' 700 "$(stat -c %a "$S/dest/.offsite-work" 2> /dev/null)"
else
    skip 'the manifest 0600 and .offsite-work 0700: this filesystem does not honour chmod'
fi

scenario 'S2 nothing new to upload: no age or oci call, a ping'
run_offsite
: > "$S/calls"
run_offsite
eq 'exit status' 0 "$RC"
starts 'the last line' "$H OK: 0 uploaded, 3 already off-host"
eq 'no age call' 0 "$(count 'age -R')"
eq 'no oci call' 0 "$(count 'oci ')"
eq 'one ping' 1 "$(count 'curl -K ')"
eq 'still three manifest rows' 3 "$(row_count)"

scenario 'S3 an archive remade under its name: sent again, as a second storage/ object'
run_offsite
make_backup cass-storage-2026-11-01.tar.gz 2
: > "$S/calls"
run_offsite
eq 'exit status' 0 "$RC"
starts 'the last line' "$H OK: 1 uploaded, 2 already off-host"
eq 'one put' 1 "$(count 'oci put ')"
eq 'two storage/ objects for that archive' 2 "$(objects | grep -c '^storage/cass-storage-2026-11-01\.tar\.gz\.' || true)"
eq 'four manifest rows' 4 "$(row_count)"

scenario 'S4 age fails: exit 2, nothing sent'
: > "$S/age-fails"
run_offsite
eq 'exit status' 2 "$RC"
starts 'the last line' "$H FAILED (exit 2)"
eq 'no put' 0 "$(count 'oci put ')"
eq 'no manifest row' 0 "$(row_count)"
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S5 age writes plain text: exit 2, the bucket stays empty'
: > "$S/age-plain"
run_offsite
eq 'exit status' 2 "$RC"
starts 'the last line' "$H FAILED (exit 2)"
eq 'the bucket stays empty' 0 "$(object_count)"
has 'says why' 'not an age file'
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S6 the upload fails: exit 3'
: > "$S/put-fails"
run_offsite
eq 'exit status' 3 "$RC"
starts 'the last line' "$H FAILED (exit 3)"
has "shows the CLI's error" 'InternalServerError'
eq 'no manifest row' 0 "$(row_count)"
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S7 the bucket holds another size: exit 4'
: > "$S/put-truncates"
run_offsite
eq 'exit status' 4 "$RC"
starts 'the last line' "$H FAILED (exit 4)"
eq 'no manifest row' 0 "$(row_count)"
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S8 the object is not listed after its upload: exit 4'
: > "$S/list-empty"
run_offsite
eq 'exit status' 4 "$RC"
starts 'the last line' "$H FAILED (exit 4)"
has 'says so' 'not in the bucket'
eq 'no manifest row' 0 "$(row_count)"
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S9 one file fails, the others go: exit 2'
echo cass-2026-11-05-0317.sql.gz > "$S/age-fails-on"
run_offsite
eq 'exit status' 2 "$RC"
starts 'the last line' "$H FAILED (exit 2): 2 uploaded, 1 failed, 0 already off-host"
eq 'two objects' 2 "$(object_count)"
eq 'none for the file age failed on' 0 "$(objects | grep -c '^db/cass-2026-11-05-0317\.' || true)"
eq 'two manifest rows' 2 "$(row_count)"
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S10 the newest dump is stale: exit 5, everything still sent'
for f in cass-2026-11-05-0317.sql.gz cass-2026-11-06-0317.sql.gz cass-storage-2026-11-01.tar.gz; do
    touch -d "$(hours_ago 72)" "$S/dest/$f"
done
run_offsite
eq 'exit status' 5 "$RC"
starts 'the last line' "$H FAILED (exit 5)"
eq 'three objects' 3 "$(object_count)"
has 'says how old against the limit' 'h old (limit 30 h)'
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S11 no dump: exit 5, the archive still sent'
rm "$S/dest/cass-2026-11-05-0317.sql.gz" "$S/dest/cass-2026-11-06-0317.sql.gz"
run_offsite
eq 'exit status' 5 "$RC"
starts 'the last line' "$H FAILED (exit 5)"
eq 'one object' 1 "$(object_count)"
has 'says so' 'no dump'
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S12 the ping fails: exit 6, everything copied'
: > "$S/curl-fails"
run_offsite
eq 'exit status' 6 "$RC"
starts 'the last line' "$H FAILED (exit 6)"
eq 'three manifest rows' 3 "$(row_count)"
eq 'one curl call' 1 "$(count 'curl ')"
has 'says so' 'ping failed'

scenario 'S13 no ping file: exit 6, everything copied'
rm "$S/ping"
run_offsite
eq 'exit status' 6 "$RC"
starts 'the last line' "$H FAILED (exit 6)"
eq 'three manifest rows' 3 "$(row_count)"
eq 'no curl call' 0 "$(count 'curl ')"
has 'says so' 'no ping file'

scenario 'S14 no CLI: exit 1 before any age call'
run_offsite CASS_OCI_BIN="$S/none/oci"
eq 'exit status' 1 "$RC"
starts 'the last line' "$H FAILED (exit 1)"
has 'says so' 'OCI CLI not found'
eq 'no age call' 0 "$(count 'age -R')"

scenario 'S15 no recipients file: exit 1 before any age call'
rm "$S/recipient"
run_offsite
eq 'exit status' 1 "$RC"
starts 'the last line' "$H FAILED (exit 1)"
has 'names the missing file' "$S/recipient"
eq 'no age call' 0 "$(count 'age -R')"

scenario 'S16 a private key in the recipients file: exit 1, the key never printed'
# Built here rather than written into the repository, whose secret scanners would flag it.
SECRET_KEY="$(printf 'AGE-SECRET-KEY-1%s' "$(printf 'Q%.0s' $(seq 58))")"
printf '# created: 2026-11-06T09:00:00Z\n# public key: %s\n%s\n' "$PUBLIC_KEY" "$SECRET_KEY" > "$S/recipient"
run_offsite
eq 'exit status' 1 "$RC"
starts 'the last line' "$H FAILED (exit 1)"
has 'says what the file holds' 'private key'
lacks 'never prints the key' 'AGE-SECRET-KEY-1QQQQ'
eq 'no age call' 0 "$(count 'age -R')"

scenario 'S17 no bucket= line in the configuration: exit 1 before any age call'
grep -v '^bucket=' "$S/conf" > "$S/conf.new"
mv "$S/conf.new" "$S/conf"
run_offsite
eq 'exit status' 1 "$RC"
starts 'the last line' "$H FAILED (exit 1)"
has 'names the missing line' 'bucket='
eq 'no age call' 0 "$(count 'age -R')"

scenario 'S18 another run holds the lock: exit 1 before any age call'
: > "$S/lock-held"
run_offsite
eq 'exit status' 1 "$RC"
starts 'the last line' "$H FAILED (exit 1)"
has 'says so' 'another run holds'
eq 'no age call' 0 "$(count 'age -R')"

scenario 'S19 a file where .offsite-work belongs: exit 1, one FAILED line'
echo 'not a directory' > "$S/dest/.offsite-work"
run_offsite
eq 'exit status' 1 "$RC"
eq 'one FAILED line' 1 "$(grep -c '^\[cass-backup-offsite\] FAILED' "$S/out" || true)"
eq 'no object' 0 "$(object_count)"
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S20 a dump cut short, still being written or damaged: exit 2, the other two sent'
CUT="$S/dest/cass-2026-11-06-0317.sql.gz"
head -c "$(( $(stat -c %s "$CUT") / 2 ))" "$CUT" > "$S/dest/.cut"
mv "$S/dest/.cut" "$CUT"
touch -d "$(hours_ago 1)" "$CUT"
run_offsite
eq 'exit status' 2 "$RC"
starts 'the last line' "$H FAILED (exit 2): 2 uploaded, 1 failed"
eq 'two objects' 2 "$(object_count)"
eq 'none for the cut dump' 0 "$(objects | grep -c '^db/cass-2026-11-06-0317\.' || true)"
has 'says why' 'not a complete gzip'
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S21 the bucket holds the same size with another MD5: exit 4'
: > "$S/list-md5-differs"
run_offsite
eq 'exit status' 4 "$RC"
starts 'the last line' "$H FAILED (exit 4)"
eq 'no manifest row' 0 "$(row_count)"
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S22 the CLI hangs: exit 3, timed out'
: > "$S/put-hangs"
run_offsite CASS_OFFSITE_CLI_TIMEOUT=2
eq 'exit status' 3 "$RC"
starts 'the last line' "$H FAILED (exit 3)"
has 'says so' 'timed out'
eq 'no manifest row' 0 "$(row_count)"
eq 'no ping' 0 "$(count 'curl ')"

scenario 'S23 a backup rotated away mid-run: exit 0, the others sent'
echo cass-storage-2026-11-01.tar.gz > "$S/age-removes"
run_offsite
eq 'exit status' 0 "$RC"
starts 'the last line' "$H OK: 2 uploaded, 0 already off-host"
eq 'two objects' 2 "$(object_count)"
has 'says it is gone' 'gone'
eq 'one ping' 1 "$(count 'curl -K ')"

scenario 'S24 the real flock holds the lock: exit 1'
if [ -n "$REAL_FLOCK" ]; then
    mv "$ROOT/bin/flock" "$ROOT/flock.fake"
    "$REAL_FLOCK" "$S/dest/.offsite.lock" sleep 20 &
    HOLDER=$!
    sleep 1
    run_offsite
    if command -v pkill > /dev/null 2>&1; then pkill -P "$HOLDER" 2> /dev/null || true; fi
    kill "$HOLDER" 2> /dev/null || true
    wait "$HOLDER" 2> /dev/null || true
    mv "$ROOT/flock.fake" "$ROOT/bin/flock"
    eq 'exit status' 1 "$RC"
    starts 'the last line' "$H FAILED (exit 1)"
    has 'says so' 'another run holds'
    eq 'no age call' 0 "$(count 'age -R')"
else
    skip 'S24 needs a real flock, and this machine has none (CI has one)'
fi

scenario 'S25 a new destination sends everything again'
run_offsite
sed 's/^bucket=test-bucket$/bucket=test-bucket-2/' "$S/conf" > "$S/conf.new"
mv "$S/conf.new" "$S/conf"
echo test-bucket-2 > "$S/expected-bucket"
# The new bucket starts empty.
mv "$S/bucket" "$S/bucket-1"
mkdir "$S/bucket"
: > "$S/calls"
run_offsite
eq 'exit status' 0 "$RC"
starts 'the last line' "$H OK: 3 uploaded, 0 already off-host"
eq 'three puts' 3 "$(count 'oci put ')"
eq 'three objects in the new bucket' 3 "$(object_count)"
eq 'three more manifest rows, for the new destination' 3 \
    "$(rows | awk -F '\t' '$2 == "xx-test-1/testns/test-bucket-2"' | grep -c . || true)"

scenario 'S26 an object already under that name, with other bytes: exit 4, left as it was'
# The name the fake age's bytes for that dump give, taken by other bytes.
mkdir -p "$S/scratch"
FAKE="$S/scratch" "$ROOT/bin/age" --encrypt --recipients-file "$S/recipient" \
    --output "$S/scratch/expected.age" "$S/dest/cass-2026-11-05-0317.sql.gz"
TAKEN="db/cass-2026-11-05-0317.sql.gz.$(sha < "$S/scratch/expected.age").age"
mkdir -p "$S/bucket/db"
printf 'age-encryption.org/v1\nother bytes, from an earlier upload\n' > "$S/bucket/$TAKEN"
BEFORE="$(sha < "$S/bucket/$TAKEN")"
run_offsite
eq 'exit status' 4 "$RC"
starts 'the last line' "$H FAILED (exit 4)"
eq 'that object unchanged' "$BEFORE" "$(sha < "$S/bucket/$TAKEN")"
eq 'no manifest row for it' 0 "$(rows | awk -F '\t' -v o="$TAKEN" '$6 == o' | grep -c . || true)"
eq 'no ping' 0 "$(count 'curl ')"

# ---------------------------------------------------------------------------
echo
echo "$N scenarios: $PASSES ok, $SKIPS skipped, $FAILURES failed"
if [ "$FAILURES" -eq 0 ]; then
    echo 'cass-backup-offsite.test.sh: all assertions passed'
    exit 0
fi
echo "cass-backup-offsite.test.sh: $FAILURES assertion(s) failed" >&2
exit 1
