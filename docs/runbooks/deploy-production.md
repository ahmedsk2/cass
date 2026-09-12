# Deploy CASS to production

Host address, SSH user and key path live in the owner's private ops notes, not in this repository.

Host: OCI `hosting-1`, `<ssh-user>@<origin-ip>` (key `<ssh-key>`), Coolify + Traefik, Cloudflare in front.
App URL: https://cass.towardpcc.com. Repo: https://github.com/ahmedsk2/cass, branch `main`.

## One-time setup

1. DNS (Cloudflare, owner): A record `cass` -> `<origin-ip>`, proxied (orange cloud).
2. Coolify: New resource -> Docker Compose -> public repository `https://github.com/ahmedsk2/cass`, branch `main`, compose file `docker-compose.production.yml`, build pack Docker Compose.
3. Coolify domain for the `app` service: `https://cass.towardpcc.com:8080`.
4. Environment variables in Coolify (all required unless marked optional): `APP_KEY` (generate locally with `php artisan key:generate --show`), `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` (`tls`), `MAIL_FROM_ADDRESS`, `CASS_CONTACT_EMAIL`, `CASS_ADMIN_EMAIL`, `CASS_ADMIN_PASSWORD` (12+ characters), optional `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY`.
5. Deploy from the Coolify UI. Wait for the `app` container to report healthy.
6. First migration and admin user (owner runs, never at boot):
   ```bash
   ssh -i <ssh-key> <ssh-user>@<origin-ip>
   C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
   sudo docker exec -it "$C" su-exec app php artisan migrate --force
   sudo docker exec -it "$C" su-exec app php artisan db:seed --force
   ```
   If `$C` is empty, use the Coolify UI terminal for the `app` service instead.
7. Log in at https://cass.towardpcc.com/admin/login, change the admin password under Profile, enable two-factor authentication.

## Every release

1. Merge to `main` with CI green.
2. Coolify: Deploy (or enable auto-deploy on push).
3. If the release adds migrations, apply them as soon as Coolify reports the new `app` container healthy. The migration files exist only in the new image, and Coolify's Docker Compose deploys stop the old container before the new one starts (no rolling update), so there is no moment at which the old container could run them — `migrate` there just prints "Nothing to migrate". Turn auto-deploy off for such a release so you are at the terminal when the new container goes live. Run the migration only, **not** step 6's `db:seed`: the seeder resets the platform admin's password back to `CASS_ADMIN_PASSWORD`.

   ```bash
   C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
   sudo docker exec -it "$C" su-exec app php artisan migrate --force
   sudo docker exec -it "$C" su-exec app php artisan migrate:status
   ```

   Nothing should be Pending. Plan 2 is such a release: it adds seven tables (`conferences`, `tracks`, `custom_fields`, `review_forms`, `review_questions`, `short_links`, `short_link_visits`), and until they exist every organizer dashboard (the Conferences widget queries `conferences`) and every `/org/{tenant}/conferences`, `/c/...` and `/q/...` page returns 500.

   Plan 3 is such a release: it adds five tables (`submissions`, `submission_authors`, `submission_files`, `email_templates`, `email_logs`) and two columns on `conferences` (`reference_prefix`, `submission_counter`). Until they exist, `/c/{org}/{conference}/submit`, `/s/{token}`, `/files/{ulid}`, the organizer submission list and the admin email log all return 500, **and every outgoing email fails**, because the mail listener writes an `email_logs` row before the message is sent.

   Plan 5 is such a release: it adds six columns to `submissions` (`score`,
   `score_spread`, `review_count`, `scored_at`, `decision`,
   `decision_notified_at`), one to `reviews` (`score`) and one table
   (`submission_decisions`). Until they exist, three screens that worked before
   the deploy return 500: the new ranking page; the organizer **conference view**
   of any conference in Reviewing, Decisions sent or Archived, whose Decisions
   section calls `Conference::decisionCounts()` (the section is `->visible()`-gated
   to those three statuses, so a conference in Draft or Open is unaffected); and
   the admin conference list `/admin/conferences`, whose two new count columns
   query `decision` and `decision_notified_at` for every row on every render,
   regardless of status. `/s/{token}` is the one thing that degrades quietly - a
   missing `decision_notified_at` reads as null and the page shows its neutral
   "no decision yet" block. Turn auto-deploy off for this release and run
   `migrate --force` the moment the new container is healthy, then run the
   `cass:rescore` backfill described under "Scoring, ranking and decisions".
