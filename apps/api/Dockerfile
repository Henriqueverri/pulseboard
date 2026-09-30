# Production image for Render: Nginx + PHP-FPM in one container, app in /var/www/html.
# Build context: apps/api. Configuration comes only from environment variables at runtime.

FROM php:8.4-fpm-alpine AS base

# $PHPIZE_DEPS is a space-separated package list; Alpine only keeps the latest package versions.
# hadolint ignore=DL3018,SC2086
RUN apk add --no-cache bash nginx libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache \
    && apk del .build-deps \
    && rm -f /usr/local/etc/php-fpm.d/*.conf \
    && rm -rf /var/www/html/*

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/production.ini "$PHP_INI_DIR/conf.d/zz-production.ini"
COPY docker/php-fpm/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx/nginx.conf.template /etc/nginx/pulseboard.conf.template

WORKDIR /var/www/html

FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# hadolint ignore=DL3018
RUN apk add --no-cache unzip

COPY composer.json composer.lock ./

RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

COPY . .

# package:discover (post-autoload-dump) must only see the production packages.
RUN rm -rf docker bootstrap/cache/*.php \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && composer check-platform-reqs --no-dev

FROM base AS runtime

COPY --from=vendor /var/www/html /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod 0755 /usr/local/bin/entrypoint \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# hadolint ignore=DL3066
USER www-data

ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint"]
