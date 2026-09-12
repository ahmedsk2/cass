#!/bin/sh
# Nightly database backup (spec section 8: "nightly mysqldump to the data
# volume with 14-day rotation").
#
# Runs on the HOST, not in a container: it docker-execs into the mysql
# container and writes to a host path, deliberately, so that a compromised app
# container cannot reach or delete the backups. It is committed here so it is
# reviewed and versioned like everything else; the runbook's step is to copy it
# to /usr/local/bin/cass-backup and add the cron line.
#
# Usage: cass-backup [destination]   (default /srv/backups/cass)
set -eu

# 0077: the dump is a complete copy of every author's name, address, phone
# number and abstract, every reviewer's comments and every user's password
# hash. Without this it lands 0644 in a 0755 directory and any local account
# on this host can read it - which would make the whole "keep the backups off
# the app container" argument above pointless.
umask 0077

DEST="${1:-/srv/backups/cass}"
KEEP_DAYS="${CASS_BACKUP_KEEP_DAYS:-14}"
STAMP="$(date +%F-%H%M)"

CONTAINER="$(docker ps --filter label=com.docker.compose.service=mysql --format '{{.Names}}' | grep -i cass | head -1)"

if [ -z "$CONTAINER" ]; then
    echo "[cass-backup] no cass mysql container is running" >&2
    exit 1
fi

mkdir -p "$DEST"
# Set every run, so a chmod somebody did by hand cannot quietly loosen it.
chmod 700 "$DEST"

# MYSQL_PWD from the container's own environment, so the password is never an
# argv the host's process list can show. --single-transaction so InnoDB is
# consistent without locking the site during a deadline.
#
# --no-tablespaces: without it mysqldump queries INFORMATION_SCHEMA.FILES for
# CREATE TABLESPACE, which needs the GLOBAL `PROCESS` privilege (MySQL 8.0.21
# and later). The `cass` user the image creates from MYSQL_USER holds ALL
# PRIVILEGES ON cass.* and nothing global, so without this flag every run dies
# with "Access denied; you need (at least one of) the PROCESS privilege(s)"
# and there is no backup at all.
# Not piped into gzip: POSIX sh has no `pipefail`, so `set -e` cannot see a
# mysqldump that failed mid-stream - the gzip succeeds and the run looks clean.
if ! docker exec "$CONTAINER" sh -c '
    MYSQL_PWD="$MYSQL_PASSWORD" mysqldump \
        --single-transaction \
        --quick \
        --no-tablespaces \
        --default-character-set=utf8mb4 \
        --routines \
        --events \
        -h 127.0.0.1 -u"$MYSQL_USER" "$MYSQL_DATABASE"
' > "$DEST/cass-$STAMP.sql.partial"; then
    echo "[cass-backup] mysqldump failed" >&2
    rm -f "$DEST/cass-$STAMP.sql.partial"
    exit 1
fi

# A dump that died mid-stream still gzips to well over 4 KB and would rotate a
# good backup away fourteen days later. mysqldump writes this line last.
if ! tail -5 "$DEST/cass-$STAMP.sql.partial" | grep -q '^-- Dump completed'; then
    echo "[cass-backup] dump is truncated - refusing it" >&2
    rm -f "$DEST/cass-$STAMP.sql.partial"
    exit 1
fi

# Compressed only once it is whole, so a run interrupted at 03:07 never leaves
# a truncated file that looks like a backup and restores as half a database.
gzip -9 -c "$DEST/cass-$STAMP.sql.partial" > "$DEST/cass-$STAMP.sql.gz"
rm -f "$DEST/cass-$STAMP.sql.partial"
chmod 600 "$DEST/cass-$STAMP.sql.gz"

SIZE="$(stat -c %s "$DEST/cass-$STAMP.sql.gz")"

# An empty-ish gzip is a dump that failed and still exited 0 somewhere in the
# pipe. Refuse it rather than rotating a good backup away in favour of it.
if [ "$SIZE" -lt 4096 ]; then
    echo "[cass-backup] dump is only ${SIZE} bytes - refusing it" >&2
    rm -f "$DEST/cass-$STAMP.sql.gz"
    exit 1
fi

find "$DEST" -name 'cass-*.sql.gz' -type f -mtime "+${KEEP_DAYS}" -delete
find "$DEST" -name '*.partial' -type f -mtime +1 -delete
# $DEST needs room for fourteen compressed dumps AND one uncompressed one.

echo "[cass-backup] $DEST/cass-$STAMP.sql.gz (${SIZE} bytes), keeping ${KEEP_DAYS} days"