4. Check https://cass.towardpcc.com/up returns 200, then open the landing page and `/org/login`. **`/org/login` must be styled**: this release is the first image that runs `filament:assets` and publishes Livewire's script, so `/css/filament/filament/app.css` and `/vendor/livewire/livewire.min.js` should both return 200. Cloudflare may still be serving the old 404s — purge `/css/filament/*`, `/js/filament/*`, `/fonts/filament/*` and `/vendor/livewire/*` if so.
5. Time the public conference page after the deploy. Spec section 10 gives it a 300 ms server budget, which is the reason it is plain Blade instead of Livewire.

   ```bash
   C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
   for U in "/c/<org>/<conference>" "/c/<org>/<conference>/submit"; do
     echo "$U"
     for i in 1 2 3 4 5; do
       sudo docker exec "$C" php -r '$c = stream_context_create(["http" => ["header" => "Host: cass.towardpcc.com\r\n"]]); $t = microtime(true); file_get_contents("http://127.0.0.1:8080".$argv[1], false, $c); printf("%.3f\n", microtime(true) - $t);' "$U"
     done
   done
   ```

   The same 0.300 s median for both. Spec section 10 puts the submission form on
   the same budget as the conference page, and it is the one page on that budget
   that is Livewire rather than plain Blade, so it is the one to re-measure after
   every change to the form.

   The first run after a deploy warms OPcache and may be slower. The `Host` header is required because `TrustHosts` only accepts the `APP_URL` host, and the probe runs *inside* the container because the compose file publishes no ports on the host.

## Poster PDF fonts

The poster PDF is rendered by dompdf using three IBM Plex TTFs committed at `resources/fonts/` (Sans regular, Sans semibold, Mono semibold for the short URL). dompdf converts them to its own metrics format on first use and caches the result in `storage/fonts/`, which must be writable by the `app` user — the Dockerfile creates it and chowns it. Nothing is downloaded at runtime.

If a poster download returns a 500, look for `Failed to open stream: Permission denied` on a path under `/var/www/html/storage/fonts/`. dompdf's font library writes the metrics file first, so the usual line is `fopen(/var/www/html/storage/fonts/ibm_plex_sans_normal_<hash>.ufm): Failed to open stream: Permission denied`; if the directory itself is missing it is `mkdir(): Permission denied`. The directory lives in the container layer, not on a volume (`cass-storage` is mounted at `storage/app` only), so a redeploy recreates it with the right owner. To repair the running container:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec -it "$C" sh -c 'mkdir -p storage/fonts && chown -R app:app storage/fonts'
```

## Cloudflare Turnstile

The public submission form carries a Turnstile widget only when **both**
`TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` are set in the Coolify
environment. With neither set, the form still has its honeypot, its four-second
minimum fill time and its 5/min/IP throttle, and no request is made to
Cloudflare.

To turn it on: Cloudflare dashboard → Turnstile → Add widget, hostname
`cass.towardpcc.com`, mode Managed. Copy the site key and the secret key into
Coolify, redeploy, and open the form as a logged-out visitor — the widget should
render above the "Submit abstract" button.

Verification **fails open** when Cloudflare itself is unreachable: a connection
error is logged at `warning` with the message "Turnstile verification could not
reach Cloudflare" and the submission is allowed. An explicit `success: false`
from Cloudflare, or a 5xx from it, is still a refusal. That choice is
deliberate — an outage at Cloudflare in the last hour before a deadline would
otherwise refuse every abstract — so if the log fills with that warning, treat
it as an incident rather than as noise.

## Author uploads and private storage

Submission files live on the `local` disk, whose root is
`storage/app/private`. `docker-compose.production.yml` mounts the named volume
`cass-storage` at `/var/www/html/storage/app`, so uploads are already inside it
and survive a redeploy. Nothing else needs mounting, and **nothing serves that
directory**: files are reachable only through `/files/{ulid}`, which requires a
signature that expires after `CASS_FILE_URL_MINUTES` (30).

Paths are content-addressed: `{first two characters of the sha256}/{ulid}.{ext}`.
Two submissions holding identical bytes still own separate objects, so deleting
one never breaks the other.

To see how much the volume holds:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" du -sh storage/app/private
sudo docker exec "$C" sh -c 'find storage/app/private -type f | wc -l'
```

Back it up with the database, not separately: a file with no row is unreachable
and a row with no file is a broken link, so the two have to be restored from the
same moment.

