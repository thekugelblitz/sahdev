FROM php:8.4-fpm-bookworm AS app
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libonig-dev unzip \
    && docker-php-ext-install -j2 pdo_pgsql mbstring intl zip pcntl opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/sahdev
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/releases bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/php.ini /usr/local/etc/php/conf.d/sahdev.ini
COPY deploy/pool.conf /usr/local/etc/php-fpm.d/zz-sahdev.conf
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM caddy:2-alpine AS web
COPY --from=app /var/www/sahdev/public /var/www/sahdev/public
COPY deploy/Caddyfile /etc/caddy/Caddyfile
COPY deploy/DokployCaddyfile /etc/caddy/DokployCaddyfile
