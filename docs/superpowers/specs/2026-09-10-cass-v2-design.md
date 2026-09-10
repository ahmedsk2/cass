# CASS v2 Design

Date: 2026-09-10
Status: approved by owner in conversation on 2026-09-10 (approach A, both review modes, refreshed bird brand, public repo `ahmedsk2/cass`).

CASS (Conference Abstract Submission System) v2 is a ground-up rebuild of the legacy PHP site as a multi-tenant platform that any conference organizer can use to collect abstracts, run peer review, and announce decisions. It replaces the legacy application reviewed in `legacy-review.md` (kept out of the public repository).

---

## 1. Goals and non-goals

**Goals for v1 (this spec)**

1. Self-serve organizer onboarding with platform-admin approval before anything goes public.
2. A polished public conference page and abstract submission form, with a QR code and short link for every conference.
3. Reviewer invitation, two review modes (open pool and assigned), configurable weighted review forms, deadlines with automatic reminders.
4. Normalised scoring, ranking, bulk decisions with templated emails.
5. Organization branding (logo, colours) and verified custom domains.
6. Import of the two legacy conferences, their abstracts and reviews.
7. Production deployment on the owner's Oracle Cloud host through Coolify, from a public GitHub repository.

**Non-goals for v1 (v2 backlog, section 14)**

Arabic/RTL interface, paid attendee registration, program builder and certificates, WhatsApp notifications, reviewer bidding and formal conflict-of-interest declarations, ORCID, e-posters with per-abstract QR, proceedings export.

---

## 2. Recorded decisions

| Topic | Decision |
|---|---|
| Stack | PHP 8.4, Laravel 13, Filament 5 for authenticated panels, Livewire 4 + Blade + Tailwind 4 for public pages, Pest 5. |
| Hosting | Existing OCI instance `hosting-1` (<origin-ip>, me-riyadh-1, Ubuntu, ARM64) running Coolify + Traefik behind Cloudflare. |
| Domain | `cass.towardpcc.com`, Cloudflare-proxied A record to <origin-ip>. |
| Organizer onboarding | Self-serve signup; platform admin approves each organization. |
| Email | Owner's SMTP mailbox via Laravel mailer; every email queued. |
| Review modes | Open pool and assigned, chosen per conference. |
| Brand | Keep the blue bird mark, refresh the wordmark, palette derived from the bird's blues. |
| Repository | `ahmedsk2/cass`, public now, private later. |
| Database | MySQL 8 in a dedicated container (not the shared one on the host). |
| Migrations | Run by the owner, never at container boot (host rule). |

---

## 3. Domain model

```
Platform
└── Organization (tenant)              status: pending | approved | suspended
    ├── OrganizationMember (user, role: owner | admin | member)
    └── Conference                      status: draft | open | closed | reviewing | decided | archived
        ├── Track (optional)
        ├── CustomField (extra submission fields)
        ├── ReviewForm ── ReviewQuestion (weighted)
        ├── ReviewerInvitation ──▶ ConferenceReviewer (user, status)
        ├── Submission
        │   ├── SubmissionAuthor
        │   ├── SubmissionFile
        │   ├── ReviewAssignment (assigned mode only)
        │   ├── Review ── ReviewAnswer
        │   └── SubmissionDecision (history)
        ├── EmailTemplate (per template key)
        └── ShortLink ── ShortLinkVisit
User (platform-wide identity; roles come from pivots, never an enum on the user)
EmailLog, ActivityLog (audit)
```

**Key rules**

- A user may be an organization member in several organizations and a reviewer in several conferences at once.
- Authors do not have accounts. They act through a long random access token per submission.
- A conference has exactly one active review form. Questions may be edited until the first review is submitted; after that they are locked and only new questions may be appended.
- Every foreign key is a real InnoDB constraint. Deleting a conference is soft-delete only; hard purge is a platform-admin action that cascades in application code.
- Public identifiers are ULIDs; database primary keys are big integers.

---

## 4. Roles and permissions

