# Backlog of deferred review items

Items raised in code reviews during Plan 1 that were deliberately deferred. Each names the plan that should absorb it.

## Security and platform (Plan 6, launch hardening)

- Content-Security-Policy for public pages and panels: needs a nonce strategy compatible with Livewire 4 and Filament 5. Until then the `/storage/` nginx location carries a strict CSP and SVG uploads are refused.
- Pin Docker base images by digest (`node`, `composer`, `php:8.4-fpm-alpine`, `mysql:8.4`) and add Dependabot for the docker ecosystem.
- Trusted hosts are pinned to `APP_URL`; when custom domains ship, the trusted-host closure must also return verified `organizations.custom_domain` values, and the runbook section on custom domains becomes valid.
- Origin IP and SSH details existed in early commits of the public repository; restrict port 22 on the OCI security list to known IPs.
- Measure real memory use under load and adjust the compose limits (app 768M with php-fpm capped at 4 children, MySQL 512M with performance schema off).
- Queue the Filament password-reset notification (verification mail is already queued).
- GitHub Actions: move to action versions that run on Node 24 (`actions/checkout`, `docker/*`) when the deprecation lands.

## Organizer features (Plan 2 onwards)

- Suspended organizations: decide whether owners keep read access to the organizer panel (current behaviour: yes, with a banner) or are locked out.
- Delete replaced logos from the `branding` disk (`FileUpload::deleteUploadedFileUsing` or a cleanup job).
- `User::roleIn()` and `canAccessTenant()` query on every call; use the loaded relation when present once tables show N+1 in profiling.
- Organization name is not unique; approval is the gate. Revisit if duplicates become a support problem.
- Conference status `reviewing` and `decided` are in the enum and the transition table but nothing drives them yet: Plan 4 moves Closed -> Reviewing, Plan 5 moves Reviewing -> Decided.
- `review_forms.locked_at` is written by nothing except factories until Plan 4's `SubmitReview` sets it.
- **Decided (owner):** suspending an organization takes every one of its public conference pages offline immediately — 404 to the public, still previewable by members — because the suspension email already promises exactly that. Tested in `ConferencePageTest` and `ShortLinkTest`; no further confirmation needed.
- **Decided (owner):** the QR PNG is the smallest whole-module square at or above 1024 px rather than exactly 1024 px, because chillerlan renders whole modules and resampling would blur them. The SVG is the print master. Revisit only if a print shop asks for an exact pixel size.
- `ShortLink::dailyVisitCounts()` groups in PHP. Move it to a grouped SQL query if a single conference ever passes roughly 100k scans.
- Organization member management (spec 4 "Manage organization members") was handed to Plan 2 by Plan 1 and moves on to **Plan 4**, which builds the hashed-token invitation flow that member and reviewer invitations share. Until then every organization has only its registering owner, so the owner/admin/member distinctions Plan 2 enforces cannot occur in production.
- Per-conference email templates (spec 5.2, 5.9) are not built in Plan 2. **Plan 3** adds `EmailTemplate`, the platform defaults for every template key in spec 5.9, the per-conference editor with placeholders and the placeholder-rendering unit test, because Plan 3 sends the first conference-scoped emails (`submission_draft_saved`, `submission_received`).
- Platform-admin hard purge of a conference (spec 3): the foreign keys are `RESTRICT`, and `ConferencePolicy::before()` already returns true for a platform admin, so a `ForceDeleteAction` must not be added to either panel until an action class deletes the tree in application code (and, from Plan 3 on, the files in private storage). Plan 6.
- Organizer panel theme: `php artisan make:filament-theme organizer`, `->viteTheme(...)`, add the file to the `vite.config.js` input, and move the Dockerfile's `vendor` stage above the `assets` stage so `vendor/filament` can be copied in (the theme's `@import` reaches into it, and `.dockerignore` excludes `vendor`). Until then Tailwind utility classes in panel Blade views do nothing — Plan 1's dashboard banners are already unstyled, and Plan 2 uses inline styles and `<x-filament::section>` instead.
- Spec section 10 requires every string to live in language files so Arabic can be added without code changes. Plans 1 and 2 hardcode English in the public Blade views, the countdown script and the poster template; extract them to `lang/en/*.php` before any Arabic work.
- Admin `OrganizationResource` has the route-key mismatch described in fact 16: `Organization::getRouteKeyName()` is the slug while the resource sets `$recordRouteKeyName = 'id'`, so its table View action and row link generate `/admin/organizations/{slug}` and resolve `where id = '{slug}'` — a 404. (The approval email builds an id URL by hand and works.) Do **not** fix it by changing the model's route key: the public `/c/{organization}` route depends on the slug. Bind the admin resource by slug, or build the View URL from the id.
- Reject or downscale organization logos above 16 MP at upload (`EditOrganizationProfile`), so `GenerateConferencePoster::logoDataUri()` never has to drop one silently.

## Public site polish (Plan 2 or 3)

- Add `width`/`height` to the About and How-it-works illustrations to avoid layout shift; serve the hero as WebP at display size.
- Replace hardcoded `/org/login` URLs with `route('filament.organizer.auth.login')`.
- Add the pagination views to the Tailwind `@source` list when the first paginated public page appears.
- Contact mailable: render the message as plain escaped text with line breaks instead of markdown so senders cannot inject headings or quotes into the internal mail.
- The public conference page CTA links to `#` in its open state. Plan 3 replaces that one `href` in `resources/views/public/partials/submit-cta.blade.php` with `route('conference.submit', ...)`, and registers `/c/{organization}/{conference:slug}/submit` — the same explicit `:slug` binding field `conference.show` uses, because the model's route key is the ULID.
- Conference `og:image` (the QR poster or the organization logo) for link previews when a conference is shared on social media.

## Documentation

- `docs/superpowers/plans/2026-09-10-plan-1-foundation.md` describes the original `trustProxies(at: '*')` and seeder defaults in places; the code is authoritative. Refresh the plan text if it is reused as a template.
