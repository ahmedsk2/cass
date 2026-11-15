#!/usr/bin/env bash
# Prints, as "<file>: <value>", every identifier-shaped value that a file this branch touches holds
# at HEAD but did not hold at the merge base: an empty output (and exit 0) means the branch added
# none. The comparison is per file, so a value copied or moved from one file into another shows.
#   bash tools/docs/new-identifiers.sh [<base>]   (from the repository root; the base defaults to origin/main)
#   bash tools/docs/new-identifiers.sh --sample <file>   (the patterns and the allow-list over one file)
set -euo pipefail
base="${1:-origin/main}"
P='ocid1\.[a-z0-9._-]+'
P="$P"'|([0-9]{1,3}\.){3}[0-9]{1,3}'
P="$P"'|[0-9a-f]{32,}'
P="$P"'|(^|[^A-Za-z0-9])[a-z0-9]{24}([^A-Za-z0-9]|$)'
P="$P"'|([a-z0-9-]+\.)*towardp(cc|icu)\.com'
P="$P"'|ntfy\.sh/[A-Za-z0-9_-]+'
P="$P"'|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}'
P="$P"'|ssh [^`]*[a-z_][a-z0-9_-]*@[^ `]+'
P="$P"'|\.ssh/[A-Za-z0-9._-]+'
P="$P"'|[Cc]:\\Users\\[A-Za-z0-9._-]+'
P="$P"'|/c/Users/[A-Za-z0-9._-]+'
# Loopback, the RFC 5737 documentation ranges, the metadata address, the project's own public
# names and addresses, and RFC 2606 or test-only mail domains.
ALLOW='^(127\.[0-9.]+|169\.254\.169\.254|192\.0\.2\.[0-9]+|198\.51\.100\.[0-9]+|203\.0\.113\.[0-9]+'
ALLOW="$ALLOW"'|cass\.towardpcc\.com|cass@towardp(cc|icu)\.com|noreply@anthropic\.com|[^@]*@(example\.(com|org)|cass\.test|import\.invalid))$'
clean() { sed -E 's/^[^A-Za-z0-9.~\/]+|[^A-Za-z0-9]+$//g' | sort -u; }
if [ "$base" = "--sample" ]; then grep -ohE "$P" "$2" | clean | grep -vE "$ALLOW" || true; exit 0; fi
mb="$(git merge-base "$base" HEAD)"
mapfile -t files < <(git diff --name-only "$mb" HEAD)
values() { { git grep -ohE "$P" "$1" -- "$2" 2>/dev/null || true; } | clean; }
found=0
for f in "${files[@]}"; do
    while IFS= read -r v; do printf '%s: %s\n' "$f" "$v"; found=1; done \
        < <(comm -13 <(values "$mb" "$f") <(values HEAD "$f") | grep -vE "$ALLOW" || true)
done
exit "$found"
