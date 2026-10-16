# Automation used to build CASS v2

The scripts the orchestrating Claude session used for Plans 2–6, kept so the method survives a
change of machine. None of them holds a secret; the host address and the Coolify application
uuid come from the environment or from the private project memory.

| File | Runs where | What it does |
|---|---|---|
| `deploy.sh` | on the production host, through `ssh ... 'bash -s' < tools/claude/deploy.sh` with `CASS_COOLIFY_APP_UUID` set | triggers a Coolify deploy, waits for a NEW healthy `app` container (Coolify queues deploys and restarts; a `running:healthy` status alone proves nothing), runs `php artisan migrate --force` inside it as user `app`, then probes the public pages. |
| `split-plan.sh` | locally | splits a plan markdown into `header.md`, `task-NN.md` and `tail.md` on `### Task N` headings, ignoring headings inside code fences, so an implementer agent reads one task file instead of an 11,000-line plan. |
| `review-plan.template.js` | Workflow tool | the six-lens adversarial plan review: six reviewers (API correctness, cross-task consistency, security and tenancy, test validity, spec coverage, build and operations) each verified by a skeptic, one editor applying confirmed fixes to the plan, one critic, a second edit pass. Adapt `CONTEXT`, `PLAN`, the section map and the lens briefs per plan. |
| `implement-plan-staged.js` | Workflow tool | staged implementation: `stages` is a list of task-number lists; a single task runs in the main tree, a pair runs in two worktrees at once and is merged by an agent afterwards; each task = implement (max effort) → one combined spec + quality reviewer → one fix round → recheck. The last task opens the pull request. |
| `premerge-review.js` | Workflow tool | the quality gate that found what the per-task reviewers missed (30 verified defects per plan on average): three maximum-effort lenses over the whole branch diff, a skeptic per lens, one fixer that applies the confirmed findings test-first, pushes and watches CI. Run it on every plan branch before merging. |

Per-task log files: parallel worktree agents share `/tmp`, so every gate command writes to
`/tmp/t-task-N.log`, never `/tmp/t.log`.

## Dependabot

- `mysql`: majors are ignored in `.github/dependabot.yml` on purpose. MySQL moved to calendar
  versioning; an "innovation" release is not a successor to the 8.4 LTS line and the data
  volume cannot be downgraded. CI never starts the compose MySQL image, so a green run on such a
  PR proves nothing.
- Base-image majors (`php`, `node`) change what production runs; the suite is verified on PHP 8.4
  only. Treat them as deliberate upgrades with the workflow's `php-version` bumped in the same
  change.
- Actions, composer patch/minor and npm patch/minor: merge when CI is green; click through the
  panels after a Vite major.
