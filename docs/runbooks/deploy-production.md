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
redacted (see the `cass` log_format in `docker/nginx.conf`), but **Cloudflare
still sees the full URI** — do not enable Logpush for this zone without a
transform rule that strips it, and never paste a `/s/...` URL into a ticket.
To take a leaked link out of circulation, open the submission in the organizer
panel and use **Resend status link**, which rotates the token and kills the old
one.

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
`email_logs` row, plus `X-CASS-Template` and `X-CASS-Organization`; the member
notice (`App\Notifications\NewSubmissionNotice`) adds `X-CASS-Conference` and
`X-CASS-Submission`. For templated mail the conference and the submission are
columns on the `email_logs` row rather than headers on the message, because
`SendTemplatedEmail` writes that row itself before queueing. When the owner's
mailbox shows a bounce, `X-CASS-Log` is how the bounce is matched to a row —
search the log for the ulid rather than guessing from the subject line.

To re-send an author's link after a bounce is fixed, do **not** replay the queue
job: open the submission in the organizer panel and use **Resend status link**,
which mints a fresh token. The token in the bounced message is already dead.

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
