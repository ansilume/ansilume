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
    echo "[entrypoint] waiting for database..."
    elapsed=0
    while ! php -r "new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306'), getenv('DB_USER'), getenv('DB_PASSWORD'));" 2>/dev/null; do
        elapsed=$((elapsed + 2))
        if [ $elapsed -ge 60 ]; then
            echo "[entrypoint] ERROR: database not reachable after 60s" >&2
            exit 1
        fi
        sleep 2
    done
    echo "[entrypoint] database is reachable"

    echo "[entrypoint] running migrations..."
    php /var/www/yii migrate --interactive=0
fi

exec "$@"
