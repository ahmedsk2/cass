# CASS v2 Plan 10: B8 Off-Host Encrypted Backups and the Restore Drill

> **Status: DRAFT, revised 2026-11-06 after its adversarial review** (verdict "approve with fixes": 5 major, 14 minor and 7 nits; every major and minor is applied, the nits as listed under "Review findings and where they landed"). Written against `main` at `d417c8a`; not prototyped. Next: the owner's approval of the summary (review point 1), including the seven defaults marked "owner to confirm".
>
> **Owner decisions of 2026-11-06, binding for every task below.** The off-host copies go to a private OCI Object Storage bucket in the owner's own tenancy (same cloud, another service and failure domain than the VM's disk), with a retention rule or versioning and a lifecycle rule deleting objects after about 90 days. The host signs in as an instance principal through the OCI CLI installed for root, with no API key on the host. Every file is encrypted with `age` to a public key kept root-only on the host; the owner generates the key pair on their own PC and keeps the private key off the host in two places. A new script runs after the nightly dump and the weekly archive, encrypts what has not been sent yet, uploads it under a content-addressed name carrying the date, verifies it, records it and pings its own Uptime Kuma push monitor on success. A monthly drill on the owner's Windows PC restores an off-host dump into a throwaway `mysql:8.4` and compares row counts with production. The OCI resources are created after merge by the orchestrating session with the owner present ("After merge"). The choices this plan adds are in the facts below and in decision 0016 (Task 7); approving the summary accepts them.

## Owner steps (yours alone)

Three steps and nothing else; the session does the rest with you present ("After merge"). Any time before the host install. The runbook's "The key" (Task 4) repeats them, with rotation and what to do on a loss.

1. **Generate the age key pair on your PC.** First turn clipboard history off (Settings, System, Clipboard, and its cloud sync), or clear it after step 2. Then, in Git Bash:
   ```bash
   cd /c/Users/khawl/Desktop/Ahmed/claude/tools
   curl -fsSLO https://github.com/FiloSottile/age/releases/download/v1.3.2/age-v1.3.2-windows-amd64.zip
   echo 'f48d8f8f9ebe903ab5027ed067652f2cc1db94bc206976430133b905dcd8e8c7  age-v1.3.2-windows-amd64.zip' | sha256sum -c -
   powershell -NoProfile -Command "Expand-Archive age-v1.3.2-windows-amd64.zip -DestinationPath ."
   ./age/age-keygen.exe     # no options, never -pq: the host's age cannot encrypt to an age1pq1 key
   ```
   `sha256sum -c` must print `OK`; anything else, stop and tell the session (the digest is the one GitHub lists for that file, release v1.3.2 of 2026-08-29). The archive unpacks into a folder `age` with `age.exe` and `age-keygen.exe`; if yours unpacked elsewhere, use that path below. `age-keygen` prints three lines and writes nothing to disk.
2. **Store the private key twice, then check the stored copy.** The three lines (`# created: …`, `# public key: age1…`, `AGE-SECRET-KEY-1…`) go into one password-manager entry, "CASS backup key (age), <date>", and onto paper, written by hand or printed on a printer attached by cable, kept away from the PC. Then check the entry by pasting from the password manager, not from the screen:
   ```bash
   R=age1…   # your public key, from the "# public key:" line
   echo cass-key-check | ./age/age.exe -r "$R" -o check.age
   read -rs -p 'AGE-SECRET-KEY line: ' K; echo; printf '%s\n' "$K" | ./age/age.exe -d -i - check.age; unset K; rm -f check.age
   ```
   It prints `cass-key-check`. Clear the clipboard history, close the terminal (its scrollback holds the key), and give the session the `age1…` public key only. The private key never goes to the host, into a chat or into a cloud drive; lose both copies and every off-host copy is unreadable.
3. **Create the Kuma push monitor** `cass offsite copy`: type Push, heartbeat interval 24 hours, 1 retry after 60 minutes, the email notification the other three use. Create it on the day of the host install, or create it paused and resume it then: a push monitor that never hears from the host goes down after a day and emails you. Keep its push URL: at the install you paste it yourself into `/root/.cass-ping-offsite` in your own terminal, never into a chat.

**Goal:** an encrypted copy of every dump and storage archive off the host, which the host can add to but can neither read back nor delete, with a heartbeat that goes quiet when a night's copy is missing, and a monthly restore from that copy. Brief B8's "Done when": "A dump restored from the off-host copy, not the host copy, matches production row counts; the drill date and the next one are in `HANDOVER.md`."

**Architecture.** One owner per seam:
- The copy: `tools/backup/cass-backup-offsite.sh`, installed as `/usr/local/bin/cass-backup-offsite` and run by its own root cron line at 04:17. It reads `/srv/backups/cass`, writes only there (`.offsite-work/`, `offsite-manifest.tsv`, `.offsite.lock`) and to the bucket, and pings Kuma.
- Its proof: `tools/backup/cass-backup-offsite.test.sh`, fakes for `oci`, `age`, `curl` and `flock` and a scenario directory standing in for `/srv/backups/cass`, `/root` and the temporary folder; CI's `scripts` job runs it.
- The fences, outside the repository: the bucket with its retention rule, lifecycle, quota and size alarm, the dynamic group and two policies ("After merge"); the private key, with the owner.
- The procedures: runbook "Backups" ("Off-host copies" with "The key", "Installing the off-host copy", "Restore drill from the off-host copy") and "Monitoring"; `docs/HANDOVER.md`; decision 0016. The privacy page says how long backups keep deleted data (Task 6).

**Tech stack.** Bash 5 with coreutils, util-linux `flock`, `curl` and Python 3 (JSON and MD5 only) on the host's Ubuntu (ARM64); `age` from Ubuntu's archive on the host (1.1.1 on 24.04) and v1.3.2 on the PC; OCI CLI 3.96.0 (with `oci` SDK 2.190.0) in a root-only virtual environment on the host and in the tools folder on the PC; OCI Object Storage, IAM and Monitoring; Uptime Kuma push monitors. On the application side, two strings in `lang/en/public.php` and one Pest test; nothing in the image or the compose files changes.

