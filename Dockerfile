# syntax=docker/dockerfile:1
# Production image for CASS. Builds natively on arm64 (OCI Ampere) and amd64.
# nginx + php-fpm + queue worker + scheduler under supervisord; app processes run as `app`.
# Migrations are NOT run at boot; the owner runs them (see docs/runbooks/deploy-production.md).

# Base images are pinned by DIGEST (spec section 11). The digest is the
# multi-architecture index digest from `docker buildx imagetools inspect`, not
# the platform digest from `docker inspect`: this image is built for
# linux/arm64 in production and linux/amd64 in the CI smoke job, and a platform
# digest resolves on exactly one of them.
#
# .github/dependabot.yml raises a weekly pull request when any of these moves.
# Do not "unpin to get the security fix" - let Dependabot open the PR, so the
# change is reviewed and the digest stays recorded.
FROM node:24-alpine@sha256:50c8e8ca1d27439048670df5883f32d57cf81cff6233222c893fd0d9884cbd81 AS assets
WORKDIR /build
COPY package*.json vite.config.js ./
RUN npm ci
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
# intl and gd are installed in the runtime stage; the platform check is waived only here.
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress \
    --ignore-platform-req=ext-intl --ignore-platform-req=ext-gd

FROM php:8.4-fpm-alpine@sha256:49734670eccf414af884c2a0c2e558401e228615f8028f1c9fca30a0d4fb1bc2 AS runtime
RUN apk add --no-cache nginx supervisor su-exec icu-libs libpng libjpeg-turbo freetype libzip mysql-client tzdata \
 && apk add --no-cache --virtual .build icu-dev libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" intl gd pdo_mysql zip opcache bcmath pcntl \
 && apk del .build \
 && addgroup -S app && adduser -S -G app -h /var/www app \
 && sed -i 's/^user = www-data/user = app/; s/^group = www-data/group = app/' /usr/local/etc/php-fpm.d/www.conf \
 && sed -i 's/^user  *nginx;/user app;/' /etc/nginx/nginx.conf

RUN printf '[www]\nlisten = 127.0.0.1:9000\nlisten.allowed_clients = 127.0.0.1\npm = dynamic\npm.max_children = 4\npm.start_servers = 2\npm.min_spare_servers = 1\npm.max_spare_servers = 3\npm.max_requests = 500\n' \
      > /usr/local/etc/php-fpm.d/zzz-cass.conf

WORKDIR /var/www/html
COPY --chown=app:app . .
COPY --from=vendor --chown=app:app /build/vendor ./vendor
COPY --from=assets --chown=app:app /build/public/build ./public/build
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-cass.ini
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
# Not run in the container - it docker-execs into the mysql container and
# writes to a host path. It is here so an operator on the host can `docker cp`
# it out of the running image rather than hunting for the repository, and
# --chmod so its mode is not an accident of the host filesystem.
COPY --chmod=755 docker/backup.sh /usr/local/bin/cass-backup.sh
# Same deal for the weekly archive of the uploads volume: it runs on the host
# (docker inspect + docker run), and lives in the image only so the operator can
# `docker cp` it out. Its tests are docker/backup.test.sh, run in CI.
COPY --chmod=755 docker/storage-backup.sh /usr/local/bin/cass-storage-backup.sh
# Filament's CSS/JS/fonts and Livewire's script are git-ignored and composer
# runs with --no-scripts, so publish them into public/ here. Livewire serves
# /vendor/livewire/livewire.min.js once public/vendor/livewire/manifest.json exists.
RUN chmod +x /usr/local/bin/entrypoint.sh \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
      storage/app/private storage/app/public storage/fonts bootstrap/cache /run/nginx \
 && php artisan filament:assets \
 && php artisan vendor:publish --tag=livewire:assets --force \
 && chown -R app:app storage bootstrap/cache /run/nginx /var/lib/nginx /var/log/nginx \
 && chmod 755 /var/www/html

EXPOSE 8080
# Laravel's TrustHosts middleware (bootstrap/app.php) only accepts the Host
# header matching APP_URL's host, so a healthcheck hitting 127.0.0.1 with no
# matching Host header gets a 400. Send one derived from $APP_URL so the
# request is trusted (Symfony's getHost() ignores a port suffix, so this
# works whether or not APP_URL carries one).
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD wget -qO- --header="Host: ${APP_URL#*://}" http://127.0.0.1:8080/up || exit 1
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