The request-body ceilings are `client_max_body_size 110m` (nginx) and
`post_max_size=110M` (PHP), sized for the maximum ten files at 10 MB a
conference may allow in **one** Livewire upload POST — Livewire sends every file
of a `multiple` input in a single request. They live in the image, so raising a
conference's `max_files` above 10 would need a rebuild, not just a setting.

## Author status links

The 64 characters after `/s/` are a bearer credential: anyone holding them can
edit or withdraw that abstract until the deadline. nginx logs that path
redacted, **and the `Referer` header with it**: `App\Http\Middleware\SecurityHeaders`
sets `Referrer-Policy: strict-origin-when-cross-origin`, so every same-origin
request the status page makes — each `/files/{ulid}` click, each
`/livewire/update` POST — sends the whole status-page URL in `Referer`. Both go
through a redacting `map` in the `cass` log_format in `docker/nginx.conf`; an
ordinary referer is still logged verbatim. **Cloudflare still sees the full
URI** — do not enable Logpush for this zone without a transform rule that
strips it, and never paste a `/s/...` URL into a ticket. To take a leaked link
out of circulation, open the submission in the organizer panel and use **Resend
status link**, which rotates the token and kills the old one.

**One residual the redaction cannot cover: the error log.** `error_log
/dev/stderr warn` has no configurable format — nginx writes its own, and it
includes `request: "GET /s/<token> HTTP/1.1"` verbatim. Any status-page request
that ends in 413, 499, 502 or 504 therefore puts the whole token into the
Coolify log stream, redaction or no redaction. There is no nginx directive that
changes this; the options are to accept it (these statuses are rare and the
stream is not public) or to raise `error_log` to `crit`, which would also hide
the upstream failures triage needs. It is accepted, and written down here so the
next person reading a 502 in the log knows what is in front of them: a token
seen in an error line is a token to rotate with **Resend status link**.

`tests/Feature/AccessLogRedactionTest.php` reads `docker/nginx.conf` and fails
if that redaction is ever removed, but it does not run nginx. Syntax-check the
file after every edit to it:

```bash
cd /c/Users/ahmed/Documents/CASS && MSYS_NO_PATHCONV=1 docker run --rm \
  -v "$(pwd)/docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro" \
  nginx:alpine nginx -t 2>&1 | tail -3
```

Mount it at **`conf.d`**, never at `http.d`. The official `nginx:alpine` image
includes only `/etc/nginx/conf.d/*.conf` and has no `http.d` directory at all,
so a file mounted there is never read and `nginx -t` cheerfully reports "syntax
is ok" for the stock config whatever this file contains. Production is the
other way round — `apk add nginx` on Alpine includes `/etc/nginx/http.d/*.conf`,
which is where `Dockerfile:39` copies it — but both are the same `http {}`
context, so the `conf.d` mount parses the file exactly as production does.
`MSYS_NO_PATHCONV=1` stops Git Bash rewriting the container-side path into a
Windows one.

## Email triage

Every message the application sends writes a row to `email_logs` — templated
conference mail, the member notice, the organization approval mail, password
resets and verification mail alike. The admin panel lists them at
`/admin/email-logs`, filterable by status and organization and searchable by
recipient.

The three statuses mean exactly this:

| Status | Meaning |
|---|---|
| `sent` | The transport accepted the message. Not the same as delivered — check the mailbox's own logs for a bounce. |
| `failed` | The queued job threw, and `error` holds the exception message. Only templated mail (`App\Mail\TemplatedMail`) can reach this state: it has a `failed()` hook. |
| `queued` | The row was written and the job has not reported back. A few seconds is normal. Hours is not. |