| Capability | Platform admin | Org owner | Org admin | Org member | Reviewer | Author (token) |
|---|---|---|---|---|---|---|
| Approve / suspend organizations | ✔ | | | | | |
| See all organizations and conferences | ✔ | | | | | |
| Manage organization profile, branding, domain | ✔ | ✔ | ✔ | | | |
| Manage organization members | ✔ | ✔ | ✔ | | | |
| Create / edit conferences, forms, templates | ✔ | ✔ | ✔ | ✔ | | |
| Invite reviewers, assign, decide | ✔ | ✔ | ✔ | ✔ | | |
| View submissions and files | ✔ | ✔ | ✔ | ✔ | assigned or pool only | own only |
| Submit reviews | | | | | ✔ | |
| Submit / edit / withdraw abstract | | | | | | ✔ (until deadline) |

Authorization is enforced with Laravel policies on every model plus Filament tenancy scoping in the organizer panel. Every query in panels is scoped to the current tenant; tests in section 12 assert cross-tenant isolation for each resource.

Platform admin is a boolean on the user, granted only by console command or by another platform admin.

---

## 5. Flows

### 5.1 Organization onboarding

1. Visitor opens `/register`, enters name, email, password, organization name, organization type (society, hospital, university, company, other), country, website, and a short purpose.
2. Account created, verification email sent. Organization created with status `pending`.
3. Platform admin receives a notification email and sees the request in the admin panel with the supplied details.
4. Admin approves (organization becomes `approved`, owner emailed) or rejects with a reason (organization becomes `suspended`, owner emailed).
5. While pending, the owner can log in to the organizer panel, complete branding and draft a conference, but cannot publish it.

### 5.2 Conference setup

Organizer creates a conference with name, slug, dates, venue, timezone, description (rich text), submission window, review deadline, review mode, blind-review flag, reviewers-per-submission (assigned mode), word limit (default 500), max files (default 3), allowed file types (default PDF), presentation types offered (oral, poster, either), optional tracks, optional custom fields.

The review form starts from a default template (the eight legacy questions plus a recommendation question, all Likert 1–5) that the organizer can edit. Email templates start from platform defaults and can be edited per conference with placeholders.

Publishing requires: organization approved, at least one open submission window, an active review form with at least one question. Publishing sets status `open` and generates the short link and QR.

### 5.3 Author submission

1. Author reaches `/c/{org}/{conference}` (or the short link `/q/{code}`) and reads the call, deadline countdown, terms.
2. Form: title, abstract (live word count against the limit), track, presentation preference, authors list (name, email, affiliation, presenter flag, corresponding flag), contact phone, custom fields, files, agreement checkbox.
3. Draft save creates the submission with status `draft` and emails the author a status link. Submit validates everything server-side (word limit, deadline, file MIME by content, sizes) and sets `submitted` with a reference number like `CPDS26-017`.
4. Confirmation email to the corresponding author; notification email to organization members who opted in.
5. Status page `/s/{token}` shows current status, files, and allows edit or withdraw until the deadline. After decisions, it shows the decision letter.

Bot protection: honeypot field, per-IP rate limit, and Cloudflare Turnstile when keys are configured.

### 5.4 Reviewer invitation and review

1. Organizer invites reviewers by name and email (single or pasted list). Each invitation has a hashed token and a 14-day expiry. Email sent with the accept link.
2. Accept link `/invite/{token}`: if the email already has an account, the reviewer logs in and the conference is attached; otherwise a short account-creation form (name prefilled, password) creates the account. Invitation becomes `accepted`, token invalidated.
3. Reviewer panel shows conferences, progress, and a queue. In open pool mode the queue is every submitted abstract in the conference. In assigned mode it is the reviewer's assignments.
4. Review page shows the abstract (authors hidden when blind), files, and the review form. Reviews can be saved as draft and submitted; a submitted review can be reopened by the reviewer until the review deadline.
5. Reminders: the scheduler emails reviewers with outstanding work at 7, 3 and 1 days before the review deadline, and once after it passes. Organizers can trigger a manual reminder.

### 5.5 Assignment (assigned mode)

Organizer assigns reviewers per submission manually, or runs balanced auto-assign: for each submission pick the N reviewers with the fewest assignments, skipping reviewers whose affiliation matches any author affiliation (case-insensitive) or whose email domain matches an author's email domain. Result is shown for confirmation before saving. Assignments can be changed until the review is submitted.

### 5.6 Scoring, ranking, decisions

