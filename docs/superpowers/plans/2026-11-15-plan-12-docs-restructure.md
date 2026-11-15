# Plan 12: documentation restructure (C8, with D8)

Branch `docs/c8-restructure`. Brief: `docs/reviews/2026-10-16-expert-brief.md`, phase C row C8, phase D row D8 and "Question 7, documentation: keep, condense, move". Written on 2026-11-15 against `main` at `2f1f78f`, and revised twice that day in review; its final form was approved at the second review. It is implemented in this worktree once the open phase C and D branches have merged (Task 1). `tools/claude/split-plan.sh` cuts it into a header, one file per task and a tail.

## Summary

Docs, plus comments in three code files, two new tests and one line of CI. No behaviour change, no migration, no environment variable, no dependency. Sensitive files:
- `bootstrap/app.php`: comments only;
- `.github/workflows/ci.yml`: one more `node --test` file in the `scripts` job, so CI runs the pointer checker.

- **Backlog.** 144 lines and 96 entries become one table of 46 rows and 52 lines (item, area, trigger, the one-line change, link).
- **Engineering choices.** Those whose reasoning already sits in a docblock get no decision file and no row: one line of the backlog's preamble names their docblocks.
- **Decisions.** Nine new files:
  - five are the owner's;
  - four are proposed, and listed in the PR body for the owner to accept with the merge.

  `docs/decisions/README.md` is unchanged.
- **Runbook, new block.** A "Quick procedures" block at the top: six procedures with lettered steps and one-line commands. Each command appears verbatim in the section its step links.
- **Runbook, other changes.** No reasoning paragraph moves. The runbook does gain:
  - four fixes (F-1 to F-4);
  - two facts from the handover;
  - a "First move" column in the Health table;
  - the commands the quick procedures cite.
- **Handover.** Each item in the "Open" lists becomes one line with a link, and a phase C table records the merged branches. Every sentence left only behind the permalink to the old text is listed in the PR body.
- **Design document.** Marked "as approved 2026-09-10; the code is authoritative where they differ", with sections 2 and 4 annotated.
- **D8, comments only:**
  - `bootstrap/app.php`: 4 places;
  - `routes/web.php`: 7 places;
  - the vanished backlog citation in `tests/Unit/DockerAssetsStageTest.php`.
- **Tests and tools:**
  - a ratchet test that fails when `(Plan|Task|fact) N` comment references rise;
  - a pointer checker that CI's `scripts` job runs, written with Node built-ins only;
  - a check that no new identifier enters a touched file.

## 0. Verified on 2026-11-15

| Fact | The briefs said | Today, on `2f1f78f` |
|---|---|---|
| Runbook | 1,364 lines; about 1,450 | 1,708 lines, no duplicate heading |
| Backlog | 100 dense lines | 144 lines, 14 sections, 96 entries |
| Decisions | 0001 to 0017 exist | as said; backlog lines 35-36, 43-45, 59-61 and 81 are 0001 to 0009, line 112 is 0015's amendment, line 144 is 0016 |
| Provenance | 223 comments | 381 matches of `(Plan\|Task\|fact) N` in 183 files (any text, not only comments); the ratchet counts comments only, and its baseline is measured in Task 11 |
| Pointers | | 26 distinct runbook titles are cited, plus 11 living "Every release" step 2 or step 5 citations. Two broken pointers today: `backlog.md:73` cites `backlog.md:48`, and `tests/Unit/DockerAssetsStageTest.php:11` cites a backlog item that no longer exists |

The D8 pointers date from 2026-10-16. Checked against today's files:

- `bootstrap/app.php:26-29` has moved to **34-37** (`2d509f0`, 2026-11-04).
- `routes/web.php:129-133` and `:155-156` are **unchanged**; the file has not changed since `119ec94` (2026-09-12).
- Design section 4 is at **line 94**.
- "Docs that call the repository public" **no longer applies**: the repository is public (decision 0011). The opposite mistake does exist: runbook "One-time setup" step 2 ("The repository is private"), design line 39 ("public now, private later") and HANDOVER A1 ("now that it is private").

Four things noted by the review, and confirmed:

- In the installed `laravel/framework` v13.40.0, `withCommands()`'s default sits at `Configuration/ApplicationBuilder.php:307-309`.
- Livewire 4.4.8 calls `substituteImplicitBindings()` at `Drawer/ImplicitRouteBinding.php:85`, not :83.
- The design names no 20/min limit for `/s/{token}`; `CASS_STATUS_PAGE_RATE_LIMIT` (default 20) sets it.
- `app/Actions/Legacy/` is denied to sessions: no step greps or reads it (use `--exclude-dir=Legacy`).

## 1. Rules for every task

- **Sources.** A decision file's Context and Consequence come from its source alone (the backlog entry, a plan, `CLAUDE.md` or the design), with no added reasoning. If a source states no consequence, the bullet reads "None recorded beyond the decision." "To reverse" is built from a grep of the code, with the grep named. First-recorded dates come from `git log -S '<phrase>'` over `docs/superpowers/plans/` and the runbook.
- **No moves, no renames.** No file is moved or renamed. No heading is removed or renamed. Untouched text is not reflowed.
- **Quick procedures.** Their headings are `### Q1.` to `### Q6.` with lettered steps (`- **a.** …`), so a citation like `"Every release" step 2` always means the full section.
- **No new identifiers.** New text names no host, address, account, path under a user directory, or cloud or Coolify identifier. It uses `<uuid>`, `<ssh-user>@<origin-ip>` and `cass_container`. Lines that already carry such values (listed in the tail) are left for the owner.
- **After each commit:** `node tools/docs/check-pointers.mjs . > /tmp/links-task-G.log 2>&1; echo "rc=$?"`. Until Task 12 commits the checker, run the scratch copy, which has the same source.

  Before Task 7 it reports the `backlog.md:73` line reference, and before Task 10 the `DockerAssetsStageTest.php:11` citation; nothing else.
- **Commits.**
  - One commit per task, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
  - A message that names the production words goes through `git commit -F <file>`.
  - No push before Task 13.

## 2. Fold-in from the other branches