A row **stuck at `queued`** is either a stopped queue worker or a *notification*
that failed: `Illuminate\Notifications\Notification` has no per-message failure
hook, so a transport error on one leaves its row where it was. Check both:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" supervisorctl status
sudo docker exec "$C" su-exec app php artisan queue:failed
```

Every delivered message carries an `X-CASS-Log` header holding the `ulid` of its
`email_logs` row: `App\Mail\TemplatedMail` sets its own, and
`App\Listeners\RecordOutgoingEmail::sending()` stamps one on everything else.
The rest of the `X-CASS-*` set is **not** universal:

| Message | Headers beyond `X-CASS-Log` |
|---|---|
| `App\Mail\TemplatedMail` — every conference template | `X-CASS-Template`, `X-CASS-Organization` |
| `App\Notifications\NewSubmissionNotice` — the member notice | `X-CASS-Organization`, `X-CASS-Conference`, `X-CASS-Submission` |
| Everything else — `OrganizationApproved`, Filament password resets, verification mail | none |

For templated mail the conference and the submission are columns on the
`email_logs` row rather than headers on the message, because `SendTemplatedEmail`
writes that row itself before queueing. When the owner's mailbox shows a bounce,
`X-CASS-Log` is how the bounce is matched to a row — search the log for the ulid
rather than guessing from the subject line.

To re-send an author's link after a bounce is fixed, do **not** replay the queue
job: open the submission in the organizer panel and use **Resend status link**,
which mints a fresh token. The token in the bounced message is already dead.

## Reviewer reminders

`php artisan schedule:work` already runs under supervisord (`docker/supervisord.conf`),
so nothing new starts at deploy time. The entry added in Plan 4 is:

| Command | Cadence |
|---|---|
| `cass:reviewer-reminders` | hourly, `withoutOverlapping(55)` |

**What it does each hour.** For every conference whose status is `reviewing` and
which has a `review_deadline`, it works in *that conference's* timezone and asks
two questions: is the local clock at or past `CASS_REMINDER_HOUR` (07:00 by
default), and is a threshold due that this reviewer has not already been sent?
The thresholds are 7, 3 and 1 days before the deadline and once after it. The
latest applicable threshold fires, so a conference that opened for review five
days before its deadline gets the seven-day reminder late rather than not at all.

**Why hourly and not daily.** One `Schedule` entry carries one timezone, and
every conference has its own (spec section 10). The hourly pass plus the
`unique (conference_id, user_id, threshold)` index on `reviewer_reminders` gives
the same behaviour as "daily at 07:00 local" without picking a timezone, and it
is self-healing: an outage that spans the send hour catches up on the next run
the same day.

**Checking it by hand.**

```bash
docker exec cass su-exec app php artisan schedule:list
docker exec cass su-exec app php artisan cass:reviewer-reminders
```

The command is idempotent, so running it by hand is safe. It prints one line per
conference it sent for and a total.

**If a reviewer says they got nothing**, in order:

1. `select * from reviewer_reminders where conference_id = ? and user_id = ?` —
   a row means it was sent; find it in `email_logs` by `to_email` and check
   `status`.
2. No row: are they `active` in `conference_reviewers`, and does
   `ReviewerScope` still give them outstanding work? A reviewer who has
   submitted every review in their queue is deliberately not reminded.
3. Still nothing: is the conference `reviewing`, and is its `review_deadline`
   set? A conference in `closed` sends nothing.

To re-send one threshold deliberately, delete that one row and wait for the next
hourly run — do not edit `sent_at`.

**If nothing is sent for hours and `schedule:list` looks right**, the overlap
mutex may be stale after a hard restart: an OOM kill or `docker kill` skips the
signal handler that would have released it, and in production the lock is a
durable row in the `cache_locks` table (`CACHE_STORE=database`), so a restart
does not clear it either. Clear it with the framework's own command rather than
SQL — the row's key carries the cache prefix (`cass-cache-framework/schedule-<sha1>`),
so a hand-written `LIKE 'framework/%'` query finds nothing:

```bash
sudo docker exec -it "$C" su-exec app php artisan schedule:list
sudo docker exec -it "$C" su-exec app php artisan schedule:clear-cache
```

It expires by itself after 55 minutes in any case, which is why the entry passes
that value rather than taking Laravel's 1440-minute default.

## Invitations and their tokens

Member invitations (`organization_invitations`) and reviewer invitations
(`reviewer_invitations`) share one route, `/invite/{token}`, one token shape and
one accept action. The token is 32 random bytes as 64 hex characters, stored
**only** as a SHA-256 hash; the plaintext exists in the emailed link and nowhere
else, so a lost link is re-sent (which mints a new token and kills the old one),
never recovered.

**Two consequences worth knowing before they surprise somebody.**

- **Accepting an invitation marks the address verified** without sending a
  verification email. Following a 64-character secret that only ever reached
  that mailbox is the same proof `VerifyEmail` asks for. This is the only place
  in CASS that grants verification that way, and it is why accepting while
  signed in as a *different* account is refused outright.
- **The plaintext token is in the queued job payload** for as long as the
  notification or mailable is queued, exactly as author status links already
  are. The `jobs` table is on the internal-only MySQL network and failed jobs
  are pruned after 30 days (`queue:prune-failed --hours=720`); if a failed job
  carrying an invitation is ever exported for debugging, treat the export as
  containing a live credential until the invitation expires (14 days by default,
  `CASS_INVITATION_EXPIRY_DAYS`).

**Two bearer-token URL shapes are redacted from stored subjects.** There are now
two credential-carrying URLs in this application — `/s/{64}` (an author's status
link) and `/invite/{64}` — and `SendTemplatedEmail` strips both from a rendered
subject before it is written to `email_logs` and put on the wire, because a
Subject header travels in clear text through every relay and an organizer can
read `email_logs`. If you see a live 64-character token in a stored subject, that
pattern has been narrowed and it is a bug, not a curiosity.

**Two rate-limit buckets protect `/invite/{token}`, not one.** The GET carries
the route middleware `throttle:invitation-accept`; each Livewire action spends a
second, separate 10/min/IP budget inside the component, because a Livewire action
POSTs to `/livewire/update` and no middleware on `/invite/...` ever sees it.
Laravel stores a named route limiter under `md5($limiterName.$key)`, a private
format application code must not reproduce, so the two cannot share one counter.
A 429 on the page itself is the route limiter; "Too many attempts" rendered
*inside* the page is the component's. Both are the spec's 10/min/IP.

**Signing in on that page does not sign anybody in.** The password field
*verifies* the account and grants the membership or reviewership; the panel login
is what issues the session, so the multi-factor challenge cannot be skipped by
holding an invitation link. An invitee who confirms their password lands on the
panel's login screen next, and that is intended.

An invitation that is expired, withdrawn or already used is answered with a page
explaining which, not a 404 — the holder has already proved they have the
secret, and a 404 there only generates support mail. A token that matches
nothing is a flat 404.

An invitation is also refused at accept time if whoever minted it no longer
manages the organization, or minted an Owner invitation and is no longer an
Owner. `RemoveMember` and `ChangeMemberRole` withdraw those rows on the spot;
this is the belt to that braces.

## Blind review and file names

When a conference has `blind_review` on, a reviewer sees no authors, no
affiliations and no contact number — **and no real file names**. The reviewer's
download link carries `blind=1` inside its HMAC signature, so it cannot be
stripped, and the file is served as `attachment-1.pdf`. An organizer's link is
not blinded: spec section 4 gives every organization member full sight of
submissions and files, the CSV export still contains author addresses, and
somebody has to be able to answer an author's email.

**Custom fields are the blind spot to check when setting a conference up.** A
question the organizer wrote themselves — "Institution", "Department", "Funding
source" — prints its answer on the review page like any other, and only the
organizer knows which of their questions identify an author. Each custom field
has a **Hide this answer from reviewers** toggle; turn it on for those, and a
blind conference stops printing them. It changes nothing for a non-blind
conference, and nothing for the organizer's own screens or the CSV export.

## Scoring, ranking and decisions

Every command in this section looks the container up the way the rest of this
runbook does and runs artisan as `app` via `su-exec`. The stack is deployed by
Coolify under its own project name with its own environment, so
`docker compose -f docker-compose.production.yml ...` from a checkout is a
*different* stack (or a failure on unset `${...}` variables), and running artisan
as root leaves root-owned files in `bootstrap/cache` and `storage/framework`.

### Where the numbers come from

`submissions.score`, `submissions.score_spread`, `submissions.review_count` and
`submissions.scored_at` are **denormalised**. Nothing computes them on read —
that is what lets the ranking table sort five hundred abstracts without touching
`reviews` — and exactly one class writes them, `App\Actions\Submissions\ComputeSubmissionScore`,
with exactly three callers:

| Caller | When |
|---|---|
| `App\Actions\Reviews\SubmitReview` | a reviewer submits a review |
| `App\Actions\Reviews\ReopenReview` | a reviewer reopens one |
| `cass:rescore {conference}` | you, deliberately |

`reviews.score` is written for drafts too; only submitted reviews reach the
submission's mean, spread and count.

### `cass:rescore`

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec -it "$C" su-exec app php artisan cass:rescore <CONFERENCE-ULID>
```

