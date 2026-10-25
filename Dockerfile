# TimeTune – demo image (FrankenPHP: PHP + Caddy in one binary).
# One image for all roles: web (default), reverb, queue, scheduler.

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.4-bookworm

# --- Base: PHP with the extensions TimeTune needs --------------------------
FROM ${PHP_IMAGE} AS base

RUN install-php-extensions pdo_sqlite gd exif intl pcntl bcmath zip opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-timetune.ini"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# --- Build: PHP dependencies + frontend (Wayfinder needs PHP during the build)
FROM base AS build

COPY --from=node:20-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:20-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    # npm 10 fails on the starter kit's "overrides" (edgesOut bug)
    && npm install -g npm@11.6.2

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover \
    && npm run build \
    && rm -rf node_modules

# --- Runtime -----------------------------------------------------------------
FROM base AS runtime

COPY --from=build /app /app
COPY docker/Caddyfile /etc/timetune/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/timetune-entrypoint

RUN chmod +x /usr/local/bin/timetune-entrypoint \
    && rm -f public/storage && php artisan storage:link \
    && mkdir -p /data

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite

EXPOSE 80
ENTRYPOINT ["timetune-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/timetune/Caddyfile"]