- Each answer is normalised to 0–100: Likert `(value − min) / (max − min) × 100`; boolean yes = 100, no = 0; select options carry an optional score; text answers do not score.
- Review score = weighted mean of scored answers.
- Submission score = mean of submitted review scores; spread = sample standard deviation; review count shown alongside.
- Ranking table: sortable by score, spread, count, track; filters by status and track; export CSV and XLSX.
- Decisions: `accepted_oral`, `accepted_poster`, `waitlisted`, `rejected`. Applied per row or in bulk. Each decision records who and when and appends to `SubmissionDecision`. Decision emails use the matching template and are sent when the organizer clicks "Send decision emails" (so decisions can be prepared quietly first).

### 5.7 QR codes and short links

- On publish, each conference gets a short code (8 characters, unambiguous alphabet) at `/q/{code}` redirecting to the conference page, and a QR encoding that URL.
- Organizer panel offers SVG and PNG downloads (1024 px) and a printable poster PDF (A4 and A3) with organization logo, conference name, "Submit your abstract" line, QR, short URL and deadline.
- Every visit to `/q/{code}` is counted (timestamp only, no personal data). The panel shows total scans and a 30-day sparkline.
- The same mechanism is designed to serve per-abstract QR codes in v2 (`ShortLink` targets are polymorphic).

### 5.8 Branding and custom domain

- Organization sets logo (SVG/PNG), primary colour, accent colour. Public pages and emails for that organization use them. Contrast is checked and a warning shown if text on primary would fail WCAG AA.
- Custom domain: organizer enters `abstracts.example.org`. The app shows a TXT record `_cass-verify` with a token and a CNAME target `cass.towardpcc.com`. "Verify" performs the DNS lookup; on success the domain is marked verified and the platform admin is notified to add the host to the Coolify resource so Traefik issues the certificate (documented runbook). Once live, `https://abstracts.example.org/{conference-slug}` serves the conference without the `/c/{org}` prefix.

### 5.9 Emails

Template keys: `submission_received`, `submission_draft_saved`, `reviewer_invitation`, `reviewer_reminder`, `reviewer_overdue`, `decision_accepted_oral`, `decision_accepted_poster`, `decision_waitlisted`, `decision_rejected`, `organization_approved`, `organization_rejected`. Placeholders: `{{author_name}}`, `{{title}}`, `{{reference}}`, `{{conference}}`, `{{organization}}`, `{{deadline}}`, `{{status_link}}`, `{{review_link}}`, `{{decision}}`, `{{reviewer_name}}`. All mail is queued, logged to `email_logs` with status and error, and rendered in a branded layout.

### 5.10 Legacy import

Console command `cass:import-legacy {sql} {uploads-dir}` creates one organization for the legacy owner, two conferences, users (without passwords; they receive a reset link on first login), reviewer memberships, review forms and questions, submissions with authors parsed from the legacy text fields, files copied into private storage, and reviews with answers and computed scores. Idempotent by legacy id. Run once on production by the owner.

---

## 6. URL scheme

| Route | Purpose |
|---|---|
| `/` | Landing page, organizer and reviewer login links, "Register your organization". |
| `/about`, `/contact`, `/privacy`, `/terms` | Static pages; contact form emails the platform. |
| `/register` | Organization signup. |
| `/org/...` | Organizer panel (Filament, tenant = organization). |
| `/review/...` | Reviewer panel (Filament). |
| `/admin/...` | Platform admin panel (Filament). |
| `/c/{org}/{conference}` | Public conference page. |
| `/c/{org}/{conference}/submit` | Submission form. |
| `/s/{token}` | Author status page. |
| `/q/{code}` | Short link redirect with scan count. |
| `/invite/{token}` | Reviewer invitation accept. |
| `/files/{ulid}` | Signed, expiring file download. |
| Custom domain `/{conference}` | Same as `/c/{org}/{conference}` for a verified domain. |

Each panel has its own login page; all share the `users` table. A user with both organizer and reviewer roles sees a switch link in the panel header.

---

## 7. Architecture