These findings are provisional, worked out from the pushed branches' diffs against `2f1f78f` (`git diff --stat 2f1f78f origin/<branch>`). Task 1 redoes the work on `main` after the merges, and the coordinator's reports reconcile it:

- **`ci/c1-c7-hygiene`:**
  - `0277281` adds `tools` to `.dockerignore`, which closes backlog 129.
  - It also brings gitleaks, pcov coverage, the removal of `ExampleTest` and the `PurgeTest` skip fix.
  - Backlog 90 and 96 are untouched.
  - For the HANDOVER CI row: the coverage number, and C1's before and after wall-clock time.
- **`feat/c3-health-disk-row`:** the `disk` row in `cass:health` closes backlog 141. The runbook's Health table gains `disk`, and a variables table follows it. "Every release" step 2 gains the exit-7 note.
- **`feat/c4-seeder-force-password`:**
  - the seeder no longer resets an existing admin's password;
  - a new command, `cass:admin-password`, which makes eight discovered commands;
  - in the runbook: step 4, step 6 and "Every release" step 3 change, and a new section "Platform admin password" appears;
  - the owner step: take `CASS_ADMIN_PASSWORD` out of Coolify.

  No backlog row. Only the seeder grants `is_platform_admin`; the new command replaces an existing admin's password and nothing more.
- **`feat/c5-native-submission-tenancy`:** not pushed yet. It is expected to close backlog 37.
- **`refactor/c6-one-membership-check`:** policies only; no backlog row.
- **`test/d3-table-action-helpers`:** tests only; closes backlog 55.

## Tasks

### Task 1: Rebase, re-verify and fold in (no commit)

1. When the coordinator says the branches have merged: `git fetch origin && git rebase origin/main`. Only the plan commit is on the branch.
2. Run the scratch checker on the new `main`. Expect only the two known pointers; record anything else for the task that fixes it.
3. **Fold-in.**
   - For each backlog row's file, read `git log -p 2f1f78f..origin/main -- <file>` and write down which rows the merged branches closed or changed.
   - Reconcile that with the coordinator's reports and section 2.
   - Write the result to the session scratchpad as `fold-in.md`: one line per row and per HANDOVER item, used by Tasks 7 and 8.
4. **Re-verify by content, not by line number:**
   - the D8 places (Task 10);
   - the runbook places Tasks 4 and 5 edit, found by their first words;
   - C3's Health table and C4's changes;
   - `ls docs/decisions`: the next free number replaces 0018 in Tasks 2 and 3, and each file after it moves by the same amount.
5. **Facts for the design annotation (Task 9).** Re-run `grep -rn "'is_platform_admin' => true" app database --exclude-dir=Legacy` and `grep -rn 'is_platform_admin' app/Console`.

### Task 2: The owner's decisions (five files, one commit)

Commit: `docs(decisions): file five of the owner's decisions the backlog and the rule book recorded`. Each file uses the four bullets of `docs/decisions/README.md`, in the style of 0013 and 0014, and keeps to section 1's source rule.

| No. | Slug | Decided | Sources | "To reverse" from |
|---|---|---|---|---|
| 0018 | `custom-domain-step-is-manual` | the owner, with the design on 2026-09-10 (section 15, line 283: "Documented runbook; admin notification on verification; v2 may automate through the Coolify API") | backlog 77; runbook "What you do when the email arrives" | **the Grep tool, not Bash** (the production hook refuses a Bash command whose text names Coolify): pattern `coolify`, case-insensitive, over `app` but not `app/Actions/Legacy`. It finds no client, only `VerifyCustomDomain`'s docblock. The change is an API call from `VerifyCustomDomain`, with a Coolify token in the environment (the runbook's `PATCH` call) |
| 0019 | `suspension-takes-public-pages-offline` | the owner, recorded on 2026-09-11 in Plan 2 (`9841e14`, its line 8324) and the backlog (`d50c826`), with no date of its own | backlog 17 | the worked example below |
| 0020 | `qr-png-is-a-whole-module-square` | the owner, recorded on 2026-09-11 in Plan 2 (`9841e14`, line 8498: "Deviation, decided by the owner") | backlog 18; spec 5.7 ("PNG downloads (1024 px)") | `GenerateConferenceQr::png()` (`app/Actions/Conferences/GenerateConferenceQr.php`, its docblock near :51); invert `it('renders a png of at least the configured size')` in `tests/Unit/ConferenceQrTest.php` |
| 0021 | `port-22-stays-open-key-only` | the owner, on 2026-09-25, recorded on 2026-11-06 (`303d6de`), and re-affirmed on 2026-11-06 | backlog 10; checklist section 2; HANDOVER B3 | no code: restrict tcp 22 in the VCN's security list to known addresses; no test |
| 0022 | `dns-verification-token-is-stored-in-plaintext` | the owner. `CLAUDE.md` line 20 names "the DNS verification nonce" the one documented exception to hashed tokens; it was added in `6ae4e9b`, which the owner adopted with #32, merged 2026-10-16. First argued on 2026-09-12 in Plan 6, Task 1, decision 1 (`e1be4f1`) | backlog 74; `ClaimCustomDomain`'s docblock; runbook "What the organizer does" | `grep -rn custom_domain_token app --exclude-dir=Legacy`. The token is written in `ClaimCustomDomain.php:67`, cleared in `ReleaseCustomDomain.php:31`, and read in `VerifyCustomDomain.php:41` for `carriesToken()`. Tests: `grep -rln custom_domain_token tests` (5 files) |

The worked example, 0019:

```
# Suspending an organisation takes its public conference pages offline at once

- **Context.** The suspension email promises that a suspended organisation's conference pages go offline.
- **Decision.** Decided by the owner, recorded on 2026-09-11 as "Decided (owner)" in Plan 2 (`9841e14`) and in the backlog (`d50c826`), with no date of its own: suspending an organisation takes every one of its public conference pages offline immediately, 404 to the public and still previewable by members.
- **Consequence.** None recorded beyond the decision.
- **To reverse.** The `isApproved()` checks that gate the public pages (`grep -rn 'isApproved()' app --exclude-dir=Legacy`): `ConferenceController.php:45`, `ShortLinkController.php:34`, `CustomDomainController.php:34`, `SubmissionForm.php:181`, `SubmissionStatus.php:73` and `SendOneDecisionEmail.php:97`; invert `it('takes a suspended organization conference offline but keeps the member preview')` in `tests/Feature/Public/ConferencePageTest.php`, the `'suspended organization'` case in `tests/Feature/Public/ShortLinkTest.php`, and whatever `grep -rln 'suspended()' tests` finds for the other four.
```

