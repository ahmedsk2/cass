#!/usr/bin/env bash
# The off-host copy of the CASS backups. Every finished dump (cass-YYYY-MM-DD-HHMM.sql.gz) and
# weekly storage archive (cass-storage-YYYY-MM-DD.tar.gz) in the backup directory that is not
# off the host yet is encrypted with age to the owner's public key, uploaded to a private OCI
# Object Storage bucket, found again in a listing with its size and MD5, and recorded in
# offsite-manifest.tsv beside the backups. The heartbeat is pinged only when every finished
# backup is off-host and verified and the newest dump is younger than the limit.
#
# Runs ON the production host as root, from its own cron line after the nightly dump and the
# weekly archive (runbook "Off-host copies"), never in a container. It signs in as the
# instance, an instance principal, so no API key lives on the host. The bucket's policy lets
# the host create objects and list them, nothing else: it can add copies, but never read one
# back, overwrite it or delete it. A multipart upload needs OBJECT_READ and OBJECT_OVERWRITE,
# which the host does not have, so every upload is a single part (50 GiB at most), sent with
# --no-overwrite under a name that carries the SHA-256 of the encrypted bytes and so never has
# to replace anything.
#
# A file is sent only once `gzip -t` has read it through: a dump still being written, or a
# damaged one, is not. Nothing in plain text is written outside the backup directory: age
# reads the backup there and writes the encrypted copy into its .offsite-work/, removed once
# sent. The manifest keys each copy by destination (region/namespace/bucket), file, size and
# mtime, so a file remade under its name is sent again, and a changed destination gets every
# file again.
#
# Usage: cass-backup-offsite [dir]   (default /srv/backups/cass)
#
# Environment, with the production defaults:
#   CASS_OFFSITE_CONFIG          /root/.cass-offsite.conf: namespace=, bucket= and region= lines
#   CASS_OFFSITE_RECIPIENTS      /root/.cass-age-recipient: age1 public keys, one per line
#   CASS_OFFSITE_PING_FILE       /root/.cass-ping-offsite: one curl config line, url = "<push URL>"
#   CASS_OCI_BIN                 /opt/oci-cli/bin/oci
#   CASS_AGE_BIN                 age
#   CASS_OFFSITE_MAX_AGE_HOURS   30
#   CASS_OFFSITE_CLI_TIMEOUT     1800 seconds for each CLI call
#
# Exits with the first failure's code; one file's failure does not stop the others:
#   0  every finished backup is off-host and verified, the newest dump is under the limit,
#      the ping went out
#   1  the directory, a tool, the configuration, the recipients file, the lock or the manifest
#      is missing or wrong, another run holds the lock, or something failed unexpectedly
#   2  a file is not a complete gzip, age failed on it, or what age wrote is not an age file;
#      that file was not sent
#   3  an upload failed or timed out
#   4  an object is missing after its upload, or its size or MD5 differs
#   5  there is no dump, or the newest is older than CASS_OFFSITE_MAX_AGE_HOURS or dated in
#      the future
#   6  everything was copied, but the ping failed or its file is missing
#   129, 130, 143  stopped by SIGHUP, SIGINT or SIGTERM, once the command in hand returned
# Every run ends with one line, "[cass-backup-offsite] OK: ..." or
# "[cass-backup-offsite] FAILED (exit N): ...", a stop by a signal included. Only SIGKILL,
# which nothing can catch, leaves none.
#
# cass-backup-offsite.test.sh beside it runs it against fakes for oci, age, curl and flock.
set -euo pipefail
# A failure inside $(...) stops the script too, instead of handing back partial output.
shopt -s inherit_errexit
# The encrypted copies, the manifest and the lock are root's alone, as the backups are.
umask 0077