**Spec and brief:** `docs/reviews/2026-10-16-expert-brief.md`, phase-B row B8 (its "Done when" is this plan's acceptance), "Question 4, operations" (the rows "Survive losing the host" and "Know the backup is a backup") and its point 4 on backups readable at rest; `docs/superpowers/specs/2026-09-10-cass-v2-design.md` section 8 (the NAS sync that was never connected); `docs/decisions/0015-error-tracking-and-heartbeats.md` (pings on success, ping URLs in root-only files); `docs/decisions/0011-repository-public-for-ci.md` (so no namespace, OCID or address goes into a file here).

**Environment facts for every command below**
- `$REPO` is the tree you implement in: the worktree the run gives you, branch `plan-10-b8-offsite-backups` from `main` at `d417c8a`, or your task's own `CASS-wt-N` worktree and branch. Shell variables do not survive between Bash calls: begin every call with `REPO=<that path>;` or write the path itself, and `cd $REPO`. Git Bash, after `export PATH="/c/Users/khawl/Desktop/Ahmed/claude/tools/php84:/c/Users/khawl/Desktop/Ahmed/claude/tools/composer:$PATH"`.
- A fresh worktree has no `vendor/`, `node_modules/`, `public/build` or `.env`. Before the first PHP gate: `php /c/Users/khawl/Desktop/Ahmed/claude/tools/composer/composer.phar install --no-interaction --no-progress`, `npm ci`, `npm run build`, then `cp .env.example .env && php artisan key:generate --force`. Never `composer.bat`, and never with `npm run dev` running.
- **The production hook** (fact 4) refuses a Bash command holding lowercase `oci` followed by a space, the release script's file name, the deployment platform's name, or a remote-shell command. Search such text with the Grep tool, write it with Write and Edit, run the harness by its path, and pass commit messages and the PR body as files. The project's read rule on `Legacy/` also covers `app/Actions/Legacy/`; this plan needs neither.
- Git Bash's `/tmp` is `C:\Users\khawl\AppData\Local\Temp`; the Write and Read tools turn `/tmp/...` into `C:\tmp\...`, which git never reads. Write message files at the Windows path; read gate logs with `tail -n 60 /tmp/<log>` in Bash or at the Windows path.
- **The gates**, N being the task number, each judged by its exit code alone:
  ```bash
  cd $REPO && bash -n tools/backup/cass-backup-offsite.sh > /tmp/syntax-task-N.log 2>&1; echo "syntax rc=$?"
  bash tools/backup/cass-backup-offsite.test.sh > /tmp/offsite-task-N.log 2>&1; echo "offsite rc=$?"
  sh docker/backup.test.sh > /tmp/backup-task-N.log 2>&1; echo "backup rc=$?"
  php artisan test > /tmp/t-task-N.log 2>&1; echo "test rc=$?"
  ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G > /tmp/phpstan-task-N.log 2>&1; echo "stan rc=$?"
  ./vendor/bin/pint --test > /tmp/pint-task-N.log 2>&1; echo "pint rc=$?"
  ```
  Task 1 runs the last four only: the script does not exist yet, and its harness run is the red proof. Baseline is the count `php artisan test` reports on the fresh branch (`/tmp/t-plan10-baseline.log`); Tasks 1 to 5 end at the baseline, Tasks 6 and 7 at baseline + 1 (Task 6's test).
- **Commits:** the message with the Write tool at `C:\Users\khawl\AppData\Local\Temp\commit-plan10-task-N.txt`, ending with the `Co-Authored-By:` trailer of the model that writes the commit, as the run's brief gives it; `git add` the task's files by name (the two scripts with `git add --chmod=+x`, fact 3), then `test -s /tmp/commit-plan10-task-N.txt && git commit -F /tmp/commit-plan10-task-N.txt`. Never with a gate red.
- **Order.** Stages `[[1],[2],[3,4],[5,6],[7]]`. Task 2 needs Task 1's harness; Tasks 3 and 4, and Tasks 5 and 6, touch disjoint files and run as pairs; Task 5 extends the runbook after Task 4; Task 7 runs last and opens the pull request.
- New shell is bash, `#!/usr/bin/env bash`, four-space indents as in `tools/claude/deploy.sh`. Comments explain the code, never its provenance: no "Plan 10", "Task N", "B8" or "fact N" in either script.
- `$SCRATCH` is the scratch directory the run gives you, outside the repository. Task 2's mutants live there and are never committed.

**What this plan does NOT build**
- Any change to `docker/backup.sh`, `docker/storage-backup.sh`, the `Dockerfile`, `.dockerignore`, the compose files or `tools/claude/deploy.sh`: the two backups, their cron lines and pings, the pre-migration backup and the image stay as they are, and the off-host copy needs no deploy. The privacy sentence (Task 6) reaches the site with the owner's next deploy; nothing in the install waits for it.
- PHP beyond Task 6's two strings and test; a migration, a policy class or an application environment variable. `cass:health` gains no row: the heartbeat is the signal.
- Any call to OCI from the repository, the harness or CI. The bucket, quota, alarm and IAM, the host install and the first drill come after merge, with the owner ("After merge").
- The metadata-service fence for containers (B3, owner to confirm), a drill script, a procedure for restoring production onto a new host, a hash-locked requirements list for the CLI, an object-name fence in the policy, `tools/` in `.dockerignore`: backlog (Task 7).

## Owner summary (review point 1)

#### Behaviour changes a user would notice
- On the site, with the next deploy: the privacy page says that deleted data stays in the backups until they expire, at most 90 days later, and that the service and its backups are kept in Saudi Arabia (Task 6; owner to confirm the wording).
- **For you, once installed:** every night at 04:17 the host encrypts each new dump and weekly archive to your public key and copies it to a private bucket in your OCI tenancy; Kuma's monitor `cass offsite copy` shows the push, and a night without one emails you the next morning. The host can add to the bucket and list names; it cannot read a copy back, overwrite it or delete it. For 30 days after upload nobody can change or delete a copy while the retention rule stands; locked after the first good drill (owner to confirm), nobody can remove the rule either. Copies are deleted 90 days after upload. The first run also sends the 15 dumps and the 8 or 9 archives already on the host. An OCI alarm emails `hosting-alerts` when the bucket passes 8 GB of its 10 GB quota.
- **What a compromised host can still do** (decision 0016): fill the quota with junk that nobody can delete for 30 days, so real copies fail until you raise the quota; and, as root, upload forged copies that decrypt cleanly (it holds your public key; age proves secrecy, not origin) and keep pinging Kuma while sending nothing (it holds the push URL). So the drill restores the copy the host's manifest names and checks it byte for byte against the host's own file, and after a suspected compromise only copies made before it count.
- **Your key is the only way in**, to an intruder and to you alike. Runbook "The key" says how to rotate it and what to do if a copy is lost or may have leaked.
- **Monthly, about thirty minutes:** the restore drill on your PC (runbook "Restore drill from the off-host copy"). You sign in to OCI in the browser for an hour-long session (`oci session authenticate`) instead of keeping an API key, restore the copy into a throwaway MySQL held in memory, compare the counts with production, tick the list, and move the two encrypted files to your external drive. The first drill closes B8.

#### Policies touched or added
None in the application. In OCI: the dynamic group `cass-backup-host` (this instance only); the policy `cass-backup-host-writes`, attached to the compartment `cass-backups`, letting the host create objects and list them in the bucket `cass-offsite` and nothing else; `cass-backups-lifecycle`, letting Object Storage itself delete expired copies there; the quota `cass-backups-cap` and the alarm `cass-offsite-filling` (runbook "Off-host copies"). Before any of it, the session lists every dynamic group and every policy statement that could already reach the instance, and stops if one does.

#### Migrations
None.

#### Environment variables
None in the application, and nothing in `.env.example` (`config/` reads none of them). On the host, three root-only files, `/root/.cass-offsite.conf`, `/root/.cass-age-recipient` and `/root/.cass-ping-offsite`, and the script's overrides, all in the runbook.

#### Sensitive files touched
- `.github/workflows/ci.yml`: one step in the `scripts` job runs the new harness. A change to this file also makes the pull request build the arm64 image (fact 2).
- Not on the list, but worth your eye: `tools/backup/cass-backup-offsite.sh`, which runs as root on the production host with the instance's OCI rights; `CLAUDE.md`, whose sensitive-path list gains `tools/backup/` (default 5); `lang/en/public.php`, the privacy page's wording (default 6).

#### Defaults, each owner to confirm (the review's recommendations; the plan is written to them)
1. **Lock the retention rule after the first good drill** (owner to confirm). The lock takes effect 14 days after it is set and can be cancelled until then; from then on nobody, Oracle included, can remove the rule or shorten it, and the bucket can go only once its objects have aged out. It is the one thing that stops someone holding your OCI login, which sits on a PC where agents run, from deleting the copies. Against a junk flood the only remedy is then a larger quota.
2. **Stay in Riyadh** (owner to confirm). A second region would mean a second upload by the script, not OCI replication (a replication destination cannot carry retention rules), billed outside the Always Free allowance and still in the same account; default 3 covers the likelier loss.
3. **The copy outside OCI: each drill moves its two encrypted files to an external drive, kept 90 days** (owner to confirm). It is the only copy that survives losing the tenancy itself (closure, suspension, a stolen login). The review suggested twelve months; 90 days keeps Task 6's privacy sentence true, and twelve months would need that sentence to say twelve months.
4. **The metadata-service fence for containers goes to B3 as a separate item, not into this plan** (owner to confirm): a `DOCKER-USER` rule refusing the container networks' traffic to 169.254.169.254, never a rule on the host's own traffic, which the CLI and the Oracle Cloud Agent behind `hosting-1-silent` need. Until then a compromised container can upload junk and read object names and sizes (Task 7 writes it into HANDOVER's B3 row).
5. **Add `tools/backup/` to the sensitive paths in `CLAUDE.md` in this pull request** (owner to confirm; Task 7). The script runs as root on production.
6. **The privacy page sentence on backups** (owner to confirm the wording; Task 6): deleted data stays in the backups until they expire, at most 90 days later; the service and its backups are kept in Saudi Arabia.
7. **The drill signs in with `oci session authenticate`** (owner to confirm): a browser sign-in and a token that expires within the hour, kept in a configuration file of its own, rather than a stored administrator API key on a PC where agents run.

## Review findings and where they landed

- **Major 1**, least privilege never audited, canary ambiguous: "After merge" A.1 lists every dynamic group (legacy and identity-domain) and every statement naming `dynamic-group`, `any-user` or `request.principal`, or stops; the canary runs before the retention rule exists and checks `NotAuthorizedOrNotFound` on the read, the delete, the forced overwrite and a bucket listing (Task 5).
- **Major 2**, what a compromise still allows: decision 0016, the owner summary and "Off-host copies" state the junk flood, forged copies and a faked ping; the drill restores the manifest's last rows and checks them byte for byte against the host's files; the exit-3 row says what to do on `StorageQuotaExceeded` (Tasks 4, 5, 7).
- **Major 3**, the plain-text check: ctime, not mtime; new top-level names only in `/tmp` and `/var/tmp`; a marker per scenario; back-dated decoys; unreadable entries skipped; the scenario's home, temporary and working folders read-only where chmod holds; mutants M5 and M6 (Tasks 1, 2).
- **Major 4**, the harness on CI: it refuses only when `age`, `curl` or `flock` is not a fake; the `python3` shim stays local (Task 1).
- **Major 5**, the key: "The key" in the runbook covers making, storing, checking, rotating, losing and leaking it (Task 4).
- **Minors**: 6 drill criteria and 8 the container in memory (Task 5); 7 the session sign-in (Task 5, default 7); 9 harness gaps, S21 to S26, M3 corrected, M7, static checks (Tasks 1, 2); 10 a vanished file and stdin (Task 2); 11 the quota's peak and the size alarm (Task 4, "After merge"); 12 the destination in the manifest key (Tasks 1, 2); 13 key hygiene (owner steps, "The key"); 14 the CLI binary-only with its versions recorded (Task 5, backlog for a hash list); 15 privacy and erasure (Tasks 5, 6, 7); 16 the script from a green commit (Task 5); 17 the alert proof through `-K -` (Task 5); 18 the runbook's gaps (Tasks 4, 5, backlog); 19 the fake key built at run time (Task 1).
- **Nits** 20 (trailer per model), 21 (HANDOVER's wrapped line), 22 (stages), 23 (15 dumps), 25 (canary name to the second) and 26 (PR gate logs) applied. 24 half: `cass-backup-host-writes` is attached to `cass-backups`, but no object-name fence, since junk could still go under `db/` and `storage/` and the drill no longer trusts names.

## File structure created or changed by this plan

```
tools/backup/cass-backup-offsite.test.sh   created, T1: fakes for oci, age, curl and flock; 26 scenarios
tools/backup/cass-backup-offsite.sh        created, T2: the off-host copy
.github/workflows/ci.yml                   changed, T3: the scripts job runs it
docs/runbooks/deploy-production.md         changed, T4 (Backups intro, Off-host copies, The key, Monitoring) and T5 (the install, the drill, the erasure note)
tests/Feature/Public/PrivacyPageTest.php, lang/en/public.php   created and changed, T6
docs/decisions/0016-offsite-backups.md     created, T7
CLAUDE.md, docs/HANDOVER.md, docs/superpowers/plans/backlog.md, docs/launch-checklist.md   changed, T7
```

## Facts verified before writing this plan

Read at `d417c8a` and in the vendors' documentation on 2026-11-06; the review re-checked 5 to 9 against the CLI 3.96.0 source.

1. **The backups today.** `docker/backup.sh` (POSIX sh, `set -eu`, `umask 0077` at :27) writes `cass-$(date +%F-%H%M).sql.gz` (:31) by a redirect from a `.partial` file, 0600 (:120-138), and rotates with `find "$DEST" -name 'cass-*.sql.gz' -mtime +14` (fifteen kept) and `find "$DEST" -name '*.partial' -mtime +1` (:150-151), both recursive; `docker/storage-backup.sh` writes `cass-storage-$(date +%F).tar.gz` (:38-42) by an atomic `mv`, a second run that day replacing it, rotates at 56 days (:162-163) and ends `(N bytes, M entries)` (:165). Root's crontab runs them at 03:17 and Sunday 03:47, each followed by `&& curl -fsS -m 10 --retry 3 -o /dev/null -K /root/.cass-ping-…` (runbook :1211-1213, :1320-1321), the ping files holding one line `url = "…"` (:1206). They reach the host by `docker cp` from the image (`Dockerfile`:63-71). The release script runs `cass-backup` before a pending migration, at any hour, and stops with exit 4 when it fails (its header, :16). So `offsite-manifest.tsv` matches no rotation pattern, and a `cass-….age.partial` under `.offsite-work/` is touched only once it is a day old.
2. **Shell tests here.** `tools/claude/deploy.test.sh` (bash) writes its fakes into `$ROOT/bin` (:42-268), refuses to run unless each wins the PATH lookup (:270-277), writes a `python3` shim only where `python3` is not Python 3 (:279-290), wraps runs in `timeout 30` (:292-293) and gives each scenario a fresh directory (`scenario()` :302, `run_deploy()` :345, `eq`/`has` :359-375); `docker/backup.test.sh` skips mode assertions where chmod is not honoured (:188-191). CI's `scripts` job (`.github/workflows/ci.yml`:190-223, `ubuntu-24.04`, 5 minutes) runs `bash -n` and the harness (:201-202). The `changes` job counts `ci.yml` as an image input (:61), so this pull request runs the arm64 `image` job.
3. **This PC's Git Bash** (checked 2026-11-06): no `flock`; `python3` is the Microsoft Store stub (`python` is 3.11); `timeout`, `sha256sum`, `md5sum`, `openssl`, `base64`, `stat` and `mktemp` exist. `core.filemode` is false, so the executable bit comes from `git add --chmod=+x`, as `tools/claude/split-plan.sh` has it (100755). `.dockerignore` does not list `tools/`, so the image holds a copy of `tools/` that nothing runs.
4. **The production hook** (`.claude/hooks/guard-production.mjs`:13-18): a Bash command matching `(^|[^\w.-])oci\s`, `deploy\.sh`, `coolify` (any case) or `ssh|scp|sftp|rsync` followed by whitespace is refused unless the owner unlocked production in the last hour; the `oracle-oci` tools are gated the same way (:20).
5. **OCI permissions** (Object Storage policy reference): `HeadObject` needs `OBJECT_READ` or `OBJECT_INSPECT`; `ListObjects` and `ListObjectVersions` `OBJECT_INSPECT`; `PutObject` `OBJECT_CREATE` for a new name and `OBJECT_OVERWRITE` for an existing one; `CreateMultipartUpload` and `UploadPart` `OBJECT_CREATE` and `OBJECT_OVERWRITE`; `CommitMultipartUpload` `BUCKET_READ`, `OBJECT_CREATE`, `OBJECT_READ` and `OBJECT_OVERWRITE`; `AbortMultipartUpload` and `DeleteObject` `OBJECT_DELETE`; `GetNamespace` none. The `inspect` verb on `objects` is exactly `OBJECT_INSPECT`; `target.bucket.name` (case-insensitive) and `target.object.name` are supported conditions. A host holding `OBJECT_CREATE` and `OBJECT_INSPECT` can upload in one part only, never overwrite, read or delete. An instance can be in up to five dynamic groups, and its rights are the union of every statement naming them, `any-user` or `request.principal`.
6. **OCI CLI 3.96.0**, PyPI's latest on 2026-11-06 (uploaded 2026-11-03, pins `oci==2.190.0`, its other dependencies ranges). `os object put`: above 128 MiB a file goes multipart unless `--no-multipart`, and stdin always does; without `--force` the CLI first calls `HeadObject`, then PUTs with `If-None-Match: *`; with `--no-overwrite` and an existing name it prints "The object already exists and was not overwritten" and exits 0; `--content-md5` (base64) is sent on single-part uploads and the service refuses a body with another MD5 (HTTP 400). `os object list`: `--prefix`, `--fields` (name, size, md5, timeCreated, …), output `{"data": [{"name", "size", "md5", "time-created", …}], "prefixes": []}`, and `{"prefixes": []}` with no `data` when nothing matches. `--auth instance_principal` signs with certificates the instance metadata service issues and rotates several times a day; "Any user who has access to the instance … automatically inherits the privileges granted to the instance", and every instance principal also holds `compartment_inspect`. `oci session authenticate` signs in through the browser for 5 to 60 minutes (default 60), keeps its token under `~/.oci/sessions`, writes its profile where `--config-location` says, and accepts `--region me-riyadh-1`; later commands take `--config-file`, `--profile` and `--auth security_token`.
7. **Bucket rules and monitoring.** Retention rules and versioning exclude each other; lifecycle "can't remove objects protected by active retention rules"; a time-bound rule blocks update, overwrite and delete of each object, metadata included, for its duration from its Last-Modified time; a lock waits 14 days, can be cancelled until then, and is irreversible after (Using retention rules). Lifecycle needs `Allow service objectstorage-<region> to manage object-family in compartment <c>`, which may be narrowed to `BUCKET_INSPECT`, `BUCKET_READ`, `OBJECT_INSPECT`, `OBJECT_UPDATE_TIER`, `OBJECT_DELETE` and `OBJECT_VERSION_DELETE`, in the root compartment; it runs once a day, and its rules are `{name, target (objects | multipart-uploads | previous-object-versions), action (DELETE | ABORT | ARCHIVE | INFREQUENT_ACCESS), time_amount, time_unit (DAYS | YEARS), is_enabled}` (Using lifecycle policies; SDK `ObjectLifecycleRule`). Quotas: family `object-storage`, quota `storage-bytes`, regional, plain bytes. Metrics: namespace `oci_objectstorage`, `StoredBytes` and `ObjectCount` hourly, dimensions `resourceID`, `resourceDisplayName` (the bucket's name) and `tier`; an empty bucket emits nothing; alarm intervals at least an hour. Dynamic groups: rules `instance.id = '…'` inside `Any {…}`; a change takes about an hour. Always Free, in the home region: 10 GB in each tier on a paid account, 20 GB across tiers on an Always Free-only one, and 50,000 requests a month.
8. **age.** Ubuntu's `universe` has `age` with ARM64 builds in 22.04 (1.0.0), 24.04 (1.1.1) and 26.04 (1.2.1). Upstream v1.3.2 (2026-08-29) lists, through GitHub's release API, SHA-256 `f48d8f8f…e8c7` for `age-v1.3.2-windows-amd64.zip` and `6b8dc433…37f4` for `age-v1.3.2-linux-arm64.tar.gz`, each with a `.proof`. The man page: `-R` reads one recipient per line, ignoring blank and `#` lines; `-i -` reads the identity from stdin and then needs the input as a file argument; exit 0 "if and only if" the whole input was processed; about 200 bytes per recipient plus 16 per 64 KiB; files from a stable version decrypt with every later one. An age file begins with the line `age-encryption.org/v1`. Post-quantum keys come only from `age-keygen -pq` (`age1pq1…`), which 1.1.1 cannot encrypt to.
9. **The drill's inputs.** `docker-compose.production.yml`:87 pins `mysql:8.4@sha256:24989179…0f70` (the `scripts` job keeps `ci.yml` and the dev compose on the same digest); `config/app.php`:70 is UTC and neither compose file sets a time zone, so `created_at` and mysqldump's `-- Dump completed on` line are UTC. Every table the drill counts has `created_at` (`timestamps()` in its create migration); organizations, conferences and submissions soft-delete, and rows are hard-deleted by an organization purge, `cass:demo-reset`, unassigning a reviewer, the email-log retention and a manual erasure. The legacy import keeps the legacy rows' `created_at` (per the review). Erasure is a manual, per-row procedure with no record outside the database (runbook "Erasing one person's data", :1431-1460).
10. **The privacy page** (`resources/views/public/privacy.blade.php`) renders `public.privacy.*` from `lang/en/public.php`:97-111: `updated` "Last updated 10 September 2026.", `retention_body` "Data is kept for the life of the conference record. …", `hosting_body` "The service is hosted in Saudi Arabia. …". `tests/Feature/Public/LandingPageTest.php`:15-21 checks only its heading; no test pins its wording.
11. **Not verified.** The host's Ubuntu release and time zone (the install records both; cron's 04:17 is local time); whether this tenancy's identity domain accepts `IdentityClient.create_dynamic_group` (the fallback is named); whether OCI checks IAM before the retention rule (it does not matter: the canary runs before the rule exists); the 50 GiB single-PUT limit; what the host's firewall allows to the metadata service.

### Task 1: The harness and its fakes (red until Task 2)
The scenarios come before the script. This task writes the whole harness and shows every scenario failing because the script does not exist; the commit is red on purpose. Nothing runs the harness until Task 3 adds it to CI, after Task 2 has turned it green, and the PHP gates stay green throughout.

**Files:** create `tools/backup/cass-backup-offsite.test.sh` (Write tool).
**Interfaces:** consumes the contract below. Produces `CASS_OFFSITE_SCRIPT` (the script under test, default `$HERE/cass-backup-offsite.sh`) for Task 2's mutants, and the titles S1 to S26 that Task 2's report cites.

**The contract under test** (Task 2 builds exactly this):
- `cass-backup-offsite [dir]`, `dir` defaulting to `/srv/backups/cass`. Overrides with their defaults: `CASS_OFFSITE_CONFIG` (`/root/.cass-offsite.conf`: lines `namespace=`, `bucket=`, `region=`, and `#` comments), `CASS_OFFSITE_RECIPIENTS` (`/root/.cass-age-recipient`), `CASS_OFFSITE_PING_FILE` (`/root/.cass-ping-offsite`), `CASS_OCI_BIN` (`/opt/oci-cli/bin/oci`), `CASS_AGE_BIN` (`age`), `CASS_OFFSITE_MAX_AGE_HOURS` (30), `CASS_OFFSITE_CLI_TIMEOUT` (1800 seconds per CLI call).
- It copies the regular files directly in `dir` named `cass-YYYY-MM-DD-HHMM.sql.gz` (kind `db`) or `cass-storage-YYYY-MM-DD.tar.gz` (kind `storage`), and nothing else. It skips a file whose destination, name, size and mtime already have a row in `dir/offsite-manifest.tsv`, and, with a line saying so, a file that vanished after the listing.
- Per file: `gzip -t dir/<file>` (a file still being written, or damaged, is not sent); `age --encrypt --recipients-file <recipients> --output dir/.offsite-work/<file>.age.partial dir/<file>`; the object `<kind>/<file>.<SHA-256 of the encrypted bytes>.age`; `oci os object put --auth instance_principal --region R --namespace N --bucket-name B --name <object> --file <encrypted> --content-md5 <base64 MD5> --no-multipart --no-overwrite`, then `oci os object list` with the same first four options and `--prefix <object> --fields name,size,md5`, each under `timeout` with stdin from `/dev/null`; on a match, a manifest row of eight tab-separated fields: UTC time, destination `R/N/B`, file, bytes, mtime, object, object bytes, MD5.
- The ping, `curl -fsS -m 10 --retry 3 -o /dev/null -K <ping file>`, only when nothing failed and the newest dump is under the limit. Exits 0 to 6 as in Task 2's table; the first failure's code wins; one file's failure does not stop the others.
- Output: a line per file sent, gone or failed, then exactly one last line: `[cass-backup-offsite] OK: <u> uploaded, <a> already off-host; newest dump <file>, <h> h old; pinged` or `[cass-backup-offsite] FAILED (exit <n>): <u> uploaded, <f> failed, <a> already off-host; <first failure>`.

**The fakes**, written into `$ROOT/bin` as `deploy.test.sh` writes its own (fact 2). Each appends a line per call to `$FAKE/calls` and reads its knobs, files, from `$FAKE`. They encode the host's OCI rights and the real CLI's answers (facts 5 and 6), so a script that asks for more fails here:
- `oci`: `os object put` and `os object list` only. Both require `--auth instance_principal` and the namespace, bucket and region in `$FAKE/expected-ns`, `-bucket` and `-region`, else exit 2 naming the mismatch. `put` requires `--no-multipart` (else exit 2, "multipart needs OBJECT_READ and OBJECT_OVERWRITE, which the host does not have") and `--no-overwrite` (else exit 2, "without --no-overwrite the CLI would ask before overwriting"), refuses `--force`, and compares `--content-md5` with the base64 MD5 of `--file` (else exit 1 with `{"code": "UnmatchedContentMD5", "status": 400}`). An existing name gets the real CLI's answer, "The object already exists and was not overwritten", exit 0, nothing stored; otherwise the file is copied to `$FAKE/bucket/<name>` and `oci put <name>` logged. Knobs: `put-fails` (exit 1, `{"code": "InternalServerError", "status": 500}` on stderr, nothing stored), `put-truncates` (stores the first half), `put-hangs` (sleeps 10 seconds first). `list` prints `{"data": [{"name": …, "size": …, "md5": …}], "prefixes": []}` for the stored objects under `--prefix`, built in bash from `find` and `stat`, or `{"prefixes": []}` when there are none, and logs `oci list <prefix>`; knobs `list-empty` (always `{"prefixes": []}`) and `list-md5-differs` (the true size, another MD5). Anything else logs `oci FORBIDDEN <subcommand>` and exits 1 with `{"code": "NotAuthorizedOrNotFound", "status": 404}`.
- `age`: accepts `--encrypt`/`-e`; requires `--recipients-file`/`-R` (a readable file), `--output`/`-o` and an input; `-r`, `-p`, `-a`, `-d` or `-i` exit 2 ("not how the host calls age"). Logs `age -R <file> -o <out> <in>` and writes `age-encryption.org/v1`, two fake stanza lines and the input through `tr 'A-Za-z' 'N-ZA-Mn-za-m'`, so its output is a function of its input. Knobs: `age-fails`, or `age-fails-on` holding one input's base name (writes `partial` to the output, exits 1); `age-plain` (copies the input to the output, exit 0: a broken age); `age-removes` holding a base name (its first call deletes that file from the input's folder: a rotation mid-run).
- `curl`: appends its arguments to `$FAKE/curl-argv` and logs `curl -K <file>`; knob `curl-fails` exits 22 with curl's 404 line.
- `flock`: logs; knob `lock-held` exits 1.
- `python3`: `deploy.test.sh`'s shim, written only where `python3` is not Python 3 (this PC's Store stub); a CI runner's own `python3` is used as it is. The fakes' MD5 and JSON helpers read stdin, never a path.

The harness refuses to start unless `age`, `curl` and `flock` resolve to `$ROOT/bin` first (the CLI is reached by path, through `CASS_OCI_BIN`), and wraps each run in `timeout 30` where `timeout` exists. Once, before the scenarios, it asserts that the script's first line is `#!/usr/bin/env bash` and that the file holds no CR, since cron runs the file itself.

**A scenario** (`scenario <title>`) gets a fresh `$S` with `dest/`, `home/`, `tmp/`, `cwd/` and `bucket/`; `$S/conf` (`namespace=testns`, `bucket=test-bucket`, `region=xx-test-1`, mirrored into the fake's `expected-*`); `$S/recipient` (a `#` line, then `age1` and 58 `q`); `$S/ping` (`url = "https://uptime.test/api/push/PUSHTOKEN7f3a?status=up&msg=OK&ping="`); its own `MARKER` (`CASS-PLAINTEXT-<scenario>-<pid>-<random>`); and fixtures written straight into `dest/` by `make_backup <name> <age>`: gzip of three SQL lines holding `$MARKER`, mtime set with `touch -d`, SHA-256 appended to `$S/fixture-sums`. The default set: `cass-2026-11-05-0317.sql.gz` (25 hours old), `cass-2026-11-06-0317.sql.gz` (1 hour) and `cass-storage-2026-11-01.tar.gz` (5 days), with decoys to leave alone, all back-dated a day: `cass-2026-11-06-0400.sql.partial`, `notes.txt`, a directory `cass-2026-11-03-0317.sql.gz/` and, where `ln -s` makes a real link (probed once), `cass-2026-11-04-0317.sql.gz -> /etc/hostname`. Last, the names directly in `/tmp` and `/var/tmp` go into `$S/tmp-before`, and `touch "$S/.start"`.

`run_offsite [VAR=VALUE…]` runs `bash "$SCRIPT" "$S/dest"` from `$S/cwd` with the fakes first on PATH, `HOME`, `TMPDIR` and `FAKE` inside `$S`, the overrides pointing at `$S` and `CASS_OCI_BIN=$ROOT/bin/oci`; output to `$S/out`, exit status in `RC`. Where chmod is honoured, `home/`, `tmp/` and `cwd/` are read-only (0555) for the run, so a write there fails even when it would have been deleted before the checks. Then `after_run` checks, every time:
1. exactly one line matches `^\[cass-backup-offsite\] (OK|FAILED)`, and it is the last;
2. no plain text outside `dest/`. The candidates are every file under `$S` but `$S/dest` whose status changed after `.start` (`find … -cnewer "$S/.start"`: a copy made with `cp -p`, `tar` or `rsync -t` keeps the backup's mtime but gets a new ctime), and every entry of `/tmp` and `/var/tmp` whose name is not in `$S/tmp-before` (a file, or the files under a new directory). `/tmp` is never walked beyond those new names, and unreadable entries are skipped (`2>/dev/null`, `|| true`). No candidate may have a SHA-256 in `$S/fixture-sums` or hold `$MARKER`, as it is or through `gzip -dc`;
3. no `oci FORBIDDEN` call;
4. at most one `curl` call, none unless `RC` is 0 or 6, and `PUSHTOKEN7f3a` in neither `curl-argv` nor `out`;
5. where `dest/.offsite-work` is a directory, it holds no file;
6. every object the manifest names (field 6) is in the bucket, starts with `age-encryption.org/v1` and has the SHA-256 its name carries; no object in the bucket is a fixture.

**Scenarios**, each also asserting its exit code and the start of its last line:
- S1 first run: the three backups go, the decoys stay. Exit 0; exactly the objects `db/cass-2026-11-05-0317.sql.gz.<64 hex>.age`, `db/cass-2026-11-06-0317.sql.gz.<64 hex>.age` and `storage/cass-storage-2026-11-01.tar.gz.<64 hex>.age`; three `age` calls, each with `-R $S/recipient`; three puts, three lists; three manifest rows of eight fields, destination `xx-test-1/testns/test-bucket`; one `curl -K $S/ping`; `OK: 3 uploaded, 0 already off-host; newest dump cass-2026-11-06-0317.sql.gz`. Where chmod is honoured (`docker/backup.test.sh`'s probe), the manifest is 0600 and `.offsite-work` 0700.
- S2 nothing new to upload: S1's run, `calls` emptied, a second run: no `age` or `oci` call, one ping, exit 0, `OK: 0 uploaded, 3 already off-host`.
- S3 an archive remade under its name: after a first run, new content and a new mtime for `cass-storage-2026-11-01.tar.gz`; the second run sends it as a second `storage/` object; exit 0, `OK: 1 uploaded, 2 already off-host`.
- S4 age fails: `age-fails`; exit 2; no put; no manifest row; no ping.
- S5 age writes plain text: `age-plain`; exit 2; the bucket stays empty; "not an age file"; no ping.
- S6 the upload fails: `put-fails`; exit 3; the fake's error is in the output; no manifest row; no ping.
- S7 the bucket holds another size: `put-truncates`; exit 4; no manifest row; no ping.
- S8 the object is not listed: `list-empty`; exit 4; no manifest row; no ping.
- S9 one file fails, the others go: `age-fails-on` holding `cass-2026-11-05-0317.sql.gz`; exit 2; two objects; `FAILED (exit 2): 2 uploaded, 1 failed, 0 already off-host`.
- S10 the newest dump is stale: all three backups 3 days old; exit 5; three objects; "h old (limit 30 h)"; no ping.
- S11 no dump: only the archive; exit 5; one object; "no dump"; no ping.
- S12 the ping fails: `curl-fails`; exit 6; three manifest rows; one `curl` call; "ping failed".
- S13 no ping file: exit 6; three manifest rows; no `curl` call; "no ping file".
- S14 no CLI: `CASS_OCI_BIN=$S/none/oci`; exit 1; "OCI CLI not found"; no `age` call.
- S15 no recipients file: exit 1; the output names `$S/recipient`; no `age` call.
- S16 a private key in the recipients file, built when the harness runs, `printf 'AGE-SECRET-KEY-1%s' "$(printf 'Q%.0s' $(seq 58))"`, never written into this repository, whose secret scanners would flag a literal; exit 1; "private key"; `AGE-SECRET-KEY-1QQQQ` not in the output; no `age` call.
- S17 no `bucket=` line: exit 1; the output names `bucket`; no `age` call.
- S18 another run holds the lock: `lock-held`; exit 1; no `age` call.
- S19 a file where `.offsite-work` belongs: exit 1; one `FAILED` line; no object; no ping.
- S20 a dump cut short (still being written, or damaged): `cass-2026-11-06-0317.sql.gz` truncated to half its bytes; exit 2; the other two sent; "not a complete gzip"; no ping.
- S21 the same size with another MD5: `list-md5-differs`; exit 4; no manifest row; no ping.
- S22 the CLI hangs: `put-hangs` with `CASS_OFFSITE_CLI_TIMEOUT=2`; exit 3; "timed out"; no ping.
- S23 a backup rotated away mid-run: `age-removes` holding `cass-storage-2026-11-01.tar.gz`; exit 0; two objects; "gone"; one ping.
- S24 the real `flock` holds the lock: only where a real `flock` exists (CI); with the fake moved aside and `flock "$S/dest/.offsite.lock" sleep 20 &` holding the lock a second before the run, exit 1 and "another run holds"; the holder killed and the fake put back afterwards. Elsewhere it prints `skip`.
- S25 a new destination sends everything again: after a first run, `bucket=test-bucket-2` in the configuration and the fake; the second run exits 0 with `OK: 3 uploaded, 0 already off-host`.
- S26 an object already under that name, with other bytes: the harness computes the name the fake age gives `cass-2026-11-05-0317.sql.gz` and seeds the bucket with other bytes under it; exit 4; that object unchanged; no ping.

- [ ] **Step 1: Write it.** bash, `set -eu`, `trap 'rm -rf "$ROOT"' EXIT INT TERM`, `ok`/`bad`/`skip` counters and `eq`/`has`/`lacks` helpers as in `deploy.test.sh`, a header comment saying what it proves and that it needs no network, docker or OCI account, and the usage line `bash tools/backup/cass-backup-offsite.test.sh`. It ends with `cass-backup-offsite.test.sh: all assertions passed` and exit 0, or the failure count and exit 1.
- [ ] **Step 2: Syntax, then red.** `cd $REPO && bash -n tools/backup/cass-backup-offsite.test.sh; echo "syntax rc=$?"` → 0. `bash tools/backup/cass-backup-offsite.test.sh > /tmp/t-task-1-red.log 2>&1; echo "rc=$?"` → `rc=1`: the static checks and every scenario fail on the missing script (bash's "No such file or directory" in each `out`), S24 prints `skip` on this PC, and the harness runs to its last line rather than aborting.
- [ ] **Step 3: The gates (N=1), commit.** `backup rc=0`, `test rc=0` at the baseline, `stan rc=0`, `pint rc=0`. Commit the harness with the subject `test(backup): harness for the off-host copy, red until the script exists` and a body naming the four fakes and the six checks every scenario gets.

### Task 2: The script, green, and the harness bites
**Files:** create `tools/backup/cass-backup-offsite.sh` (Write tool).
**Interfaces:** consumes Task 1's contract and harness. Produces the command the runbook installs as `/usr/local/bin/cass-backup-offsite`, its files in the backup directory (`.offsite-work/`, `offsite-manifest.tsv`, `.offsite.lock`) and these exit codes, which Task 4 documents:

| Exit | When |
|---|---|
| 0 | every finished backup is off-host and verified, the newest dump is under the limit, the ping went out |
| 1 | the directory, a tool, the configuration or the recipients file is missing or wrong, another run holds the lock, or something failed unexpectedly |
| 2 | a file is not a complete gzip, age failed on it, or what age wrote is not an age file; that file was not sent |
| 3 | an upload failed or timed out |
| 4 | an object is missing after its upload, or its size or MD5 differs |
| 5 | there is no dump, or the newest is older than `CASS_OFFSITE_MAX_AGE_HOURS` |
| 6 | everything was copied, but the ping failed or its file is missing |

- [ ] **Step 1: The header comment**, as `docker/backup.sh` and `tools/claude/deploy.sh` open: what it does; that it runs on the host as root from its own cron line, never in a container; why the host can add copies but never read or delete them (the bucket policy) and why uploads are single-part; that a file is sent only once `gzip -t` passes, and that no plain text is written outside the backup directory; that a changed destination sends everything again; the usage line, every override with its default, the exit table, and that `cass-backup-offsite.test.sh` beside it runs it against fakes.
- [ ] **Step 2: The body.** Preflight, in this order, each a `fail 1 "<message>"`: the directory exists (`no backup directory <dir>`); `[ -x "$OCI" ]` (`OCI CLI not found at <path> (runbook: Installing the off-host copy)`); `command -v` finds `$AGE`, `python3`, `gzip`, `sha256sum`, `curl`, `flock` and `timeout` (`<tool> not found`); `CASS_OFFSITE_MAX_AGE_HOURS` and `CASS_OFFSITE_CLI_TIMEOUT` match `^[1-9][0-9]*$`; then `exec 9>> "$DEST/.offsite.lock"` and `flock -n 9` (`another run holds <lock>`); the configuration, read line by line and never sourced: blank and `#` lines skipped, a trailing CR dropped, each other line `key=value` with the value matching `^[A-Za-z0-9._-]+$`, keys `namespace`, `bucket` and `region` only, all three required (`<config> has no bucket= line`); the recipients file: readable (`no age recipients file at <path>`), no `AGE-SECRET-KEY-` in any case (`<path> holds an age private key: delete the file now, make a new key pair on the PC and put only its age1 public key here`, never echoing the line), every other non-comment line, its trailing CR dropped, matching `^age1[0-9a-z]{58}$`, at least one. Then `mkdir -p "$WORK"`, `chmod 700`, `rm -f "$WORK"/*.age.partial`, and the manifest's `#` header line, tab-separated, when the file is new. The rest:
```bash
set -euo pipefail
shopt -s inherit_errexit
umask 0077
# … DEST and the overrides of the contract (CLI_TIMEOUT among them), DEST made absolute as docker/storage-backup.sh does …
WORK="$DEST/.offsite-work"
MANIFEST="$DEST/offsite-manifest.tsv"
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
on_exit() {
    local rc=$?
    if [ -n "$TMP" ]; then rm -f "$TMP"; fi
    if [ -z "$DONE" ]; then DONE=yes RC=1 FIRST="stopped by an unexpected exit $rc while $STEP"; summary; exit 1; fi
}
trap on_exit EXIT

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

# … preflight as above …
DESTINATION="$REGION/$NS/$BUCKET"
OCI_ARGS=(--auth instance_principal --region "$REGION" --namespace "$NS" --bucket-name "$BUCKET")
STEP='listing the backups'
LIST=$(find "$DEST" -maxdepth 1 -type f \( -name 'cass-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]-[0-9][0-9][0-9][0-9].sql.gz' \
    -o -name 'cass-storage-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].tar.gz' \) -printf '%f\n' | LC_ALL=C sort)
NEWEST=$(grep -E '^cass-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{4}\.sql\.gz$' <<< "$LIST" | tail -n 1 || true)
if [ -z "$NEWEST" ]; then problem 5 "no dump in $DEST"
else
    HOURS=$(( ($(date +%s) - $(stat -c %Y "$DEST/$NEWEST")) / 3600 ))
    if [ "$HOURS" -ge "$MAX_AGE_HOURS" ]; then problem 5 "the newest dump, $NEWEST, is $HOURS h old (limit $MAX_AGE_HOURS h)"; fi
fi

# An array, not a loop over stdin: a child reading stdin would swallow the rest of the list.
mapfile -t FILES <<< "$LIST"
for f in "${FILES[@]}"; do
    [ -n "$f" ] || continue
    STEP="copying $f"
    # cass-backup, which a release also runs before a migration, may rotate a file away at any hour.
    if [ ! -f "$DEST/$f" ]; then say "$f: gone before it was sent (rotated away?)"; continue; fi
    # One substitution per assignment: in "a=$(x) b=$(y)" a failing x is masked by y.
    size=$(stat -c %s "$DEST/$f")
    mtime=$(stat -c %Y "$DEST/$f")
    if awk -F '\t' -v d="$DESTINATION" -v f="$f" -v s="$size" -v m="$mtime" \
        '$2 == d && $3 == f && $4 == s && $5 == m { hit = 1 } END { exit !hit }' "$MANIFEST"; then
        ALREADY=$((ALREADY + 1)); continue
    fi
    # The dump's final name is written by a redirect, so a hand run beside cass-backup could
    # see it half written; gzip -t reads it through and writes nothing.
    if ! gzip -t "$DEST/$f"; then problem 2 "$f: not a complete gzip file (still being written, or damaged) - not sent" 1; continue; fi
    case "$f" in cass-storage-*) kind=storage ;; *) kind=db ;; esac
    TMP="$WORK/$f.age.partial"
    if ! "$AGE" --encrypt --recipients-file "$RECIPIENTS" --output "$TMP" "$DEST/$f" < /dev/null; then
        rm -f "$TMP"; problem 2 "$f: age failed" 1; continue
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
```
- [ ] **Step 3: Green.** The syntax gate, then `bash tools/backup/cass-backup-offsite.test.sh > /tmp/t-task-2-green.log 2>&1; echo "rc=$?"` → `rc=0` and `all assertions passed` (S24 `skip` on this PC). A red scenario is fixed in the script, never by loosening the harness; a harness defect found here is fixed and named in the report.
- [ ] **Step 4: The harness bites.** Copy the script seven times into `$SCRATCH` and make one change in each with the Edit tool. For each, `cd $REPO && CASS_OFFSITE_SCRIPT=$SCRATCH/mutant-N.sh bash tools/backup/cass-backup-offsite.test.sh > /tmp/t-task-2-mutant-N.log 2>&1; echo "rc=$?"` must print `rc=1` with the named scenarios among the failures. M1, the ping no longer waits for `RC` to be 0: S4, S6, S7. M2, the `age-encryption.org/v1` check removed: S5. M3, the size and MD5 comparison removed: S7, S21 (S8 still fails at "not in the bucket"). M4, `--no-multipart` removed: S1. M5, the backup copied with `cp -p` into `$TMPDIR` and that copy encrypted: S1, by check 2. M6, the push URL read from the ping file onto curl's command line instead of `-K`: S1, by check 4. M7, `--no-overwrite` removed: S1. A mutant that stays green is a hole in the harness: close it there, then run all seven again.
- [ ] **Step 5: The gates (N=2), commit.** All six gates exit 0 (`offsite rc=0` at 26 scenarios). Commit the script with the subject `feat(backup): cass-backup-offsite, an encrypted off-host copy of every dump and archive` and the body "Encrypts each new dump and weekly archive with age to the owner's public key, uploads it in one part under a name carrying the SHA-256 of the encrypted bytes, checks its size and MD5 in a listing, records it with its destination in offsite-manifest.tsv, and pings its heartbeat only when everything is off-host and the newest dump is under 30 hours old. Nothing in plain text is written outside the backup directory."

### Task 3: CI runs the harness
**Files:** modify `.github/workflows/ci.yml` (Edit tool). A sensitive file: Task 7's pull request lists it.
**Interfaces:** consumes Tasks 1 and 2. Produces the step `Off-host backup script`, which Task 7's PR body and decision 0016 name.

- [ ] **Step 1: The step.** In the `scripts` job, after the two lines of `- name: Deploy script` (fact 2), insert, indented like its neighbours:
```yaml
      # The off-host copy the production host runs as root every night, against fakes for
      # oci, age, curl and flock: what it sends and under which names, that nothing in plain
      # text is written outside the backup directory, and that its heartbeat fires only on
      # success. No network, no OCI account, and the runner's own python3.
      - name: Off-host backup script
        run: bash -n tools/backup/cass-backup-offsite.sh && bash tools/backup/cass-backup-offsite.test.sh
```
- [ ] **Step 2: Check the diff.** `git diff --stat` names `.github/workflows/ci.yml` alone, 6 insertions, 0 deletions; `git diff` shows them between the `Deploy script` and `Guardrail hooks` steps. CI first runs it on Task 7's push, where S24 runs with the runner's real `flock`.
- [ ] **Step 3: The gates (N=3), commit** with the subject `ci: run the off-host backup harness in the scripts job` and the body "Runs tools/backup/cass-backup-offsite.test.sh after the deploy script's harness: no network, no OCI account, a few seconds."

### Task 4: The runbook, part 1: the off-host copy and the key
Docs only, with the Edit tool, in `docs/runbooks/deploy-production.md`. Placeholders in angle brackets stay placeholders, because the namespace, the OCIDs and the host's address never go into this public repository (decision 0011).

**Files:** modify `docs/runbooks/deploy-production.md`.
**Interfaces:** consumes the names fixed in Tasks 1 and 2 and the resources of "After merge". Produces "Off-host copies" and its part "The key", which Task 5, Task 7 and the owner steps cite.

- [ ] **Step 1: The stale lines.** Under `## Backups`, "Spec section 8: nightly `mysqldump`, 14-day rotation, then the existing off-host sync to the NAS." becomes "Spec section 8: nightly `mysqldump` and 14-day rotation. The spec's NAS sync was never connected; an encrypted copy goes to OCI Object Storage instead (**Off-host copies**, decision 0016)." The heading `### Verifying one — monthly, and not optional` becomes `### Verifying one on the host`, and its first paragraph, which ends "which is the point of putting them there.", gains: " The monthly drill restores from the off-host copy instead (**Restore drill from the off-host copy**); this check of the host's own copy is the quick one, after a migration release for instance." In "What is NOT backed up by this", "The NAS sync must include it, or restore is half a restore." becomes "The off-host copy includes it (**Off-host copies**); without it a restore is half a restore."
- [ ] **Step 2: Monitoring.** In its first paragraph, "three dead-man heartbeats" becomes "four dead-man heartbeats"; under "Heartbeats", "Create three, type Push:" becomes "Create four, type Push:". The heartbeat table gains the first row below after `cass weekly archive`, and "When an alert fires" the second after its "nightly dump or weekly archive late" row:
```markdown
| cass offsite copy | 24 hours | 1, 60 minutes | the 04:17 `cass-backup-offsite` run: every finished backup off-host and verified, the newest dump under 30 hours (one missed dump leaves it quiet, since `cass nightly dump` reports that; two in a row take it down) | `/root/.cass-ping-offsite` (**Off-host copies**) |
| offsite copy late, or `cass-offsite-filling` | the last `[cass-backup-offsite]` line of `/var/log/cass-backup.log`; the bucket's size in the OCI Console | its exit code in **Off-host copies** |
```
- [ ] **Step 3: "Off-host copies"**, inserted after the paragraph that ends "`ls -l /srv/backups/cass | head`." and before `## Secret rotation`:
````markdown
### Off-host copies

Every night at 04:17 `cass-backup-offsite` sends each finished dump and storage archive in `/srv/backups/cass` that is not off the host yet to the private OCI Object Storage bucket `cass-offsite`, compartment `cass-backups`, in the host's region (decision 0016). It checks that each file is a whole gzip (`gzip -t`), then encrypts it with `age` to the owner's public key; the private key is never on this host. Nothing in plain text is written outside `/srv/backups/cass`: age reads the backup and writes the encrypted copy to `.offsite-work/` there, removed once sent. An object is named after the file and the SHA-256 of its encrypted bytes, `db/cass-2026-11-06-0317.sql.gz.<64 hex>.age` or `storage/cass-storage-2026-11-01.tar.gz.<64 hex>.age`. After each upload the script lists the object, compares size and MD5, and appends a row to `offsite-manifest.tsv` (time, destination, file, bytes, mtime, object, object bytes, MD5); a file with a row for this destination, size and mtime is not sent again, so a failed night is caught up the next, and a new bucket or region gets everything again. It pings `cass offsite copy` (**Monitoring**) only when every finished backup is off-host and verified and the newest dump is under 30 hours old. Room in `/srv/backups/cass`: one encrypted copy of the largest backup at a time.

The host signs in as itself, an instance principal, with no key on the host, and may only create objects in that bucket and list them. That bounds the damage a compromise does; it does not prevent it. Every process on the host, containers included, reaches the metadata service that hands out the credential, so any of them can read the objects' names and sizes and upload junk until the 10 GB quota is full; nobody can delete that junk for 30 days, and real copies fail with `StorageQuotaExceeded` until the quota is raised. Root on the host can also upload forged copies that decrypt cleanly, because it holds the public key and age proves secrecy, not origin, and it can keep pinging `cass offsite copy` while sending nothing, because it holds the push URL. So after a suspected compromise of the host, trust only copies made before it: each drill recorded in `docs/HANDOVER.md` names an object and its SHA-256, checked against the host's own file at the time. Uploads are single-part (`--no-multipart`, 50 GiB at most), because a multipart upload needs rights the host does not have.

| In OCI | Name | What it is |
|---|---|---|
| Compartment | `cass-backups` | child of the tenancy root; holds the bucket only |
| Bucket | `cass-offsite` | private, Standard tier, versioning off, no object events, Oracle-managed encryption |
| Retention rule | `keep-30-days` | while it stands, nobody, the owner included, can change or delete an object for 30 days after its upload; locked after the first good drill (decision 0016), nobody can remove it either |
| Lifecycle | `delete-after-90-days`, `abort-uploads-after-7-days` | objects deleted 90 days after upload; unfinished multipart uploads aborted after 7 |
| Quota | `cass-backups-cap` | `Set object-storage quota storage-bytes to 10000000000 in compartment cass-backups` |
| Alarm | `cass-offsite-filling` | `StoredBytes[1h]{resourceDisplayName = "cass-offsite"}.max() > 8000000000` (namespace `oci_objectstorage`), WARNING, to `hosting-alerts` |
| Dynamic group | `cass-backup-host` | `Any {instance.id = '<OCID of hosting-1>'}`, and no other group may match the instance |
| Policy in `cass-backups` | `cass-backup-host-writes` | `Allow dynamic-group cass-backup-host to inspect objects in compartment cass-backups where target.bucket.name = 'cass-offsite'` and `Allow dynamic-group cass-backup-host to manage objects in compartment cass-backups where all {target.bucket.name = 'cass-offsite', request.permission = 'OBJECT_CREATE'}` |
| Policy in the root | `cass-backups-lifecycle` | `Allow service objectstorage-<region> to manage object-family in compartment cass-backups where any {request.permission='BUCKET_INSPECT', request.permission='BUCKET_READ', request.permission='OBJECT_INSPECT', request.permission='OBJECT_UPDATE_TIER', request.permission='OBJECT_DELETE', request.permission='OBJECT_VERSION_DELETE'}` |

| Exit | Meaning | Then |
|---|---|---|
| 0 | every finished backup off-host and verified, newest dump under 30 hours, pinged | nothing |
| 1 | a file, tool or setting missing (the line names it), another run holding the lock, or an unexpected failure | fix what the line names; run it by hand |
| 2 | a backup not a complete gzip, age failed, or its output not an age file; that file not sent | beside a hand-run `cass-backup`, run it again. A damaged file fails every night: move it into `/srv/backups/cass/damaged/` (the script looks at no subfolder) and tell the engineer. Otherwise `age --version` and `/root/.cass-age-recipient` |
| 3 | an upload failed or timed out; the CLI's error is above the line | `StorageQuotaExceeded`: raise `cass-backups-cap`, then find out what wrote the junk, since it cannot be deleted for 30 days. Otherwise the policy (**Installing the off-host copy**) and the network |
| 4 | an object missing after its upload, or its size or MD5 differs | run it again; twice in a row, tell the engineer |
| 5 | no dump, or the newest older than 30 hours | the nightly dump (**Backups**) |
| 6 | everything copied, but the ping failed or `/root/.cass-ping-offsite` is missing | that file, then Kuma |

One file's failure does not stop the others; the exit is the first failure's. Every run ends with one line, `[cass-backup-offsite] OK: …` or `[cass-backup-offsite] FAILED (exit N): …`, in `/var/log/cass-backup.log`. After a hand-run `cass-backup` (a migration release), `sudo /usr/local/bin/cass-backup-offsite` sends that dump at once. Overrides, for tests: `CASS_OFFSITE_CONFIG`, `CASS_OFFSITE_RECIPIENTS`, `CASS_OFFSITE_PING_FILE`, `CASS_OCI_BIN`, `CASS_AGE_BIN`, `CASS_OFFSITE_MAX_AGE_HOURS` (30), `CASS_OFFSITE_CLI_TIMEOUT` (1800). The weekly archives are full copies. The first run sends every archive the host keeps (8 or 9), so for the first 90 days the bucket holds about 21 archives, then 13; `cass-offsite-filling` warns at 8 GB, from outside the host.

#### The key

Made, stored and checked on the PC, in Git Bash, in the tools folder; never on the host:

```bash
curl -fsSLO https://github.com/FiloSottile/age/releases/download/v1.3.2/age-v1.3.2-windows-amd64.zip
echo 'f48d8f8f9ebe903ab5027ed067652f2cc1db94bc206976430133b905dcd8e8c7  age-v1.3.2-windows-amd64.zip' | sha256sum -c -
powershell -NoProfile -Command "Expand-Archive age-v1.3.2-windows-amd64.zip -DestinationPath ."
./age/age-keygen.exe                                   # three lines on the screen; nothing on disk
R=age1…; echo cass-key-check | ./age/age.exe -r "$R" -o check.age
read -rs -p 'AGE-SECRET-KEY line: ' K; echo; printf '%s\n' "$K" | ./age/age.exe -d -i - check.age; unset K; rm -f check.age
```

- **Make it** with the first four lines: age v1.3.2 for Windows, its zip checked by `sha256sum -c` against the digest GitHub lists (it must print `OK`), then `age-keygen.exe` with no options. Never `-pq`: the host's age 1.1.1 cannot encrypt to an `age1pq1…` key. Clipboard history off (Settings, System, Clipboard, and its cloud sync) while the key is on the screen, cleared afterwards. The `age1…` public key goes to `/root/.cass-age-recipient`; the private key goes nowhere but the two places below.
- **Store it twice:** one password-manager entry, "CASS backup key (age), <date>", and paper written by hand or printed on a printer attached by cable, kept away from the PC. Never on the host, in a chat, in this repository or in a cloud drive.
- **Check the stored copy** with the last two lines, pasting the `AGE-SECRET-KEY-1…` line from the password manager, not from the screen: they print `cass-key-check`. Then clear the clipboard history and close the terminal, whose scrollback held the key. At every third drill, type the paper copy instead of pasting: the key carries a checksum, so a clean decrypt proves the paper.
- **Rotate** at once on a suspected leak, otherwise when the owner decides: make a new pair, store and check it as above, and replace the line in `/root/.cass-age-recipient`. Keep the old private key, both copies, until the last copy encrypted to it is gone (90 days in the bucket, 90 on the external drive), then destroy both. Each password-manager entry notes the dates its key was in use.
- **One copy lost:** make a new copy from the other at once, then check it.
- **Both copies lost:** every off-host copy is unreadable. Make a new pair at once and replace the recipient line; until new copies build up, the host's own 15 dumps and 8 or 9 archives are the only backups. Tell the engineer; `docs/HANDOVER.md` records the date.
- **Suspected leak:** rotate now. Copies made under the old key stay readable to anyone who also gets read access to the bucket or the external drive, so check who holds the owner's OCI login.
````
- [ ] **Step 4: The gates (N=4), commit.** `git status --short` lists the runbook only; all six gates exit 0 (no code changed). Commit with the subject `docs: runbook off-host copies and the backup key`.

### Task 5: The runbook, part 2: the install and the restore drill
Docs only, with the Edit tool, after Task 4, in `docs/runbooks/deploy-production.md`; the same rule for placeholders.

**Files:** modify `docs/runbooks/deploy-production.md`.
**Interfaces:** consumes "Off-host copies" and "The key". Produces "Installing the off-host copy" and "Restore drill from the off-host copy", which Task 7 and "After merge" cite.

- [ ] **Step 1: Two subsections**, inserted after Task 4's "The key" and before `## Secret rotation`:
````markdown
### Installing the off-host copy

Once, with the owner. Before it, in OCI: the audit has found no dynamic group but `cass-backup-host` matching the instance and no other statement reaching it (statements naming `dynamic-group`, `any-user` or `request.principal`, in the tenancy and every compartment; the session runs it through the OCI API, and again at every third drill); every resource in the table above exists except the retention rule; the dynamic group is an hour old. A rebuilt or replaced instance has a new OCID: change the group's rule, wait an hour, run the canary again. `age` comes from Ubuntu's archive and follows its security updates (the format is frozen); on an Ubuntu older than 22.04, which has no package, use the release `age-v1.3.2-linux-arm64.tar.gz`, checked with `sha256sum -c` against `6b8dc4333c53a5a57c9e5834e3a48f92605d7154014cd07269ff3327db5d37f4`, in `/usr/local/bin`, with `CASS_AGE_BIN=/usr/local/bin/age` on the cron line. The CLI goes into its own virtual environment from wheels only (no source build; if pip refuses a package for want of one, stop), and its resolved versions are kept beside it.

```bash
sudo apt-get -s install age python3-venv | grep '^Inst'      # only these and their new dependencies, or stop
sudo NEEDRESTART_MODE=l apt-get install -y age python3-venv && age --version
sudo python3 -m venv /opt/oci-cli && sudo /opt/oci-cli/bin/pip install --no-cache-dir --only-binary :all: 'oci-cli==3.96.0'
sudo /opt/oci-cli/bin/pip freeze | sudo tee /opt/oci-cli/requirements.lock && sudo chmod -R go-rwx /opt/oci-cli
sudo /opt/oci-cli/bin/oci --version                                                   # 3.96.0
for f in .cass-offsite.conf .cass-age-recipient .cass-ping-offsite; do sudo install -m 600 /dev/null /root/$f; done
```

Then the three files: `/root/.cass-offsite.conf` gets `namespace=<namespace>`, `bucket=cass-offsite` and `region=<region>`; `/root/.cass-age-recipient` the owner's public key, one line starting `age1`; `/root/.cass-ping-offsite` one line, `url = "<the push URL of cass offsite copy>"`, which the owner writes with `sudoedit` in their own terminal. Then the canary, while the bucket has no retention rule yet, so a refusal can only be IAM's: create and list work, and the read, the delete, the overwrite and a bucket listing must each print `refused by IAM`. Any other answer: stop.

```bash
O='sudo /opt/oci-cli/bin/oci --auth instance_principal'; A='-ns <namespace> -bn cass-offsite'; K=canary/$(date +%FT%H%M%S).txt
N='"code": "NotAuthorizedOrNotFound"'; printf 'canary\n' | sudo tee /root/cass-canary.txt > /dev/null
$O os object put $A --name "$K" --file /root/cass-canary.txt --no-multipart --no-overwrite        # an etag
$O os object list $A --prefix canary/ --fields name,size                                          # lists it
$O os object get $A --name "$K" --file /dev/null 2>&1 | grep -qF "$N" && echo 'refused by IAM'
$O os object delete $A --name "$K" --force 2>&1 | grep -qF "$N" && echo 'refused by IAM'
$O os object put $A --name "$K" --file /root/cass-canary.txt --no-multipart --force 2>&1 | grep -qF "$N" && echo 'refused by IAM'
$O os bucket list --compartment-id <cass-backups OCID> 2>&1 | grep -qF "$N" && echo 'refused by IAM'
sudo rm /root/cass-canary.txt
```

Now the retention rule (`keep-30-days`, 30 days, not locked). Then the script, from a commit of `main` whose push run is green, both ends' SHA-256 compared; the commit and the digest go into the project memory:

```bash
git fetch origin && C=$(git rev-parse origin/main) && gh run list --workflow ci.yml --branch main --commit "$C" --json conclusion --jq '.[0].conclusion'   # success
git show "$C:tools/backup/cass-backup-offsite.sh" > /tmp/cass-backup-offsite && sha256sum /tmp/cass-backup-offsite
ssh -i ~/.ssh/<key> <user>@<host> 'sudo tee /usr/local/bin/cass-backup-offsite > /dev/null && sudo chmod 755 /usr/local/bin/cass-backup-offsite && sha256sum /usr/local/bin/cass-backup-offsite' < /tmp/cass-backup-offsite
```

On the host, run `sudo /usr/local/bin/cass-backup-offsite` once and read every line (the first run sends every dump and archive the host holds), then add the cron line beside the other two (`sudo crontab -e`). Cron's PATH, `/usr/bin:/bin`, holds `age`, `curl`, `flock`, `gzip` and `python3`; the CLI is called by its full path.

```cron
# CASS off-host copy, nightly at 04:17: an hour after the 03:17 dump, half an hour after Sunday's 03:47 archive.
17 4 * * * /usr/local/bin/cass-backup-offsite >> /var/log/cass-backup.log 2>&1
```

Prove the alert as for the other monitors, without the URL on a command line: `for i in 1 2; do sudo sh -c 'sed s/status=up/status=down/ /root/.cass-ping-offsite | curl -fsS -m 10 -o /dev/null -K -'; done` takes the monitor down and Kuma emails; the next run by hand brings it back. To update the CLI, rebuild the environment with the new version; to update the script, the three lines from the PC with the new green commit.

### Restore drill from the off-host copy — monthly, and not optional

The owner, on the PC in Git Bash with Docker Desktop running, and on the host in their own terminal; never in a Claude session, and in a folder outside every repository. It restores the copy the host's manifest names, not whichever object is newest: anything uploaded after it, junk or forgery, is never picked. It proves the copy is the host's file byte for byte, then that it restores. The decrypted data exists only in pipes and in a container held in memory; the private key is pasted at a prompt. Once per PC, the CLI: `python -m venv <tools>/oci-cli && <tools>/oci-cli/Scripts/pip install --only-binary :all: 'oci-cli==3.96.0'`, `<tools>` being `/c/Users/khawl/Desktop/Ahmed/claude/tools`. Once, too: check that Windows device encryption is on (Settings, Privacy and security, Device encryption).

First on the host, with `cass_container` pasted: the last `db/` and `storage/` rows of the manifest, the two files' digests, the dump's completion time and the counts at a cut-off five minutes before it.

```bash
sudo awk -F '\t' '$6 ~ /^db\// { d = $0 } $6 ~ /^storage\// { s = $0 } END { print d; print s }' /srv/backups/cass/offsite-manifest.tsv
F='<file of the db row>'; G='<file of the storage row>'; sudo sha256sum "/srv/backups/cass/$F" "/srv/backups/cass/$G"
sudo sh -c "gzip -dc /srv/backups/cass/$F | tail -n 1"                       # -- Dump completed on <date> <time>
CUT=$(date -u -d '<date> <time> 5 minutes ago' '+%F %T'); C=$(cass_container mysql)
for T in organizations users conferences submissions submission_files reviews review_assignments email_logs; do
  sudo docker exec "$C" sh -c "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot -N -e \
    'SELECT \"$T\", (SELECT COUNT(*) FROM cass.$T WHERE created_at < \"$CUT\"), (SELECT COUNT(*) FROM cass.$T);'"
done
sudo grep -F "$G (" /var/log/cass-backup.log | tail -n 1                     # that archive's entry count
```

Then on the PC, the two objects of those rows, by name, signed in for at most an hour:

```bash
set -o pipefail; T=/c/Users/khawl/Desktop/Ahmed/claude/tools; OCI=$T/oci-cli/Scripts/oci.exe; AGE=$T/age/age.exe
"$OCI" session authenticate --region <region> --profile-name cass-drill --config-location ~/.oci/cass-drill.config
X=(--config-file ~/.oci/cass-drill.config --profile cass-drill --auth security_token)
mkdir -p ~/cass-drill && cd ~/cass-drill && NS=$("$OCI" os ns get "${X[@]}" --query data --raw-output)
DB='<object of the db row>'; ST='<object of the storage row>'
"$OCI" os object get "${X[@]}" -ns "$NS" -bn cass-offsite --name "$DB" --file db.age
"$OCI" os object get "${X[@]}" -ns "$NS" -bn cass-offsite --name "$ST" --file st.age
for p in "db.age $DB" "st.age $ST"; do set -- $p
  [ "$(sha256sum < "$1" | cut -d' ' -f1)" = "$(sed -E 's/.*\.([0-9a-f]{64})\.age$/\1/' <<< "$2")" ] && echo "$1: name hash OK"
  echo "$1: $(wc -c < "$1") bytes, MD5 $(openssl dgst -md5 -binary "$1" | base64)"; done   # as in the manifest rows
IMG=$(grep -oE 'mysql:8\.4@sha256:[0-9a-f]{64}' /c/Users/khawl/Desktop/Ahmed/claude/projects/CASS/docker-compose.production.yml)
docker rm -f -v cass-drill > /dev/null 2>&1
docker run -d --name cass-drill --network none --tmpfs /var/lib/mysql:rw,size=1g -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=cass_restore "$IMG"
for i in $(seq 60); do docker exec cass-drill mysql -h127.0.0.1 -uroot -e 'SELECT 1' > /dev/null 2>&1 && break; sleep 3; done
read -rs -p 'AGE-SECRET-KEY line: ' KEY; echo
for f in db.age st.age; do printf '%s\n' "$KEY" | "$AGE" -d -i - "$f" | sha256sum; done      # the host's two digests
printf '%s\n' "$KEY" | "$AGE" -d -i - db.age | gzip -dc | docker exec -i cass-drill mysql -uroot cass_restore; echo "load rc=$?"
for t in organizations users conferences submissions submission_files reviews review_assignments email_logs; do
  echo "$t $(docker exec cass-drill mysql -uroot -N -e "SELECT COUNT(*) FROM cass_restore.$t")"; done
printf '%s\n' "$KEY" | "$AGE" -d -i - st.age | tar tzf - | wc -l                           # the host log's entry count
unset KEY; docker rm -f -v cass-drill; "$OCI" session terminate --config-file ~/.oci/cass-drill.config --profile cass-drill
D=/<drive>/cass-offline; mkdir -p "$D/$(date +%F)" && mv db.age st.age "$D/$(date +%F)/" && find "$D" -mindepth 1 -maxdepth 1 -type d -mtime +90 -exec rm -r {} +
```

Tick each box; one left empty is a failed drill.
- [ ] The two objects are the manifest's last `db/` and `storage/` rows, and the `db/` row is at most a day old.
- [ ] Each download printed `name hash OK`, and its bytes and MD5 equal its manifest row's.
- [ ] Each decrypted SHA-256 equals the host's `sha256sum` of that file: the off-host copy is the host's copy, byte for byte.
- [ ] `load rc=0`, with no MySQL error.
- [ ] Every table: restored ≥ the host's first number, the rows that existed five minutes before the dump finished (the cut-off assumes the dump took less than five minutes). Above it only for a reason you can name: rows deleted since the dump (an organization purged, `cass:demo-reset`, a reviewer unassigned, email logs past retention, an erasure). If a legacy import ran after the dump, its rows keep their old `created_at` and this comparison is void: drill again on a dump taken after the import.
- [ ] The archive lists as many entries as the host's log line for it.
- [ ] The container is gone, the session terminated, `KEY` unset, the terminal closed, the clipboard history cleared, and the two files are on the external drive.

A failed box: do not wait a month; see **Off-host copies** and tell the engineer. The session writes the date, both objects with their SHA-256, the result and the next date into `docs/HANDOVER.md`'s Backups row. What a drill cannot tell: a compromised host can fill the quota with junk that nobody can delete for 30 days, upload forged copies that decrypt cleanly, and ping Kuma while sending nothing (**Off-host copies**). Picking by the manifest keeps junk out of a restore, but root could have forged both a copy and its manifest row; the byte-for-byte match with the host's file, the counts and the drills already recorded are what speak for a copy, and after a suspected compromise only a copy made before it is restored. A restore of production must also re-apply every erasure made since the copy's date (**Erasing one person's data**).
````
- [ ] **Step 2: The erasure record.** At the end of "Erasing one person's data", after the paragraph on `email_logs`, add: "Note each erasure, its date and the address, in the owner's own records outside the platform. A restore from a backup older than the erasure brings the rows back, and they must be erased again; backups keep them at most 90 days (privacy page)."
- [ ] **Step 3: The gates (N=5), commit.** `git status --short` lists the runbook only; all six gates exit 0. Commit with the subject `docs: runbook install of the off-host copy and the monthly restore drill`.

### Task 6: The privacy page says how long backups keep deleted data
Default 6, owner to confirm the wording before merge. The page reaches the site with the owner's next deploy; nothing in the install waits for it, since the host has kept unmentioned copies for 15 and 56 days all along.

**Files:** create `tests/Feature/Public/PrivacyPageTest.php` (Write tool); modify `lang/en/public.php` (Edit tool).
**Interfaces:** consumes fact 10. Produces the two sentences decision 0016 and Task 5's erasure note cite.

- [ ] **Step 1: The failing test.** New file, in the form of `tests/Feature/Public/LandingPageTest.php`: `<?php`, a blank line, `declare(strict_types=1);`, a blank line, `use function Pest\Laravel\get;`, then:
```php
it('says how long backups keep deleted data, and where they are kept', function () {
    get('/privacy')->assertOk()
        ->assertSeeText('Deleted data remains in our backups until they expire, at most 90 days later')
        ->assertSeeText('The service and its backups are kept in Saudi Arabia.');
});
```
- [ ] **Step 2: Red.** `cd $REPO && php artisan test --compact --filter=PrivacyPageTest > /tmp/t-task-6-red.log 2>&1; echo "rc=$?"` → `rc=1`, 1 failed, on the first sentence ("Failed asserting that … contains").
- [ ] **Step 3: The strings.** In `lang/en/public.php`, under `'privacy'`: `updated` becomes `'Last updated <the day this task runs, written as "7 November 2026">.'`; `retention_body` becomes `'Data is kept for the life of the conference record. Organizers may delete a conference, and authors may ask the organizer or us to delete a submission. Deleted data remains in our backups until they expire, at most 90 days later; copies kept away from our server are encrypted. Write to :email.'`; `hosting_body` begins `'The service and its backups are kept in Saudi Arabia.'` instead of `'The service is hosted in Saudi Arabia.'`, the rest unchanged. `terms.updated` stays as it is.
- [ ] **Step 4: Green, the gates (N=6), commit.** The filter to `/tmp/t-task-6-green.log`: `rc=0`, 1 passed. All six gates exit 0, `test rc=0` at baseline + 1. Commit the two files with the subject `feat(public): the privacy page says backups keep deleted data at most 90 days`.

### Task 7: The record, the final gates and the pull request
**Files:** create `docs/decisions/0016-offsite-backups.md` (Write tool); modify `CLAUDE.md`, `docs/HANDOVER.md`, `docs/superpowers/plans/backlog.md`, `docs/launch-checklist.md` (Edit tool).
**Interfaces:** consumes every name above and the number `gh` prints. Produces the owner's next steps in `docs/HANDOVER.md`.

- [ ] **Step 1: The decision**, in the format of `docs/decisions/README.md`:
```markdown
# Off-host backups: age-encrypted copies in a create-only OCI bucket, restored monthly

- **Context.** Every dump and storage archive lived only on the host's disk, `/srv/backups/cass`, so losing or imaging the host lost or exposed them all (`docs/reviews/2026-10-16-expert-brief.md`, B8 and question 4). The spec's NAS sync was never connected.
- **Decision.** The owner's, on 2026-11-06: a private OCI Object Storage bucket in the owner's own tenancy; the host signs in as an instance principal through the OCI CLI, installed for root, with no API key on the host; every file is encrypted with `age` to the owner's public key before it leaves, and the private key stays off the host, in a password manager and on paper; a monthly restore drill from that copy on the owner's PC. With Plan 10's summary the owner accepted: `cass-backup-offsite` on its own cron line at 04:17, after both backups, rather than at the end of `cass-backup`, which the release script also runs before a migration, so an OCI failure neither stops a release nor blurs the nightly-dump heartbeat, and each run catches up on what is not yet copied; the script in `tools/backup/`, installed from a green commit of `main` with both ends' SHA-256 compared, so it needs no deploy; a policy letting the host create objects and list them in that one bucket, checked against every other grant before install, hence single-part uploads (a multipart upload needs `OBJECT_READ` and `OBJECT_OVERWRITE`) and names carrying the SHA-256 of the encrypted bytes, so nothing is ever overwritten; a 30-day retention rule instead of versioning (OCI allows one or the other, and the host can neither overwrite nor delete anyway), locked after the first good drill, a lifecycle deleting objects 90 days after upload, a 10 GB quota and an alarm at 8 GB; the bucket in the host's region, Riyadh; `age` from Ubuntu's archive on the host and v1.3.2, checked against GitHub's digest, on the PC; the OCI CLI pinned to 3.96.0, from wheels only, in a root-only virtual environment; the heartbeat pinged from inside the script, so CI tests it, only when every finished backup is off-host and verified and the newest dump is under 30 hours old; the drill restoring the copy the host's manifest names, checked byte for byte against the host's file, signed in through a browser session rather than a stored key, its two encrypted files then kept 90 days on an external drive; the privacy page saying that backups keep deleted data at most 90 days.
- **Consequence.** The policy bounds what a compromise can do; it does not stop it. Any process on the host, containers included, reaches the metadata service and so the instance principal: it can read the objects' names and sizes and fill the quota with junk that nobody can delete for 30 days, after which real copies fail until the quota is raised. Root on the host can also upload forged copies that decrypt cleanly (it holds the public key; age proves secrecy, not origin), edit the manifest to name them, and keep pinging Kuma while sending nothing (it holds the push URL); after a suspected compromise only copies made before it count, and the drills recorded in `docs/HANDOVER.md` name them. The copies share the host's tenancy and region; the external drive is the copy that survives losing the tenancy. Losing both copies of the private key makes every off-host copy unreadable; each drill proves one copy. An erased person's rows survive in backups up to 90 days, and a restore brings them back unless the erasure is re-applied. One recipient only: a second key pair would survive losing the first, at the price of a second secret.
- **To reverse.** Remove the 04:17 cron line, `/usr/local/bin/cass-backup-offsite`, `/opt/oci-cli` and the three root-only files from the host; delete the policies `cass-backup-host-writes` and `cass-backups-lifecycle`, the dynamic group `cass-backup-host`, the alarm `cass-offsite-filling` and the quota `cass-backups-cap`; let the lifecycle empty the bucket, then delete it and the compartment (a locked rule waits for its objects to age out); delete `tools/backup/`, the `Off-host backup script` step in `.github/workflows/ci.yml`, `tools/backup/` in `CLAUDE.md`'s sensitive paths, and the privacy sentence with `tests/Feature/Public/PrivacyPageTest.php`.
```
- [ ] **Step 2: `CLAUDE.md`** (default 5, owner to confirm): in "Security-sensitive changes", the list ending "`.github/`, `.claude/`." ends "`.github/`, `.claude/`, `tools/backup/`."
- [ ] **Step 3: Backlog and checklist.** In `backlog.md` ("Launch"), the entry on the nightly dump's location ends "The off-host copy (Plan 10, `docs/decisions/0016-offsite-backups.md`) covers that host path, the dumps and the weekly `cass-storage` tarball alike." instead of its sentence about the NAS sync. Insert the first block below before `## Open questions for the owner (raised by Plans 7 and 8)`; that heading becomes `## Open questions for the owner (raised by Plans 7, 8 and 10)`, and the second block goes at the end of its list. In `launch-checklist.md` section 2, the backup item's "first so a truncated `mysqldump` cannot hide inside a pipe), and that the NAS sync includes it **and** the weekly `cass-storage` tarball." becomes "first so a truncated `mysqldump` cannot hide inside a pipe) and one encrypted copy of the largest backup, and that the off-host copy (runbook **Off-host copies**) includes the dumps **and** the weekly `cass-storage` tarball."; in section 3, after the item "Restore that dump into a scratch database …", add "- [ ] Restore the off-host copy on the PC and compare it with production (runbook **Restore drill from the off-host copy**); its date and the next one go into `docs/HANDOVER.md`." wrapped like its neighbours.
```markdown
## Backups (deferred by Plan 10)

- **Containers can use the instance principal** until the `DOCKER-USER` fence of B3's second item is in (`docs/HANDOVER.md`, B3; decision 0016).
- **A hash-locked requirements list for the OCI CLI.** Today it installs from wheels only, its versions kept in `/opt/oci-cli/requirements.lock`; hashes need `pip-compile --generate-hashes` run for the host's platform and Python.
- **Restoring production onto a new host from the off-host copies** is not written down. The drill covers the database half; the storage archive needs its volume found and filled, as `docker/storage-backup.sh` finds it.
- **An outside signal of uploads.** Root on the host can ping Kuma while sending nothing; an OCI alarm when no PutObject reached `cass-offsite` in a day would not depend on the host.
- **The drill is commands, not a script.** If a month's drill goes wrong on a typo: `tools/backup/restore-drill.sh` with fakes like the host script's.
- **Weekly archives are full copies.** About 21 in the bucket for the first 90 days, then 13; at 500 MB each, 13 is 6.5 GB of the 10 GB quota. Before then, a shorter lifecycle for `storage/` or incremental archives.
- **`tools/` is copied into the image.** `.dockerignore` lacks it, so `tools/claude/` and `tools/backup/` sit unused under `/var/www/html/tools`. One line, with an image build.
```
```markdown
- **Plan 10's defaults, each to confirm** (decision 0016): lock the bucket's retention rule after the first good drill; keep the bucket in Riyadh; keep each drill's two encrypted files 90 days on an external drive; the containers' metadata-service fence as a second B3 item; `tools/backup/` among `CLAUDE.md`'s sensitive paths; the privacy page's backup sentence; the drill's browser sign-in.
```
- [ ] **Step 4: HANDOVER, with `#NN` for the pull request.** The Backups row gains, before its closing `|`, " Nothing is off the host yet: the off-host copy is in #NN, not installed (B8)." The B3 Status cell becomes "Not started. A second item, from Plan 10 (owner to confirm): a `DOCKER-USER` rule refusing the container networks' traffic to 169.254.169.254, so that only host processes can use the instance's OCI rights; never a rule on the host's own traffic, which the OCI CLI and the Oracle Cloud Agent need (decision 0016)." The B8 Status cell becomes: "Engineer part done (#NN): `tools/backup/cass-backup-offsite.sh`, nightly at 04:17 from its own root cron line, encrypts each new dump and weekly archive with age to the owner's public key and uploads it to a private, create-only OCI Object Storage bucket, verified, recorded and with its own heartbeat; tested in CI's `scripts` job against fakes; runbook "Off-host copies", "Installing the off-host copy" and "Restore drill from the off-host copy"; decision 0016. Owner next: confirm Plan 10's seven defaults; its three owner steps (the age key pair, the private key in the password manager and on paper, the Kuma push monitor `cass offsite copy`); then, with the session, Plan 10's "After merge": the audit, the bucket, quota, alarm, dynamic group and policies, the host install and first run, the first drill, the retention lock. Done when that drill matches production and its date and the next one are in the Backups row." In the paragraph after the phase-B table, the text "B1, B3, B4, B8, B12" at the end of one line and "and B13 not started" at the start of the next (one Edit across the line break) become "B8's engineer part is in #NN, its owner part open; B1, B3, B4, B12" and "and B13 not started". Under "How the work is done", "Plans 1–9 and the backlog" becomes "Plans 1–10 and the backlog".
- [ ] **Step 5: The final gates (N=7), commit.** All six gates exit 0, `test rc=0` at baseline + 1. Commit the five files with the subject `docs: decision 0016, the backups backlog, HANDOVER for plan 10` (the number follows in Step 7).
- [ ] **Step 6: Push and open the pull request.** Write `C:\Users\khawl\AppData\Local\Temp\pr-plan10.md` (Write tool) with the body below, then:
```bash
cd $REPO && git push -u origin plan-10-b8-offsite-backups > /tmp/push-task-7.log 2>&1; echo "push rc=$?"
gh pr create --base main --head plan-10-b8-offsite-backups --title "Plan 10: off-host encrypted backups and the restore drill (B8)" --body-file /tmp/pr-plan10.md > /tmp/pr-task-7.log 2>&1; echo "pr rc=$?"
```
```markdown
**Summary.** B8's engineer part. `tools/backup/cass-backup-offsite.sh`, run on the production host by its own root cron line at 04:17, encrypts each new dump and weekly archive with age to the owner's public key, uploads it in one part to a create-only OCI Object Storage bucket under a name carrying the SHA-256 of the encrypted bytes, checks its size and MD5 in a listing, records it, and pings the Kuma monitor `cass offsite copy` only when everything is off-host and the newest dump is under 30 hours old. `tools/backup/cass-backup-offsite.test.sh` runs it against fakes for oci, age, curl and flock in CI's scripts job: 26 scenarios, each followed by six checks, among them that no plain text is written outside the backup directory (even by a `cp -p` copy) and that the ping fires only on success; seven mutants proved the checks bite. Runbook: Off-host copies (with the key), Installing the off-host copy, Restore drill from the off-host copy. The privacy page says backups keep deleted data at most 90 days. Decision 0016. Nothing in the image changes; the off-host copy needs no deploy.

**Sensitive files.**
- .github/workflows/ci.yml: one step in the scripts job runs the new harness (and this pull request builds the arm64 image).
- Also worth a look, though not on the list: tools/backup/cass-backup-offsite.sh runs as root on the production host with the instance's OCI rights; CLAUDE.md adds `tools/backup/` to the sensitive paths; lang/en/public.php changes the privacy page's wording.

**Migrations.** None.

**Environment variables.** None in the application; the host's three root-only files are in the runbook.

**Docs changed.** docs/runbooks/deploy-production.md (Backups, Monitoring, Erasing one person's data), docs/HANDOVER.md, docs/decisions/0016-offsite-backups.md (new), docs/superpowers/plans/backlog.md, docs/launch-checklist.md, CLAUDE.md.

**Gate logs.** /tmp/syntax-task-2.log to -7.log, /tmp/offsite-task-2.log to -7.log, /tmp/backup-task-1.log to -7.log, /tmp/t-task-1.log to -7.log, /tmp/phpstan-task-7.log, /tmp/pint-task-7.log, all rc=0; red: /tmp/t-task-1-red.log, /tmp/t-task-6-red.log; mutants: /tmp/t-task-2-mutant-1.log to -7.log, each rc=1 as intended.

**Tests added.** tools/backup/cass-backup-offsite.test.sh (26 scenarios); tests/Feature/Public/PrivacyPageTest.php (1; the suite at baseline + 1).

**Anything skipped.** The audit, the OCI resources, the host install, the first drill and the retention lock come after merge, with the owner (Plan 10, "After merge"); Plan 10's seven defaults wait for the owner's confirmation; the deferred items are in the backlog under "Backups".

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```
- [ ] **Step 7: The number.** Replace every `#NN` in `docs/HANDOVER.md` with the number `gh` printed, and rewrite the "Open pull requests" row from `gh pr list --state open` at that moment: this pull request first ("#N (Plan 10, B8 off-host backups; awaiting the owner's merge)."), then one clause per other open pull request. Commit with `docs: HANDOVER records plan 10 (#N)` and push. Then watch CI in the background, the arm64 `image` job included (about half an hour): `cd $REPO && gh pr checks <N> --watch --interval 60 > /tmp/ci-task-7.log 2>&1; echo "ci rc=$?"`. On a red job: `cd $REPO && RUN=$(gh run list --branch plan-10-b8-offsite-backups --limit 1 --json databaseId --jq '.[0].databaseId') && gh run view "$RUN" --log-failed > /tmp/ci-failed-task-7.log 2>&1; echo "log rc=$?"`, read it with `tail -n 80`, and report it rather than retrying.

## After merge: with the owner

Nothing here runs from the repository, and no deploy is involved; still, not in the 48 hours before an open conference's deadline. The owner unlocks production for the session (`touch .claude/deploy-unlocked`) and stays for each part. Print only allow-listed fields of every OCI answer (names, ids, matching rules, statements, lifecycle states). The namespace, the OCIDs, the region identifier and the host's address go into the project memory, never into a file in this repository.

**A. In OCI**, with the `oracle-oci` tools (`invoke_oci_api`), in this order; any time after the owner approves this plan, before or after the merge.
1. **The audit, first.** Read the tenancy OCID; the instance `hosting-1` (`oci.core.ComputeClient.list_instances`, then `get_instance`: its `id`, region, compartment and tags); the namespace (`oci.object_storage.ObjectStorageClient.get_namespace`); every dynamic group with its matching rule, both `oci.identity.IdentityClient.list_dynamic_groups` (compartment = tenancy) and, for each identity domain from `IdentityClient.list_domains`, `oci.identity_domains.IdentityDomainsClient.list_dynamic_resource_groups` on its URL; every compartment (`IdentityClient.list_compartments`, `compartment_id_in_subtree` true) and, for it and the tenancy, `IdentityClient.list_policies`, keeping each statement that names `dynamic-group`, `any-user` or `request.principal`, and any service statement already letting `objectstorage-<region>` manage object-family. A group matches `hosting-1` when its rule names the instance's OCID, its compartment's or a tag it carries. Record "no group matches hosting-1, no statement reaches it" in the project memory, or stop and bring the owner what was found.
2. `IdentityClient.create_compartment`: parent the tenancy, name `cass-backups`, description "CASS off-host backups (decision 0016)"; wait for ACTIVE.
3. `oci.limits.QuotasClient.create_quota`: compartment the tenancy, name `cass-backups-cap`, statements `["Set object-storage quota storage-bytes to 10000000000 in compartment cass-backups"]`.
4. `ObjectStorageClient.create_bucket`: name `cass-offsite`, compartment `cass-backups`, `public_access_type` NoPublicAccess, `storage_tier` Standard, `versioning` Disabled, `object_events_enabled` false, `auto_tiering` Disabled, no KMS key. No retention rule yet (B).
5. Unless the audit found one: `IdentityClient.create_policy`, compartment the tenancy, name `cass-backups-lifecycle`, the service statement of runbook "Off-host copies" with this region's identifier.
6. `ObjectStorageClient.put_object_lifecycle_policy` on `cass-offsite`, items `{name delete-after-90-days, target objects, action DELETE, time_amount 90, time_unit DAYS, is_enabled true}` and `{name abort-uploads-after-7-days, target multipart-uploads, action ABORT, time_amount 7, time_unit DAYS, is_enabled true}`. A refusal naming the service's permissions means step 5 has not taken effect yet: wait a few minutes and repeat.
7. `IdentityClient.create_dynamic_group`: compartment the tenancy, name `cass-backup-host`, matching rule `Any {instance.id = '<hosting-1 OCID>'}`. If the tenancy's identity domain refuses that call, `IdentityDomainsClient.create_dynamic_resource_group` on the Default domain's URL, with the same name and rule.
8. `IdentityClient.create_policy`: compartment `cass-backups` (attached there, so no later edit can reach beyond it), name `cass-backup-host-writes`, the two statements of runbook "Off-host copies". Nothing else: no user, group, API key, auth token, pre-authenticated request, public access, replication or customer-managed key.
9. `oci.monitoring.MonitoringClient.create_alarm`: `display_name` cass-offsite-filling, compartment and metric compartment `cass-backups`, namespace `oci_objectstorage`, the query of runbook "Off-host copies", severity WARNING, `pending_duration` PT1H, the `hosting-alerts` topic as destination, a body naming runbook "Off-host copies".
10. Read each one back and record its name and OCID in the project memory. The dynamic group applies within about an hour.

**B. On the host**, an hour after A.7: runbook "Installing the off-host copy", in its order. The session writes `/root/.cass-offsite.conf` and `/root/.cass-age-recipient` (owner step 2's public key) with `sudo tee` into the files the install made 0600; the owner writes `/root/.cass-ping-offsite` with `sudoedit` in their own terminal (owner step 3's URL), so the URL never passes through the session. Once the canary has printed `refused by IAM` four times, `ObjectStorageClient.create_retention_rule` on `cass-offsite`: `display_name` keep-30-days, `duration` {`time_amount` 30, `time_unit` DAYS}, no `time_rule_locked`; then the script, the first run and the cron line. Record in the project memory: `lsb_release -ds`, `timedatectl show -p Timezone --value`, `age --version`, the CLI's version and `requirements.lock`, the installed commit and the script's SHA-256, and the first run's last line. Then the alert proof.

**C. The first drill**, on the owner's PC, the day after B, so that the copy came from the cron line rather than the hand run: runbook "Restore drill from the off-host copy". This once, type the key from the paper copy instead of pasting it. If every box is ticked, and the owner confirmed default 1: `ObjectStorageClient.update_retention_rule` on `keep-30-days` with `time_rule_locked` 15 days ahead (RFC 3339); it can be cancelled until then.

**D. The record**, a docs-only pull request: the Backups row's off-host sentence becomes "Off-host copy since <date>: `cass-backup-offsite` nightly at 04:17 to `cass-offsite` (runbook "Off-host copies"); the first run sent <n> dumps and <m> archives. Restore drill from the off-host copy on <date>: <db object> and <storage object>, both byte for byte the host's files, every table at or above the host's count; next drill <date a month on>."; the Monitoring row says four push monitors, `cass offsite copy` among them, and the alarm `cass-offsite-filling`; B8 becomes "Done <date>" with that drill and the lock's date; launch checklist section 3's off-host item ticked with the date. That is B8's "Done when".

## Verification

- After Task 7 the six gates exit 0; the harness passes on this PC (S24 skipped) and in CI's `scripts` job (S24 run); each of the seven mutants turned it red (`/tmp/t-task-2-mutant-1.log` to `-7.log`); `/tmp/t-task-1-red.log` shows every scenario red before the script existed, and `/tmp/t-task-6-red.log` the privacy test red before its strings.
- CI is green on the pull request, the arm64 `image` job included (the workflow changed).
- The pull request lists the sensitive file, no migration, no variable, the docs and the gate logs, for review point 2, and the seven defaults wait for the owner.
- After merge, outside CI: the audit's record, the canary's four `refused by IAM`, the first run's `OK` line, Kuma's first push and alert, the first drill's ticks, the lock (part D).

## Deferred

- `docs/superpowers/plans/backlog.md`, "Backups (deferred by Plan 10)": the containers' fence (now B3's second item), a hash-locked CLI list, restoring onto a new host, an outside upload signal, a drill script, incremental archives, `tools/` in `.dockerignore` (Task 7, Step 3).
- Plan 10's seven defaults, recorded in the backlog's open questions until the owner confirms them.