- **Single Laravel application**, one image, one database. No microservices.
- **Panels (Filament 5)**: `Admin`, `Organizer` (tenancy on Organization), `Reviewer`. Resources are declarative; business logic lives in `app/Actions` classes (one class per use case: `PublishConference`, `SubmitAbstract`, `InviteReviewer`, `AutoAssignReviewers`, `SubmitReview`, `ComputeSubmissionScore`, `ApplyDecision`, `SendDecisionEmails`, `VerifyCustomDomain`, `GenerateConferenceQr`, `ImportLegacy`).
- **Public pages**: Blade layouts + Livewire components for the submission form and status page. Tailwind 4 with design tokens for organization branding injected as CSS variables.
- **Jobs and scheduler**: database queue driver, one worker under supervisord; scheduler runs `schedule:run` every minute under supervisord (no host cron).
- **Packages**: `filament/filament`, `livewire/livewire`, `chillerlan/php-qrcode` (QR SVG/PNG, GD), `barryvdh/laravel-dompdf` (poster PDF), `spatie/laravel-activitylog` (audit), `openspout/openspout` (CSV and XLSX export), `laravel/pint`, `larastan/larastan`, `pestphp/pest` with Livewire and Filament plugins. No Chromium in the image.
- **Directories**: standard Laravel plus `app/Actions`, `app/Filament/{Admin,Organizer,Reviewer}`, `app/Livewire/Public`, `app/Support/Scoring`, `resources/views/public`, `docs/`.

---

## 8. Data storage

MySQL 8, InnoDB, utf8mb4_0900_ai_ci, foreign keys on every relation, indexes on every foreign key and on `(conference_id, status)`, `(submission_id, reviewer_user_id)` unique, `short_links.code` unique, `organizations.slug` unique, `organizations.custom_domain` unique, `(conference_id, slug)` unique.

Files: `storage/app/private` on a named Docker volume; paths are content-addressed (`{sha256 prefix}/{ulid}.pdf`); downloads only through signed routes. Uploads are validated by size (10 MB per file), count, extension and sniffed MIME, and are never executed or served directly.

Backups: nightly `mysqldump` to the data volume with 14-day rotation, plus the owner's existing off-host sync script pattern to the NAS.

---

## 9. Security requirements

