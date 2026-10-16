#!/usr/bin/env bash
# Split a plan markdown into header.md, task-NN.md and tail.md on "### Task N" headings,
# ignoring headings that sit inside ``` code fences (plans embed markdown in code blocks).
#   bash tools/claude/split-plan.sh docs/superpowers/plans/<plan>.md <outdir>
set -euo pipefail
plan="$1"; out="$2"; mkdir -p "$out"; rm -f "$out"/header.md "$out"/task-*.md "$out"/tail.md
awk -v out="$out" '
  BEGIN { cur = out "/header.md"; n = 0; fence = 0 }
  /^```/ { fence = !fence }
  !fence && /^### Task [0-9]+/ { match($0, /Task [0-9]+/); n = substr($0, RSTART+5, RLENGTH-5); cur = sprintf("%s/task-%02d.md", out, n) }
  !fence && /^## / && n > 0 { cur = out "/tail.md" }
  { print > cur }
' "$plan"
wc -l "$out"/*.md