Afterwards, `grep -c` confirms each file has the four bullets, and the checker passes.

### Task 3: Decisions proposed for the owner's acceptance (four files, one commit)

Commit: `docs(decisions): four engineering choices for the owner to accept`.

These choices have their reasoning in no docblock, so each needs a file. Each file's "Decision" bullet uses 0017's wording: "Proposed by the session on <first-recorded date> (<commit>) and accepted by the owner with the merge of #NN, which adds this file (the owner approves that pull request)."
- `#NN` is filled in once `gh pr create` returns it (Task 13). The files carry no date of acceptance, so no commit follows the approval.
- The PR body lists the four files as one list, the first item under "For the owner".

| No. | Slug | First recorded | Sources | "To reverse" from |
|---|---|---|---|---|
| 0023 | `membership-is-not-a-conflict-of-interest` | 2026-09-11, Plan 4 (`e5cdf36`) | backlog 52. `AutoAssignReviewers::conflicts()` states the rule (:189) but not the reason | add a membership clause to `AutoAssignReviewers::conflicts()`; invert its case (`grep -n -i member tests/Unit/AutoAssignReviewersTest.php`) |
| 0024 | `custom-domains-are-verified-once-by-txt` | 2026-09-12, Plan 6, Task 1, decision 2 (`e1be4f1`) | backlog 75 and 76; `VerifyCustomDomain`'s docblock and its comment at :69 | a CNAME lookup in `VerifyCustomDomain`, and a scheduled re-check in `routes/console.php`; tests: `grep -rln FakeDnsResolver tests` |
| 0025 | `legacy-import-carries-no-addresses-or-passwords` | 2026-09-12, Plan 6 (`e1be4f1`) | backlog 78 and 79 (their `legacy-review.md:276` citation is copied, not read); spec 5.10 | **from the design document and Plan 6's plan only.** `app/Actions/Legacy/` is denied to sessions: grep `docs/superpowers/plans/2026-09-13-plan-6-launch.md` for `import.invalid` and `password`, and name the plan's task, not a code line |
| 0026 | `composer-platform-php-is-pinned` | 2026-10-01 (`4bee8d1`) | backlog 100 | `composer.json:95` (`config.platform.php`, `"8.4.1"`); no test. What it guards: Dependabot's framework-group updates failing without a pull request |

### Task 4: Runbook fixes F-1 to F-3, with the handover's two facts (one commit)

Commit: `docs(runbook): the repository is public, and two commands that could not run as written`.

- **F-1, "One-time setup" step 2.** Replace "The repository is private, so Coolify clones it with the private key" with three sentences:
  - "The repository has been public again since 2026-10-16 (decision 0011)."
  - "Coolify still clones it with the private key `cass-deploy-key`, to which the application was switched that day while the repository was briefly private."
  - "A deploy key works either way."

  "Private Repository (with deploy key)" stays: it is Coolify's name for the source type. **Beside it, HANDOVER A1's fact:** Coolify's API and UI could not attach a first key to an application on the public source, so on 2026-10-16 the switch was a one-row edit of Coolify's `applications` table. The old values are saved on the host as `~/cass-app-source-backup-20261016-185435.txt`, moved word for word.
- **F-2, "Reviewer reminders", "Checking it by hand".** `docker exec cass` names no real container. It becomes `C=$(cass_container app)` followed by `sudo docker exec "$C" su-exec app php artisan …`. The stale-lock block (the `schedule:clear-cache` lines) gains the same `C=` line.
- **F-3, "Verifying one on the host"** (closes backlog 128). The directory is 0700 and owned by root, and a glob expands before `sudo` runs. So the lines become `NEWEST=$(sudo sh -c 'ls -1t /srv/backups/cass/cass-*.sql.gz | head -1')` and `sudo gunzip -c "$NEWEST" | …`.
- **"Secret rotation" gains HANDOVER A8's fact.** On 2026-11-06 the application's four manual webhook secrets, which an engineering session's pre-flight had printed into that session, were replaced with 64-character random values. It was a one-row update in Coolify's database, and the values were never shown, so the printed ones are dead.

### Task 5: Runbook: what the quick procedures point to (one commit)

Commit: `docs(runbook): the rollback that is known to work, a First move per health row, and the commands the quick procedures cite`. These edits exist so that every quick-procedure command has a verbatim home.

- **F-4, "Rollback".**
  - Mark "Coolify -> Deployments -> redeploy the previous successful build" as unverified. No rollback has been recorded, and for this application Restart and Redeploy build `main`'s head of that moment ("Every release" step 2).
  - Add the known route as one block: a revert of the merge on a branch from `origin/main`, then a pull request, CI green, the owner's approval and the merge, then the script.

    ```bash
    git fetch origin && git switch -c revert-<sha> origin/main
    git revert -m 1 <merge sha>
    git push -u origin revert-<sha>
    gh pr create --base main --title "Revert <sha>" --body "<why>"
    ```

  - The pre-Plan-7 note stays.
  - Backlog row 40 ("Rollback has no tested fast path", trigger C2) points here.
- **"Health".**
  - The table gains a third column, "First move". It points to existing text only:
    - database: `sudo docker ps`, then **Rollback** or **When the host itself is slow**;
    - migrations: the by-hand block in **Every release** step 3;
    - queue: Q5;
    - scheduler: `supervisorctl status`, then `schedule:clear-cache` (**Reviewer reminders**);
    - private disk: `cass:sweep-uploads`;
    - disk: C3's "Make room" sentence, moved word for word out of its "Red means" cell;
    - mail: the `MAIL_*` variables (**One-time setup** step 4).
  - C3's variables table is not touched.
