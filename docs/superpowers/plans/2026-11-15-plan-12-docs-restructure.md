# Plan 12: documentation restructure (C8, with D8)

Branch `docs/c8-restructure`. Brief: `docs/reviews/2026-10-16-expert-brief.md`, phase C row C8, phase D row D8 and "Question 7, documentation: keep, condense, move". Written on 2026-11-15 against `main` at `2f1f78f`. Implemented later in the same worktree, after the six open phase C and D branches have merged (section 7).

## Summary

Docs only, plus comments in two code files. No behaviour change, no migration, no environment variable, no dependency. Sensitive files: `bootstrap/app.php`, comments only.

- Backlog: 144 lines and 96 entries become one table (item, area, trigger, the one-line change, link) of about 49 rows, under 60 lines.
- Decisions: 23 new files, provisionally 0018 to 0040. They hold every "Decided" entry that has no file yet, plus the reasons the runbook and the backlog give for deliberate choices. The numbers are fixed after the rebase.
- Runbook (1,708 lines): a "Quick procedures" block at the top, with six procedures of under 40 lines each, made of commands and their expected output. About 18 reasoning paragraphs become one sentence and a link. No heading is renamed or removed, and no procedure or incident note is dropped.
- Handover: each item in the "Open" lists becomes one line with a link, and a phase C table records the six merged branches. The old full text stays one permalink away.
- Design document: marked "as approved 2026-09-10; the code is authoritative where they differ", with sections 2 and 4 annotated.
- D8: fixes the comments in `bootstrap/app.php` and `routes/web.php`, including four more wrong comments found while checking them against the code. It also says in runbook "One-time setup" step 2 that the repository is public, and fixes two runbook commands that cannot run as written.
- A pointer checker (links, anchors, backticked paths, cited titles) gates the pull request. On `main` today it finds 0 broken pointers.

## 0. Verified on 2026-11-15

| Fact | The briefs said | Today, on `2f1f78f` |
|---|---|---|
| Runbook | 1,364 lines; about 1,450 | 1,708 lines, 141 KB, no duplicate heading |
| Backlog | 100 dense lines | 144 lines, 14 sections, 96 entries, 20 lines with "Decided" |
| Handover | | 122 lines, 37 KB; 18 lines over 400 characters |
| Decisions | 0001 to 0017 exist | as said. 0001 to 0009 copy the backlog's launch decisions word for word. Backlog lines 35-36, 43-45, 59-61 and 81 are 0001 to 0009, line 112 is 0015's amendment and line 144 is 0016 |
| Provenance in code | 223 comments | 381 matches of `(Plan\|Task\|fact) N` in 183 files (`grep -rEo`, over app, tests, resources, routes, bootstrap, config, database, lang, docker and tools/backup). They are not bulk-rewritten; the two D8 files go to 0 |
| Pointer style | | Living docs have no relative markdown links. They point with backticked paths and quoted titles: 26 distinct runbook titles are cited, for example `runbook "Every release" step 2` |
| Pointer check | | 0 broken in 40 files (section 8, run on `2f1f78f`) |

The D8 pointers date from 2026-10-16. Checked against today's files:

- `bootstrap/app.php:26-29` has moved to **34-37**: the tracker imports added on 2026-11-04 (`2d509f0`) pushed it down by 8 lines. It is the same comment, and it is still false.
- `routes/web.php:129-133` and `:155-156` are **unchanged**: the file has not changed since 2026-09-12 (`119ec94`).
- Design section 4 is at **line 94**. It still says admin is "granted only by console command or by another platform admin".
- "Docs that call the repository public" **no longer applies**. The repository has been public since 2026-10-16, and decision 0011 kept it public on 2026-11-06. The opposite mistake does exist in three places: runbook "One-time setup" step 2 ("The repository is private"), design section 2 ("public now, private later") and HANDOVER A1 ("now that it is private").

## 1. Target shape

### 1.1 Backlog: one table

```
# Backlog

What is deferred, one row each: the trigger that brings it back and the one-line change. What is decided is in `docs/decisions/`, the state in `docs/HANDOVER.md`. A row is closed by the pull request that makes its change. Areas that other files cite (Submissions, Testing, Dependencies, Monitoring, Backups) keep those names.

| Item | Area | Trigger | The one-line change | Link |
| --- | --- | --- | --- | --- |
| The status link a submit emails is not capped | Submissions | `email_logs` shows one address receiving repeated `submission_received` rows | Route `SubmitAbstract`'s receipt through `SendSubmissionStatusLink::handleForAuthor()`, which already caps the draft form's link | [0014](../../decisions/0014-status-link-caps-on-the-public-form.md) |
```

Rules:
- One physical line per row, at most 400 characters, five cells, and no unescaped `|` inside a cell.
- An item that other files cite keeps its wording exactly ("Tracker events carry no release").
- The Link cell points to a decision, a runbook anchor, a spec section, or `—`.
- The file stays under 60 lines: 6 lines of preamble plus about 49 rows leaves room for 4 more.
- Area values: Security, Organizer, Public site, Documentation, Submissions, v2, Reviewing, Decisions, Domains, Testing, CI, Dependencies, Monitoring, Backups, Owner questions.

### 1.2 Decision files

Use the format in `docs/decisions/README.md`, in the bullet style of 0013 and 0014. Each file is a faithful condensation of its source and adds no new reasoning. "Decision" says who decided and when, as far as the record shows. "To reverse" names the code and the test that pins it, found by grep at implementation. If a source names no reversal, the file says what would change it instead. Worked example:

