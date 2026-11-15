#!/usr/bin/env bash
# bash tools/docs/new-identifiers.test.sh
#
# new-identifiers.sh on a throwaway repository with a base branch and a topic branch. A value the
# topic adds to a file is reported with that file's name, even when another touched file held it at
# the base; a value the same file already held, or an allow-listed one, is not; and a branch that
# touches nothing reports nothing.
set -euo pipefail
script="$(cd "$(dirname "$0")" && pwd)/new-identifiers.sh"
repo="$(mktemp -d)"
trap 'rm -rf "$repo"' EXIT
cd "$repo"
g() { git -c user.name=test -c user.email=test@example.com -c commit.gpgsign=false -c core.autocrlf=false "$@"; }
# The first octet is an argument so that this file holds no address itself: the script reports
# nothing from it when it runs over a branch that touches this file.
o=10
g -c init.defaultBranch=main init -q
printf 'host %s.1.2.3\n' "$o" > a.md
printf 'nothing here\n' > b.md
printf 'loopback 127.0.0.1\n' > c.md
g add . && g commit -qm base
g switch -qc topic
printf 'host %s.1.2.3, unchanged value\n' "$o" > a.md
printf 'copied %s.1.2.3\n' "$o" > b.md
printf 'loopback 127.0.0.1 and 192.0.2.7\n' > c.md
printf 'new file %s.9.8.7\n' "$o" > d.md
g add . && g commit -qm topic

fail=0
rc=0
out="$(bash "$script" main)" || rc=$?
expected="$(printf 'b.md: %s.1.2.3\nd.md: %s.9.8.7' "$o" "$o")"
[ "$rc" -eq 1 ] || { echo "FAIL: exit $rc on the topic branch, expected 1"; fail=1; }
[ "$out" = "$expected" ] || { printf 'FAIL: on the topic branch the output was:\n%s\nexpected:\n%s\n' "$out" "$expected"; fail=1; }

g switch -q main
rc=0
out="$(bash "$script" main)" || rc=$?
{ [ "$rc" -eq 0 ] && [ -z "$out" ]; } || { printf 'FAIL: on the base itself, exit %s and output:\n%s\n' "$rc" "$out"; fail=1; }

[ "$fail" -eq 0 ] && echo "ok: new-identifiers.sh, 2 cases"
exit "$fail"