DEST="${1:-/srv/backups/cass}"
# Absolute, as docker/storage-backup.sh makes it, so that every message names one place.
case "$DEST" in
    /*) ;;
    *) DEST="$(pwd)/$DEST" ;;
esac
CONFIG="${CASS_OFFSITE_CONFIG:-/root/.cass-offsite.conf}"
RECIPIENTS="${CASS_OFFSITE_RECIPIENTS:-/root/.cass-age-recipient}"
PING_FILE="${CASS_OFFSITE_PING_FILE:-/root/.cass-ping-offsite}"
OCI="${CASS_OCI_BIN:-/opt/oci-cli/bin/oci}"
AGE="${CASS_AGE_BIN:-age}"
MAX_AGE_HOURS="${CASS_OFFSITE_MAX_AGE_HOURS:-30}"
CLI_TIMEOUT="${CASS_OFFSITE_CLI_TIMEOUT:-1800}"
WORK="$DEST/.offsite-work"
MANIFEST="$DEST/offsite-manifest.tsv"
LOCK="$DEST/.offsite.lock"
UPLOADED=0 FAILED=0 ALREADY=0 RC=0 FIRST='' STEP='starting' DONE='' TMP='' NEWEST='' HOURS=''

say() { printf '[cass-backup-offsite] %s\n' "$*"; }
summary() {
    if [ "$RC" -eq 0 ]; then say "OK: $UPLOADED uploaded, $ALREADY already off-host; newest dump $NEWEST, $HOURS h old; pinged"
    else say "FAILED (exit $RC): $UPLOADED uploaded, $FAILED failed, $ALREADY already off-host; $FIRST"; fi
}
# problem <exit code> <message> [files failed]: said on stderr; the first one sets the exit code.
problem() {
    printf '[cass-backup-offsite] %s\n' "$2" >&2
    FAILED=$((FAILED + ${3:-0}))
    if [ "$RC" -eq 0 ]; then RC=$1 FIRST=$2; fi
}
finish() { DONE=yes; summary; exit "$RC"; }
fail() { problem "$1" "$2"; finish; }
# Whatever set -e stops on gets the same one last line, below that command's own error.
on_exit() {
    local rc=$?
    if [ -n "$TMP" ]; then rm -f "$TMP"; fi
    if [ -z "$DONE" ]; then
        DONE=yes RC=1
        if [ "$rc" -eq 0 ]; then FIRST="stopped unexpectedly while $STEP"
        else FIRST="stopped by an unexpected exit $rc while $STEP"; fi
        summary
        exit 1
    fi
}
# on_signal <name> <exit code>: bash runs it once the command in hand returns, an upload
# included, so the run stops there with its one last line and the signal's exit code.
on_signal() {
    if [ -z "$DONE" ]; then DONE=yes RC=$2 FIRST="stopped by SIG$1 while $STEP"; summary; fi
    exit "$RC"
}
trap on_exit EXIT
trap 'on_signal HUP 129' HUP
trap 'on_signal INT 130' INT
trap 'on_signal TERM 143' TERM

# md5_b64 <file>: the base64 MD5 that OCI checks a single-part upload against.
md5_b64() { python3 -c 'import base64, hashlib, sys
h = hashlib.md5(usedforsecurity=False)
for chunk in iter(lambda: sys.stdin.buffer.read(1 << 20), b""):
    h.update(chunk)
print(base64.b64encode(h.digest()).decode())' < "$1"; }
# listed <object>: "<size> <md5>" of that object in the listing on stdin, nothing when absent.
listed() { python3 -c 'import json, sys
raw = sys.stdin.read().strip()
items = (json.loads(raw).get("data") or []) if raw else []
match = [i for i in items if i.get("name") == sys.argv[1]]
if match:
    print(match[0].get("size"), match[0].get("md5"))' "$1"; }

STEP='checking the setup'
if [ ! -d "$DEST" ]; then fail 1 "no backup directory $DEST"; fi
if [ ! -x "$OCI" ]; then fail 1 "OCI CLI not found at $OCI (runbook: Installing the off-host copy)"; fi
for tool in "$AGE" python3 gzip sha256sum curl flock timeout; do
    command -v "$tool" > /dev/null || fail 1 "$tool not found"
done
if ! [[ "$MAX_AGE_HOURS" =~ ^[1-9][0-9]*$ ]]; then fail 1 "CASS_OFFSITE_MAX_AGE_HOURS is not a whole number of hours: $MAX_AGE_HOURS"; fi
if ! [[ "$CLI_TIMEOUT" =~ ^[1-9][0-9]*$ ]]; then fail 1 "CASS_OFFSITE_CLI_TIMEOUT is not a whole number of seconds: $CLI_TIMEOUT"; fi

# One run at a time: cron's, and a hand run after a migration release's cass-backup.
exec 9>> "$LOCK" || fail 1 "cannot open the lock file $LOCK"
flock -n 9 || fail 1 "another run holds $LOCK"

# Read line by line and never sourced: the file names a destination, it runs nothing.
if [ ! -f "$CONFIG" ] || [ ! -r "$CONFIG" ]; then fail 1 "no configuration at $CONFIG (runbook: Installing the off-host copy)"; fi
NS='' BUCKET='' REGION=''
while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    case "$line" in ''|'#'*) continue ;; esac
    key="${line%%=*}"
    value="${line#*=}"
    if [ "$key" = "$line" ] || ! [[ "$value" =~ ^[A-Za-z0-9._-]+$ ]]; then
        fail 1 "$CONFIG: each line is key=value, the value letters, digits, dots, dashes and underscores only"
    fi
    case "$key" in
        namespace) NS="$value" ;;
        bucket) BUCKET="$value" ;;
        region) REGION="$value" ;;
        *) fail 1 "$CONFIG: unknown key $key (namespace, bucket and region only)" ;;
    esac
done < "$CONFIG"
if [ -z "$NS" ]; then fail 1 "$CONFIG has no namespace= line"; fi
if [ -z "$BUCKET" ]; then fail 1 "$CONFIG has no bucket= line"; fi
if [ -z "$REGION" ]; then fail 1 "$CONFIG has no region= line"; fi

if [ ! -f "$RECIPIENTS" ] || [ ! -r "$RECIPIENTS" ]; then fail 1 "no age recipients file at $RECIPIENTS (runbook: Installing the off-host copy)"; fi
# A private key here would sit on the host beside the copies it opens. No line is ever echoed.
if grep -qi 'AGE-SECRET-KEY-' "$RECIPIENTS"; then
    fail 1 "$RECIPIENTS holds an age private key: delete the file now, make a new key pair on the PC and put only its age1 public key here"
fi
KEYS=0
while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    case "$line" in ''|'#'*) continue ;; esac
    if ! [[ "$line" =~ ^age1[0-9a-z]{58}$ ]]; then fail 1 "$RECIPIENTS: a line that is not an age1 public key (blank and # lines aside)"; fi
    KEYS=$((KEYS + 1))
done < "$RECIPIENTS"
if [ "$KEYS" -eq 0 ]; then fail 1 "$RECIPIENTS holds no age1 public key"; fi

STEP="preparing $WORK"
mkdir -p "$WORK"
chmod 700 "$WORK"
# A run cut short (a reboot, a kill) leaves its encrypted copy half written; it was never sent.
rm -f "$WORK"/*.age.partial
if [ ! -e "$MANIFEST" ]; then
    printf '# %s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' 'time (UTC)' destination file bytes mtime object 'object bytes' 'MD5 (base64)' > "$MANIFEST"
fi
# Found out before any upload: a copy uploaded and then left out of the manifest would be
# uploaded again every night.
if ! : >> "$MANIFEST"; then fail 1 "cannot append to $MANIFEST"; fi
# A last row cut short (a full disk, a power cut) lacks its newline; the next row must not join it.
if [ -n "$(tail -c 1 "$MANIFEST")" ]; then printf '\n' >> "$MANIFEST"; fi

DESTINATION="$REGION/$NS/$BUCKET"
OCI_ARGS=(--auth instance_principal --region "$REGION" --namespace "$NS" --bucket-name "$BUCKET")
STEP='listing the backups'
LIST=$(find "$DEST" -maxdepth 1 -type f \( -name 'cass-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]-[0-9][0-9][0-9][0-9].sql.gz' \
    -o -name 'cass-storage-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].tar.gz' \) -printf '%f\n' | LC_ALL=C sort)
NEWEST=$(grep -E '^cass-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{4}\.sql\.gz$' <<< "$LIST" | tail -n 1 || true)
if [ -z "$NEWEST" ]; then problem 5 "no dump in $DEST"
else
    HOURS=$(( ($(date +%s) - $(stat -c %Y "$DEST/$NEWEST")) / 3600 ))
    if [ "$HOURS" -lt 0 ]; then problem 5 "the newest dump, $NEWEST, is dated $(( -HOURS )) h in the future: check the host's clock"
    elif [ "$HOURS" -ge "$MAX_AGE_HOURS" ]; then problem 5 "the newest dump, $NEWEST, is $HOURS h old (limit $MAX_AGE_HOURS h)"; fi
fi

# An array, not a loop over stdin: a child reading stdin would swallow the rest of the list.
mapfile -t FILES <<< "$LIST"
for f in "${FILES[@]}"; do
    [ -n "$f" ] || continue
    STEP="copying $f"
    # cass-backup, which a release also runs before a migration, may rotate a file away at any
    # hour; a file gone before or while it is read is no failure.
    if [ ! -f "$DEST/$f" ]; then say "$f: gone (rotated away?)"; continue; fi
    # One substitution per assignment: in "a=$(x) b=$(y)" a failing x is masked by y.
    size=$(stat -c %s "$DEST/$f")
    mtime=$(stat -c %Y "$DEST/$f")
    if awk -F '\t' -v d="$DESTINATION" -v f="$f" -v s="$size" -v m="$mtime" \
        '$2 == d && $3 == f && $4 == s && $5 == m { hit = 1 } END { exit !hit }' "$MANIFEST"; then
        ALREADY=$((ALREADY + 1)); continue
    fi
    # The dump's final name is written by a redirect, so a hand run beside cass-backup could
    # see it half written; gzip -t reads it through and writes nothing.
    if ! gzip -t "$DEST/$f"; then
        if [ ! -f "$DEST/$f" ]; then say "$f: gone (rotated away?)"; continue; fi
        problem 2 "$f: not a complete gzip file (still being written, or damaged) - not sent" 1; continue
    fi
    case "$f" in cass-storage-*) kind=storage ;; *) kind=db ;; esac
    TMP="$WORK/$f.age.partial"
    if ! "$AGE" --encrypt --recipients-file "$RECIPIENTS" --output "$TMP" "$DEST/$f" < /dev/null; then
        rm -f "$TMP"
        if [ ! -f "$DEST/$f" ]; then say "$f: gone (rotated away?)"; continue; fi
        problem 2 "$f: age failed" 1; continue
    fi
    if [ "$(head -c 21 "$TMP")" != 'age-encryption.org/v1' ]; then
        rm -f "$TMP"; problem 2 "$f: what age wrote is not an age file - refusing to upload it" 1; continue
    fi
    hash=$(sha256sum "$TMP" | cut -d ' ' -f 1)
    osize=$(stat -c %s "$TMP")
    md5=$(md5_b64 "$TMP")
    object="$kind/$f.$hash.age"
    if timeout "$CLI_TIMEOUT" "$OCI" os object put "${OCI_ARGS[@]}" --name "$object" --file "$TMP" \
            --content-md5 "$md5" --no-multipart --no-overwrite < /dev/null > /dev/null; then :
    else
        rc=$?; rm -f "$TMP"
        if [ "$rc" -eq 124 ]; then why="timed out after $CLI_TIMEOUT s"; else why="exit $rc"; fi
        problem 3 "$object: the upload failed ($why)" 1; continue
    fi
    rm -f "$TMP"; TMP=''
    if ! listing=$(timeout "$CLI_TIMEOUT" "$OCI" os object list "${OCI_ARGS[@]}" --prefix "$object" --fields name,size,md5 < /dev/null); then
        problem 4 "$object: could not list it after the upload" 1; continue
    fi
    if ! found=$(listed "$object" <<< "$listing"); then problem 4 "$object: the listing could not be read" 1; continue; fi
    if [ -z "$found" ]; then problem 4 "$object: not in the bucket after the upload" 1; continue; fi
    if [ "$found" != "$osize $md5" ]; then problem 4 "$object: the bucket holds $found (bytes, MD5), expected $osize $md5" 1; continue; fi
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$(date -u +%FT%TZ)" "$DESTINATION" "$f" "$size" "$mtime" "$object" "$osize" "$md5" >> "$MANIFEST"
    UPLOADED=$((UPLOADED + 1)); say "$object ($osize bytes) uploaded and verified"
done

STEP='pinging'
if [ "$RC" -eq 0 ]; then
    if [ ! -f "$PING_FILE" ]; then problem 6 "no ping file at $PING_FILE"
    elif ! curl -fsS -m 10 --retry 3 -o /dev/null -K "$PING_FILE"; then problem 6 "the ping failed"; fi
fi
finish