```
# Suspending an organisation takes its public conference pages offline at once

- **Context.** A suspended organisation's published conferences could have stayed online until each one closed; the suspension email already promises that they go offline.
- **Decision.** Decided by the owner and recorded in the backlog on 2026-09-11 (`d50c826`) as "Decided (owner)", with no date of its own. Every public page of a suspended organisation's conferences, and every short link to one, answers 404 to the public at once; members can still preview them.
- **Consequence.** A suspension stops an open call for abstracts with no grace period, and the authors' status pages stay offline until reinstatement (backlog "Owner questions" asks whether Resend status link should then be refused).
- **To reverse.** Let a suspended organisation through the `isApproved()` checks in `ConferenceController::isPublic()` and `ShortLinkController`; invert `it('takes a suspended organization conference offline but keeps the member preview')` in `tests/Feature/Public/ConferencePageTest.php` and the `'suspended organization'` case in `tests/Feature/Public/ShortLinkTest.php`.
```

`docs/decisions/README.md` gains two things:

- A clause in the "Decision" bullet: "or an engineering decision that a plan recorded, which says the owner has not ruled on it".
- A closing sentence: "0018 to 0040 were filed on <date> from the backlog and the runbook, numbered in the order they were first recorded; each names its own date."

Existing files are never edited.

### 1.3 Runbook

- **Lines 1-6 stay.** The title, the private-notes line, and the host and app lines.
- **`## Quick procedures` goes next, before "Finding the containers"** (section 5). It opens with two sentences:
  - "Each procedure is the shortest correct path. The section it links to has the reasons, the edge cases and the incidents."
  - "When a section changes, its quick procedure changes in the same commit."
- **The full sections stay where they are, under their current titles.** Only the paragraphs listed in section 4 change. Each becomes its operational sentence plus `(decision NNNN)`. Untouched text is not reflowed, so other branches' runbook diffs still apply.
- **Quick-procedure headings never repeat an existing title.** They are `### Q1. Every release` to `### Q6. The off-host copy`, so `runbook "Every release"` still means the full section.

### 1.4 HANDOVER

- **Unchanged:** the title, the introduction, "Where things stand" (apart from the facts that arrive from Open rows), "How the work is done", "Files that are not in this repository" (cited by `docs/dev-setup.md`) and "Setting up a development PC".
- **Open lists:**
  - Every heading stays.
  - Each row keeps its id (A1 to A9 and B1 to B13 are cited as "HANDOVER, B3" and so on) and becomes one line of at most 300 characters: id, item, who, and a status with a link.
  - Any step a row still leaves open stays in its line. For example, B5's "one probe of `/up-missing` to see the alarm's email arrive" is recorded nowhere else.
  - A state fact a session needs moves to its "Where things stand" row. For example, the installed off-host script's commit and SHA-256 go to the Backups row.
  - History that is stated nowhere else stays reachable through one permalink, placed under the first Open heading: `docs/HANDOVER.md` at the commit the rebase starts from.
- **The two paragraphs after phase B** (lines 64-75) become two lines: what the owner does next, and where the owner's open items are (backlog "Monitoring", "Backups", "Owner questions").
- **New `## Open — phase C, first month after launch`:** C1 to C8, one line each, filled from the six merged branches' reports (section 7). C2 is not started and C8 is this pull request.
- **Phase D:** one line linking the brief's table. D3 is done by its own branch, D8 by this pull request, and D1 and D2 are backlog rows.

Example:

```
| B8 | Off-host encrypted backups and a restore drill | Owner, with the engineer | Installed 2026-11-14 (#71; [decisions 0016](decisions/0016-offsite-backups.md) and [0017](decisions/0017-two-dynamic-groups-match-the-host.md)); left: the first drill, then the retention lock (backlog "Backups") |
```

### 1.5 Design document, launch checklist, decisions README

- **Design document.** After the `Status:` line (line 4) goes `> As approved 2026-09-10; the code is authoritative where they differ. Later decisions are in docs/decisions/, deferrals in the backlog and the state in docs/HANDOVER.md.` Sections 2 and 4 get the annotations in section 6. Nothing else in the document changes: the banner covers the other differences. Two examples: section 9 says the domain token is hashed, but the code stores it in plaintext (0033); and section 11 lists a "backup restore" in the runbook that does not exist (section 5, Q3).
- **Launch checklist,** pointer fixes only:
  - Line 162: delete the stale "The backlog records that"; the sentence keeps its claim.
  - Lines 335-336: "answered in `docs/superpowers/plans/backlog.md`" becomes "recorded in `docs/decisions/` (0001 to 0008)".
  - Section 1's introduction gains that same sentence.

## 2. Backlog: where each of the 96 entries goes

Entries are cited by their line on `2f1f78f`. "Row" is a row of the new table, "file" is a new decision (section 3), and "exists" is a file already there.

