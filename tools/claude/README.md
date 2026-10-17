# Automation used to build CASS v2

The method and the scripts the orchestrating Claude session used for Plans 2–6, kept so the
method survives a change of machine. None of them holds a secret; the host address and the
Coolify application uuid come from the environment or from the private project memory.
`CLAUDE.md` "How work is done" is the short version; this file has the detail.

## Models and cost

- The orchestrator (currently Fable 5.1) decides, briefs agents, reads their one-line reports,
  verifies gates by exit code and talks to the owner. It does not read plans, vendor code, diffs
  or long logs itself; it asks an agent for the answer instead. It does read the pre-merge
  review's list of sensitive files and migrations before asking the owner to approve a merge.
- The strongest available model (currently Opus, at maximum effort) does the substantive work:
  writing and reviewing plans, implementing tasks, reviewing code, fixing findings, writing
  scripts, asset work. Multi-step work runs through the Workflow tool; single tasks through the
  Agent tool; both in the background.
- A mid-tier model (currently Sonnet) only for a mechanical one-file edit with a complete spec.
  Never a small model (Haiku) for code.

## Owner review points

Adopted from the outside review of 2026-10-16 (`docs/reviews/2026-10-16-expert-brief.md`, Q2).

| Point | The owner reads | Then |
|---|---|---|
| 1. Plan approval | The plan's summary and its list of migrations, policies, environment variables and sensitive files, not the plan | Approves, or asks |
| 2. Pre-merge | The pre-merge review in a fixed format: sensitive files with one line each, migrations, tests added, anything skipped, gate log paths | Approves the merge |
| 3. Deploy | The CI result and the `docs/HANDOVER.md` line for this release | Unlocks production for 60 minutes (`touch .claude/deploy-unlocked`) and watches the deploy |
| 4. Post-deploy | `cass:health`, `migrate:status`, the two page timings | Confirms `docs/HANDOVER.md` is updated in the same session |

Without the owner in the session, an agent never: pushes to `main`; merges a pull request;
deploys or runs anything on the host, in Coolify, Cloudflare or OCI; edits `.github/`,
`Dockerfile`, a compose file or `.claude/`; deletes, skips or weakens a test; updates a pinned
digest; reads `Legacy/`; raises a major version constraint; runs a `tinker` write anywhere.
`.claude/settings.json`, `.claude/hooks/guard-production.mjs` and `tools/git-hooks/pre-push`
enforce the production, data, force-push and push-to-`main` items; `gh pr merge` always asks.

Length budget for briefs and outputs: a task file under 150 lines; a review report under 40
lines plus its findings list; a backlog entry of three lines plus a link to a decision file; a
runbook procedure under 40 lines. Anything longer becomes a file in `docs/decisions/` and is
linked.

