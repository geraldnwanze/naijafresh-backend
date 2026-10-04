# NaijaFresh API: nginx + php-fpm + queue worker + scheduler in one container
# (cheapest layout for Render; see docker/supervisord.conf to split them later).
FROM php:8.4-fpm

# System dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    git \
    curl \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        mbstring \
        intl \
        zip \
        opcache \
        bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Dependencies first, for Docker layer caching. --no-scripts because the
# post-install hooks call `artisan`, which isn't copied in yet.
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

# Application
COPY . .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-interaction \
    && php artisan package:discover --ansi

# PHP: production settings + an fpm pool that keeps the container's environment
# variables (php-fpm clears them by default, which would hide DB_URL, APP_KEY…).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/zz-app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/zz-render.conf /usr/local/etc/php-fpm.d/zz-render.conf

# Nginx + Supervisor
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Entrypoint: caches config, migrates, runs first-run setup, then starts supervisor.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Which background jobs run in this container (set to false to run them elsewhere).
ENV RUN_MIGRATIONS=true \
    RUN_QUEUE_WORKER=true \
    RUN_SCHEDULER=true

EXPOSE 10000

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1:10000/up || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
