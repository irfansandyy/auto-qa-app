# syntax=docker/dockerfile:1

FROM composer:2 AS composer

FROM php:8.5-fpm-bookworm AS php
WORKDIR /var/www/html
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev \
    && docker-php-ext-install -j"$(nproc)" intl pcntl \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf '%s\n' '[www]' 'pm = ondemand' 'pm.max_children = 6' 'pm.process_idle_timeout = 15s' 'pm.max_requests = 500' > /usr/local/etc/php-fpm.d/zz-autoqa.conf \
    && groupadd --gid 1000 autoqa \
    && useradd --uid 1000 --gid 1000 --create-home autoqa

FROM php AS dependencies
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock artisan ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY resources ./resources
COPY routes ./routes
RUN mkdir -p bootstrap/cache storage/framework/views \
    && composer dump-autoload --no-dev --optimize --no-interaction

FROM node:24-bookworm-slim AS assets
WORKDIR /var/www/html
COPY package.json package-lock.json .npmrc vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
COPY --from=dependencies /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views/ ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/
RUN npm run build

FROM php AS application
COPY --from=dependencies /var/www/html/ ./
COPY public ./public
COPY --from=assets /var/www/html/public/build/ ./public/build/
RUN install -d -o 1000 -g 1000 -m 0750 \
        storage storage/app storage/app/private storage/app/public storage/framework \
        storage/framework/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && install -d -o 1000 -g 1000 -m 0700 storage/app/private/evidence \
    && chmod 0755 /var/www/html
USER 1000:1000

FROM nginx:stable-alpine AS edge
COPY public /var/www/html/public
COPY --from=assets /var/www/html/public/build/ /var/www/html/public/build/
COPY deploy/nginx /opt/autoqa/nginx
COPY --chmod=755 deploy/nginx/configure.sh /docker-entrypoint.d/40-autoqa-configure.sh
