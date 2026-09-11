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
4. Check https://cass.towardpcc.com/up returns 200, then open the landing page and `/org/login`. **`/org/login` must be styled**: this release is the first image that runs `filament:assets` and publishes Livewire's script, so `/css/filament/filament/app.css` and `/vendor/livewire/livewire.min.js` should both return 200. Cloudflare may still be serving the old 404s — purge `/css/filament/*`, `/js/filament/*`, `/fonts/filament/*` and `/vendor/livewire/*` if so.
5. Time the public conference page after the deploy. Spec section 10 gives it a 300 ms server budget, which is the reason it is plain Blade instead of Livewire.

   ```bash
   C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
   for i in 1 2 3 4 5; do
     sudo docker exec "$C" php -r '$c = stream_context_create(["http" => ["header" => "Host: cass.towardpcc.com\r\n"]]); $t = microtime(true); file_get_contents("http://127.0.0.1:8080/c/<org>/<conference>", false, $c); printf("%.3f\n", microtime(true) - $t);'
   done
   ```

   The median must stay under 0.300 s. The first run after a deploy warms OPcache and may be slower. The `Host` header is required because `TrustHosts` only accepts the `APP_URL` host, and the probe runs *inside* the container because the compose file publishes no ports on the host.

## Poster PDF fonts

The poster PDF is rendered by dompdf using three IBM Plex TTFs committed at `resources/fonts/` (Sans regular, Sans semibold, Mono semibold for the short URL). dompdf converts them to its own metrics format on first use and caches the result in `storage/fonts/`, which must be writable by the `app` user — the Dockerfile creates it and chowns it. Nothing is downloaded at runtime.

If a poster download returns a 500, look for `Failed to open stream: Permission denied` on a path under `/var/www/html/storage/fonts/`. dompdf's font library writes the metrics file first, so the usual line is `fopen(/var/www/html/storage/fonts/ibm_plex_sans_normal_<hash>.ufm): Failed to open stream: Permission denied`; if the directory itself is missing it is `mkdir(): Permission denied`. The directory lives in the container layer, not on a volume (`cass-storage` is mounted at `storage/app` only), so a redeploy recreates it with the right owner. To repair the running container:

```bash
C=$(sudo docker ps --filter label=com.docker.compose.service=app --format '{{.Names}}' | grep -i cass | head -1)
sudo docker exec -it "$C" sh -c 'mkdir -p storage/fonts && chown -R app:app storage/fonts'
```

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
