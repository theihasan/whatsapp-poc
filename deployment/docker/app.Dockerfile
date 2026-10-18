# Laravel app image (PHP-FPM). Used by app, horizon, reverb and scheduler services.
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY . .
# Assets are built in the PHP stage: the Wayfinder Vite plugin runs artisan.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM php:8.4-fpm-bookworm AS app
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libicu-dev libonig-dev unzip git nodejs npm \
    && docker-php-ext-install pdo_mysql intl zip bcmath pcntl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY deployment/docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/node_modules ./node_modules
# Public Reverb address is compiled into the frontend bundle.
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8000
ARG VITE_REVERB_SCHEME=http
ENV VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME
RUN composer dump-autoload --optimize --no-dev \
    && npm run build \
    && rm -rf node_modules services/whatsapp-gateway/node_modules \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
