# CASS launch checklist

Spec section 12: *"Before launch: security review, production-readiness audit,
and a manual walk-through of all flows on the deployed site."* This is that
list. Copy it into an issue and tick it.

Nothing here is automated on purpose. Everything that could be a test is one;
what is left is either a judgement, an owner decision, or something only a
person with a browser and the production host can see.

---

## 1. Owner decisions, before anything else

Eight questions Plans 3, 4 and 5 recorded and deliberately did not answer.
Each has a one-line change either way. Leaving one unanswered ships whichever
answer a plan author happened to choose.

- [ ] **Turnstile fails open when Cloudflare is unreachable.** A connection
      error is logged at `warning` and the submission is allowed; an explicit
      `success: false` or a 5xx is still a refusal. The reasoning: an outage
      would otherwise refuse every abstract in the last hour before a deadline,
      which is when it would cost most, while the honeypot, the four-second
      minimum fill time and the 5/min/IP throttle keep running.
      **To flip it:** `return false;` in place of the `return true;` in the
      `catch (ConnectionException …)` branch of `App\Support\Turnstile::verify()`,
      and invert one case in `tests/Unit/TurnstileTest.php`.
- [ ] **Any organization member can withdraw an abstract and resend its status
      link.** Spec section 4 gives "submit / edit / withdraw" to the author
      alone, but `SubmissionPolicy::withdraw()` and `resendLink()` mirror
      `view()`, which is every member down to a plain `member`. A withdrawal is
      irreversible and a resent link kills the one the author is holding. Both
      are logged with the actor.
      **To narrow:** both policy methods to `canManageOrganization()`.
- [ ] **Any organization member can remove a reviewer and invite anyone.**
      `ReviewerInvitationPolicy` and `ConferenceReviewerPolicy` mirror spec
      section 4's "invite reviewers, assign, decide" row, which is every
      member. Bounded by `CASS_INVITATION_SEND_LIMIT` (200/hour/org) and
      `ReviewerList::MAX_ENTRIES` (100) per paste.
      **To narrow:** both to `canManageOrganization()`.
- [ ] **A submitted review freezes at the review deadline, but a draft can
      still be finished.** The strict reading freezes everything — but
      `reviewer_overdue`, a template this platform ships, tells a reviewer
      after the deadline to "complete them as soon as you can", which the
      strict reading makes impossible.
      **To make it strict:** one clause in `SubmitReview::blockers()`, and
      invert one case in `tests/Unit/SubmitReviewTest.php`.
