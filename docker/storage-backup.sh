#!/bin/sh
# Weekly backup of the uploaded-files volume (spec section 8's other half: the
# database dump restores rows that point at objects, and `docker/backup.sh`
# does not carry the objects).
#
# Runs on the HOST, not in a container, for the same reason as its sibling: it
# needs `docker inspect`/`docker run` and a host path, so that a compromised app
# container can neither read nor delete the backups. The runbook's step is to
# copy it out of the image to /usr/local/bin/cass-storage-backup and add the
# weekly cron line.
#
# Usage: cass-storage-backup [destination]   (default /srv/backups/cass)
#
# Environment:
#   CASS_APP_CONTAINER              name the app container explicitly, skipping
#                                   the detection below. Used when it is
#                                   running; a stale value warns and falls back.
#   CASS_STORAGE_BACKUP_KEEP_DAYS   rotation window in days (default 56)
#   CASS_BACKUP_TAR_IMAGE           image the tar runs in (default alpine:3)
set -eu

# The archive is every uploaded abstract and every organization logo. 0077 for
# the same reason backup.sh sets it: without it the file lands 0644 and any
# local account on this host can read the lot.
umask 0077

DEST="${1:-/srv/backups/cass}"
# $DEST becomes the source of a `docker run -v` below, and docker reads a
# RELATIVE source as a named volume rather than a path - so `cass-storage-backup
# backups/` would write the archive into a new docker volume called "backups"
# instead of ./backups. Make it absolute before it can be misread.
case "$DEST" in
    /*) ;;
    *)  DEST="$(pwd)/$DEST" ;;
esac
KEEP_DAYS="${CASS_STORAGE_BACKUP_KEEP_DAYS:-56}"
TAR_IMAGE="${CASS_BACKUP_TAR_IMAGE:-alpine:3}"
STAMP="$(date +%F)"

# docker-compose.production.yml mounts the cass-storage volume here.
MOUNT='/var/www/html/storage/app'
ARCHIVE="cass-storage-$STAMP.tar.gz"

# Identify the container by what it IS, never by what it is called - see the
# long note in docker/backup.sh. Coolify names it
# app-vag136azl5q2bv87qqzgblo1-1 and labels it com.docker.compose.project=
# vag136azl5q2bv87qqzgblo1, so nothing in the name or the labels says "cass".
# The compose file's APP_NAME does.
container_env() {
    docker inspect "$1" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null
}

# The volume backing $MOUNT on a container, empty if it has no such mount.
container_storage_volume() {
    docker inspect "$1" --format \
        "{{range .Mounts}}{{if eq .Destination \"$MOUNT\"}}{{.Name}}{{end}}{{end}}" 2>/dev/null
}

find_app_container() {
    _candidates="$(docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}')"

    for _name in $_candidates; do
        if container_env "$_name" | grep -qFx 'APP_NAME=CASS'; then
            printf '%s\n' "$_name"
            return 0
        fi
    done

    # Fallback for a deployment that overrode APP_NAME: an app service that
    # mounts something at storage/app is this application whatever it calls
    # itself. Weaker than the env check, so it is only reached second.
    for _name in $_candidates; do
        if [ -n "$(container_storage_volume "$_name")" ]; then
            printf '%s\n' "$_name"
            return 0
        fi
    done

    return 0
}

is_running() {
    docker ps --format '{{.Names}}' | grep -qFx "$1"
}

CONTAINER=''
if [ -n "${CASS_APP_CONTAINER:-}" ]; then
    if is_running "$CASS_APP_CONTAINER"; then
        CONTAINER="$CASS_APP_CONTAINER"
    else
        echo "[cass-storage-backup] CASS_APP_CONTAINER=$CASS_APP_CONTAINER is not running - detecting instead" >&2
    fi
fi

if [ -z "$CONTAINER" ]; then
    CONTAINER="$(find_app_container || true)"
fi

if [ -z "$CONTAINER" ]; then
    echo "[cass-storage-backup] no running container has com.docker.compose.service=app with APP_NAME=CASS in its environment or a mount at ${MOUNT}" >&2
    echo "[cass-storage-backup] list the candidates with: docker ps --filter label=com.docker.compose.service=app" >&2
    echo "[cass-storage-backup] or name it explicitly with CASS_APP_CONTAINER=<name>" >&2
    exit 1
fi

# Resolve the volume from the container's own mounts, never by name. Compose
# prefixes named volumes with the project name and Coolify adds its own
# identifier, so `-v cass-storage:/data` does not fail - it silently CREATES a
# new, empty volume and archives nothing.
VOLUME="$(container_storage_volume "$CONTAINER")"
if [ -z "$VOLUME" ]; then
    echo "[cass-storage-backup] $CONTAINER has no volume mounted at ${MOUNT}" >&2
    exit 1
fi

echo "[cass-storage-backup] app container: $CONTAINER, volume: $VOLUME"

mkdir -p "$DEST"
# Set every run, so a chmod somebody did by hand cannot quietly loosen it.
chmod 700 "$DEST"

# Read-only mount of the data, and the tar runs in a throwaway container rather
# than on the host so the host needs no tar-visible copy of the volume.
# Written through a .partial name so an interrupted run never leaves a
# truncated file that looks like a week's archive.
if ! docker run --rm \
        -v "$VOLUME":/data:ro \
        -v "$DEST":/out \
        "$TAR_IMAGE" \
        tar czf "/out/$ARCHIVE.partial" -C /data .; then
    echo "[cass-storage-backup] tar failed" >&2
    rm -f "$DEST/$ARCHIVE.partial"
    exit 1
fi

# Count entries, NOT bytes. The size guard this replaces (>10240 bytes) fired
# on production against a correct archive: storage/app early in a conference's
# life holds a handful of small files and compresses well under 10 KB, while an
# archive of the WRONG (freshly created, empty) volume is about forty-five
# bytes. Byte size cannot tell those apart; entry count can. `tar czf . ` of a
# real store lists at least ./, ./private/ and ./public/ - an empty volume
# lists only ./.
#
# Listed to a file first: POSIX sh has no `pipefail`, so `tar tzf ... | wc -l`
# would report 0 for a tar that failed to read and `set -e` would not see it.
tar tzf "$DEST/$ARCHIVE.partial" > "$DEST/$ARCHIVE.listing"
ENTRIES="$(wc -l < "$DEST/$ARCHIVE.listing" | tr -d ' ')"
rm -f "$DEST/$ARCHIVE.listing"

if [ "$ENTRIES" -lt 3 ]; then
    echo "[cass-storage-backup] archive of $VOLUME holds only ${ENTRIES} entries - refusing it" >&2
    echo "[cass-storage-backup] that is an archive of an empty or wrong volume, not of ${MOUNT}" >&2
    rm -f "$DEST/$ARCHIVE.partial"
    exit 1
fi

mv "$DEST/$ARCHIVE.partial" "$DEST/$ARCHIVE"
chmod 600 "$DEST/$ARCHIVE"

SIZE="$(stat -c %s "$DEST/$ARCHIVE")"

find "$DEST" -name 'cass-storage-*.tar.gz' -type f -mtime "+${KEEP_DAYS}" -delete
find "$DEST" -name 'cass-storage-*.partial' -type f -mtime +1 -delete

echo "[cass-storage-backup] $DEST/$ARCHIVE (${SIZE} bytes, ${ENTRIES} entries), keeping ${KEEP_DAYS} days"
