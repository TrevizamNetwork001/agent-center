FROM php:8.4-fpm-bookworm@sha256:1b6bb4f3d32dfbcd1e159d7bd47caa8852507f61666db7731d73f34acec781b6 AS base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libonig-dev libxml2-dev libzip-dev unzip git ca-certificates libfcgi-bin \
    && docker-php-ext-install -j2 pdo_pgsql mbstring pcntl bcmath zip dom \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
RUN php -r 'exit(version_compare(PHP_VERSION, "8.4.27", ">=") ? 0 : 1);'
ENV COMPOSER_ALLOW_SUPERUSER=1

FROM base AS tooling

FROM base AS production
COPY app/ /var/www/html/
RUN chmod -R a+rX /var/www/html \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/php/zz-health.conf /usr/local/etc/php-fpm.d/zz-health.conf
COPY deploy/php/production.ini /usr/local/etc/php/conf.d/production.ini
USER www-data
CMD ["php-fpm", "-F"]