- **"Every release" step 1** gains two one-line commands:
  - `gh run list --workflow ci.yml --branch main --commit "$(git rev-parse origin/main)" --json databaseId,conclusion --jq '.[0]'`;
  - `gh run view <id> --json jobs --jq '.jobs[]|select(.name=="image").conclusion'`.
- **"Email triage"'s check block** gains `sudo docker exec "$C" su-exec app php artisan queue:monitor database:default`, with the comment `# [N] OK, N falling`.

### Task 6: Runbook: the quick procedures (one commit)

Commit: `docs(runbook): quick procedures at the top`.

- Insert `## Quick procedures` after line 6, before "Finding the containers". It opens with:
  - a two-column index (situation, procedure);
  - "Each procedure is the shortest correct path; the section a step links holds the reasons, the edge cases and the incidents";
  - "When a section changes, its quick procedure changes in the same commit".
- Host steps assume `ssh -i <ssh-key> <ssh-user>@<origin-ip>` and `cass_container` pasted ([Finding the containers](../../runbooks/deploy-production.md#finding-the-containers)).
- The format of every step:
  - one-line commands only;
  - a multi-line block is linked, never copied;
  - the expected output comes from the code that prints it;
  - no line is copied that carries the Coolify compose id or a Windows user path.

In the runbook the links become bare `#anchors`.

- **Q1. Every release.**
  - **a.** The run is green, and so is its `image` job: run the two step 1 commands. If the job is `skipped`, run `gh workflow run ci.yml --ref main` and wait.
  - **b.** No open conference is within 48 hours of its submission or review deadline (`CLAUDE.md`). The `/admin/conferences` list shows the first deadline, the view page the second.
  - **c.** If the mysql digest changed, run `sudo /usr/local/bin/cass-backup` first. It prints `[cass-backup] /srv/backups/cass/cass-<stamp>.sql.gz (<n> bytes), keeping 14 days`.
  - **d.** `git fetch origin main` again; compare, and merge nothing until the deploy ends.
  - **e.** Run the script line. Its last line is `DEPLOY_DONE`. A `DEPLOY_FAILED:` line means stop. The exit codes are at the top of `deploy.sh`: 2 means "When the host itself is slow", 4 means nothing was migrated, 7 means a red row (and for `disk`, C3's note).
  - **f.** `/up` answers 200 and `/org/login` is styled (step 4).
  - **g.** Run the timing block in step 5; the median must be 0.300 s or less.
  - **h.** Record the release in the HANDOVER Production row.

  Never Restart, Redeploy or `db:seed`, in C4's wording.
- **Q2. Roll back.**
  - **a.** Find whether a release did it ([When an alert fires](../../runbooks/deploy-production.md#when-an-alert-fires)).
  - **b.** The known route: F-4's revert, one command per step, linked to Rollback.
  - **c.** Then Q1 from a. If Q1 b's 48-hour rule blocks, stop and ask the owner whether a rollback is an exception ("What needs the owner", item 4).
  - **d.** "Redeploy the previous successful build" is unverified (F-4).
  - **e.** Never roll the schema back.
- **Q3. Restore check.**
  - **a.** `C=$(cass_container mysql)`.
  - **b.** F-3's `NEWEST` line: expect last night's 03:17 dump or a later hand run.
  - **c.** The restore and count block in "Verifying one on the host", linked. Live and restored are close, and neither is zero.
  - **d.** Monthly, [the drill](../../runbooks/deploy-production.md#restore-drill-from-the-off-host-copy--monthly-and-not-optional), the owner's, never run in a session.
  - **e.** Restoring over production is not written down: stop and ask the engineer (backlog row 36).
- **Q4. Health.**
  - **a.** `C=$(cass_container app)`, then `sudo docker exec "$C" su-exec app php artisan cass:health`: every row `OK`, exit 0.
  - **b.** A FAIL row: its "First move" ([Health](../../runbooks/deploy-production.md#health)).
  - **c.** A quiet heartbeat: run the `grep WARNING` and `docker logs` lines of [Heartbeats](../../runbooks/deploy-production.md#heartbeats).
- **Q5. A stuck queue.** The full text below.
- **Q6. The off-host copy.**
  - **a.** `sudo tail -n 40 /var/log/cass-backup.log`: the last `[cass-backup-offsite]` line reads `OK: <n> uploaded, <m> already off-host; newest dump <file>, <h> h old; pinged` (`tools/backup/cass-backup-offsite.sh:82`).
  - **b.** On `FAILED (exit N)`, use the exit table ([Off-host copies](../../runbooks/deploy-production.md#off-host-copies)), then run `sudo /usr/local/bin/cass-backup-offsite`.
  - **c.** Kuma's `cass offsite copy` is up, and `cass-offsite-filling` is quiet.

```
### Q5. A stuck queue

Mail is late, `cass:health` shows `queue` FAIL, or `/admin/email-logs` holds `queued` rows for hours.

- **a.** `C=$(cass_container app)`, then `sudo docker exec "$C" su-exec app php artisan cass:health`: `queue` reads `oldest waiting job is <age> old`, FAIL past 15 minutes ([Health](#health)).
- **b.** `sudo docker exec "$C" supervisorctl status`: `nginx`, `php-fpm`, `queue` and `scheduler` RUNNING; if `queue` is not, `sudo docker exec "$C" supervisorctl restart queue` ([Email triage](#email-triage)).
- **c.** `sudo docker exec "$C" su-exec app php artisan queue:monitor database:default`: `[N] OK`, N falling; a bare `database` watches a queue nothing uses ([Email triage](#email-triage), [Secret rotation](#secret-rotation)).
- **d.** `sudo docker exec "$C" su-exec app php artisan queue:failed`: no rows, or each failure. Fix the cause, then `sudo docker exec "$C" su-exec app php artisan queue:retry all`; judge it by the worker and the mailbox ([Sending decision emails](#sending-decision-emails)).
- **e.** Reminders still unsent while the queue drains: `sudo docker exec -it "$C" su-exec app php artisan schedule:clear-cache` ([Reviewer reminders](#reviewer-reminders)).

Never `cache:clear`: with the database cache store it empties the scheduler heartbeat and every rate limit too.
```

After this task, each Q section is under 40 lines and contains no fenced block. The checker's verbatim rule passes.

### Task 7: The backlog as one table (one commit)

Commit: `docs(backlog): one table, a row per deferred item`.

The preamble is 6 lines:

```
# Backlog
What is deferred, one row each: its trigger and the one-line change. Decisions the owner made are in `docs/decisions/`. A row is closed by the pull request that makes its change. Areas other files cite (Submissions, Reviewing, Decisions, Launch, Testing, Dependencies, Monitoring, Backups) keep their names.
Decided in code, with the reasoning in the docblock named, so no row: a purge leaves `activity_log` (`app/Actions/Organizations/PurgeOrganization.php:62`); removing a reviewer deletes their assignments and keeps their reviews (`app/Actions/Reviewers/RemoveConferenceReviewer.php:17`); invitation status is derived, not stored (`app/Enums/InvitationStatus.php:11`); `review_count` and `score` count different reviews, and a question's weight locks with the form (`app/Support/Scoring/SubmissionScorer.php:11`, `app/Console/Commands/RescoreConferenceCommand.php:17`); backups are made by the host, outside every container (`docker/backup.sh:5`).

| Item | Area | Trigger | The one-line change | Link |
| --- | --- | --- | --- | --- |
```

**Rules:**
- A row's text comes from its source lines on `2f1f78f`, condensed and never extended.
- **No cell reads "—".**
  - Every Trigger comes from the row's source. Rows 1, 21 and 22 carry the triggers their sources give:
    - row 1: Filament ships views that Livewire's CSP-safe build can parse (backlog 7);
    - row 21: a dedicated performance runner (69);
    - row 22: "tighten it only if" an owner reports a conference marked decided with a full queue and nobody noticing (62).
  - The Link cell names a decision, a runbook section, a spec section, or the code file the row is about. "(its code file)" below means the file the row's own text names, found by grep.
- At most 400 characters a row, except rows 6 and 24, which keep D1's ten views and D2's nine files whole (at most 700).
- An escaped `\|` is the only pipe allowed inside a cell.
- An item that other files cite keeps its wording ("Tracker events carry no release").
- Rows the fold-in closed are left out. If C5 or D3 has not merged, the entries at lines 37 or 55 come back as rows.

**Over budget:** if the table reaches 59 lines, merge rows 30 and 31.

In the table below, a numbered range on one line is one row per source entry, in that order. Only a row whose source column names several entries merges them.

| # | Item | Area | Source lines | Link |
|---|---|---|---|---|
| 1 | CSP keeps `'unsafe-eval'` and inline style attributes, and has no report endpoint | Security | 7, 82 | `app/Http/Middleware/ContentSecurityPolicy.php:49`, `:147` |
| 2 | Three published Filament views | Security | 8, 85, 101 | runbook "Content-Security-Policy" |
| 3 | Memory limits measured under real load | Security | 9 | runbook "Resource limits, and how to measure them" |
| 4, 5 | `User::roleIn()` and `canAccessTenant()` query on every call; organisation names are not unique | Organizer | 15; 16 | (its code file) |
| 6 | Panel views still carry 69 inline `style` attributes (D1) | Organizer | 21 | (the ten views) |
| 7 | Scale limits: `ShortLink::dailyVisitCounts()` and the XLSX export | Scale | 19, 68 | (its code files) |
| 8, 9 | Pagination views outside Tailwind's `@source`; conference `og:image`, the poster half | Public site | 25, 83; 26 | `resources/css/app.css`; (its code file) |
| 10 | Stale text in plans, and provenance in code comments | Documentation | 30, brief question 7 | `CLAUDE.md`; the ratchet test (Task 11) |
| 11 | The status link a submit emails is not capped | Submissions | 38 | 0014 |
| 12 | Every IP-keyed rate limit keys on the full client address | Submissions | 39 | `app/Support/ClientIp.php` |
| 13 | Spec section 14 features with a foothold in the code | v2 | 34, 47, 49 | design section 14 |
| 14-17 | Per-row unassign; expertise and track matching; AutoAssign never removes; free-mailbox list | Reviewing | 46, 48, 50, 51 | (its code file) |
| 18-20 | Per-track quotas; no score-to-decision rule; one template per decision | Decisions | 65, 66, 67 | (its code file) |
| 21 | The ranking's wall-clock case is a diagnostic | Decisions | 69 | `tests/Feature/Organizer/ConferenceRankingPerformanceTest.php:26`; runbook "The 500-row budget" |
| 22 | Unsent letters do not block marking a conference decided | Decisions | 62 | `app/Actions/Conferences/MarkDecided.php:34` |
| 23 | Automate the custom-domain step | Launch | 77 | decision 0018 |
| 24 | The language sweep does not cover the application (D2) | Launch | 84 | `tests/Feature/LanguageCoverageTest.php` |
| 25, 26 | `SubmissionFormTest`'s fill-time case; phone readers and the 18 hard QR matrices | Testing | 90; 92 (its unverified part) | the two test files |
| 27 | The `image` job does not run on a `composer.lock`-only change | CI | 96 | `.github/workflows/ci.yml` |
| 28 | PHP 8.5 with Node 26 LTS | Dependencies | 102 | 0012 |
| 29 | Tracker events carry no release | Monitoring | 106, 107 | runbook "Error tracker" |
| 30, 31 | MySQL's duplicate-entry wording in the server log; the tracker's URL scrub names its paths | Monitoring | 109 (the open half), 110 | (its code file) |
| 32 | The owner's monitoring checks: Kuma's ntfy notification, GlitchTip's new-issue email, the OCI support request | Monitoring | 111, 112 (what is left), 113 | runbook "Where alerts go" |
| 33 | Bot Fight Mode and the outside probe cannot both run on the Free plan | Monitoring | 115 | runbook "Cloudflare in front" |
| 34 | The first restore drill from the off-host copy, then the retention lock | Backups | 119, 120 | runbook "Restore drill from the off-host copy" |
| 35 | Containers can use the instance principal | Backups | 122 | 0016 |
| 36 | Restoring production, on this host or a new one, is not written down | Backups | 124, widened | runbook "Backups" |
| 37-39 | An outside signal of uploads; backup tooling refinements; weekly archives are full copies | Backups | 125; 123, 126, 130; 127 | 0016; `tools/backup/`; 0016 |
| 40 | Rollback has no tested fast path | Deploy | new; trigger C2 | runbook "Rollback" |
| 41 | Suspended organisations: owners' panel access, and Resend status link | Owner questions | 14, 143 | (its code file) |
| 42-46 | Slug rename; admin invitations; emailing owners; a reviewer's affiliation (both questions); 10 links per IP | Owner questions | 136, 137, 138, 139-140, 142 | (its code file) |

**Entries with no row:**
- `0001` to `0009` (35-36, 43-45, 59-61, 81).
- The Task 2 and Task 3 files (10, 17, 18, 52, 74-79, 100).
- Named in the preamble with their docblocks: 20 (its decided half), 53, 54, 63, 64 and 86.
- Answered or done entries: 73, 108, 114, 144, and the done halves of 20, 92 and 109.
- 121: decision 0016's Consequence states the rule, and the files age out by their own rotation.
- Closed: 80 by Task 10, 128 by F-3, and 37, 55, 129 and 141 by the merged branches.

### Task 8: The handover, one line per open item (one commit)

Commit: `docs(handover): one line per open item, with links; phase C`.

- **What stays.** Every heading stays. "Where things stand" changes only where a fact arrives from an Open row, such as the installed off-host script's commit and SHA-256 in the Backups row.
- **Open rows.**
  - Each row keeps its id (A1 to A9, B1 to B13).
  - Each becomes one line of at most 300 characters: id, item, who, and a status with a link.
  - Each open step survives in its line. Search the old text for "not started", "left", "to do", "to confirm", "pending", "open", "but for" and "not checked". Examples:
    - B3: the containers' metadata-service fence;
    - B4: one real abstract through the WAF;
    - B5: the `/up-missing` probe;
    - B7: the new-issue email and the release tag;
    - B8: the first drill and the lock, and the Kuma monitor's settings, not checked by a session;
    - A8: "left open" points to checklist section 3.
- **Permalink.** Under the first Open heading:

  `The rows below were condensed on <date>; their full earlier text is [HANDOVER.md at <short sha>](https://github.com/ahmedsk2/cass/blob/<short sha>/docs/HANDOVER.md).`

  Use the base commit's short SHA (`git rev-parse --short <base>`): a 40-hex value would fail the identifier check.

  Every sentence that now lives only behind that link is listed in the PR body, one per line.
- **Paragraphs.** The phase B summary paragraph and "Also open for the owner" become two lines: the walk-through is next (checklist section 4), and the owner's open items are in backlog "Monitoring", "Backups" and "Owner questions".
- **New `## Open — phase C, first month after launch`:** C1 to C8, one line each, from `fold-in.md`. C2 is not started and C8 is this pull request.
- **Phase D:** one line linking the brief's table. D3 is done by its branch and D8 here; D1 and D2 are backlog rows 6 and 24.
- **A1 and A8.** Their facts are in the runbook now (Task 4), so their lines are: "Done 2026-10-16: the deploy key (runbook "One-time setup" step 2)" and "Done 2026-10-17 (checklist section 3; the webhook secrets under runbook "Secret rotation")".
- **If C7 closed backlog 90,** "Setting up a development PC" no longer says "backlog "Testing"" about that case.

### Task 9: Design document and launch checklist (one commit)

Commit: `docs: the design document as approved, and the checklist's pointers`.

- **Banner.** After the design document's `Status:` line (line 4): `> As approved 2026-09-10; the code is authoritative where they differ. Later decisions are in docs/decisions/, deferrals in the backlog and the state in docs/HANDOVER.md.`
- **Line 39.** Append: *"It stays public: the owner's decision of 2026-11-06 (decision 0011)."*
- **Line 94.** Append, using Task 1's grep: *"As built: `PlatformAdminSeeder` makes `CASS_ADMIN_EMAIL`'s account the platform admin when `db:seed` runs at the first deploy; no command and no screen grants the flag, and `cass:admin-password` only replaces that admin's password."*
- **Nothing else in the design changes.** The banner covers the other differences, for example section 9's hashed domain token (0022) and section 11's "backup restore" (backlog row 36).
- **Checklist:**
  - line 162 drops the stale "The backlog records that";
  - lines 335-336 point to `docs/decisions/` (0001 to 0008);
  - section 1's introduction gains the same sentence.

### Task 10: Comments that disagree with the code (one commit)

Commit: `docs(comments): bootstrap/app.php and routes/web.php say what the code does`. These are comment-only changes. Each removed provenance reference is replaced by the fact it stood for.

| Where | Wrong today | Fix |
|---|---|---|
| `bootstrap/app.php:34-37` | "app/Console/Commands is NOT scanned without this (fact 2)". `Application::configure()` calls `withCommands()` with no arguments (`Application.php:252`), which adds `app/Console/Commands` as a discovery path (`ApplicationBuilder.php:307-309`) | The comment says the list is redundant: discovery registers every command in the directory, all eight once C4's `cass:admin-password` is there. The list only names some for grep, and removing a class from it unregisters nothing. The list stays |
| `bootstrap/app.php:59` | "(fact 15)" | deleted |
| `bootstrap/app.php:93` | "Spec 5.8 and the Plan 1 backlog item:" | "Spec 5.8:" |
| `bootstrap/app.php:146-150` | "Sentry's 5,000 a month …; The DSN's own rate limit in Sentry caps the total". The tracker is GlitchTip on the owner's host (decision 0015, amended) | "neither floods the tracker nor holds a worker per request"; `(runbook "Error tracker")` stays |
| `routes/web.php:46-47` | "Plan 3 registers …/submit" | "conference.submit below uses the same binding field" |
| `routes/web.php:77-81` | "would 404 in production … (Task 1 Step 6 fixes the fallback …)". `docker/nginx.conf:65-75` falls through to Laravel | "nginx serves a static-extension URI from disk first and reaches Laravel only through its fallback, which a download route should not depend on" |
| `routes/web.php:113-116` | "answers any URI ending in a static-file extension from disk before PHP sees it" | the same fact as the previous row |
| `routes/web.php:125-134` | "a placeholder until Task 8 writes the real page", and Task 5 | the paragraph is deleted; 135-138 stay |
| `routes/web.php:136` | "The throttle is spec section 9's 20/min/IP". The design names no such limit | "The throttle is `CASS_STATUS_PAGE_RATE_LIMIT` a minute per client address (default 20; the `submission-status` limiter in `AppServiceProvider`)" |
| `routes/web.php:155-156` | "the hex alphabet InvitationToken mints". The constraint at :158 is `[A-Za-z0-9]{64}` | "64 letters and digits, which every token InvitationToken mints (lowercase hex, `InvitationToken.php:29`) matches". The constraint stays, and no backlog row |
| `routes/web.php:189` | `Drawer/ImplicitRouteBinding.php:83`, which is now :85 | name the class and the method, with no line number |
| `tests/Unit/DockerAssetsStageTest.php:11` | "Plan 6 found app/Livewire in exactly that state (backlog, "The production image builds its CSS without app/Livewire in scope")". That backlog item no longer exists | "app/Livewire was once outside that stage's scope, so production CSS lacked every class used only there" |

- Read every comment in `bootstrap/app.php` and `routes/web.php` once against the code, and list any further disagreement in the PR body.
- One is known already: `AppServiceProvider.php:84` repeats "Spec section 9: … 20/min/IP". It is outside these files, so it is listed and not changed.
- `grep -cE '\b(Plan|Task|fact) [0-9]'` is 0 for the two code files.

### Task 11: The provenance ratchet (one commit)

Commit: `test: no new Plan, Task or fact reference in a code comment`.

**The test.** `tests/Unit/ProvenanceCommentsTest.php`:
- **PHP files.** Counts `/\b(?:Plan|Task|[Ff]act) \d+/` in the `T_COMMENT` and `T_DOC_COMMENT` tokens (`PhpToken::tokenize`) of every `*.php` file under `app`, `bootstrap`, `config`, `database`, `lang`, `routes` and `tests`. Its own file is skipped.
- **Blade views.** Counts the same pattern in the `{{-- … --}}` comments of `resources/views/**/*.blade.php`.
- **The assertion.** `count <= BASELINE`. The failure message reads: "a comment names a plan, task or fact; state the fact instead (CLAUDE.md)".

**Order:**
1. Measure the count after Task 10, and set `BASELINE` to it.
2. Add a probe file, `tests/Unit/ProvenanceProbe.php`, holding `// Plan 99`. Run `php artisan test --filter=ProvenanceComments > /tmp/t-task-G-ratchet-red.log 2>&1; echo "rc=$?"` and expect rc=1.
3. Delete the probe and run again: rc=0.

The probe is never committed. The baseline and the red log path go in the PR body.

### Task 12: The pointer checker in CI's `scripts` job, and the identifier check (one commit)

Commit: `test(tools): CI checks every documentation pointer, and a check for new identifiers`.

**What is committed:**
- **`tools/docs/check-pointers.mjs`:** the scratch checker's source. Node built-ins only (`node:fs`, `node:path`), because the `scripts` job runs no `npm ci`.
  - It covers links and anchors, backticked paths, and runbook and backlog titles in possessive, comma and chained forms, wrapped across lines, in markdown and in code comments and strings.
  - It also checks:
    - the runbook's `under "X"`, `(see "X")` (as at its line 1632, "Author uploads and private storage") and `(see **X**)`;
    - `"X" step N`;
    - decision numbers;
    - HANDOVER ids.
  - It refuses `backlog.md:<digit>` and applies the quick procedures' verbatim rule.
  - It skips `app/Actions/Legacy/`, `Legacy/` and `legacy-review.md`.
- **`tools/docs/check-pointers.test.mjs`:** `node:test` and `node:assert` only.
  - For each rule, one passing and one failing fixture tree, built in `os.tmpdir()`.
  - Last, a case that runs the checker over the repository itself and expects no broken pointer, so CI checks the real tree.
- **`tools/docs/new-identifiers.sh`:** the section-8 identifier check, run by hand in Task 13. It is not in CI.
- **`.github/workflows/ci.yml`, one line.** The `scripts` job's "Guardrail hooks" step appends `tools/docs/check-pointers.test.mjs` to its `node --test` list. The runner's preinstalled Node runs it. This is a sensitive file; the PR lists it with that why.

**Test first:**
1. Write the node test, then run `node --test tools/docs/check-pointers.test.mjs > /tmp/t-task-G-checker-red.log 2>&1; echo "rc=$?"`. It is red, because the module is missing.
2. Add the script. Run the same command into `/tmp/t-task-G-checker.log`: rc=0.

The scratch negative tests of 2026-11-15 caught every planted break: chained, wrapped, code-comment, step-number, line-reference, verbatim, and the quoted `(see "X")` at runbook :1632.

### Task 13: Checks, gates and the pull request

1. Run every check in the tail and every gate, and collect the logs.
2. **No local gitleaks run.** The PR relies on CI's `secret-scan` job (#79), which must have merged before this PR opens. If it has not, wait.
3. Push and open the PR:
   - `git push -u origin docs/c8-restructure`;
   - write the body to a scratch file;
   - run `gh pr create --base main --title "<title>" --body-file <file>`. The body names Coolify and `deploy.sh`, so a `--body` on the command line would be refused by the production hook.
4. Fill `#NN` into Task 3's four files in one commit: `docs(decisions): name the pull request that adds them`. Push it, and wait for CI green. The files carry no acceptance date, so nothing follows the owner's approval.
5. The PR body:
   - a paragraph on what and why;
   - "Files changed";
   - "For review":
     - the sensitive files: `bootstrap/app.php` (comments only) and `.github/workflows/ci.yml` (one more `node --test` file in `scripts`, so CI checks every documentation pointer);
     - migrations none, environment none;
     - the two tests added (`tests/Unit/ProvenanceCommentsTest.php`, `tools/docs/check-pointers.test.mjs`);
     - the red logs and the gate logs;
   - "For the owner": the four proposed decisions as one list, and the rest of the tail's "What needs the owner";
   - the backlog mapping, each entry to its row, file, closure or reason;
   - the sentences that remain only behind the HANDOVER permalink;
   - the further comment disagreements found;
   - the note on identifiers already in the repository (tail);
   - `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

## Checks

1. **Pointers.**
   - `node tools/docs/check-pointers.mjs . > /tmp/links-task-G.log 2>&1; echo "rc=$?"` prints 0.
   - `node --test tools/docs/check-pointers.test.mjs > /tmp/t-task-G-checker.log 2>&1; echo "rc=$?"` prints 0. This is what CI's `scripts` job runs (Task 12).
2. **Backlog.**
   - `test "$(wc -l < docs/superpowers/plans/backlog.md)" -lt 60` (52 expected).
   - `awk 'length > 400' docs/superpowers/plans/backlog.md` prints only the preamble's docblock line and rows 6 and 24. Each of the two rows is under 700 characters.
   - `grep -c '| — |' docs/superpowers/plans/backlog.md` prints 0.
   - `sed 's/\\|//g' docs/superpowers/plans/backlog.md | awk -F'|' '/^\|/ && NF != 7'` prints nothing.
3. **Quick procedures.** Each `### Q` section is under 40 lines and contains no fenced block (awk between the Q headings). The verbatim rule is part of check 1.
4. **Nothing lost.**
   - For the runbook, the HANDOVER, the design document and the checklist, the headings outside fences are compared: `comm -23 <(git show origin/main:<file> | <headings> | sort) <(<headings> <file> | sort)` prints nothing.
   - The PR body carries the backlog mapping.
   - Every open step found by Task 8's keywords appears in a new line.
5. **Identifiers.** `bash tools/docs/new-identifiers.sh origin/main; echo "rc=$?"` prints nothing and rc=0.
   - It compares the sets of distinct values matched at the base and at HEAD (`git grep -ohE … | sort -u`, then `comm -13`), over every file the branch touches.
   - Patterns: OCIDs, IPv4 addresses, hex runs of 32 or more, 24-character lowercase identifiers, `towardpcc` and `towardpicu` hosts, ntfy topics, email addresses, `user@` in ssh lines, `.ssh/` paths, `C:\Users\` and `/c/Users/` paths.
   - Allowed: loopback, the RFC 5737 ranges, 169.254.169.254, the public app host, the project's own mail addresses, the commit attribution address, and the `example.com`, `example.org`, `cass.test` and `import.invalid` domains.
   - The scratch run caught each planted value and let the allowed ones through.
6. **Provenance.** The ratchet is green. The two D8 code files and `DockerAssetsStageTest.php` have no `(Plan|Task|fact) N` reference left.
7. **Gates.** Each followed by `echo "rc=$?"`, and all must be 0:
   - `php artisan test > /tmp/t-task-G.log 2>&1`;
   - `./vendor/bin/phpstan analyse > /tmp/stan-task-G.log 2>&1`;
   - `./vendor/bin/pint --test > /tmp/pint-task-G.log 2>&1`;
   - `git diff --check origin/main...HEAD > /tmp/diffcheck-task-G.log 2>&1`.

   Copy `vendor/` and `public/build/` from the main checkout first.

## What needs the owner

1. **The four proposed decisions (Task 3).** The owner accepts them by approving the merge, or tells the session which to drop.
2. **Dates that cannot be fully verified:**
   - port 22's 2026-09-25 first appears on 2026-11-06 (`303d6de`);
   - 0019 and 0020 carry no date of their own ("by 2026-09-11").
3. **Restoring over production is not written down** (backlog row 36), although design section 11 lists "backup restore". Decide whether it is written before the walk-through.
4. **Is a rollback an exception to the 48-hour rule?**
   - Q2 c runs Q1 from its first step, and Q1 b refuses a deploy within 48 hours of an open conference's submission or review deadline (`CLAUDE.md`).
   - So a bad release made earlier could not be reverted in the hours before a deadline. Until the owner decides, Q2 c says to stop and ask the owner when Q1 b blocks.
5. **F-1's "Coolify still clones with the deploy key"** rests on HANDOVER A1 and A8, not on a fresh check of the host.
6. **Identifiers already in the public repository,** for the owner's call; this branch does not change them:
   - the Coolify compose project id: runbook lines 16, 17, 1237, 1275 and 1325, and HANDOVER line 16, which also carries Coolify deployment ids;
   - a Windows user path in runbook "The key" and "Restore drill from the off-host copy" (1396, 1408, 1480, 1499, 1509);
   - personal email addresses in dated plans under `docs/superpowers/plans/`.

## Decided in review

The plan was reviewed twice; this is the record of what the reviews settled, so the implementer does not reopen it.

1. **Decision files.** Only the owner's decisions (Task 2), and four proposed for the owner's acceptance (Task 3). Engineering choices argued in a docblock get neither a file nor a row; the backlog's preamble names their docblocks. `docs/decisions/README.md` is unchanged.
2. **The runbook's reasoning paragraphs stay where they are.** A paragraph moves one at a time, when its section is next edited, as the brief says.
3. **(a) CI's `scripts` job runs the pointer checker** as one more `node --test` file: one line of `ci.yml`, a sensitive file, with Node built-ins only. There is no Pest wrapper.
4. **(b) No "—" cell in the backlog.** Rows 1, 21 and 22 carry their sources' triggers.
5. **(c) Task 5's additions stay:**
   - Rollback's known route;
   - Health's "First move", with C3's "Make room" sentence word for word;
   - two commands in "Every release" step 1;
   - one line in "Email triage".

   Q5 c links "Email triage" and "Secret rotation".
6. **The merged rows:**
   - 1 (7, 82), 2 (8, 85, 101), 7 (19, 68), 8 (25, 83), 13 (34, 47, 49);
   - 29 (106, 107), 32 (111-113), 34 (119, 120), 38 (123, 126, 130), 41 (14, 143) and 45 (139, 140).

   Each keeps what its entries still leave open.
7. **Comment-only D8.** The redundant `withCommands` list and the broad `{64}` invitation constraint stay, and neither gets a backlog row.
8. **Shell discipline.** No Bash command names Coolify or ssh: the Grep tool for those searches, and `--body-file` for the PR. The HANDOVER permalink uses a short SHA. The decision files use 0017's "accepted by the owner with the merge of #NN", so no commit follows the approval.
