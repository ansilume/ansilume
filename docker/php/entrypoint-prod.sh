#!/bin/sh
set -e

# Ensure runtime directories exist and are writable.
for dir in /var/www/runtime /var/www/runtime/projects /var/www/runtime/artifacts /var/www/runtime/logs /var/www/web/assets; do
    mkdir -p "$dir"
    chown -R www-data:www-data "$dir"
done

# Verify vendor autoload is intact (baked into image but could be damaged by volume mounts)
if ! php -r "require '/var/www/vendor/autoload.php';" 2>/dev/null; then
    echo "[entrypoint] ERROR: vendor/autoload.php is missing or broken" >&2
    echo "[entrypoint] The container image may be corrupted. Pull the latest image:" >&2
    echo "[entrypoint]   docker compose pull && docker compose up -d" >&2
    exit 1
fi

# Run database migrations when starting as php-fpm (the app container).
# Workers and schedule-runner start with a different command, so they skip this.
if [ "$1" = "php-fpm" ]; then
    echo "[entrypoint] waiting for database..."
    elapsed=0
    while true; do
        db_result=$(php -r "
            try {
                new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306'),
                        getenv('DB_USER'), getenv('DB_PASSWORD'));
                echo 'ok';
            } catch (PDOException \$e) {
                if (str_contains(\$e->getMessage(), 'Access denied')) {
                    echo 'auth_error';
                } else {
                    echo 'unavailable';
                }
            }
        " 2>/dev/null)

        if [ "$db_result" = "ok" ]; then
            break
        fi
        if [ "$db_result" = "auth_error" ]; then
            echo "[entrypoint] ERROR: database credentials rejected (Access denied)" >&2
            echo "[entrypoint] If you re-ran quickstart, choose 'Fresh install' to reset the database." >&2
            exit 1
        fi

        elapsed=$((elapsed + 2))
        if [ $elapsed -ge 60 ]; then
            echo "[entrypoint] ERROR: database not reachable after 60s" >&2
            exit 1
        fi
        sleep 2
    done
    echo "[entrypoint] database is reachable"

    echo "[entrypoint] running migrations..."
    if ! php /var/www/yii migrate --interactive=0; then
        echo "[entrypoint] ERROR: database migrations failed" >&2
        echo "[entrypoint] Check the migration output above for details." >&2
        echo "[entrypoint] Common causes: schema conflicts, insufficient permissions, or a stuck lock." >&2
        exit 1
    fi
    echo "[entrypoint] migrations complete"
fi

exec "$@"
