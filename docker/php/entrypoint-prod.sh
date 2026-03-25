#!/bin/sh
set -e

# Ensure runtime directories exist and are writable.
for dir in /var/www/runtime /var/www/web/assets; do
    mkdir -p "$dir"
    chown -R www-data:www-data "$dir"
done

# Run database migrations when starting as php-fpm (the app container).
# Workers and schedule-runner start with a different command, so they skip this.
if [ "$1" = "php-fpm" ]; then
    echo "[entrypoint] running migrations..."
    php /var/www/yii migrate --interactive=0
fi

exec "$@"