| Lines | Entry, short | Goes to |
|---|---|---|
| 7 | CSP shipped, two loose directives, three published views | row "CSP keeps `'unsafe-eval'` and inline style attributes" (Security) + file 0037; the views part joins the next row |
| 8, 85, 101 | Drop the published views; three views frozen; the next Filament bump is red by design | row "Three published Filament views" (Security): each bump re-publishes them with their digests and the `EditProfile::content()` pin; the sidebar copy can go now, the other two when Filament nonces `<style>` |
| 9 | Measure memory under load | row (Security) |
| 10 | Port 22, closed by decision | file 0038; no row |
| 14 | Suspended organisations' owners keep read access? | row (Owner questions) |
| 15, 16, 19, 21 | `roleIn()` memo; organisation name not unique; `dailyVisitCounts()`; 69 inline styles | one row each (Organizer) |
| 17, 18 | Decided (owner): suspension offline; the QR PNG size | files 0018, 0019 |
| 20 | Hard purge exposed; Decided: the purge leaves `activity_log` | file 0032; the done half is dropped |
| 25, 83 | Pagination views not in `@source` (stated twice) | one row (Public site) |
| 26, 84 | `og:image` poster half; the language sweep (D2) | one row each (Public site) |
| 30 | Plan 1's text describes the old defaults | row "Plans and code comments carry stale text and provenance" (Documentation), with the brief's rule of fixing a provenance reference when its file is touched |
| 34, 47, 49 | Per-abstract QR and abstract pages; bidding and declared conflicts; certificates | one row "Spec section 14 features" (v2), keeping each entry's foothold (`short_links` is polymorphic; today the organiser removes a conflicted reviewer) |
| 35, 36, 43, 44, 45, 59, 60, 61, 81 | The owner's launch decisions | exist as 0001 to 0009; no row |
| 37 | Decided: `SubmissionResource` is scoped by hand | closed by C5, with C5's reason; if C5 has not merged, a row with trigger C5 |
| 38, 39 | Submit link not capped; IPv6 /64 limiter key | one row each (Submissions); 38 is the worked example |
| 46, 48, 50, 51 | Per-row unassign; expertise and track matching; AutoAssign never removes; free-mailbox list | one row each (Reviewing) |
| 52, 53, 54 | Decided: membership is no conflict; removing keeps reviews; invitation status derived | files 0023, 0024, 0025 |
| 55 | Deprecated Filament table helpers | closed by D3; if D3 has not merged, a row |
| 62, 63, 64 | Decided: unsent letters do not block; `review_count` and `score`; weight locks | files 0026, 0027, 0028 |
| 65, 66, 67, 68 | Per-track quotas; no score-to-decision rule; one template per decision; XLSX on disk | one row each (Decisions) |
| 69 | Ranking wall clock measured deliberately | file 0029 (the reasoning) + row "The ranking's wall-clock case is a diagnostic" (Decisions), trigger: a dedicated performance runner |
| 73 | Answered (owner, 2026-10-01), the eight questions | dropped: they are 0001 to 0008 and checklist section 1 |
| 74; 75, 76 | Domain token stored in plaintext; TXT not CNAME, never re-verified | files 0033; 0034 |
| 77 | The Coolify domain step is manual | file 0035 (why the application never calls Coolify) + row "Automate the custom-domain step" (Domains), trigger: v2, spec section 15 |
| 78, 79 | Legacy `.invalid` addresses; legacy passwords not carried | file 0036 |
| 80 | `withCommands` registration fact | closed by D8: the `bootstrap/app.php` comment now says it (section 6) |
| 82 | No CSP report endpoint | file 0037 |
| 86 | The nightly dump lives on the host | file 0030 |
| 90 | `SubmissionFormTest` wall clock | row (Testing), or closed by C7 if its report says so |
| 92 | Fixed: the QR flake | the fix is dropped (the HANDOVER `main` row records #73); row "Phone readers and the 18 hard QR matrices" (Testing) for the unverified part |
| 96 | `image` job skips a `composer.lock`-only change | row (CI), or closed by C1 if its report says so |
| 100 | `config.platform.php` pinned to 8.4.1 | file 0039 |
| 102 | PHP 8.5 with Node 26 LTS | row (Dependencies), linking 0012 |
| 106, 107 | Tracker events carry no release; the first `report()` produced no event | one row "Tracker events carry no release" (Monitoring), with the watch item as its last clause |
| 108, 114 | Done: `APP_PREVIOUS_KEYS`; Auto Deploy off | dropped (done) |
| 109 | Frame arguments done, MySQL half open | row "MySQL's duplicate-entry wording in the server log" (Monitoring) |
| 110, 115 | URL scrub names its paths; Bot Fight Mode and the probe | one row each (Monitoring) |
| 111, 112, 113 | Kuma's ntfy notification; GlitchTip decided (in 0015) with its alert to confirm; OCI support request | one row "The owner's monitoring checks" (Monitoring) |
| 119, 120 | First drill, then the lock; the Kuma monitor unchecked | one row (Backups) |
| 121 | Twenty-one old backups stay on the host | dropped: 0016's Consequence states the rule, and they age out by their own rotation, the last near 2026-12-31 |
| 122, 124, 125, 127, 129 | Instance principal; restoring onto a new host; an outside upload signal; full weekly archives; `tools/` in the image | one row each (Backups); 124 is widened to "Restoring production, on this host or a new one, is not written down" (section 5, Q3) |
| 123, 126, 130 | Hash-locked CLI requirements; the drill as a script; two harmless races | one row "Backup tooling refinements, when the scripts next change" (Backups) |
| 128 | "Verifying one on the host" needs a root shell | closed here: the runbook block is fixed (section 4, F-3) |
| 136-140, 142, 143 | Seven open questions | one row each (Owner questions) |
| 141 | `cass:health` and free space | closed by C3 |
| 144 | Plan 10's seven defaults | dropped: answered in 0016 |

That gives 48 rows, plus a 49th, "The pointer checker runs only by hand" (Documentation; trigger: the next change to `.github/workflows/ci.yml`), if the checker is committed (section 8). C1 and C7 may close one row each. If the branches' reports add rows and the table reaches 59 lines, merge in this order:
1. the two reviewer-affiliation questions (139, 140);
2. `dailyVisitCounts()` with the XLSX row, as "Scale limits".

## 3. New decision files

The numbers are provisional. At implementation they start at the next free number on `main`, in this order (the order first recorded). "Engineering" means a plan recorded it and the owner has not ruled on it separately, and the file says so.

| No. | Slug | Decided by, first recorded | Sources |
|---|---|---|---|
| 0018 | `suspension-takes-public-pages-offline` | owner, by 2026-09-11 (`d50c826`) | backlog 17 |
| 0019 | `qr-png-whole-module-square` | owner, by 2026-09-11 (`d50c826`) | backlog 18; `GenerateConferenceQr::png()`, `it('renders a png of at least the configured size')` |
| 0020 | `nginx-error-log-may-carry-a-status-token` | engineering, Plan 3 pre-merge, 2026-09-11 (`75f6473`) | runbook "Author status links" |
| 0021 | `reviewer-reminders-run-hourly` | engineering, Plan 4, 2026-09-12 (`0cee201`) | runbook "Reviewer reminders" ("Why hourly and not daily") |
| 0022 | `accepting-an-invitation-verifies-the-address` | engineering, Plan 4, 2026-09-12 (`0cee201`) | runbook "Invitations and their tokens" |
| 0023 | `membership-is-not-a-conflict-of-interest` | engineering, Plan 4, 2026-09-12 (`5d15285`) | backlog 52 |
| 0024 | `removing-a-reviewer-keeps-their-reviews` | engineering, Plan 4, 2026-09-12 (`5d15285`) | backlog 53 |
| 0025 | `invitation-status-is-derived` | engineering, Plan 4, 2026-09-12 (`5d15285`) | backlog 54 |
| 0026 | `unsent-letters-do-not-block-decided` | engineering, Plan 5, 2026-09-12 (`3dad42d`) | backlog 62; runbook "Marking a conference decided" |
| 0027 | `review-count-and-score-count-different-reviews` | engineering, Plan 5, 2026-09-12 (`3dad42d`) | backlog 63 |
| 0028 | `question-weights-lock-with-the-form` | engineering, Plan 5, 2026-09-12 (`3dad42d`) | backlog 64; runbook "`cass:rescore`" |
| 0029 | `ranking-wall-clock-measured-by-hand` | engineering, 2026-09-12 (`e4dea8c`) | backlog 69; runbook "The 500-row budget" |
| 0030 | `backups-are-made-by-the-host` | engineering, Plan 6 Task 11 decisions 3 and 4, 2026-09-12 (`86d9f8e`) | backlog 86; runbook "Backups" |
| 0031 | `queued-mail-travels-encrypted` | engineering, Plan 6 pre-merge, 2026-09-12 (`0354b9b`) | runbook "Invitations and their tokens", "Secret rotation" |
| 0032 | `a-purge-never-touches-the-activity-log` | engineering, Plan 6, 2026-09-12 (`ada2be2`) | backlog 20; runbook "Resetting", "Erasing one person's data"; `tests/Unit/PurgeConferenceTest.php` |
| 0033 | `custom-domain-token-is-stored-in-plaintext` | engineering, Plan 6, 2026-09-12 (`ada2be2`); spec section 9 deviation, the exception `CLAUDE.md` names | backlog 74; runbook "What the organizer does" |
| 0034 | `custom-domains-verify-once-by-txt` | engineering, Plan 6, 2026-09-12 (`ada2be2`) | backlog 75, 76; runbook "What the organizer does" |
| 0035 | `the-application-never-calls-coolify` | engineering, Plan 6, 2026-09-12 (`ada2be2`); spec section 15 | backlog 77; runbook "What you do when the email arrives" |
| 0036 | `legacy-import-carries-no-addresses-or-passwords` | engineering, Plan 6, 2026-09-12 (`ada2be2`); spec 5.10 | backlog 78, 79 (its `legacy-review.md:276` citation is copied, not read) |
| 0037 | `csp-argued-exceptions` | engineering, Plan 6, 2026-09-12 (`ada2be2`), worded again by Plan 7 | backlog 7, 82; runbook "Content-Security-Policy" |
| 0038 | `port-22-stays-open-key-only` | owner, 2026-09-25 as recorded on 2026-11-06 (`303d6de`), re-affirmed 2026-11-06 | backlog 10; checklist section 2; HANDOVER B3 |
| 0039 | `composer-platform-php-pinned` | engineering, 2026-10-01 (`4bee8d1`) | backlog 100 |
| 0040 | `one-email-log-row-per-message` | engineering, Plan 7, 2026-10-02 (`6173648`) | runbook "Email triage", "Sending decision emails" |

New files name no cloud resource, host, address or identifier that the decision does not need. For example, 0038 says "the VCN's security list", not its name.

## 4. Runbook: reasoning moved, and three fixes

Lines are on `2f1f78f`. At implementation each paragraph is found by its first words, because C3 and C4 edit this file first.

| Section, lines | Moves out | Stays, with the link |
|---|---|---|
| Cloudflare Turnstile, 159-165 | why fail open | the behaviour, the warning text and "treat it as an incident" (0001) |
| Author status links, 232-241 | why the error-log residue is accepted | which statuses leak a token, and "rotate it with **Resend status link**" (0020) |
| Email triage, 279-285 | one row per message, keyed by the notification | "a retry never adds a row; a `failed` row stays `failed` after a retry delivers; judge by the worker and the mailbox" (0040) |
| Reviewer reminders, 352-357 | "Why hourly and not daily" | one line (0021) |
| Invitations and their tokens, 408-426 | the two consequences | "accepting verifies the address; accepting while signed in as another account is refused" (0022); "a dump plus `APP_KEY` is a credential" (0031) |
| `cass:rescore`, 515-518 | why it is not scheduled | "Run it in exactly four situations" and the list (0028) |
| Sending decision emails, 585-589 and 596-606 | the retry-status reason; why letters rotate the link | the instruction to judge by worker and mailbox (0040); the support answer to "my old link is dead" (0005) |
| Marking a conference decided, 634-637 | nothing | add (0026) |
| The 500-row budget, 652-658 | why the wall-clock case is skipped | the CI guards, the opt-in command and the host timing (0029) |
| Resetting, 796-799 | why `activity_log` survives | what it leaves behind (0032) |
| What the organizer does, 982-986 and 992-996 | why TXT and not CNAME; why the token is plaintext | "only the TXT record is checked"; "shown in plaintext on purpose, never hash it" (0034, 0033) |
| What you do when the email arrives, 1025-1028 | why the application never calls Coolify | one line (0035) |
| Backups, 1218-1221 | why the script runs on the host | "it runs on the host, not in a container" (0030) |
| Content-Security-Policy, 1585-1598 | why there is no report endpoint; the two loose directives | "Deploy with it true", the published views and Rocket Loader, unchanged (0037) |
| Erasing one person's data, 1642-1644 | why the purge keeps `activity_log` | the rest (0032) |
| Off-host copies, 1364 (optional; see section 10) | the compromise bounds, which 0016's Consequence already holds | "after a suspected compromise trust only copies made before it", and single-part uploads (0016) |

Fixes, where the doc disagrees with reality:

- **F-1 (D8).** "One-time setup" step 2: "The repository is private, so Coolify clones it with the private key" becomes:
  - "The repository has been public again since 2026-10-16 (decision 0011)."
  - "Coolify still clones it with the private key `cass-deploy-key`. The application was switched to it that day, while the repository was briefly private (HANDOVER A1), and a deploy key works either way."
  - "Private Repository (with deploy key)" stays: it is Coolify's name for the source type. Sources: HANDOVER A1 and A8, where the build of 2026-10-17 went through the deploy key on the public repository.
- **F-2.** "Reviewer reminders", "Checking it by hand" (362-363) runs `docker exec cass`. No container has that name, so the block uses `C=$(cass_container app)` and `sudo docker exec "$C"`. The block at 391-394 gains the same `C=` line.
- **F-3** (closes backlog 128). "Verifying one on the host", 1289 and 1293. The directory is 0700 and owned by root, and a glob expands in the operator's shell before `sudo` runs. So the lines become `NEWEST=$(sudo sh -c 'ls -1t /srv/backups/cass/cass-*.sql.gz | head -1')` and `sudo gunzip -c "$NEWEST" | …`.

## 5. Quick procedures: outline

Assumptions for every procedure:
- Host commands run after `ssh -i <ssh-key> <ssh-user>@<origin-ip>` with `cass_container` pasted ([Finding the containers](../../runbooks/deploy-production.md#finding-the-containers)). In this outline, links point into the runbook from here; inside the runbook they become bare `#anchors`.
- A session's command reaches the host only while production is unlocked, and the unlock is the owner's.
- The block opens with a two-column index (situation, procedure).
- Every expected output below is copied from the code that prints it:
  - `deploy.sh`: `DEPLOY_DONE`, and the `DEPLOY_FAILED:` exit codes 1 to 7 at its lines 11-19;
  - `HealthCommand`: a Check/Status/Detail table, with `OK` or `FAIL`;
  - `docker/backup.sh:154`;
  - `tools/backup/cass-backup-offsite.sh:82-83`;
  - `docker/supervisord.conf`: the programs php-fpm, nginx, queue and scheduler.

- **Q1. Every release, under 30 lines.**
  1. On the PC, the commit and its run: `gh run list --workflow ci.yml --branch main --commit "$(git rev-parse origin/main)" --json conclusion --jq '.[0].conclusion'` prints `success`. The `image` job must be `success`; if it is `skipped`, run `gh workflow run ci.yml --ref main` and wait.
  2. No open conference has its submission or review deadline within 48 hours (`CLAUDE.md`). The `/admin/conferences` list shows the submission deadline, and each conference's view page shows the review deadline.
  3. If the mysql digest changed, run `sudo /usr/local/bin/cass-backup` first; it prints `[cass-backup] /srv/backups/cass/cass-<stamp>.sql.gz (<n> bytes), keeping 14 days`.
  4. Fetch and compare `origin/main` again, and merge nothing until the deploy ends.
  5. Run the script line. Its last line is `DEPLOY_DONE`. On `DEPLOY_FAILED:`, stop: exit 2 means "When the host itself is slow" applies, and exit 4 means nothing was migrated.
  6. `/up` answers 200, and `/org/login` is styled.
  7. Run the page timing loop; the median must be 0.300 s or less.
  8. Record the release in the HANDOVER Production row.
  9. Never deploy with Restart, Redeploy or `db:seed` (in C4's wording after its merge).
- **Q2. Roll back, under 15 lines.**
  1. Find whether a release caused it ([When an alert fires](../../runbooks/deploy-production.md#when-an-alert-fires)).
  2. In Coolify, under Deployments, redeploy the previous successful build.
  3. Never roll the schema back; migrations are additive.
  4. That deploy ran no checks, so by hand: `/up` 200, `cass:health` all OK, and `migrate:status` with nothing Pending.
  5. Before going back past Plan 7, drain the queue ([Rollback](../../runbooks/deploy-production.md#rollback)).
  6. Record the commit now running.
- **Q3. Restore check, under 30 lines.**
  1. `C=$(cass_container mysql)`, then `NEWEST` as in F-3. Expect last night's 03:17 dump or a later hand run.
  2. Create `cass_restore_check` and load it. There is no output, and the exit code is 0.
  3. Run the row-count loop: live and restored are close and neither is zero. Then drop `cass_restore_check`.
  4. Monthly: [the drill](../../runbooks/deploy-production.md#restore-drill-from-the-off-host-copy--monthly-and-not-optional).
  5. Restoring over production is not written down: stop and ask the engineer (backlog "Backups"). This procedure does not invent one.
- **Q4. Health, under 20 lines.**
  1. `cass:health` shows every row `OK`, and its exit code is 0: database, migrations, queue, scheduler, private disk and mail, plus C3's row.
  2. Each `FAIL` row's first move:
     - database: the mysql container (`sudo docker ps`);
     - migrations: the by-hand block in "Every release" step 3;
     - queue: Q5;
     - scheduler: `supervisorctl status`, then `schedule:clear-cache`;
     - private disk: `cass:sweep-uploads`;
     - mail: the `MAIL_*` variables;
     - C3's row: as its runbook row says.
  3. If the heartbeat is quiet: check the `WARNING` lines in `storage/logs/cass-health.log`, and the `DONE` or `FAIL` lines in `docker logs`.
- **Q5. A stuck queue.** The full text below shows the format.
- **Q6. The off-host copy, under 15 lines.**
  1. The last `[cass-backup-offsite]` line of `/var/log/cass-backup.log` reads `OK: <n> uploaded, <m> already off-host; newest dump <file>, <h> h old; pinged`.
  2. On `FAILED (exit N)`, use the exit table ([Off-host copies](../../runbooks/deploy-production.md#off-host-copies)), then run `sudo /usr/local/bin/cass-backup-offsite` by hand.
  3. After a hand-run `cass-backup`, run it at once.
  4. The Kuma monitor `cass offsite copy` is up and `cass-offsite-filling` is quiet.
  5. The monthly drill is the owner's, never run in a Claude session.

```
### Q5. A stuck queue

Mail is late, `cass:health` shows `queue` FAIL, or `/admin/email-logs` holds `queued` rows for hours.

1. `C=$(cass_container app)`, then `sudo docker exec "$C" su-exec app php artisan cass:health`: the `queue` row reads `oldest waiting job is <age> old`, FAIL past 15 minutes.
2. `sudo docker exec "$C" supervisorctl status`: `nginx`, `php-fpm`, `queue` and `scheduler`, each `RUNNING`. If `queue` is not: `sudo docker exec "$C" supervisorctl restart queue` prints `queue: stopped` and `queue: started`.
3. `sudo docker exec "$C" su-exec app php artisan queue:monitor database:default`: `[database] default` with `[N] OK`, N falling. Always `database:default`; a bare `database` watches a queue nothing uses ([Secret rotation](#secret-rotation)).
4. `sudo docker exec "$C" su-exec app php artisan queue:failed`: no rows, or each failure with its exception. Fix the cause (the mail transport, usually), then `sudo docker exec "$C" su-exec app php artisan queue:retry all`.
5. Judge a retry by the worker and the mailbox: a retried row keeps reading `failed` ([Email triage](#email-triage)).
6. Reminders still not sent while the queue drains: `sudo docker exec "$C" su-exec app php artisan schedule:clear-cache` ([Reviewer reminders](#reviewer-reminders)).

Never `cache:clear`: with the database cache store it empties the scheduler heartbeat and every rate limit too.
```

## 6. D8 fixes, at today's line numbers

All of these change comments or docs only. Each code edit removes a provenance reference, or states the fact the reference stood for.

| Where | Wrong today | Fix |
|---|---|---|
| `bootstrap/app.php:34-37` (brief: 26-29) | "app/Console/Commands is NOT scanned without this (fact 2)". But `Application::configure()` calls `withCommands()` with no arguments (`vendor/laravel/framework/src/Illuminate/Foundation/Application.php:252`), which adds `app/Console/Commands` as a discovery path (`Configuration/ApplicationBuilder.php:334-337`). `cass:demo-seed`, `cass:demo-reset` and `cass:sweep-uploads` are registered without being listed | The comment says discovery already registers every class in the directory, and that the list only names four commands for grep, so removing one does not unregister it. The list stays (section 10) |
| `bootstrap/app.php:59` | "(fact 15)" | deleted; the sentence already states the fact |
| `bootstrap/app.php:93` | "Spec 5.8 and the Plan 1 backlog item:" | "Spec 5.8:" |
| `bootstrap/app.php:146-150` (found) | "spends neither Sentry's 5,000 a month …; The DSN's own rate limit in Sentry caps the total". The tracker has been GlitchTip on the owner's host since 2026-11-06 (decision 0015, amended) | "so one error raised on every request neither floods the tracker nor holds a worker per request"; the runbook pointer stays |
| `routes/web.php:125-134` (brief: 129-133) | "a placeholder until Task 8 writes the real page", with Task 5 and Task 8 provenance. `SubmissionStatus` is the real page | the paragraph is deleted; lines 135-138 stay |
| `routes/web.php:155-156` | "the hex alphabet InvitationToken mints". The constraint at :158 is `[A-Za-z0-9]{64}`, and `InvitationToken` mints lowercase hex (`app/Support/Tokens/InvitationToken.php:29`) | "64 letters and digits, which every token InvitationToken mints (lowercase hex) matches, so a path of any other length or alphabet never reaches the database". The constraint stays (section 10) |
| `routes/web.php:46-47` (found) | "Plan 3 registers …/submit with the same binding field" | "conference.submit below uses the same binding field" |
| `routes/web.php:77-81` (found) | such a route "would 404 in production while passing every test (Task 1 Step 6 fixes the fallback …)". But `docker/nginx.conf:65-75` now falls through to Laravel | "nginx answers a static-extension URI from disk first and reaches Laravel only through its fallback, which a download route should not depend on" |
| `routes/web.php:113-116` (found) | "answers any URI ending in a static-file extension from disk before PHP sees it" | the same fact as the row above |
| Design document, line 94 | "granted only by console command or by another platform admin" | Annotate: "*As built: `PlatformAdminSeeder` sets it for `CASS_ADMIN_EMAIL` when `db:seed` runs at the first deploy; no command and no screen grants it.*" Re-check after C4, which may add one: grep `'is_platform_admin' => true` |
| Design document, section 2, line 39 | "public now, private later" | Annotate: "*It stays public: the owner's decision of 2026-11-06 (decision 0011).*" Add the banner after line 4 |
| Runbook "One-time setup" step 2 | "The repository is private" | F-1 (section 4) |
| HANDOVER A1 | "now that it is private" | rewritten in the condensed row |

Before the pull request, read every comment in both code files once against the code, and list in the PR any further disagreement found.

## 7. Ordering, rebase and fold-in

This item is the one exception to the common brief's rule against editing the backlog and the handover. It is scheduled after the six open branches merge, so that each file is rewritten once:
- `ci/c1-c7-hygiene`
- `feat/c3-health-disk-row`
- `feat/c4-seeder-force-password`
- `feat/c5-native-submission-tenancy`
- `refactor/c6-one-membership-check`
- `test/d3-table-action-helpers`

1. **Rebase.** When the orchestrator says the six have merged, run `git fetch origin && git rebase origin/main`. Only the plan commit is on the branch, so no conflict is expected. Run the checker on the new `main`: rc must be 0, which is the new baseline.
2. **Re-verify, don't trust this plan.**
   - Find every runbook paragraph in section 4 by its first words, and record the new line numbers in the PR.
   - Find the backlog entries of section 2 by their labels.
   - Re-check the D8 rows of section 6.
   - Read what C3 changed (the Health table) and what C4 changed ("One-time setup" step 6, the warning in "Every release" step 3, possibly the way admin is granted).
   - `ls docs/decisions`: the highest number sets 0018's replacement.
3. **Collect the fold-in.** Gather the six reports' `closes=` and `open=` lines, from the orchestrator's message or each PR body (`gh pr view <n> --json body --jq .body`). Apply them:
   - backlog lines named closed are dropped, and the closing PR goes on the HANDOVER phase C line;
   - CI row facts (C1's wall clock before and after, C7's coverage number) and C4's owner step are added to their rows.
   - If a record PR has already applied a closure, leave it as it is.
4. **Commits, in an order that keeps the checker green after each:**
   1. decision files and the README;
   2. the runbook;
   3. the backlog;
   4. the HANDOVER;
   5. the design document and the checklist;
   6. the code comments;
   7. the checker, if committed: its `node --test` file first, run and shown red, then the script.

   Subjects are `docs(...)`, `docs(comments)` and `test(tools)`. Messages that name the production words go through `git commit -F <file>`.
5. **Keep later merges clean.**
   - No file is moved or renamed, and no heading is removed or renamed.
   - The runbook changes only the listed paragraphs, plus one inserted block.
   - "Where things stand" rows change only where a fact arrives from an Open row, so a later record's diff still applies.
   - If `main` moves during the work, rebase again. Resolve each conflict by re-applying the other side's change to the new shape, never by taking one side whole, then re-run every check.

## 8. Checks, each by exit code, logs named `-task-G`

1. **Pointers.** `node tools/docs/check-pointers.mjs . > /tmp/links-task-G.log 2>&1; echo "rc=$?"` must print 0. It checks, outside code fences:
   - relative markdown links and their `#anchors`, using GitHub's slug rules (lowercase; drop everything that is not a letter, digit, mark, `_`, `-` or a space; spaces become hyphens; a repeated heading gets `-1`, `-2`);
   - backticked repository paths in living docs, allowing git-ignored runtime files and a few deliberate mentions such as `resources/images/`;
   - `runbook "X"` and `runbook **X**` citations, and the runbook's own `(see **X**)` cross-references, against headings (the whole title, or the part before " — ", " (", ":" or ",");
   - `backlog "X"` against the table's areas and items (or its headings, before the conversion);
   - `decision NNNN` against the files;
   - `HANDOVER, B8` against the row ids.

   Dated plans and `docs/reviews/` are records: only their links are checked. The script exists in the session scratchpad. On `2f1f78f` it reports 0. In a negative test it caught all seven planted breaks, and one anchor guessed by hand, `#cass-rescore`, whose real slug is `#cassrescore`.
2. **Backlog budget.**
   - `test "$(wc -l < docs/superpowers/plans/backlog.md)" -lt 60`.
   - `awk 'length > 400' docs/superpowers/plans/backlog.md` and `awk -F'|' '/^\|/ && NF != 7' docs/superpowers/plans/backlog.md` print nothing.
3. **Quick procedures.** Each `### Q` section is under 40 lines (awk between headings).
4. **Nothing lost.**
   - For the runbook, the HANDOVER, the design document and the checklist, `comm -23` of `git show origin/main:<file> | grep '^#'` against the new file's headings prints nothing.
   - The PR body carries the section 2 mapping (entry to row, file, closed with its reason, or dropped with its reason).
   - For each moved paragraph, one distinctive phrase is found by `grep -F` in its decision file.
   - Every open step in the old Open rows is listed and found in a new line: grep the old text for "not started", "left", "to do", "to confirm", "pending" and "open".
5. **No identifier added.** For each pattern, the count in `docs`, `CLAUDE.md` and `README.md` at HEAD is at most the count at `origin/main` (`git grep -c`): `ocid1\.`, IPv4 addresses, hex runs of 32 or more, 24-character lowercase identifiers, `towardpcc\.com` hosts other than the public app's, and `ntfy\.sh/`. Moving text keeps a count the same; adding it would raise it.
6. **No provenance in the two code files.** `grep -cE '\b(Plan|Task|fact) [0-9]' bootstrap/app.php routes/web.php` is 0 for each, and no added line matches.
7. **Gates.**
   - `php artisan test > /tmp/t-task-G.log 2>&1`
   - `./vendor/bin/phpstan analyse > /tmp/stan-task-G.log 2>&1`
   - `./vendor/bin/pint --test > /tmp/pint-task-G.log 2>&1`
   - `git diff --check origin/main...HEAD > /tmp/diffcheck-task-G.log 2>&1`

   Each is followed by `echo "rc=$?"`, and all must be 0. `vendor/` and `public/build/` are copied from the main checkout first, as the common brief says.

## 9. What needs the owner

Nothing is expected: the brief is approved, and the pre-merge approval of `bootstrap/app.php` follows the usual route. These are the points where a decision's date or wording cannot be verified and would be inferred. Each file states its evidence, and the owner may correct any of them:

1. **Port 22 (0038).** The date 2026-09-25 first appears on 2026-11-06 (`303d6de`), in the backlog, checklist section 2 and HANDOVER B3. No record from September exists in the repository.
2. **Suspension offline and the QR PNG size (0018, 0019).** Both say "Decided (owner)" with no date. The file uses "by 2026-09-11", the day the backlog recorded them.
3. **The twenty engineering decisions (0020 to 0037, 0039, 0040).** No owner ruling is recorded. The files say so, and the README clause in 1.2 allows that wording.
4. **Restoring over production.** No procedure exists, although design section 11 lists "backup restore" in the runbook. Q3 does not invent one, and the widened backlog row asks whether it is written before the walk-through.
5. **F-1** ("Coolify still clones with the deploy key") comes from HANDOVER A1 and A8, not from a fresh check on the host.

## 10. Choices a reviewer should challenge

1. **23 new decision files.** The alternative is only the "Decided (owner)" paragraphs (3), or every "Decided" entry (10, once C5 has closed the eleventh). Fewer files would leave reasons in the backlog and break the 60-line budget.
2. **Merged rows.** The section 2 merges (spec 14; owner checks; backup refinements; tracker watch) and the dropped entries (121; done items) are needed to fit under 60 lines while keeping every open item.
3. **Comment-only D8.** The redundant `withCommands([...])` list and the broad `[A-Za-z0-9]{64}` invitation constraint stay. Deleting the list, or narrowing the constraint to `[0-9a-f]{64}`, would change code and need tests.
4. **The design document is annotated, not edited.** Its approved text stays visible.
5. **The runbook's full sections are kept**, so the quick procedures duplicate their commands. The drift rule is in the block's own preamble.
6. **The HANDOVER condensation** leaves unique history behind a permalink, rather than in an appendix.
7. **The checker is committed** to `tools/docs/` with a `node --test`, but not wired into CI (that needs `.github/`; a backlog row covers it). The alternative is keeping it in the scratchpad.
8. **Fixes that D8 does not name are in scope:** F-2, F-3, the four comments marked "found", and the optional condensation of runbook line 1364.

## 11. Tasks

- **T1.** Rebase after the six merges. Re-baseline the checker, re-verify sections 2, 4 and 6, and collect the fold-in.
- **T2.** Decision files, in the order of section 3, plus the README clause and sentence.
- **T3.** Runbook: Q1 to Q6 at the top, the section 4 moves, F-1 to F-3.
- **T4.** Backlog: one table, applying the section 2 mapping and the fold-in. Under 60 lines.
- **T5.** HANDOVER: Open rows on one line each, the phase C table, the phase D line, the permalink, and facts moved into "Where things stand".
- **T6.** Design document banner and annotations; checklist pointers.
- **T7.** Comments in `bootstrap/app.php` and `routes/web.php`.
- **T8.** Checks 1 to 7. The PR lists the sensitive file, the mapping and the gate logs.
