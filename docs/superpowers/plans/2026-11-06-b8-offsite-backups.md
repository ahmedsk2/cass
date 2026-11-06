# CASS v2 Plan 10: B8 Off-Host Encrypted Backups and the Restore Drill

> **Status: DRAFT, written 2026-11-06 against `main` at `d417c8a`; not yet reviewed and not prototyped.** Next: the adversarial review (`CLAUDE.md`, "Pipeline per plan"), then the owner's approval of the summary (review point 1).
>
> **Owner decisions of 2026-11-06, binding for every task below.** The off-host copies go to a private OCI Object Storage bucket in the owner's own tenancy (same cloud, another service and failure domain than the VM's disk), with a retention rule or versioning and a lifecycle rule deleting objects after about 90 days. The host signs in as an instance principal through the OCI CLI installed for root, with no API key on the host. Every file is encrypted with `age` to a public key kept root-only on the host; the owner generates the key pair on their own PC and keeps the private key off the host in two places. A new script runs after the nightly dump and the weekly archive, encrypts what has not been sent yet, uploads it under a content-addressed name carrying the date, verifies it, records it and pings its own Uptime Kuma push monitor on success. A monthly drill on the owner's Windows PC restores the newest off-host dump into a throwaway `mysql:8.4` and compares row counts with production. The OCI resources are created after merge by the orchestrating session with the owner present ("After merge"). The choices this plan adds are in the facts below and in decision 0016 (Task 5); approving the summary accepts them.

## Owner steps (yours alone)

Three steps and nothing else; the session does the rest with you present ("After merge"). Any time before the host install.

1. **Generate the age key pair on your PC.** In Git Bash:
   ```bash
   cd /c/Users/khawl/Desktop/Ahmed/claude/tools
   curl -fsSLO https://github.com/FiloSottile/age/releases/download/v1.3.2/age-v1.3.2-windows-amd64.zip
   sha256sum age-v1.3.2-windows-amd64.zip  # f48d8f8f9ebe903ab5027ed067652f2cc1db94bc206976430133b905dcd8e8c7
   powershell -NoProfile -Command "Expand-Archive age-v1.3.2-windows-amd64.zip -DestinationPath ."
   ./age/age-keygen.exe                    # three lines on the screen, nothing written to disk
   ```
   The digest is the one GitHub lists for that file (release v1.3.2, 2026-08-29). Another digest: stop and tell the session. The archive unpacks into a folder `age` with `age.exe` and `age-keygen.exe`; if yours unpacked elsewhere, use that path below.
2. **Store the private key twice, then check the stored copy.** Put the three lines (`# created: …`, `# public key: age1…`, `AGE-SECRET-KEY-1…`) into one password-manager entry, "CASS backup key (age)", and print them on paper kept away from the PC. Check the entry by pasting from the password manager, not from the screen:
   ```bash
   R=age1…   # your public key, from the "# public key:" line
   echo cass-key-check | ./age/age.exe -r "$R" -o check.age
   read -rs -p 'AGE-SECRET-KEY line: ' K; echo; printf '%s\n' "$K" | ./age/age.exe -d -i - check.age; unset K; rm -f check.age
   ```
   It prints `cass-key-check`. Close the terminal (its scrollback holds the key) and give the session the `age1…` public key only. The private key never goes to the host or into a chat; lose both copies and every off-host copy is unreadable.
3. **Create the Kuma push monitor** `cass offsite copy`: type Push, heartbeat interval 24 hours, 1 retry after 60 minutes, the email notification the other three use. Create it on the day of the host install, or create it paused and resume it then: a push monitor that never hears from the host goes down after a day and emails you. Keep its push URL: at the host install you paste it yourself into `/root/.cass-ping-offsite` in your own terminal, never into a chat.

**Goal:** an encrypted copy of every dump and storage archive off the host, which the host can add to but can neither read back nor delete, with a heartbeat that goes quiet when a night's copy is missing, and a monthly restore from that copy. Brief B8's "Done when": "A dump restored from the off-host copy, not the host copy, matches production row counts; the drill date and the next one are in `HANDOVER.md`."

**Architecture.** One owner per seam:
- The copy: `tools/backup/cass-backup-offsite.sh`, installed as `/usr/local/bin/cass-backup-offsite` and run by its own root cron line at 04:17. It reads `/srv/backups/cass`, writes only there (`.offsite-work/`, `offsite-manifest.tsv`, `.offsite.lock`) and to the bucket, and pings Kuma.
- Its proof: `tools/backup/cass-backup-offsite.test.sh`, fakes for `oci`, `age`, `curl` and `flock` and a scenario directory standing in for `/srv/backups/cass`, `/root` and `/tmp`; CI's `scripts` job runs it.
- The fences, outside the repository: the bucket with its retention rule, lifecycle and quota, the dynamic group and two policies ("After merge"); the private key, with the owner.
- The procedures: runbook "Backups" ("Off-host copies", "Installing the off-host copy", "Restore drill from the off-host copy") and "Monitoring"; `docs/HANDOVER.md`; decision 0016.

**Tech stack.** Bash 5 with coreutils, util-linux `flock`, `curl` and Python 3 (JSON and MD5 only) on the host's Ubuntu (ARM64); `age` from Ubuntu's archive on the host (1.1.1 on 24.04) and v1.3.2 on the PC; OCI CLI 3.96.0 (with `oci` SDK 2.190.0) in a root-only virtual environment on the host and in the tools folder on the PC; OCI Object Storage and IAM; Uptime Kuma push monitors. Nothing in the PHP application, the image or the compose files changes.