- [ ] **Sending decision letters rotates every notified author's status link.**
      The token is stored hashed and the plaintext exists only in an emailed
      link, so a working `{{status_link}}` in a second email means a new token,
      which kills the first. Every decided author gets a letter, so every
      decided author gets a live link.
      **If unacceptable:** a second longer-lived column on `submissions`, or a
      `submission_tokens` table (which is also what "share this with my
      co-author" would want).
- [ ] **An organizer can unassign a reviewer who has already submitted a
      review.** Spec 5.5 says assignments can be changed "until the review is
      submitted"; Plan 4 allows it after. The submitted review survives — only
      the assignment row goes, and the ranking averages `reviews`, not
      `review_assignments` — but the coverage summary then describes a reviewer
      who is gone.
      **To make it strict:** one clause in `AssignReviewers::blockers()`
      refusing a removal whose reviewer has a submitted review, plus a case in
      `tests/Feature/Organizer/ConferenceAssignmentsTest.php`.
- [ ] **Only an owner or admin may send decision letters, which narrows spec
      section 4's "invite reviewers, assign, decide" row.**
      `SubmissionPolicy::decide()` follows the spec (every member);
      `ConferencePolicy::sendDecisions()` deliberately does not, because one
      click emails every author and cannot be un-sent.
      **To widen:** make `sendDecisions()` mirror `decide()`.
- [ ] **Resending the same decision overwrites the stored letter.** A changed
      decision appends a new `submission_decisions` row; a plain resend
      re-renders over `letter_subject`, `letter_markdown` and `notified_at`, so
      the superseded text is lost (the per-send history survives in
      `email_logs`).
      **To keep every send:** one more column, or one row per send.

## 2. Owner actions on services this repository does not control

- [ ] **Turnstile keys.** Create a widget for `cass.towardpcc.com` at
      Cloudflare, set `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` in
      Coolify, redeploy. Confirm the widget renders on
      `/c/{org}/{conference}/submit` — with no keys it is silently absent and
      the form is protected only by the honeypot and the throttle.
- [ ] **Two-factor authentication on the platform admin account.** All three
      panels have `AppAuthentication::make()->recoverable()` enabled. Enrol at
      `/admin` → Profile, and **store the recovery codes somewhere that is not
      this platform**.
- [ ] **SPF and DKIM for the sending domain.** Send one real email from the
      platform (register a throwaway organization) and check the received
      headers show `spf=pass` and `dkim=pass`. Spec section 15 names
      deliverability from a personal mailbox as a risk; this is the check.
      Note `.env.example:56` still ships `MAIL_FROM_ADDRESS="cass@towardpicu.com"`
      while `CASS_CONTACT_EMAIL` is `cass@towardpcc.com` — **decide which
      domain sends**, and make SPF and DKIM match it.
- [ ] **Cloudflare: Always Use HTTPS = on.** Spec section 9 gives HSTS to
      Cloudflare and the application deliberately sends no
      `Strict-Transport-Security` of its own.
- [ ] **Cloudflare: HSTS on**, `max-age` 6 months, include subdomains off
      (custom domains are other people's names).
- [ ] **Cloudflare: WAF managed rules on**, Bot Fight Mode on. Then submit one
      real abstract to confirm neither blocks the Livewire upload endpoint.
- [ ] **Cloudflare: Rocket Loader OFF** (it is off by default — confirm, do not
      assume). It rewrites every `<script>` in the HTML it proxies and
      re-executes it from its own loader, dropping the per-request nonce that
      Livewire's config script, Filament's published inline scripts and the
      Turnstile callback all carry. With Rocket Loader on and
      `CASS_CSP_REPORT_ONLY=false`, the panels and the submission form have no
      JavaScript at all — and nothing in CI can see it, because no CI job goes
      through Cloudflare. Check:
      `curl -s https://cass.towardpcc.com/org/login | grep -c 'text/rocketloader'` → **0**.
- [ ] **Cloudflare: Email Address Obfuscation — confirmed harmless.** Its
      decoder is served from `/cdn-cgi/scripts/…` on our own origin, so
      `script-src 'self'` allows it. Re-check after any change to `script-src`.
- [ ] **Cloudflare cache rules:** cache `/build/*` and `/storage/*`
      aggressively (both are content-addressed or immutable), and **bypass**
      everything else. A cached `/s/{token}` or a cached panel page is a data
      leak between visitors.
- [ ] **OCI security list: restrict port 22 to known addresses.** The backlog
      records that the origin IP and SSH details existed in early commits of
      the public repository. Ports 80 and 443 stay open to Cloudflare's ranges
      only.
- [ ] **Confirm `/srv/backups/cass` is on a filesystem with room** for fourteen
      compressed dumps **plus one uncompressed one** (the script dumps to disk
      first so a truncated `mysqldump` cannot hide inside a pipe), and that the
      NAS sync includes it **and** the weekly `cass-storage` tarball.

## 3. Production readiness, on the deployed site

Each line is a command and the answer it must give.

- [ ] `curl -sI https://cass.towardpcc.com/up` → `200`.
- [ ] `curl -sI https://cass.towardpcc.com/ | grep -i content-security-policy`
      → an **enforcing** header, not `-Report-Only`. Deploy with
      `CASS_CSP_REPORT_ONLY=true` first, walk section 4 with the browser
      console open, then set it false and redeploy.
- [ ] `curl -sI https://cass.towardpcc.com/ | grep -iE 'x-frame-options|referrer-policy|permissions-policy|cross-origin-opener|x-content-type'`
      → five headers.
- [ ] `curl -sI https://cass.towardpcc.com/ | grep -i strict-transport-security`
      → present, and served by Cloudflare rather than by the app.
- [ ] `docker exec <app> su-exec app php artisan cass:health` → every row OK,
      exit 0.
- [ ] `docker exec <app> su-exec app php artisan migrate:status` → nothing
      Pending.
- [ ] `docker exec <app> su-exec app php artisan about` → `APP_ENV=production`,
      `APP_DEBUG=false`, config **cached**, routes **cached**, events cached.
- [ ] `docker exec <app> supervisorctl status` → four programs RUNNING.
- [ ] Run `docker/backup.sh` by hand once. A `.sql.gz` appears and the printed
      size is plausible for the live database — not the 20-byte gzip of an
      empty stream; then `gunzip -c` it and confirm the last line is
      `-- Dump completed`.
- [ ] `ls -ld /srv/backups/cass` → `drwx------`, and `ls -l` shows every dump
      `-rw-------`. The script sets both every run; a dump readable by any
      local account is a copy of every author's address and every password
      hash.
- [ ] Restore that dump into a scratch database and count rows against
      production (the procedure is in the runbook under **Backups**). **A
      backup nobody has restored is not a backup.**
- [ ] `APP=$(docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)`,
      `DB=$(docker ps --filter label=com.docker.compose.service=mysql --format '{{.Names}}' | grep -i cass | head -1)`,
      then `docker stats --no-stream "$APP" "$DB"` → the app container's steady
      state well under 768 MiB and MySQL's under 512 MiB. Record both in the
      runbook. (The names are Coolify's, not `cass-app`/`cass-mysql`; neither
      compose file sets `container_name`.)
- [ ] Time the public conference page and the submit page five times each
      (the loop is in the runbook's "Every release" step 5) → a 0.300 s median.
- [ ] Time `RankedSubmissions::query()` once against a conference with a few
      hundred abstracts (the `tinker` snippet is in the runbook under
      **Scoring, ranking and decisions**) → far under one second.
- [ ] `docker exec <app> su-exec app php artisan queue:failed` → empty.

## 4. The manual walk-through

On the deployed site, in a browser, with devtools open on the console
throughout. **Every step in order, in one session** — several of them only
break when they follow each other.

- [ ] Landing, About, Contact, Privacy, Terms all render, all styled, no
      console errors, and no untranslated key strings (`public.nav.about` and
      the like) anywhere on the page.
- [ ] Register an organization. The verification email arrives; the link
      verifies; the organizer panel opens with the pending banner.
- [ ] Approve it as the platform admin. The approval email arrives, branded,
      with a working link.
- [ ] Set the organization's logo and colours. The contrast warning fires for a
      pale colour and refuses it.
- [ ] Create a conference, add a track, a custom field and a review question,
      and publish it. The short link and the QR appear.
- [ ] Download the QR SVG, the QR PNG and both poster sizes. Open the A3 PDF
      and check the fonts are the real IBM Plex, not a fallback.
- [ ] Open the public conference page **from a phone, by scanning the poster's
      QR code**. The countdown shows and counts down.
- [ ] Submit an abstract as an author, with a real PDF, from the phone. The
      confirmation email arrives; `/s/{token}` opens; edit and re-save works.
- [ ] Withdraw it, then submit another. The status page shows the right thing
      each time.
- [ ] Invite a reviewer to a second address you control. The invitation email
      arrives; the accept link creates an account; `/review` opens.
- [ ] Submit a review. The abstract moves to under review; the organizer's
      ranking page shows a score.
- [ ] Close submissions, start reviewing, apply a decision, send the decision
      emails. The letter arrives; `/s/{token}` shows it; the **old** status
      link from the confirmation email is now dead — which is expected, and is
      owner decision 5 above.
- [ ] Export the ranking as CSV and as XLSX. Open the XLSX in a real
      spreadsheet and confirm a title beginning `=` is inert.
- [ ] Mark the conference decided, then archive it. The public page 404s.
- [ ] Claim a custom domain on a real second-level domain you control. Publish
      the TXT record, verify, receive the admin email, add the host in Coolify,
      wait for the certificate, and open `https://<that domain>/<slug>`. Check
      that `/about` and `/register` 404 there.
- [ ] As the platform admin, open an organization, a conference, a submission,
      a review and the email log. Every screen is read-only; the submission's
      file link downloads.
- [ ] Purge a **test** conference. The counts in the modal match what was
      there; nothing else is affected.
- [ ] Sign out of all three panels and confirm each login page is styled and
      each rejects a wrong password five times and then throttles.

## 5. Data

- [ ] Import the two legacy conferences (runbook: **Importing the legacy
      conferences**). Dry run first, read the report, then the real run, then
      read the report again.
- [ ] Send a password reset to each imported user.
- [ ] Reset the demo tenant (`cass:demo-reset`) so nothing demo-shaped is
      visible on the live site, **or** decide deliberately to keep it and say
      so here.
- [ ] Confirm no `@import.invalid` address ever receives anything: the imported
      conferences are archived, so nothing can be sent.

## 6. Sign-off

- [ ] Every box above is ticked or has a written reason beside it.
- [ ] The eight owner decisions in section 1 are answered in
      `docs/superpowers/plans/backlog.md`, not only in somebody's head.
- [ ] `CASS_CSP_REPORT_ONLY=false` and the site has been walked once with it
      that way.