- CSRF on all forms; Livewire and Filament handle it for their requests.
- Rate limits: submission 5/min/IP, login 5/min/email+IP, invitation accept 10/min/IP, contact form 3/min/IP.
- All tokens (author access, invitations, domain verification) stored as SHA-256 hashes; plaintext appears only in the emailed link.
- Signed, expiring file URLs; no public `uploads/` directory.
- Policies on every model; Filament tenancy scoping; feature tests for cross-tenant and cross-conference access on every resource and every public token route.
- Passwords: bcrypt, minimum 10 characters, breached-password check via Laravel's `uncompromised` rule. Email verification required for organizer accounts. Optional MFA (Filament built-in) for organization owners and platform admins.
- Security headers set by the app (CSP allowing self and inline styles for Livewire, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`); HSTS by Cloudflare.
- No secret in the repository; `.env.example` lists every variable. SMTP credentials, app key and database password live in Coolify environment variables.
- Audit log for organization approvals, decisions, assignment changes, reviewer removals, domain changes.
- Error pages never expose stack traces in production; exceptions reported to the log only.

---

## 10. Non-functional requirements

- Host is a shared 4-core, 24 GiB machine with about two dozen containers. The app container is limited to 768 MiB memory and 1.5 CPU; MySQL to 512 MiB. These are starting values, to be measured under load and adjusted.
- Public conference page and submission form must render in under 300 ms server time on the host; ranking table for 500 submissions in under 1 s.
- Queue worker restarts automatically; failed jobs kept for 30 days and visible in the admin panel.
- Timezone per conference (default `Asia/Riyadh`); all timestamps stored UTC.
- Accessibility: public pages meet WCAG AA contrast, keyboard navigation, labelled inputs.
- Locale `en` only in v1, with all strings in language files so Arabic can be added without code changes.

---

## 11. Deployment and environments

- **Repository** `ahmedsk2/cass` (public). Trunk `main`; work on short-lived branches with PRs; CI must be green to merge.
- **CI (GitHub Actions)**: Pint check, Larastan level 6, Pest suite with MySQL service, `npm run build`, Docker image build for arm64 (no push).
- **Image**: multi-stage Dockerfile modelled on the endorsement project: assets built in a Node stage, Composer `--no-dev` in a build stage, runtime `php:8.4-fpm-alpine` + nginx + supervisord, all app processes as non-root `app`, base images pinned by digest, config/route/view caches built at boot, migrations never run at boot.
- **Compose** `docker-compose.production.yml`: `app` (port 8080, one Traefik network label, memory/CPU limits) and `mysql` on an internal-only network with a named volume. Coolify Docker Compose build pack, domain set in the Coolify UI as `https://cass.towardpcc.com:8080`.
- **DNS**: Cloudflare-proxied A record `cass` → <origin-ip>. Owner adds it (or grants access) since the Cloudflare connector is not available in this session.
- **Runbook** `docs/runbooks/deploy-production.md`: first deploy, migrations, adding a custom domain to the Coolify resource, rollback, backup restore, secret rotation.
- **Local development**: `docker-compose.dev.yml` with app (PHP 8.4 CLI + Composer), MySQL, Mailpit; or native PHP 8.4 with Composer installed locally. Tests run against MySQL in CI and SQLite in-memory locally where behaviour is identical, MySQL where it is not (JSON columns, full-text).

---

## 12. Testing strategy

- **Unit**: scoring normalisation and weighting, word counting, reference number generation, short code alphabet, auto-assign balancing and conflict skipping, placeholder rendering.
- **Feature (Pest)**: every flow in section 5, both review modes, deadline enforcement, token expiry, rate limits, file validation, email queued with the right template, reminders scheduled correctly at each threshold.
- **Panel tests (Filament/Livewire)**: each resource lists, creates, edits within a tenant and is invisible from another tenant; reviewer cannot open a submission outside their pool or assignments.
- **Browser**: one end-to-end submission run through the public form (Pest browser plugin or Playwright) executed in CI.
- **Discipline**: tests written before implementation per the superpowers TDD skill; every new test is shown to fail first; CI exit code is the gate; no placeholder assertions.
- **Before launch**: security review, production-readiness audit, and a manual walk-through of all flows on the deployed site.

---

## 13. Brand and UI direction

- Mark: the existing blue bird, redrawn as clean SVG. Wordmark: "CASS" in IBM Plex Sans semibold, with the expansion "Conference Abstract Submission System" as a tagline.
- Palette from the bird: primary `#176BB8` (5.48:1 on white, passes WCAG AA; the lighter `#1E7BD1` from the mark is 4.37:1 and is used only for decorative fills), deep `#0F4C8A`, light `#BFE0F7`, ink `#111827`, surface `#F8FAFC`, success `#15803D`, warning `#B45309`, danger `#B91C1C`. Organization branding overrides primary and accent on their pages and must pass the same AA check.
- Type: IBM Plex Sans for UI and body, IBM Plex Mono for reference numbers and codes. Self-hosted via `@fontsource`.
- Public pages: generous whitespace, one accent colour, real illustrations from the chosen Envato set for landing and empty states, no stock photography.
- Panels: Filament default theme with the primary colour applied; no template purchase.

---

## 14. v2 backlog (recorded, not built)

Arabic/RTL interface and bilingual templates; paid registration with Moyasar/Tap/Stripe; program builder from accepted abstracts; presenter and reviewer certificates (PDF); WhatsApp reminders; reviewer bidding and declared conflicts; ORCID login and author lookup; e-poster upload with per-abstract QR and public abstract pages; proceedings/abstract book export; AI-assisted reviewer matching; organization-level analytics; multiple active review forms per conference.

---

## 15. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Filament 5 API differences from training data | Pin versions in composer, read the installed package docs before writing resources, verify with tests. |
| ARM64 image build failures for GD/intl | Mirror the endorsement Dockerfile, which already builds on this host. |
| SMTP deliverability from a personal mailbox | Correct SPF/DKIM on the sending domain; all mail logged; switch to a transactional provider is a config change only. |
| Custom-domain TLS needs a manual Coolify step | Documented runbook; admin notification on verification; v2 may automate through the Coolify API. |
| Host resource contention | Container limits from day one, measured after launch. |
| Legacy data quality (swapped fields, latin1) | Import command normalises encoding and reports rows needing manual review instead of guessing. |