**Spec and brief:** `docs/reviews/2026-10-16-expert-brief.md`, phase-B row B8 (its "Done when" is this plan's acceptance), "Question 4, operations" (the rows "Survive losing the host" and "Know the backup is a backup") and its point 4 on backups readable at rest; `docs/superpowers/specs/2026-09-10-cass-v2-design.md` section 8 (the NAS sync that was never connected); `docs/decisions/0015-error-tracking-and-heartbeats.md` (pings on success, ping URLs in root-only files); `docs/decisions/0011-repository-public-for-ci.md` (so no namespace, OCID or address goes into a file here).

**Environment facts for every command below**
- `$REPO` is the tree you implement in: the worktree the run gives you, branch `plan-10-b8-offsite-backups` from `main` at `d417c8a`, or your task's own `CASS-wt-N` worktree and branch. Shell variables do not survive between Bash calls: begin every call with `REPO=<that path>;` or write the path itself, and `cd $REPO`. Git Bash, after `export PATH="/c/Users/khawl/Desktop/Ahmed/claude/tools/php84:/c/Users/khawl/Desktop/Ahmed/claude/tools/composer:$PATH"`.
- A fresh worktree has no `vendor/`, `node_modules/`, `public/build` or `.env`. Before the first PHP gate: `php /c/Users/khawl/Desktop/Ahmed/claude/tools/composer/composer.phar install --no-interaction --no-progress`, `npm ci`, `npm run build`, then `cp .env.example .env && php artisan key:generate --force`. Never `composer.bat`, and never with `npm run dev` running.
- **The production hook** (fact 4) refuses a Bash command holding lowercase `oci` followed by a space, the release script's file name, the deployment platform's name, or a remote-shell command. Search such text with the Grep tool, write it with Write and Edit, run the harness by its path, and pass commit messages and the PR body as files.
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
  Task 1 runs the last four only: the script does not exist yet, and its harness run is the red proof. Baseline is the count `php artisan test` reports on the fresh branch (`/tmp/t-plan10-baseline.log`); no task adds a PHP test, so every task ends at the baseline.
- **Commits:** the message with the Write tool at `C:\Users\khawl\AppData\Local\Temp\commit-plan10-task-N.txt`, ending with the trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`; `git add` the task's files by name (the two scripts with `git add --chmod=+x`, fact 3), then `test -s /tmp/commit-plan10-task-N.txt && git commit -F /tmp/commit-plan10-task-N.txt`. Never with a gate red.
- **Order.** Stages `[[1],[2],[3],[4],[5]]`. Task 2 needs Task 1's harness; Tasks 3 and 4 touch disjoint files and may run as a pair; Task 5 runs last and opens the pull request.
- New shell is bash, `#!/usr/bin/env bash`, four-space indents as in `tools/claude/deploy.sh`. Comments explain the code, never its provenance: no "Plan 10", "Task N", "B8" or "fact N" in either script.
- `$SCRATCH` is the scratch directory the run gives you, outside the repository. Task 2's mutants live there and are never committed.

**What this plan does NOT build**
- Any change to `docker/backup.sh`, `docker/storage-backup.sh`, the `Dockerfile`, `.dockerignore`, the compose files or `tools/claude/deploy.sh`: the two backups, their cron lines and pings, the pre-migration backup and the image stay as they are, and no deploy is needed.
- PHP, a migration, a policy class or an application environment variable. `cass:health` gains no row: the heartbeat is the signal.
- Any call to OCI from the repository, the harness or CI. The bucket, quota and IAM, the host install and the first drill come after merge, with the owner ("After merge").
- A drill script, a restore of the storage archive beyond counting its entries, a cross-region or off-OCI copy, a locked retention rule, a fence between the containers and the metadata service, `tools/` in `.dockerignore`: open questions and backlog (Task 5).

## Owner summary (review point 1)

#### Behaviour changes a user would notice
- Nothing on the site.
- **For you, once installed:** every night at 04:17 the host encrypts each new dump and weekly archive to your public key and copies it to a private bucket in your OCI tenancy; Kuma's monitor `cass offsite copy` shows the push, and a night without one emails you the next morning. The host can add to the bucket and list names; it cannot read a copy back, overwrite it or delete it. For 30 days after upload nobody can change or delete a copy, you included, as long as the retention rule stands (unlocked, you could still remove it: question 1). Copies are deleted 90 days after upload. The first run also sends the 14 dumps and the weekly archives already on the host.
- **Your key is the only way in.** Without the private key the copies are unreadable, to an intruder and to you alike. It never goes to the host or into a chat.
- **Monthly, about twenty minutes:** the restore drill on your PC (runbook "Restore drill from the off-host copy"): download the newest copy, decrypt it at a prompt, restore it into a throwaway MySQL with no network, compare the counts with production, tick the list. The first drill closes B8.

#### Policies touched or added
None in the application. In OCI: the dynamic group `cass-backup-host` (this instance only) and two policies: the host may create objects and list them in the bucket `cass-offsite` and nothing else; Object Storage itself may delete the expired copies in the compartment `cass-backups` (runbook "Off-host copies").

#### Migrations
None.

#### Environment variables
None in the application, and nothing in `.env.example` (`config/` reads none of them). On the host, three root-only files, `/root/.cass-offsite.conf`, `/root/.cass-age-recipient` and `/root/.cass-ping-offsite`, and the script's overrides, all in the runbook.

#### Sensitive files touched
- `.github/workflows/ci.yml`: one step in the `scripts` job runs the new harness. A change to this file also makes the pull request build the arm64 image (fact 2).
- Not on the list, but worth your eye: `tools/backup/cass-backup-offsite.sh`, which runs as root on the production host with the instance's OCI rights (question 5).

#### Open questions for the owner (each ships the behaviour stated)
1. **Lock the retention rule?** Ships unlocked: you, or anyone holding your OCI credentials, can still remove the rule and then delete copies. A lock takes effect after 14 days and is irreversible: from then on the duration can only grow, and the rule goes only with the emptied bucket. Recommended: lock it after three good drills.
2. **The host's region, or the tenancy's other Saudi region?** Ships in the host's region (Riyadh). A bucket in Jeddah would survive a region-wide outage and still keep the data in the country, so the privacy page stays true; it needs the tenancy subscribed to that region, and the Always Free allowance applies in the home region only.
3. **A third copy outside OCI?** Ships none: the drill deletes its download. Keeping each month's encrypted download on an external drive is the only copy that survives losing the tenancy itself (closure, suspension, your credentials). Recommended: yes.
4. **Fence the containers off the metadata service?** Ships open (backlog): any container on the host can use the instance's rights today, bounded by the policy. A host firewall rule would leave them to host processes; it belongs with B3, after checking nothing on the host needs that service.
5. **Add `tools/backup/` to the sensitive paths in `CLAUDE.md`?** Ships unlisted; this pull request names the script anyway. Recommended: yes, since it runs as root on production.

## File structure created or changed by this plan

```
tools/backup/cass-backup-offsite.test.sh   created, T1: fakes for oci, age, curl and flock; 20 scenarios
tools/backup/cass-backup-offsite.sh        created, T2: the off-host copy
.github/workflows/ci.yml                   changed, T3: the scripts job runs it
docs/runbooks/deploy-production.md         changed, T4: Backups (three new subsections), Monitoring
docs/decisions/0016-offsite-backups.md     created, T5
docs/HANDOVER.md, docs/superpowers/plans/backlog.md, docs/launch-checklist.md   changed, T5
```

## Facts verified before writing this plan

Read at `d417c8a` and in the vendors' documentation on 2026-11-06.

1. **The backups today.** `docker/backup.sh` (POSIX sh, `set -eu`, `umask 0077` at :27) writes `cass-$(date +%F-%H%M).sql.gz` (:31) through a `.partial` name, 0600 (:120-138), and rotates with `find "$DEST" -name 'cass-*.sql.gz' -mtime +14` and `find "$DEST" -name '*.partial' -mtime +1` (:150-151), both recursive; `docker/storage-backup.sh` writes `cass-storage-$(date +%F).tar.gz` (:38-42), a second run that day replacing it, rotates at 56 days (:162-163) and ends `(N bytes, M entries)` (:165). Root's crontab runs them at 03:17 and Sunday 03:47, each followed by `&& curl -fsS -m 10 --retry 3 -o /dev/null -K /root/.cass-ping-…` (runbook :1211-1213, :1320-1321), the ping files holding one line `url = "…"` (:1206). They reach the host by `docker cp` from the image (`Dockerfile`:63-71). The release script runs `cass-backup` before a pending migration and stops with exit 4 when it fails (its header, :16). So a file named `cass-….age.partial` under `.offsite-work/` is touched by the rotation only once it is a day old, and `offsite-manifest.tsv` matches neither pattern.
2. **Shell tests here.** `tools/claude/deploy.test.sh` (bash) writes its fakes into `$ROOT/bin` (:42-268), refuses to run unless each wins the PATH lookup (:270-277), shims `python3` to `python` where `python3` is not Python 3 (:279-290), wraps runs in `timeout 30` (:292-293) and gives each scenario a fresh directory (`scenario()` :302, `run_deploy()` :345, `eq`/`has` :359-375); `docker/backup.test.sh` skips mode assertions where chmod is not honoured (:188-191). CI's `scripts` job (`.github/workflows/ci.yml`:190-223, `ubuntu-24.04`, 5 minutes) runs `bash -n` and the harness (:201-202). The `changes` job counts `ci.yml` as an image input (:61), so this pull request runs the arm64 `image` job.
3. **This PC's Git Bash** (checked 2026-11-06): no `flock`; `python3` is the Microsoft Store stub (`python` is 3.11); `timeout`, `sha256sum`, `md5sum`, `openssl`, `base64`, `stat` and `mktemp` exist. `core.filemode` is false, so the executable bit comes from `git add --chmod=+x`, as `tools/claude/split-plan.sh` has it (100755). `.dockerignore` does not list `tools/`, so the image holds a copy of `tools/` that nothing runs.
4. **The production hook** (`.claude/hooks/guard-production.mjs`:13-18): a Bash command matching `(^|[^\w.-])oci\s`, `deploy\.sh`, `coolify` (any case) or `ssh|scp|sftp|rsync` followed by whitespace is refused unless the owner unlocked production in the last hour; the `oracle-oci` tools are gated the same way (:20).
5. **OCI permissions** (Object Storage policy reference): `HeadObject` needs `OBJECT_READ` or `OBJECT_INSPECT`; `ListObjects` and `ListObjectVersions` `OBJECT_INSPECT`; `PutObject` `OBJECT_CREATE` for a new name and `OBJECT_OVERWRITE` for an existing one; `CreateMultipartUpload` and `UploadPart` `OBJECT_CREATE` and `OBJECT_OVERWRITE`; `CommitMultipartUpload` `BUCKET_READ`, `OBJECT_CREATE`, `OBJECT_READ` and `OBJECT_OVERWRITE`; `AbortMultipartUpload` and `DeleteObject` `OBJECT_DELETE`; `GetNamespace` none. The `inspect` verb on `objects` is exactly `OBJECT_INSPECT`; `target.bucket.name` is a supported condition. A host holding `OBJECT_CREATE` and `OBJECT_INSPECT` can therefore upload in one part only, never overwrite, read or delete.
6. **OCI CLI 3.96.0**, PyPI's latest on 2026-11-06 (pins `oci==2.190.0`). `os object put`: files above 128 MiB go multipart unless `--no-multipart`, and a file from stdin always does; `--no-overwrite`; `--content-md5` (base64; the service refuses a body with another MD5, HTTP 400); `--namespace` given means no `os ns get`. `os object list`: `--prefix`, `--fields` (name, size, md5, …), output `{"data": [{"name", "size", "md5", …}], "prefixes": []}`. `--auth instance_principal` signs with certificates the instance metadata service issues and rotates several times a day; "Any user who has access to the instance … automatically inherits the privileges granted to the instance" (Calling services from an instance), and every instance principal also holds `compartment_inspect`.
7. **Bucket rules.** Retention rules and versioning exclude each other; lifecycle "can't remove objects protected by active retention rules"; a time-bound rule blocks update, overwrite and delete of each object for its duration from its Last-Modified time; a lock waits 14 days and is irreversible (Using retention rules). Lifecycle needs `Allow service objectstorage-<region> to manage object-family in compartment <c>`, which may be narrowed to `BUCKET_INSPECT`, `BUCKET_READ`, `OBJECT_INSPECT`, `OBJECT_UPDATE_TIER`, `OBJECT_DELETE` and `OBJECT_VERSION_DELETE`, runs once a day, and its rules are `{name, target (objects | multipart-uploads | previous-object-versions), action (DELETE | ABORT | ARCHIVE | INFREQUENT_ACCESS), time_amount, time_unit (DAYS | YEARS), is_enabled}` (Using lifecycle policies; SDK `ObjectLifecycleRule`). Quotas: family `object-storage`, quota `storage-bytes`, regional, plain bytes. Dynamic groups: rules `instance.id = '…'` inside `Any {…}`; a change takes about an hour. Always Free, in the home region: 10 GB in each tier on a paid account, 20 GB across tiers on an Always Free-only one, and 50,000 requests a month.
8. **age.** Ubuntu's `universe` has `age` with ARM64 builds in 22.04 (1.0.0), 24.04 (1.1.1) and 26.04 (1.2.1). Upstream v1.3.2 (2026-08-29) lists, through GitHub's release API, SHA-256 `f48d8f8f…e8c7` for `age-v1.3.2-windows-amd64.zip` and `6b8dc433…37f4` for `age-v1.3.2-linux-arm64.tar.gz`, each with a `.proof`. The man page: `-R` reads one recipient per line, ignoring blank and `#` lines; `-i -` reads the identity from stdin and then needs the input as a file argument; exit 0 "if and only if" the whole input was processed; about 200 bytes per recipient plus 16 per 64 KiB; files from a stable version decrypt with every later one. An age file begins with the line `age-encryption.org/v1`.
9. **The drill's inputs.** `docker-compose.production.yml`:87 pins `mysql:8.4@sha256:24989179…0f70` (the `scripts` job keeps `ci.yml` and the dev compose on the same digest); `config/app.php`:70 is UTC and neither compose file sets a time zone, so `created_at` and mysqldump's `-- Dump completed on` line are UTC. Every table the drill counts has `created_at` (`timestamps()` in its create migration); organizations, conferences and submissions soft-delete, and rows are hard-deleted by an organization purge, `cass:demo-reset`, unassigning a reviewer and the email-log retention.
10. **Not verified.** The CLI's output for an empty listing (nothing, or JSON without `data`; the script treats both as absent); whether `--no-overwrite` sends `If-None-Match` or asks with `HeadObject` first (the policy allows both); the host's Ubuntu release and time zone (the install records both; cron at 04:17 is local time); whether this tenancy's identity domain accepts `IdentityClient.create_dynamic_group`; nested `any` inside `all` in a policy condition, avoided by writing two statements.

### Task 1: The harness and its fakes (red until Task 2)
The scenarios come before the script. This task writes the whole harness and shows every scenario failing because the script does not exist; the commit is red on purpose. Nothing runs the harness until Task 3 adds it to CI, after Task 2 has turned it green, and the PHP gates stay green throughout.

**Files:** create `tools/backup/cass-backup-offsite.test.sh` (Write tool).
**Interfaces:** consumes the contract below. Produces `CASS_OFFSITE_SCRIPT` (the script under test, default `$HERE/cass-backup-offsite.sh`) for Task 2's mutants, and the titles S1 to S20 that Task 2's report cites.

**The contract under test** (Task 2 builds exactly this):
- `cass-backup-offsite [dir]`, `dir` defaulting to `/srv/backups/cass`. Overrides with their defaults: `CASS_OFFSITE_CONFIG` (`/root/.cass-offsite.conf`: lines `namespace=`, `bucket=`, `region=`, and `#` comments), `CASS_OFFSITE_RECIPIENTS` (`/root/.cass-age-recipient`), `CASS_OFFSITE_PING_FILE` (`/root/.cass-ping-offsite`), `CASS_OCI_BIN` (`/opt/oci-cli/bin/oci`), `CASS_AGE_BIN` (`age`), `CASS_OFFSITE_MAX_AGE_HOURS` (30).
- It copies the regular files directly in `dir` named `cass-YYYY-MM-DD-HHMM.sql.gz` (kind `db`) or `cass-storage-YYYY-MM-DD.tar.gz` (kind `storage`), and nothing else, skipping a file whose name, size and mtime already have a row in `dir/offsite-manifest.tsv`.
- Per file: `gzip -t dir/<file>` first (a file still being written, or damaged, is not sent); then `age --encrypt --recipients-file <recipients> --output dir/.offsite-work/<file>.age.partial dir/<file>`; the object is `<kind>/<file>.<SHA-256 of the encrypted bytes>.age`; `oci os object put --auth instance_principal --region R --namespace N --bucket-name B --name <object> --file <encrypted> --content-md5 <base64 MD5> --no-multipart --no-overwrite`, then `oci os object list` with the same first four options and `--prefix <object> --fields name,size,md5`; on a match, a manifest row of seven tab-separated fields: UTC time, file, bytes, mtime, object, object bytes, MD5.
- The ping, `curl -fsS -m 10 --retry 3 -o /dev/null -K <ping file>`, only when nothing failed and the newest dump is under the limit. Exits 0 to 6 as in Task 2's table; the first failure's code wins; one file's failure does not stop the others.
- Output: a line per file sent or failed, then exactly one last line: `[cass-backup-offsite] OK: <u> uploaded, <a> already off-host; newest dump <file>, <h> h old; pinged` or `[cass-backup-offsite] FAILED (exit <n>): <u> uploaded, <f> failed, <a> already off-host; <first failure>`.

**The fakes**, written into `$ROOT/bin` as `deploy.test.sh` writes its own (fact 2). Each appends a line per call to `$FAKE/calls` and reads its knobs, empty files, from `$FAKE`. They encode the host's OCI rights (fact 5), so a script that asks for more fails here:
- `oci`: answers `os object put` and `os object list` only. Both require `--auth instance_principal` and `--namespace testns --bucket-name test-bucket --region xx-test-1`, else exit 2 naming the mismatch. `put` also requires `--no-multipart` (else exit 2, "multipart needs OBJECT_READ and OBJECT_OVERWRITE, which the host does not have"), refuses `--force`, and compares `--content-md5` with the base64 MD5 of `--file` (else exit 1 with `{"code": "UnmatchedContentMD5", "status": 400}`); it copies the file to `$FAKE/bucket/<name>` and logs `oci put <name>`. Knobs: `put-fails` (exit 1, `{"code": "InternalServerError", "status": 500}` on stderr, nothing stored) and `put-truncates` (stores the first half). `list` prints `{"data": [{"name": …, "size": …, "md5": …}], "prefixes": []}` for the stored objects under `--prefix`, built in bash from `find` and `stat`, and logs `oci list <prefix>`; knob `list-empty` prints nothing. Anything else logs `oci FORBIDDEN <subcommand>` and exits 1 with `{"code": "NotAuthorizedOrNotFound", "status": 404}`.
- `age`: accepts `--encrypt`/`-e`; requires `--recipients-file`/`-R` (a readable file), `--output`/`-o` and an input; `-r`, `-p`, `-a`, `-d` or `-i` exit 2 ("not how the host calls age"). Logs `age -R <file> -o <out> <in>` and writes `age-encryption.org/v1`, two fake stanza lines and the input through `tr 'A-Za-z' 'N-ZA-Mn-za-m'`. Knobs: `age-fails`, or `age-fails-on` holding one input's base name (writes `partial` to the output, exits 1); `age-plain` (copies the input to the output and exits 0: a broken age).
- `curl`: appends its arguments to `$FAKE/curl-argv` and logs `curl -K <file>`; knob `curl-fails` exits 22 with curl's 404 line.
- `flock`: logs; knob `lock-held` exits 1. CI has the real one, this PC none (fact 3); the fake makes both the same.
- `python3`: `deploy.test.sh`'s shim where needed. Every MD5 and JSON helper, here and in the script, reads stdin rather than a path, so MSYS path conversion cannot reach it.
The harness refuses to start unless `age`, `curl`, `flock` and `python3` resolve to `$ROOT/bin` first, and wraps each run in `timeout 30` where `timeout` exists.

**A scenario** (`scenario <title>`) gets a fresh `$S` with `dest/`, `home/`, `tmp/`, `cwd/` and `bucket/`; the configuration above in `$S/conf`; `$S/recipient` (a `#` line, then `age1` and 58 `q`); `$S/ping` (`url = "https://uptime.test/api/push/PUSHTOKEN7f3a?status=up&msg=OK&ping="`); and fixtures written straight into `dest/` by `make_backup <name> <age>`: gzip of three SQL lines holding `$MARKER` (`CASS-PLAINTEXT-<epoch>-<pid>`), mtime set with `touch -d`, SHA-256 appended to `$S/fixture-sums`. The default set is `cass-2026-11-05-0317.sql.gz` (25 hours ago), `cass-2026-11-06-0317.sql.gz` (1 hour ago) and `cass-storage-2026-11-01.tar.gz` (5 days ago), with decoys to leave alone: `cass-2026-11-06-0400.sql.partial`, `notes.txt`, a directory `cass-2026-11-03-0317.sql.gz/` and, where `ln -s` makes a real link (probe once), `cass-2026-11-04-0317.sql.gz -> /etc/hostname`. Last, `touch -d '2 seconds ago' "$S/.start"`.

`run_offsite [VAR=VALUE…]` runs `bash "$SCRIPT" "$S/dest"` from `$S/cwd` with the fakes first on PATH, `HOME`, `TMPDIR` and `FAKE` inside `$S`, the overrides pointing at `$S` and `CASS_OCI_BIN=$ROOT/bin/oci`; output to `$S/out`, exit status in `RC`. Then `after_run` checks, every time:
1. exactly one line matches `^\[cass-backup-offsite\] (OK|FAILED)`, and it is the last;
2. no plain text outside `dest/`: no file under `$S`, `/tmp` or `/var/tmp` that is newer than `$S/.start` and outside `$S/dest/` has a SHA-256 in `fixture-sums` or holds `$MARKER`, as it is or through `gzip -dc`;
3. no `oci FORBIDDEN` call;
4. at most one `curl` call, none unless `RC` is 0 or 6, and `PUSHTOKEN7f3a` in neither `curl-argv` nor `out`;
5. where `dest/.offsite-work` is a directory, it holds no file;
6. every object the manifest names is in the bucket, starts with `age-encryption.org/v1` and has the SHA-256 its name carries; no object in the bucket is a fixture.

**Scenarios**, each also asserting its exit code and the start of its last line:
- S1 first run: the three backups go, the decoys stay. Exit 0; exactly the objects `db/cass-2026-11-05-0317.sql.gz.<64 hex>.age`, `db/cass-2026-11-06-0317.sql.gz.<64 hex>.age` and `storage/cass-storage-2026-11-01.tar.gz.<64 hex>.age`; three `age` calls, each with `-R $S/recipient`; three puts, three lists; three manifest rows of seven fields; one `curl -K $S/ping`; `OK: 3 uploaded, 0 already off-host; newest dump cass-2026-11-06-0317.sql.gz`. Where chmod is honoured (`docker/backup.test.sh`'s probe), the manifest is 0600 and `.offsite-work` 0700.
- S2 nothing new to upload: S1's run, `calls` emptied, a second run: no `age` or `oci` call, one ping, exit 0, `OK: 0 uploaded, 3 already off-host`.
- S3 an archive remade under its name: after a first run, new content and a new mtime for `cass-storage-2026-11-01.tar.gz`; the second run sends it as a second `storage/` object; exit 0, `OK: 1 uploaded, 2 already off-host`.
- S4 age fails: `age-fails`; exit 2; no put; no manifest row; no ping.
- S5 age writes plain text: `age-plain`; exit 2; the bucket stays empty; the output says "not an age file"; no ping.
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
- S16 a private key in the recipients file: a line `AGE-SECRET-KEY-1` followed by 58 `Q`; exit 1; "private key"; `AGE-SECRET-KEY-1QQQQ` not in the output; no `age` call.
- S17 no `bucket=` line: exit 1; the output names `bucket`; no `age` call.
- S18 another run holds the lock: `lock-held`; exit 1; no `age` call.
- S19 a file where `.offsite-work` belongs: exit 1; one `FAILED` line; no object; no ping.
- S20 a dump cut short (still being written, or damaged): `cass-2026-11-06-0317.sql.gz` truncated to half its bytes; exit 2; the other two sent; "not a complete gzip"; no ping.

- [ ] **Step 1: Write it.** bash, `set -eu`, `trap 'rm -rf "$ROOT"' EXIT INT TERM`, `ok`/`bad` counters and `eq`/`has`/`lacks` helpers as in `deploy.test.sh`, a header comment saying what it proves and that it needs no network, docker or OCI account, and the usage line `bash tools/backup/cass-backup-offsite.test.sh`. It ends with `cass-backup-offsite.test.sh: all assertions passed` and exit 0, or the failure count and exit 1.
- [ ] **Step 2: Syntax, then red.** `cd $REPO && bash -n tools/backup/cass-backup-offsite.test.sh; echo "syntax rc=$?"` → 0. `bash tools/backup/cass-backup-offsite.test.sh > /tmp/t-task-1-red.log 2>&1; echo "rc=$?"` → `rc=1`: every scenario fails on the missing script (bash's "No such file or directory" in each `out`), and the harness runs to its last line rather than aborting.
- [ ] **Step 3: The gates (N=1), commit.** `backup rc=0`, `test rc=0` at the baseline, `stan rc=0`, `pint rc=0`. Commit the harness with the subject `test(backup): harness for the off-host copy, red until the script exists` and a body naming the four fakes and the six checks every scenario gets.

### Task 2: The script, green, and the harness bites
**Files:** create `tools/backup/cass-backup-offsite.sh` (Write tool).
**Interfaces:** consumes Task 1's contract and harness. Produces the command the runbook installs as `/usr/local/bin/cass-backup-offsite`, its files in the backup directory (`.offsite-work/`, `offsite-manifest.tsv`, `.offsite.lock`) and these exit codes, which Task 4 documents:

| Exit | When |
|---|---|
| 0 | every finished backup is off-host and verified, the newest dump is under the limit, the ping went out |
| 1 | the directory, a tool, the configuration or the recipients file is missing or wrong, another run holds the lock, or something failed unexpectedly |
| 2 | a file is not a complete gzip, age failed on it, or what age wrote is not an age file; that file was not sent |
| 3 | an upload failed |
| 4 | an object is missing after its upload, or its size or MD5 differs |
| 5 | there is no dump, or the newest is older than `CASS_OFFSITE_MAX_AGE_HOURS` |
| 6 | everything was copied, but the ping failed or its file is missing |

- [ ] **Step 1: The header comment**, as `docker/backup.sh` and `tools/claude/deploy.sh` open: what it does; that it runs on the host as root from its own cron line, never in a container; why the host can add copies but never read or delete them (the bucket policy) and why uploads are single-part; that a file is sent only once `gzip -t` passes, and that no plain text is written outside the backup directory; the usage line, every override with its default, the exit table, and that `cass-backup-offsite.test.sh` beside it runs it against fakes.
- [ ] **Step 2: The body.** Preflight, in this order, each a `fail 1 "<message>"`: the directory exists (`no backup directory <dir>`); `[ -x "$OCI" ]` (`OCI CLI not found at <path> (runbook: Installing the off-host copy)`); `command -v` finds `$AGE`, `python3`, `gzip`, `sha256sum`, `curl`, `flock` and `timeout` (`<tool> not found`); `CASS_OFFSITE_MAX_AGE_HOURS` matches `^[1-9][0-9]*$`; then `exec 9>> "$DEST/.offsite.lock"` and `flock -n 9` (`another run holds <lock>`); the configuration, read line by line and never sourced: blank and `#` lines skipped, a trailing CR dropped, each other line `key=value` with the value matching `^[A-Za-z0-9._-]+$`, keys `namespace`, `bucket` and `region` only, all three required (`<config> has no bucket= line`); the recipients file: readable (`no age recipients file at <path>`), no `AGE-SECRET-KEY-` in any case (`<path> holds an age private key: delete the file now, make a new key pair on the PC and put only its age1 public key here`, never echoing the line), every other non-comment line, its trailing CR dropped, matching `^age1[0-9a-z]{58}$`, at least one. Then `mkdir -p "$WORK"`, `chmod 700`, `rm -f "$WORK"/*.age.partial`, and the manifest's `#` header line when the file is new. The rest:
```bash
set -euo pipefail
shopt -s inherit_errexit
umask 0077
# … DEST and the overrides of the contract, DEST made absolute as docker/storage-backup.sh does …
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

# … preflight as above; OCI_ARGS=(--auth instance_principal --region "$REGION" --namespace "$NS" --bucket-name "$BUCKET") …
STEP='listing the backups'
LIST=$(find "$DEST" -maxdepth 1 -type f \( -name 'cass-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]-[0-9][0-9][0-9][0-9].sql.gz' \
    -o -name 'cass-storage-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].tar.gz' \) -printf '%f\n' | LC_ALL=C sort)
NEWEST=$(grep -E '^cass-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{4}\.sql\.gz$' <<< "$LIST" | tail -n 1 || true)
if [ -z "$NEWEST" ]; then problem 5 "no dump in $DEST"
else
    HOURS=$(( ($(date +%s) - $(stat -c %Y "$DEST/$NEWEST")) / 3600 ))
    if [ "$HOURS" -ge "$MAX_AGE_HOURS" ]; then problem 5 "the newest dump, $NEWEST, is $HOURS h old (limit $MAX_AGE_HOURS h)"; fi
fi

while IFS= read -r f; do
    [ -n "$f" ] || continue
    STEP="copying $f"
    # One substitution per assignment: in "a=$(x) b=$(y)" a failing x is masked by y.
    size=$(stat -c %s "$DEST/$f")
    mtime=$(stat -c %Y "$DEST/$f")
    if awk -F '\t' -v f="$f" -v s="$size" -v m="$mtime" '$2 == f && $3 == s && $4 == m { hit = 1 } END { exit !hit }' "$MANIFEST"; then
        ALREADY=$((ALREADY + 1)); continue
    fi
    # The dump's final name is written by a redirect, so a hand run beside cass-backup could
    # see it half written; gzip -t reads it through and writes nothing.
    if ! gzip -t "$DEST/$f"; then problem 2 "$f: not a complete gzip file (still being written, or damaged) - not sent" 1; continue; fi
    case "$f" in cass-storage-*) kind=storage ;; *) kind=db ;; esac
    TMP="$WORK/$f.age.partial"
    if ! "$AGE" --encrypt --recipients-file "$RECIPIENTS" --output "$TMP" "$DEST/$f"; then
        rm -f "$TMP"; problem 2 "$f: age failed" 1; continue
    fi
    if [ "$(head -c 21 "$TMP")" != 'age-encryption.org/v1' ]; then
        rm -f "$TMP"; problem 2 "$f: what age wrote is not an age file - refusing to upload it" 1; continue
    fi
    hash=$(sha256sum "$TMP" | cut -d ' ' -f 1)
    osize=$(stat -c %s "$TMP")
    md5=$(md5_b64 "$TMP")
    object="$kind/$f.$hash.age"
    if ! timeout 1800 "$OCI" os object put "${OCI_ARGS[@]}" --name "$object" --file "$TMP" \
            --content-md5 "$md5" --no-multipart --no-overwrite > /dev/null; then
        rm -f "$TMP"; problem 3 "$object: the upload failed" 1; continue
    fi
    rm -f "$TMP"; TMP=''
    if ! listing=$(timeout 300 "$OCI" os object list "${OCI_ARGS[@]}" --prefix "$object" --fields name,size,md5); then
        problem 4 "$object: could not list it after the upload" 1; continue
    fi
    if ! found=$(listed "$object" <<< "$listing"); then problem 4 "$object: the listing could not be read" 1; continue; fi
    if [ -z "$found" ]; then problem 4 "$object: not in the bucket after the upload" 1; continue; fi
    if [ "$found" != "$osize $md5" ]; then problem 4 "$object: the bucket holds $found (bytes, MD5), expected $osize $md5" 1; continue; fi
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$(date -u +%FT%TZ)" "$f" "$size" "$mtime" "$object" "$osize" "$md5" >> "$MANIFEST"
    UPLOADED=$((UPLOADED + 1)); say "$object ($osize bytes) uploaded and verified"
done <<< "$LIST"

STEP='pinging'
if [ "$RC" -eq 0 ]; then
    if [ ! -f "$PING_FILE" ]; then problem 6 "no ping file at $PING_FILE"
    elif ! curl -fsS -m 10 --retry 3 -o /dev/null -K "$PING_FILE"; then problem 6 "the ping failed"; fi
fi
finish
```
- [ ] **Step 3: Green.** The syntax gate, then `bash tools/backup/cass-backup-offsite.test.sh > /tmp/t-task-2-green.log 2>&1; echo "rc=$?"` → `rc=0` and `all assertions passed`. A red scenario is fixed in the script, never by loosening the harness; a harness defect found here is fixed and named in the report.
- [ ] **Step 4: The harness bites.** Copy the script four times into `$SCRATCH` and make one change in each with the Edit tool. For each, `cd $REPO && CASS_OFFSITE_SCRIPT=$SCRATCH/mutant-N.sh bash tools/backup/cass-backup-offsite.test.sh > /tmp/t-task-2-mutant-N.log 2>&1; echo "rc=$?"` must print `rc=1` with the named scenarios among the failures. M1, the ping no longer waits for `RC` to be 0: S4, S6, S7. M2, the `age-encryption.org/v1` check removed: S5. M3, the size and MD5 comparison removed: S7, S8. M4, `--no-multipart` removed: S1. A mutant that stays green is a hole in the harness: close it there, then run all four again.
- [ ] **Step 5: The gates (N=2), commit.** All six gates exit 0 (`offsite rc=0` at 20 scenarios). Commit the script with the subject `feat(backup): cass-backup-offsite, an encrypted off-host copy of every dump and archive` and the body "Encrypts each new dump and weekly archive with age to the owner's public key, uploads it in one part under a name carrying the SHA-256 of the encrypted bytes, checks its size and MD5 in a listing, records it in offsite-manifest.tsv, and pings its heartbeat only when everything is off-host and the newest dump is under 30 hours old. Nothing in plain text is written outside the backup directory."

### Task 3: CI runs the harness
**Files:** modify `.github/workflows/ci.yml` (Edit tool). A sensitive file: Task 5's pull request lists it.
**Interfaces:** consumes Tasks 1 and 2. Produces the step `Off-host backup script`, which Task 5's PR body and decision 0016 name.

- [ ] **Step 1: The step.** In the `scripts` job, after the two lines of `- name: Deploy script` (fact 2), insert, indented like its neighbours:
```yaml
      # The off-host copy the production host runs as root every night, against fakes for
      # oci, age, curl and flock: what it sends and under which names, that nothing in plain
      # text is written outside the backup directory, and that its heartbeat fires only on
      # success. No network and no OCI account.
      - name: Off-host backup script
        run: bash -n tools/backup/cass-backup-offsite.sh && bash tools/backup/cass-backup-offsite.test.sh
```
- [ ] **Step 2: Check the diff.** `git diff --stat` names `.github/workflows/ci.yml` alone, 6 insertions, 0 deletions; `git diff` shows them between the `Deploy script` and `Guardrail hooks` steps. CI first runs it on Task 5's push.
- [ ] **Step 3: The gates (N=3), commit** with the subject `ci: run the off-host backup harness in the scripts job` and the body "Runs tools/backup/cass-backup-offsite.test.sh after the deploy script's harness: no network, no OCI account, a few seconds."

### Task 4: The runbook
Docs only, with the Edit tool, in `docs/runbooks/deploy-production.md`. Each new subsection stays under 40 lines of procedure; placeholders in angle brackets stay placeholders, because the namespace, the OCIDs, the region and the host's address never go into this public repository (decision 0011).

**Files:** modify `docs/runbooks/deploy-production.md`.
**Interfaces:** consumes the names fixed in Tasks 1 and 2 and the resources of "After merge". Produces "Off-host copies", "Installing the off-host copy" and "Restore drill from the off-host copy", which Task 5's HANDOVER rows and decision cite.

- [ ] **Step 1: The stale lines.** Under `## Backups`, "Spec section 8: nightly `mysqldump`, 14-day rotation, then the existing off-host sync to the NAS." becomes "Spec section 8: nightly `mysqldump` and 14-day rotation. The spec's NAS sync was never connected; an encrypted copy goes to OCI Object Storage instead (**Off-host copies**, decision 0016)." The heading `### Verifying one — monthly, and not optional` becomes `### Verifying one on the host`, and its first paragraph, which ends "which is the point of putting them there.", gains: " The monthly drill restores from the off-host copy instead (**Restore drill from the off-host copy**); this check of the host's own copy is the quick one, after a migration release for instance." In "What is NOT backed up by this", "The NAS sync must include it, or restore is half a restore." becomes "The off-host copy includes it (**Off-host copies**); without it a restore is half a restore."
- [ ] **Step 2: Monitoring.** In its first paragraph, "three dead-man heartbeats" becomes "four dead-man heartbeats"; under "Heartbeats", "Create three, type Push:" becomes "Create four, type Push:". The heartbeat table gains the first row below after `cass weekly archive`, and "When an alert fires" the second after its "nightly dump or weekly archive late" row:
```markdown
| cass offsite copy | 24 hours | 1, 60 minutes | the 04:17 `cass-backup-offsite` run: every finished backup off-host and verified, the newest dump under 30 hours | `/root/.cass-ping-offsite` (**Off-host copies**) |
| offsite copy late | the last `[cass-backup-offsite]` line of `/var/log/cass-backup.log` | its exit code in **Off-host copies** |
```
- [ ] **Step 3: The three subsections**, inserted after the paragraph that ends "`ls -l /srv/backups/cass | head`." and before `## Secret rotation`:
````markdown
### Off-host copies

Every night at 04:17 `cass-backup-offsite` sends each finished dump and storage archive in `/srv/backups/cass` that is not off the host yet to the private OCI Object Storage bucket `cass-offsite`, compartment `cass-backups`, in the host's region (decision 0016). It checks that each file is a whole gzip (`gzip -t`), then encrypts it with `age` to the owner's public key; the private key is never on this host. Nothing in plain text is written outside `/srv/backups/cass`: age reads the backup and writes the encrypted copy to `.offsite-work/` there, removed once sent. An object is named after the file and the SHA-256 of its encrypted bytes, `db/cass-2026-11-06-0317.sql.gz.<64 hex>.age` or `storage/cass-storage-2026-11-01.tar.gz.<64 hex>.age`. After each upload the script lists the object, compares size and MD5, and appends a row to `offsite-manifest.tsv` (time, file, bytes, mtime, object, object bytes, MD5); a file with a row for its size and mtime is not sent again, so a failed night is caught up the next. It pings `cass offsite copy` (**Monitoring**) only when every finished backup is off-host and verified and the newest dump is under 30 hours old. Room in `/srv/backups/cass`: one encrypted copy of the largest backup at a time.

The host signs in as itself, an instance principal, with no key on the host, and may only create objects in that bucket and list them: no reading back, overwriting or deleting. That holds for every process on the host, containers included, since all of them can reach the metadata service that hands out the credential. Uploads are single-part (`--no-multipart`, 50 GiB at most), because a multipart upload needs rights the host does not have.

| In OCI | Name | What it is |
|---|---|---|
| Compartment | `cass-backups` | child of the tenancy root; holds the bucket only |
| Bucket | `cass-offsite` | private, Standard tier, versioning off, no object events, Oracle-managed encryption |
| Retention rule | `keep-30-days` | while it stands, nobody, the owner included, can change or delete an object for 30 days after its upload; not locked, so the owner's credentials can still remove the rule |
| Lifecycle | `delete-after-90-days`, `abort-uploads-after-7-days` | objects deleted 90 days after upload; unfinished multipart uploads aborted after 7 |
| Quota | `cass-backups-cap` | `Set object-storage quota storage-bytes to 10000000000 in compartment cass-backups` |
| Dynamic group | `cass-backup-host` | `Any {instance.id = '<hosting-1's OCID>'}` |
| Policy, tenancy root | `cass-backup-host-writes` | `Allow dynamic-group cass-backup-host to inspect objects in compartment cass-backups where target.bucket.name = 'cass-offsite'` and `Allow dynamic-group cass-backup-host to manage objects in compartment cass-backups where all {target.bucket.name = 'cass-offsite', request.permission = 'OBJECT_CREATE'}` |
| Policy, tenancy root | `cass-backups-lifecycle` | `Allow service objectstorage-<region> to manage object-family in compartment cass-backups where any {request.permission='BUCKET_INSPECT', request.permission='BUCKET_READ', request.permission='OBJECT_INSPECT', request.permission='OBJECT_UPDATE_TIER', request.permission='OBJECT_DELETE', request.permission='OBJECT_VERSION_DELETE'}` |

| Exit | Meaning | Then |
|---|---|---|
| 0 | every finished backup off-host and verified, newest dump under 30 hours, pinged | nothing |
| 1 | a file, tool or setting missing (the line names it), another run holding the lock, or an unexpected failure | fix what the line names; run it by hand |
| 2 | a backup was not a complete gzip, age failed, or its output was not an age file; that file was not sent | a hand run beside `cass-backup`: run it again; otherwise `gzip -t` the file, `age --version`, `/root/.cass-age-recipient` |
| 3 | an upload failed; the CLI's error is above the line | the policy, the network, the quota (`StorageQuotaExceeded`) |
| 4 | an object missing after its upload, or its size or MD5 differs | run it again; twice in a row, tell the engineer |
| 5 | no dump, or the newest older than 30 hours | the nightly dump (**Backups**) |
| 6 | everything copied, but the ping failed or `/root/.cass-ping-offsite` is missing | that file, then Kuma |

One file's failure does not stop the others; the exit is the first failure's. Every run ends with one line, `[cass-backup-offsite] OK: …` or `[cass-backup-offsite] FAILED (exit N): …`, in `/var/log/cass-backup.log`. After a hand-run `cass-backup` (a migration release), `sudo /usr/local/bin/cass-backup-offsite` sends that dump at once. Overrides, for tests: `CASS_OFFSITE_CONFIG`, `CASS_OFFSITE_RECIPIENTS`, `CASS_OFFSITE_PING_FILE`, `CASS_OCI_BIN`, `CASS_AGE_BIN`, `CASS_OFFSITE_MAX_AGE_HOURS` (default 30). The weekly archives are full copies and thirteen are kept: watch the bucket's size against the quota.

### Installing the off-host copy

Once, with the owner, after the OCI resources above exist and the dynamic group is an hour old. `age` comes from Ubuntu's archive and follows its security updates; the age file format is frozen, so an update cannot strand a copy. The OCI CLI goes into its own virtual environment with pip, pinned with `==`, rather than through Oracle's install script, which is fetched unpinned from GitHub at run time to do the same pip install; Ubuntu's Python refuses a pip install outside a virtual environment anyway. Simulate the install first; if it would upgrade anything beyond these packages and their own new dependencies, stop.

```bash
sudo apt-get -s install age python3-venv | grep '^Inst'
sudo NEEDRESTART_MODE=l apt-get install -y age python3-venv && age --version
sudo python3 -m venv /opt/oci-cli && sudo /opt/oci-cli/bin/pip install --no-cache-dir 'oci-cli==3.96.0'
sudo chmod 700 /opt/oci-cli && sudo /opt/oci-cli/bin/oci --version          # 3.96.0
for f in .cass-offsite.conf .cass-age-recipient .cass-ping-offsite; do sudo install -m 600 /dev/null /root/$f; done
```

An Ubuntu older than 22.04 has no `age` package: take the release instead, `age-v1.3.2-linux-arm64.tar.gz` from GitHub, check `sha256sum` against `6b8dc4333c53a5a57c9e5834e3a48f92605d7154014cd07269ff3327db5d37f4`, put `age` in `/usr/local/bin`, and add `CASS_AGE_BIN=/usr/local/bin/age` to the cron line, whose PATH lacks that folder. Then `sudoedit` each file: `/root/.cass-offsite.conf` gets three lines, `namespace=<namespace>`, `bucket=cass-offsite`, `region=<region>`; `/root/.cass-age-recipient` the owner's public key, one line starting `age1`; `/root/.cass-ping-offsite` one line, `url = "<the push URL of cass offsite copy>"`, which the owner pastes. Then prove the instance's rights: create and list work; read, delete and overwrite are refused.

```bash
O='sudo /opt/oci-cli/bin/oci --auth instance_principal'; A='-ns <namespace> -bn cass-offsite'; K=canary/$(date +%F).txt
printf 'canary\n' | sudo tee /root/cass-canary.txt > /dev/null
$O os object put $A --name "$K" --file /root/cass-canary.txt --no-multipart --no-overwrite   # works
$O os object list $A --prefix canary/ --fields name,size                                   # lists it
$O os object get $A --name "$K" --file /dev/null                                           # 404 NotAuthorizedOrNotFound
$O os object delete $A --name "$K" --force                                                 # 404 NotAuthorizedOrNotFound
$O os object put $A --name "$K" --file /root/cass-canary.txt --no-multipart --force        # refused
sudo rm /root/cass-canary.txt
```

Any other answer means the policy is wrong: stop. Then the script from `main`, from the PC, with both ends' SHA-256 compared (the address is in the project memory):

```bash
git fetch origin && git show origin/main:tools/backup/cass-backup-offsite.sh > /tmp/cass-backup-offsite && sha256sum /tmp/cass-backup-offsite
ssh -i ~/.ssh/<key> <user>@<host> 'sudo tee /usr/local/bin/cass-backup-offsite > /dev/null && sudo chmod 755 /usr/local/bin/cass-backup-offsite && sha256sum /usr/local/bin/cass-backup-offsite' < /tmp/cass-backup-offsite
```

On the host, run `sudo /usr/local/bin/cass-backup-offsite` once and read every line (the first run sends every dump and archive the host holds), then add the cron line beside the other two (`sudo crontab -e`). Cron's PATH, `/usr/bin:/bin`, holds `age`, `curl`, `flock` and `python3`; the CLI is called by its full path.

```cron
# CASS off-host copy, nightly at 04:17: an hour after the 03:17 dump, half an hour after Sunday's 03:47 archive.
17 4 * * * /usr/local/bin/cass-backup-offsite >> /var/log/cass-backup.log 2>&1
```

Prove the alert as for the other monitors: two down pushes, `for i in 1 2; do sudo sh -c 'curl -fsS -m 10 "$(sed -n "s/^url = \"\(.*\)\"$/\1/p" /root/.cass-ping-offsite | sed s/status=up/status=down/)"'; echo; done`, take the monitor down and Kuma emails; the next run by hand brings it back. To update the CLI, repeat its two lines with the new version; to update the script, the two lines from the PC.

### Restore drill from the off-host copy — monthly, and not optional

The owner, on the PC, in Git Bash with Docker Desktop running; never in a Claude session, and in a folder outside every repository. It restores the newest dump **in the bucket**, not the host's, into a throwaway MySQL with no network, and compares row counts with production taken as **Verifying one on the host** takes them. The decrypted dump exists only in the pipe and in that container; the private key is pasted at a prompt and never written to disk. Once per PC: the OCI CLI, `python -m venv /c/Users/khawl/Desktop/Ahmed/claude/tools/oci-cli` and `/c/Users/khawl/Desktop/Ahmed/claude/tools/oci-cli/Scripts/pip install 'oci-cli==3.96.0'`, with your own user's API key in `~/.oci/config` (`oci setup config`). Without it, download the two newest objects from the OCI Console (Storage, Buckets, `cass-offsite`, the object's menu, Download) into `~/cass-drill` and skip the `"$OCI"` lines.

```bash
set -o pipefail; T=/c/Users/khawl/Desktop/Ahmed/claude/tools; OCI=$T/oci-cli/Scripts/oci.exe; AGE=$T/age/age.exe
mkdir -p ~/cass-drill && cd ~/cass-drill && NS=$("$OCI" os ns get --query data --raw-output) && B=cass-offsite
OBJ=$("$OCI" os object list -ns "$NS" -bn "$B" --prefix db/ --all --fields name,timeCreated --query 'max_by(data, &"time-created").name' --raw-output)
"$OCI" os object get -ns "$NS" -bn "$B" --name "$OBJ" --file newest.age
echo "$OBJ"; sha256sum newest.age                        # the same 64 hex digits as in the name
IMG=$(grep -oE 'mysql:8\.4@sha256:[0-9a-f]{64}' /c/Users/khawl/Desktop/Ahmed/claude/projects/CASS/docker-compose.production.yml)
docker run -d --name cass-drill --network none -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=cass_restore "$IMG"
until docker exec cass-drill mysql -h127.0.0.1 -uroot -e 'SELECT 1' > /dev/null 2>&1; do sleep 3; done
read -rs -p 'AGE-SECRET-KEY line: ' KEY; echo
printf '%s\n' "$KEY" | "$AGE" -d -i - newest.age | gzip -dc | tail -n 1           # -- Dump completed on <date> <time>
printf '%s\n' "$KEY" | "$AGE" -d -i - newest.age | gzip -dc | docker exec -i cass-drill mysql -uroot cass_restore; echo "load rc=$?"
for t in organizations users conferences submissions submission_files reviews review_assignments email_logs; do
  echo "$t $(docker exec cass-drill mysql -uroot -N -e "SELECT COUNT(*) FROM cass_restore.$t")"; done
SOBJ=$("$OCI" os object list -ns "$NS" -bn "$B" --prefix storage/ --all --fields name,timeCreated --query 'max_by(data, &"time-created").name' --raw-output)
"$OCI" os object get -ns "$NS" -bn "$B" --name "$SOBJ" --file storage.age
printf '%s\n' "$KEY" | "$AGE" -d -i - storage.age | tar tzf - | wc -l            # entries in the newest archive
unset KEY; docker rm -f -v cass-drill; rm -f newest.age storage.age
```

Then on the host, with `cass_container` pasted: the cut-off is the completion time above minus five minutes, in UTC (`date -u -d '<date> <time> 5 minutes ago' '+%F %T'` on the PC), and the archive's entry count is in the log.

```bash
C=$(cass_container mysql); CUT='<cut-off>'
for T in organizations users conferences submissions submission_files reviews review_assignments email_logs; do
  sudo docker exec "$C" sh -c "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" mysql -uroot -N -e \
    'SELECT \"$T\", (SELECT COUNT(*) FROM cass.$T WHERE created_at < \"$CUT\"), (SELECT COUNT(*) FROM cass.$T);'"
done
sudo grep '^\[cass-storage-backup\] /srv' /var/log/cass-backup.log | tail -n 1
```

Tick each box; one left empty is a failed drill.
- [ ] The object is at most a day old by the date in its name, and `sha256sum` printed the 64 hex digits in its name.
- [ ] age printed no error, the last line read `-- Dump completed on …`, and `load rc=0` with no MySQL error.
- [ ] Every table: restored ≥ the host's first number (rows that existed five minutes before the dump finished). Above it only for a reason you can name: rows deleted since the dump (an organization purged, `cass:demo-reset`, a reviewer unassigned, email logs past retention). The second number is production now, for scale.
- [ ] The archive's entry count equals the one in the host's log line for that archive's date.
- [ ] The container and both downloads are gone, `KEY` unset, the terminal closed.

A restored count below the host's first number, a missing table, an age or MySQL error or a SHA-256 that differs from the name: do not wait a month; see **Off-host copies** and tell the engineer. The session writes the date, the object, the result and the next date into `docs/HANDOVER.md`'s Backups row.
````
- [ ] **Step 4: The gates (N=4), commit.** `git status --short` lists the runbook only; all six gates exit 0 (no code changed). Commit with the subject `docs: runbook off-host copies, their install and the monthly restore drill`.

### Task 5: The record, the final gates and the pull request
**Files:** create `docs/decisions/0016-offsite-backups.md` (Write tool); modify `docs/HANDOVER.md`, `docs/superpowers/plans/backlog.md`, `docs/launch-checklist.md` (Edit tool).
**Interfaces:** consumes every name above and the number `gh` prints. Produces the owner's next steps in `docs/HANDOVER.md`.

- [ ] **Step 1: The decision**, in the format of `docs/decisions/README.md`:
```markdown
# Off-host backups: age-encrypted copies in a create-only OCI bucket, restored monthly

- **Context.** Every dump and storage archive lived only on the host's disk, `/srv/backups/cass`, so losing or imaging the host lost or exposed them all (`docs/reviews/2026-10-16-expert-brief.md`, B8 and question 4). The spec's NAS sync was never connected.
- **Decision.** The owner's, on 2026-11-06: a private OCI Object Storage bucket in the owner's own tenancy; the host signs in as an instance principal through the OCI CLI, installed for root, with no API key on the host; every file is encrypted with `age` to the owner's public key before it leaves, and the private key stays off the host, in a password manager and on paper; a monthly restore drill from that copy on the owner's PC. With Plan 10's summary the owner accepted: `cass-backup-offsite` on its own cron line at 04:17, after both backups, rather than at the end of `cass-backup`, which the release script also runs before a migration, so an OCI failure neither stops a release nor blurs the nightly-dump heartbeat, and each run catches up on what is not yet copied; the script in `tools/backup/`, installed from `main` with both ends' SHA-256 compared, so it needs no deploy; a policy letting the host create objects and list them in that one bucket, hence single-part uploads (a multipart upload needs `OBJECT_READ` and `OBJECT_OVERWRITE`) and names carrying the SHA-256 of the encrypted bytes, so nothing is ever overwritten; a 30-day retention rule, unlocked, instead of versioning (OCI allows one or the other, and the host can neither overwrite nor delete anyway), a lifecycle deleting objects 90 days after upload and a 10 GB quota on the compartment; `age` from Ubuntu's archive on the host and v1.3.2, checked against GitHub's digest, on the PC; the OCI CLI pinned to 3.96.0 in a root-only virtual environment; the heartbeat pinged from inside the script, so CI tests it, only when every finished backup is off-host and verified and the newest dump is under 30 hours old.
- **Consequence.** The copies share the host's tenancy and region: closing or suspending the account, or a region-wide outage, takes them with the host, and the retention rule only stops deletion within 30 days. Every process on the host, containers included, can use the instance principal through the metadata service, so the bucket policy is the only fence: a compromised container could add objects and read names and sizes, never contents, and never delete. Losing both copies of the private key makes every copy unreadable; each drill proves one copy. One recipient only: a second key pair would survive losing the first, at the price of a second secret. The weekly archives are full copies, thirteen kept off-host.
- **To reverse.** Remove the 04:17 cron line, `/usr/local/bin/cass-backup-offsite`, `/opt/oci-cli` and the three root-only files from the host; delete the policies `cass-backup-host-writes` and `cass-backups-lifecycle`, the dynamic group `cass-backup-host` and the quota `cass-backups-cap`; let the lifecycle empty the bucket, then delete it and the compartment; delete `tools/backup/` and the `Off-host backup script` step in `.github/workflows/ci.yml`.
```
- [ ] **Step 2: Backlog and checklist.** In `backlog.md` ("Launch"), the entry on the nightly dump's location ends "The off-host copy (Plan 10, `docs/decisions/0016-offsite-backups.md`) covers that host path, the dumps and the weekly `cass-storage` tarball alike." instead of its sentence about the NAS sync. Insert the first block below before `## Open questions for the owner (raised by Plans 7 and 8)`; that heading becomes `## Open questions for the owner (raised by Plans 7, 8 and 10)`, and the second block goes at the end of its list. In `launch-checklist.md` section 2, the backup item's "first so a truncated `mysqldump` cannot hide inside a pipe), and that the NAS sync includes it **and** the weekly `cass-storage` tarball." becomes "first so a truncated `mysqldump` cannot hide inside a pipe) and one encrypted copy of the largest backup, and that the off-host copy (runbook **Off-host copies**) includes the dumps **and** the weekly `cass-storage` tarball."; in section 3, after the item "Restore that dump into a scratch database …", add "- [ ] Restore the newest **off-host** copy on the PC and compare its counts with production (runbook **Restore drill from the off-host copy**); its date and the next one go into `docs/HANDOVER.md`." wrapped like its neighbours.
```markdown
## Backups (deferred by Plan 10)

- **Every container on the host can use the instance principal.** The metadata service answers any process on the instance, so the bucket policy (create and list, one bucket) is the only fence. A `DOCKER-USER` rule dropping traffic from the container networks to 169.254.169.254 would leave it to host processes; check first that Coolify, Traefik and Kuma never call it. A host firewall change, for B3's session (`docs/decisions/0016-offsite-backups.md`).
- **The drill is commands, not a script.** If a month's drill goes wrong on a typo: `tools/backup/restore-drill.sh` (download, compare the name's SHA-256, decrypt from a prompt, restore with no network, count), with fakes like the host script's.
- **Weekly archives are full copies.** Thirteen stay off-host for 90 days; at 500 MB each that is 6.5 GB of the 10 GB quota. Before then, a shorter lifecycle for `storage/` or incremental archives.
- **The CLI and age are updated by hand.** The OCI CLI is pinned to 3.96.0 in `/opt/oci-cli`; age follows Ubuntu's package. Look at both at every third drill.
- **`tools/` is copied into the image.** `.dockerignore` lacks it, so `tools/claude/` and `tools/backup/` sit unused under `/var/www/html/tools`. One line in `.dockerignore`, with an image build.
```
```markdown
- **Lock the off-host bucket's retention rule?** (Plan 10.) It ships unlocked: the owner's credentials can still remove the rule, then the copies. A lock applies after 14 days and is irreversible. Recommended after three good drills.
- **The off-host copies in the host's region, or in the other Saudi region?** They ship in the host's; a second region survives a region-wide outage and still keeps the data in the country.
- **A third copy outside OCI?** None ships; each month's encrypted drill download, kept on an external drive, would survive losing the tenancy.
- **Fence the containers off the metadata service?** It ships open (see "Backups (deferred by Plan 10)").
- **Add `tools/backup/` to the sensitive paths in `CLAUDE.md`?** It ships unlisted, though the script runs as root on production.
```
- [ ] **Step 3: HANDOVER, with `#NN` for the pull request.** The Backups row gains, before its closing `|`, " Nothing is off the host yet: the off-host copy is in #NN, not installed (B8)." The B8 Status cell becomes: "Engineer part done (#NN): `tools/backup/cass-backup-offsite.sh`, nightly at 04:17 from its own root cron line, encrypts each new dump and weekly archive with age to the owner's public key and uploads it to a private, create-only OCI Object Storage bucket, verified, recorded and with its own heartbeat; tested in CI's `scripts` job against fakes; runbook "Off-host copies", "Installing the off-host copy" and "Restore drill from the off-host copy"; decision 0016. Owner next: Plan 10's three owner steps (the age key pair, the private key in the password manager and on paper, the Kuma push monitor `cass offsite copy`); then, with the session, Plan 10's "After merge": the bucket, quota, dynamic group and policies, the host install and first run, the first drill. Done when that drill matches production and its date and the next one are in the Backups row." In the paragraph after the phase-B table, "B1, B3, B4, B8, B12 and B13 not started" becomes "B8's engineer part is in #NN, its owner part open; B1, B3, B4, B12 and B13 not started". Under "How the work is done", "Plans 1–9 and the backlog" becomes "Plans 1–10 and the backlog".
- [ ] **Step 4: The final gates (N=5), commit** the four docs with the subject `docs: decision 0016, the backups backlog, HANDOVER for plan 10` (the number follows in Step 6).
- [ ] **Step 5: Push and open the pull request.** Write `C:\Users\khawl\AppData\Local\Temp\pr-plan10.md` (Write tool) with the body below, then:
```bash
cd $REPO && git push -u origin plan-10-b8-offsite-backups > /tmp/push-task-5.log 2>&1; echo "push rc=$?"
gh pr create --base main --head plan-10-b8-offsite-backups --title "Plan 10: off-host encrypted backups and the restore drill (B8)" --body-file /tmp/pr-plan10.md > /tmp/pr-task-5.log 2>&1; echo "pr rc=$?"
```
```markdown
**Summary.** B8's engineer part. `tools/backup/cass-backup-offsite.sh`, run on the production host by its own root cron line at 04:17, encrypts each new dump and weekly archive with age to the owner's public key, uploads it in one part to a create-only OCI Object Storage bucket under a name carrying the SHA-256 of the encrypted bytes, checks its size and MD5 in a listing, records it, and pings the Kuma monitor `cass offsite copy` only when everything is off-host and the newest dump is under 30 hours old. `tools/backup/cass-backup-offsite.test.sh` runs it against fakes for oci, age, curl and flock in CI's scripts job: 20 scenarios, each followed by six checks, among them that no plain text is written outside the backup directory and that the ping fires only on success. Runbook: Off-host copies, Installing the off-host copy, Restore drill from the off-host copy. Decision 0016. Nothing in the application or the image changes; no deploy is needed.

**Sensitive files.**
- .github/workflows/ci.yml: one step in the scripts job runs the new harness (and this pull request builds the arm64 image).
- Also worth a look, though not on the list: tools/backup/cass-backup-offsite.sh runs as root on the production host with the instance's OCI rights.

**Migrations.** None.

**Environment variables.** None in the application; the host's three root-only files are in the runbook.

**Docs changed.** docs/runbooks/deploy-production.md (Backups, Monitoring), docs/HANDOVER.md, docs/decisions/0016-offsite-backups.md (new), docs/superpowers/plans/backlog.md, docs/launch-checklist.md.

**Gate logs.** /tmp/offsite-task-2.log to /tmp/offsite-task-5.log, /tmp/t-task-1.log to /tmp/t-task-5.log, /tmp/phpstan-task-5.log, /tmp/pint-task-5.log, all rc=0; red: /tmp/t-task-1-red.log; mutants: /tmp/t-task-2-mutant-1.log to -4.log, each rc=1 as intended.

**Tests added.** tools/backup/cass-backup-offsite.test.sh (20 scenarios); no PHP test, the suite unchanged at the baseline.

**Anything skipped.** The OCI resources, the host install and the first drill come after merge, with the owner (Plan 10, "After merge"); the five open questions are in the backlog.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```
- [ ] **Step 6: The number.** Replace every `#NN` in `docs/HANDOVER.md` with the number `gh` printed, and rewrite the "Open pull requests" row from `gh pr list --state open` at that moment: this pull request first ("#N (Plan 10, B8 off-host backups; awaiting the owner's merge)."), then one clause per other open pull request. Commit with `docs: HANDOVER records plan 10 (#N)` and push. Then watch CI in the background, the arm64 `image` job included (about half an hour): `cd $REPO && gh pr checks <N> --watch --interval 60 > /tmp/ci-task-5.log 2>&1; echo "ci rc=$?"`. On a red job: `cd $REPO && RUN=$(gh run list --branch plan-10-b8-offsite-backups --limit 1 --json databaseId --jq '.[0].databaseId') && gh run view "$RUN" --log-failed > /tmp/ci-failed-task-5.log 2>&1; echo "log rc=$?"`, read it with `tail -n 80`, and report it rather than retrying.

## After merge: with the owner

Nothing here runs from the repository, and no deploy is involved; still, not in the 48 hours before an open conference's deadline. The owner unlocks production for the session (`touch .claude/deploy-unlocked`) and stays for each part. Print only allow-listed fields of every OCI answer (name, id, lifecycle state, statements). The namespace, the OCIDs, the region identifier and the host's address go into the project memory, never into a file in this repository.

**A. In OCI**, with the `oracle-oci` tools (`invoke_oci_api`), in this order; any time after the owner approves this plan, before or after the merge.
1. Read the tenancy OCID; the instance `hosting-1` (`oci.core.ComputeClient.list_instances`: its `id` and region); the namespace (`oci.object_storage.ObjectStorageClient.get_namespace`); the tenancy's policies (`oci.identity.IdentityClient.list_policies`, compartment = tenancy), for one that already lets `objectstorage-<region>` manage object-family there.
2. `IdentityClient.create_compartment`: parent the tenancy, name `cass-backups`, description "CASS off-host backups (decision 0016)"; wait for ACTIVE.
3. `oci.limits.QuotasClient.create_quota`: compartment the tenancy, name `cass-backups-cap`, statements `["Set object-storage quota storage-bytes to 10000000000 in compartment cass-backups"]`.
4. `ObjectStorageClient.create_bucket`: name `cass-offsite`, compartment `cass-backups`, `public_access_type` NoPublicAccess, `storage_tier` Standard, `versioning` Disabled, `object_events_enabled` false, `auto_tiering` Disabled, no KMS key.
5. `ObjectStorageClient.create_retention_rule` on `cass-offsite`: `display_name` keep-30-days, `duration` {`time_amount` 30, `time_unit` DAYS}, no `time_rule_locked` (question 1).
6. Unless step 1 found one: `IdentityClient.create_policy`, compartment the tenancy, name `cass-backups-lifecycle`, the service statement of runbook "Off-host copies" with this region's identifier.
7. `ObjectStorageClient.put_object_lifecycle_policy` on `cass-offsite`, items `{name delete-after-90-days, target objects, action DELETE, time_amount 90, time_unit DAYS, is_enabled true}` and `{name abort-uploads-after-7-days, target multipart-uploads, action ABORT, time_amount 7, time_unit DAYS, is_enabled true}`. A refusal naming the service's permissions means step 6 has not taken effect yet: wait a few minutes and repeat.
8. `IdentityClient.create_dynamic_group`: compartment the tenancy, name `cass-backup-host`, matching rule `Any {instance.id = '<hosting-1 OCID>'}`. If the tenancy's identity domain refuses that call, `oci.identity_domains.IdentityDomainsClient.create_dynamic_resource_group` on the Default domain's endpoint, with the same name and rule.
9. `IdentityClient.create_policy`: compartment the tenancy, name `cass-backup-host-writes`, the two statements of runbook "Off-host copies". Nothing else: no user, group, API key, auth token, pre-authenticated request, public access, replication or customer-managed key.
10. Read each one back and record its name and OCID in the project memory. The dynamic group applies within about an hour.

**B. On the host**, an hour after A.8: runbook "Installing the off-host copy", end to end. The session writes `/root/.cass-offsite.conf` and `/root/.cass-age-recipient` (owner step 2's public key) with `sudo tee` into the files the install made 0600; the owner writes `/root/.cass-ping-offsite` with `sudoedit` in their own terminal (owner step 3's URL), so the URL never passes through the session. The canary's five answers must be as the runbook states, or stop. Record in the project memory: `lsb_release -ds`, `timedatectl show -p Timezone --value`, `age --version`, the CLI's version and the first run's last line. Then the alert proof.

**C. The first drill**, on the owner's PC, the day after B, so that the newest copy came from the cron line rather than the hand run: runbook "Restore drill from the off-host copy". This once, type the key from the paper copy instead of pasting it: the key carries a checksum, so a clean decrypt proves the paper too.

**D. The record**, a docs-only pull request: the Backups row's off-host sentence becomes "Off-host copy since <date>: `cass-backup-offsite` nightly at 04:17 to `cass-offsite` (runbook "Off-host copies"); the first run sent <n> dumps and <m> archives. Restore drill from the off-host copy on <date>: <object>, every table at or above the host's count, the archive's entries matched; next drill <date a month on>."; the Monitoring row says four push monitors, `cass offsite copy` among them; B8 becomes "Done <date>" with that drill; launch checklist section 3's off-host item ticked with the date. That is B8's "Done when".

## Verification

- After Task 5 the six gates exit 0; the harness passes on this PC and in CI's `scripts` job; each of the four mutants turned it red (`/tmp/t-task-2-mutant-1.log` to `-4.log`), and `/tmp/t-task-1-red.log` shows every scenario red before the script existed.
- CI is green on the pull request, the arm64 `image` job included (the workflow changed).
- The pull request lists the sensitive file, no migration, no variable, the docs and the gate logs, for review point 2.
- After merge, outside CI: the canary's five answers, the first run's `OK` line, Kuma's first push and alert, and the first drill's ticks (part D).

## Deferred

- `docs/superpowers/plans/backlog.md`, "Backups (deferred by Plan 10)": the metadata-service fence, a drill script, incremental archives, hand updates of the CLI and age, `tools/` in `.dockerignore` (Task 5, Step 2).
- The five open questions of the owner summary, recorded in the backlog: the retention lock, the region, a copy outside OCI, the container fence, `tools/backup/` as a sensitive path.
