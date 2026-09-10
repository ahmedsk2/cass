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
3. If the release adds migrations, run step 6 above after the container is healthy.
4. Check https://cass.towardpcc.com/up returns 200, then open the landing page and `/org/login`.

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
