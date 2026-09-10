# syntax=docker/dockerfile:1
# Production image for CASS. Builds natively on arm64 (OCI Ampere) and amd64.
# nginx + php-fpm + queue worker + scheduler under supervisord; app processes run as `app`.
# Migrations are NOT run at boot; the owner runs them (see docs/runbooks/deploy-production.md).

FROM node:24-alpine AS assets
WORKDIR /build
COPY package*.json vite.config.js ./
RUN npm ci
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
# intl and gd are installed in the runtime stage; the platform check is waived only here.
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress \
    --ignore-platform-req=ext-intl --ignore-platform-req=ext-gd

FROM php:8.4-fpm-alpine AS runtime
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
RUN chmod +x /usr/local/bin/entrypoint.sh \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
      storage/app/private storage/app/public bootstrap/cache /run/nginx \
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