| File | Runs where | What it does |
|---|---|---|
| `deploy.sh` | on the production host, through `ssh ... 'bash -s' < tools/claude/deploy.sh` with `CASS_COOLIFY_APP_UUID` set | triggers a Coolify deploy, waits for a NEW healthy `app` container (Coolify queues deploys and restarts; a `running:healthy` status alone proves nothing), then inside it as user `app` runs `migrate:status`; only when something is pending, `cass-backup` on the host and then `migrate --force`; then, in every case, `migrate:status` again, the page probes and `cass:health`. It prints `DEPLOY_DONE` only when every step passed; otherwise one `DEPLOY_FAILED:` line and exit 1 (configuration or unexpected), 2 (no new healthy container), 3 (deploy not queued, failed or cancelled), 4 (backup failed, nothing migrated), 5 (migration), 6 (a page's status code) or 7 (`cass:health`). `deploy.test.sh` runs it against fakes, in CI's `scripts` job. |
| `split-plan.sh` | locally | splits a plan markdown into `header.md`, `task-NN.md` and `tail.md` on `### Task N` headings, ignoring headings inside code fences, so an implementer agent reads one task file instead of an 11,000-line plan. |
| `review-plan.template.js` | Workflow tool | the six-lens adversarial plan review: six reviewers (API correctness, cross-task consistency, security and tenancy, test validity, spec coverage, build and operations) each verified by a skeptic, one editor applying confirmed fixes to the plan, one critic, a second edit pass. Adapt `CONTEXT`, its section map and the lens briefs per plan; the plan file comes from `args.planPath`. |
| `implement-plan-staged.js` | Workflow tool | staged implementation: `stages` is a list of task-number lists; a single task runs in the main tree, a pair runs in two worktrees at once and is merged by an agent afterwards; each task = implement (max effort) → one combined spec + quality reviewer → one fix round → recheck. The last task opens the pull request. |
| `premerge-review.js` | Workflow tool | the quality gate that found what the per-task reviewers missed (30 verified defects per plan on average): three maximum-effort lenses over the whole branch diff, a skeptic per lens, one fixer that applies the confirmed findings test-first, pushes and watches CI. Run it on every plan branch before merging. |

Per-task log files: parallel worktree agents share `/tmp`, so every gate command writes to
`/tmp/t-task-N.log`, never `/tmp/t.log`.

Paths: the three Workflow scripts hold no path of their own and refuse to start, with one line
that names the missing argument, unless their `args` carry their paths. `implement-plan-staged.js`
needs `repo`, `worktreeRoot` (a parallel task's worktree is `<worktreeRoot>/CASS-wt-N`), `taskDir`
(the directory `split-plan.sh` wrote) and `planPath`; `premerge-review.js` needs `repo`, `scratch`
and `planPath`; `review-plan.template.js` needs `repo`, `scratch` and `planPath`. `repo` and
`worktreeRoot` are Git Bash paths, `scratch` is a directory for the agents' throwaway scripts (the
session scratchpad, for example) and `planPath` is relative to the repository; this machine's
repository path is in `CLAUDE.local.md`. A `premerge-review.js` run, for example:

```json
{ "repo": "/c/Users/you/projects/CASS", "scratch": "/tmp/cass-premerge",
  "planPath": "docs/superpowers/plans/<date>-plan-n-topic.md",
  "branch": "plan-n-topic", "base": "abc1234", "pr": 99, "label": "Plan N" }
```

`tools/claude/workflow-args.test.mjs` proves each refusal. `split-plan.sh` and `deploy.sh` take
their paths as arguments or environment and need no change.

Tests for the guardrails and the Workflow scripts' arguments: `node --test
.claude/hooks/guard-production.test.mjs tools/git-hooks/pre-push.test.mjs
tools/claude/workflow-args.test.mjs` (also run by CI's `scripts` job).

## Dependabot

- `mysql`: majors are ignored in `.github/dependabot.yml` on purpose. MySQL moved to calendar
  versioning; an "innovation" release is not a successor to the 8.4 LTS line and the data
  volume cannot be downgraded. CI's MySQL services pin the compose digest but start on an empty
  database, so no CI run exercises the upgrade of the 8.4 data volume: a major is a planned
  migration (backup, dump, restore, verify), never a merge on green.
- A compose `mysql` digest PR also needs the same digest in `ci.yml`'s two MySQL service images
  (`test` and `smoke`): the `scripts` job fails on the drift until they match. Push one commit
  that changes both to the Dependabot branch; `@dependabot recreate` would drop it.
- Base-image majors (`php`, `node`) change what production runs; the suite is verified on PHP 8.4
  only. Treat them as deliberate upgrades with the workflow's `php-version` bumped in the same
  change.
- The ignore list was extended on 2026-10-17 by owner decision
  (`docs/decisions/0012-dependabot-base-image-majors.md`): `php` minor and major updates
  (Dependabot reads `8.4-fpm-alpine` to `8.5-fpm-alpine` as minor) and `node` majors, because
  closing the grouped #29 ignored nothing and it would have returned every Monday. Digest updates
  of the current tags still arrive. Any other base-image major that fails the image build is
  closed with the failing step quoted and a backlog entry.
- Actions, composer patch/minor and npm patch/minor: merge when CI is green; click through the
  panels after a Vite major.
