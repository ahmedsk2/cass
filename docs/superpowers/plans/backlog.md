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

## Public site polish (Plan 2 or 3)

- Add `width`/`height` to the About and How-it-works illustrations to avoid layout shift; serve the hero as WebP at display size.
- Replace hardcoded `/org/login` URLs with `route('filament.organizer.auth.login')`.
- Add the pagination views to the Tailwind `@source` list when the first paginated public page appears.
- Contact mailable: render the message as plain escaped text with line breaks instead of markdown so senders cannot inject headings or quotes into the internal mail.

## Documentation

- `docs/superpowers/plans/2026-09-10-plan-1-foundation.md` describes the original `trustProxies(at: '*')` and seeder defaults in places; the code is authoritative. Refresh the plan text if it is reused as a template.