The argument is the ULID from the organizer panel URL (`/org/{org}/conferences/{ULID}`),
or the numeric `conferences.id` if you are working from the database.

**It is not scheduled, and it is not an organizer feature.** A conference's
review form locks the moment the first review is submitted (spec section 3), and
a question's *weight* is part of the question, so nothing an organizer can do
makes a stored score wrong. Run it in exactly four situations:

1. **immediately after the release that adds the score columns** (this one).
   The four columns land empty for every abstract that already exists, and
   nothing recomputes them on read — so a conference whose reviewers finished
   under the previous release would show an entirely unscored ranking until this
   runs. Rescore every conference that already has reviews:

   ```bash
   C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
   sudo docker exec "$C" su-exec app php artisan tinker --execute='
   App\Models\Conference::query()->whereHas("submissions.reviews")->pluck("ulid")->each(fn ($u) => print($u . PHP_EOL));'
   # then, one command per ULID printed:
   sudo docker exec -it "$C" su-exec app php artisan cass:rescore <ULID>
   ```

2. **after the legacy import** (`cass:import-legacy`, Plan 6), which inserts
   reviews and answers directly and has no `SubmitReview` to hook;
3. **after correcting data by hand** in the database;
4. **after deploying a fix to `App\Support\Scoring`** — then rescore every
   conference that already had reviews, one command each.

