#!/bin/sh
# Container start-up: prepare Laravel, then hand over to supervisor.
set -e

cd /var/www

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# Render's generated secrets are plain base64; Laravel wants the "base64:" prefix.
case "$APP_KEY" in
    base64:*) ;;
    *) export APP_KEY="base64:$APP_KEY" ;;
esac

# Render tells every web service its public URL.
if [ -z "$APP_URL" ] && [ -n "$RENDER_EXTERNAL_URL" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Run artisan as the web user so files it creates (logs, caches) stay writable.
artisan() {
    runuser -u www-data -- php artisan "$@"
}

# Config is cached from the real environment, so this must run at start, not at build.
artisan config:cache
artisan route:cache
artisan event:cache
artisan view:cache

if [ "$RUN_MIGRATIONS" = "true" ]; then
    artisan migrate --force
fi

# Seeds categories/delivery windows/settings on first run and creates the first
# super admin from SUPER_ADMIN_*. Idempotent. A failure here must not take the site down.
artisan naijafresh:setup || echo "WARNING: naijafresh:setup failed (see above); continuing."

exec "$@"