It is safe to run at any time: it recomputes a whole conference and writing the
same numbers twice changes nothing.

### Sending decision emails

The organizer clicks **Send decision emails** on a conference's Ranking and
decisions page. One click queues one `TemplatedMail` per abstract that has a
decision and has never been written to, capped at `CASS_DECISION_SEND_CHUNK`
(200) per click; the notification says how many are left, and clicking again
continues.

**Throughput.** One queue worker runs under supervisord. Each job is one SMTP
handshake and one send against the owner's mailbox, so budget roughly a second
per letter: 200 letters is about three minutes, 500 is about eight. Before the
first real batch, send one conference's letters with `CASS_DECISION_SEND_CHUNK`
left at 200 and watch `supervisorctl status` plus the `sent`/`failed` split; if
the provider throttles, lower the chunk and click again rather than raising the
worker count. Watch it in the admin panel's **Email log**
(`/admin/email-logs`), filtered by template key, or:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" su-exec app php artisan tinker --execute="echo App\Models\EmailLog::query()->where('template_key','like','decision_%')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c','status');"
```

A row stuck at `queued` long after the others finished is the failure described
in the **Email triage** section above.

**If a batch fails in the worker** — the provider throttled the burst, the
mailbox credentials expired — the rows show as `failed` in `/admin/email-logs`
and the jobs are in `failed_jobs`. **Fix the transport, then retry the jobs; do
not use the per-row "Resend the letter" action.** The queued message already
carries the rendered subject and body and the `email_logs` ULID, so a retry
delivers exactly the letter that was stored, changes no token and rewrites no
history:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" su-exec app php artisan queue:failed
sudo docker exec "$C" su-exec app php artisan queue:retry all
```

`queue:retry all` retries every failed job in the table, not only the decision
letters — which is usually what you want after a transport outage.

**A retried row keeps reading `failed` in the email log even when the retry
delivers.** `RecordOutgoingEmail::sent()` only flips a row that is still
`queued`, and `TemplatedMail::failed()` already moved it to `failed` on the first
attempt. Judge a retry by the worker and by the mailbox, not by the log's status
column; the row's `error` is a record of the first attempt, not of the last.

"Resend the letter" is for one author who says nothing arrived: it re-renders
from the template **as it stands now**, overwrites the stored letter on that
decision, and mints that author a new status link. Retry first; resend only what
is still missing afterwards.

**Sending decision letters rotates every notified author's status link.** The
access token in `/s/{token}` is stored as a SHA-256 hash and the plaintext
exists only inside an emailed link (spec section 9), so the only way to put a
working link in a second email is to mint a new one — which is what
`IssueSubmissionToken` does, and what "Resend status link" has always done. The
consequence: after decision letters go out, the link in an author's *original
confirmation* email stops resolving and the link in the *decision* email works.
Every decided author receives a decision letter, so every decided author
receives a live link. If a support request arrives saying "my old link is dead",
this is why, and the answer is the decision email — or a **Resend status link**
from the submission row.

The plaintext token exists in exactly one place: the delivered email. The copy
of the letter stored on `submission_decisions.letter_markdown` deliberately
keeps `{{status_link}}` literal, so that column — which is kept for ever and
appears in every backup — is never a bearer-token store. The author's status
page substitutes the link from the token already in their own URL, so what they
see is the whole letter.

**Letters cannot be sent from a conference that is off the public site.** An
archived conference — or one whose organization has been suspended — makes
`/s/{token}` a 404, so a letter carrying a fresh link into it would be dead on
arrival *and* would kill the link the author already has. The send button is
hidden there and the action refuses per row with a sentence naming the status.
If an organizer archived a conference with letters still pending, move it back
to `reviewing` or `decided`, send, then archive again.

### Correcting a decision after the letters have gone

The plain **Decide** action disappears once an author has been written to. Use
**Change decision and resend**: it appends to the decision history, sets the new
decision, and puts the abstract back in the send queue so the next
**Send decision emails** click queues a second letter with the new answer. The
superseded history row keeps the letter it announced, and both are visible on
the submission view.

### Marking a conference decided

**Mark decisions final** moves `reviewing -> decided`. It refuses while any
abstract is still in `submitted` or `under_review` with no decision — those
authors would be waiting for an email that never arrives. It does **not** refuse
when letters are still queued; the confirmation says how many.

After `decided`: reviewers can still open and read their reviews but cannot
write them (`Conference::acceptsReviewWrites()`), and decisions can still be
corrected with change-and-resend, because a presenter withdrawing in week three
is a real thing.

### The 500-row budget

Spec section 10 budgets the ranking table at "500 submissions in under 1 s". The
deterministic guards are
`tests/Feature/Organizer/ConferenceRankingPerformanceTest.php`'s two query-count
cases, which run on every driver and in CI and fail if the table ever touches
`reviews` or grows a per-row lookup.

The wall-clock case is **measured deliberately, not on every run**: it is
skipped unless `CASS_PERF_WALL_CLOCK=1` is set, on every driver and on CI alike.
Its stopwatch sees two full Livewire renders of 500 rows rather than the query
the budget is about, and that harness is what dominates — 1.1–1.4 s on the
in-memory SQLite of `phpunit.xml`, 0.99–1.05 s against the MySQL of
`docker-compose.dev.yml`, and 0.4 s to 2.1 s an hour apart on a shared GitHub
runner, while `RankedSubmissions::query()` hydrates the same 500 rows in 0.02 s.
To read the number anyway:

```bash
CASS_PERF_WALL_CLOCK=1 php artisan test tests/Feature/Organizer/ConferenceRankingPerformanceTest.php
```

The production image carries no test runner at all — `composer install
--no-dev`, and `.dockerignore` excludes `tests/`, `phpunit.xml` and
`phpunit.browser.xml` — so there is nothing there to run it with. Measure the
query itself on the host instead, once before launch, against a conference that
already has a few hundred abstracts:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec "$C" su-exec app php artisan tinker --execute='
$c = App\Models\Conference::query()->where("ulid", "<CONFERENCE-ULID>")->firstOrFail();
for ($i = 0; $i < 5; $i++) {
    $t = microtime(true);
    $rows = App\Support\Scoring\RankedSubmissions::query($c)->with("track")->orderByDesc("score")->limit(500)->get();
    printf("%d rows in %.3fs\n", $rows->count(), microtime(true) - $t);
}'
```

That is the same entry point the table itself uses. The first run warms OPcache
and the buffer pool; take the median of the rest. If it is anywhere near 1 s with
a few hundred rows, something has started reading `reviews` per row — which is
exactly what the two CI query-count cases bound.

### Exports

The ranking page exports **CSV** and **Excel (XLSX)** of exactly the rows on
screen. The two are different from Plan 3's submission-list CSV on purpose: this
one carries scores, spreads, review counts and decisions; that one carries the
abstract text, affiliations, custom-field answers and file names.

The XLSX writer assembles the workbook in the container's `/tmp` before
streaming it (openspout builds a zip), so an export needs free space there
briefly — a few hundred kilobytes at these sizes. `/tmp` is deliberately not on
the `cass-storage` volume, so a half-written export cannot survive a restart.

Both writers prefix an apostrophe to any cell beginning with `=`, `+`, `-`, `@`,
a tab or a carriage return (`App\Support\Export\SpreadsheetCell`). In XLSX this
is not cosmetic: without it openspout writes a real formula cell.

## Brand assets

Every brand file is committed and served straight from `public/`. Nothing is generated at deploy time, and no build step touches them — a release that forgets this section still ships the right logo.

Vector sources (authored once, edited by hand):

| File | Use |
| --- | --- |
| `resources/brand/cass-mark.svg` | the gradient hummingbird mark — the source every raster below is rendered from |
| `resources/brand/cass-mark-mono.svg` | flat `#176BB8`, for single-colour contexts |
| `resources/brand/cass-mark-white.svg` | white, for a dark background (nothing uses it yet; it is here so a dark surface does not get an improvised one) |

`public/brand/` carries a verbatim copy of all three, because `resources/` is not web-served.

Rendered from `resources/brand/cass-mark.svg`, all committed:

| File | Size | Use |
| --- | --- | --- |
| `public/brand/cass-mark-144.png` | 86x144 | email header (`resources/views/vendor/mail/html/header.blade.php`) at 36px CSS height; mail clients drop SVG sources |
| `public/brand/cass-mark-600.png` | 359x600 | large raster for anything that cannot take an SVG (dompdf cannot rasterise SVG gradients) |
| `public/favicon.ico` | 16, 32, 48 | classic favicon; also `->favicon()` for both Filament panels |
| `public/favicon.svg` | square viewBox | modern favicon, linked ahead of the ICO |
| `public/apple-touch-icon.png` | 180x180 | iOS home screen; opaque white because iOS composites alpha onto black |
| `public/icon-192.png`, `public/icon-512.png` | 192, 512 | web app manifest sizes, transparent |

### Re-rendering the PNG and ICO files

The render script is committed at `docs/brand/build-icons.mjs`. `sharp` and `png-to-ico` are deliberately **not** in `package.json`: they are authoring-only (sharp ships tens of megabytes of prebuilt libvips per platform) and the outputs are committed, so neither CI nor a deploy ever installs them. Run it from a scratch directory outside the repo:

```bash
mkdir /tmp/cass-icons && cd /tmp/cass-icons
npm init -y && npm i sharp png-to-ico
cp /path/to/cass/docs/brand/build-icons.mjs .
node build-icons.mjs /path/to/cass
```

It prints the format and dimensions of every file it wrote. The script is copied into the scratch directory rather than run in place because Node resolves a bare `import sharp` from the importing file's own directory upwards, not from the working directory.

sharp rasterises through librsvg, which renders the mark's `objectBoundingBox` gradients correctly. Some other rasterisers (cairosvg among them) drop the gradient and fill the four silhouette paths black — if a render comes out black, that is the renderer, not the SVG.

### The lock-up

`resources/views/brand/logo.blade.php` is the single definition of the mark-plus-wordmark lock-up: mark at 2.25rem beside "CASS" in IBM Plex Sans semibold, `-0.01em` tracking, `#0F4C8A` on light and white under Filament's `.dark`. It is inline-styled because Filament compiles its CSS from its own sources and never sees a Tailwind class written in an app view. Both panel providers pass it via `->brandLogo(fn () => view('brand.logo'))`, and the public layout `@include`s it. `->brandLogoHeight('2.25rem')` stays on both panels: Filament wraps an `Htmlable` logo in a div with that height and falls back to `1.5rem`, which would clip the lock-up.

## Custom domain for an organization

Not yet supported: trusted hosts are pinned to APP_URL until custom domains ship in Plan 6.

## Rollback

Coolify -> Deployments -> redeploy the previous successful build. Migrations are additive; do not roll back the schema without a backup restore.

## Backups

Nightly `mysqldump` from a host cron (owner installs):
`sudo docker exec <mysql-container> mysqldump -ucass -p"$DB_PASSWORD" cass | gzip > /srv/backups/cass-$(date +%F).sql.gz` with 14-day rotation, then the existing off-host sync to the NAS.

## Secret rotation

Change the value in Coolify, redeploy. Rotating `APP_KEY` invalidates all sessions and the encrypted two-factor secrets; announce a re-login and re-enrolment.

## Trusted proxies

`TRUSTED_PROXIES` is fixed in the compose file to the private Docker ranges where Traefik lives. The app takes the client IP from Cloudflare's `CF-Connecting-IP` header for rate limiting; the OCI security list only admits Cloudflare on 80/443, so that header cannot be spoofed from outside.

## Known quirk: healthcheck Host header

Laravel's `TrustHosts` middleware (`bootstrap/app.php`) only accepts requests whose `Host` header matches `APP_URL`'s host. Both the Dockerfile's `HEALTHCHECK` and this compose file's `app.healthcheck` therefore send an explicit `Host` header derived from `$APP_URL` when probing `127.0.0.1:8080/up` from inside the container - without it the internal healthcheck gets HTTP 400 and Coolify would report the container unhealthy even though real traffic (which arrives with the correct `Host: cass.towardpcc.com` from Traefik) works fine. If `APP_URL` is ever changed, the healthcheck host follows it automatically; no separate config needed.
