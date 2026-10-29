#!/bin/sh
set -e

# Ensure runtime directories exist and are writable.
for dir in /var/www/runtime /var/www/runtime/projects /var/www/runtime/artifacts /var/www/runtime/logs /var/www/runtime/ansible-home /var/www/runtime/git-home /var/www/web/assets; do
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
        if ! db_result=$(php -r "
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
        " 2>/dev/null); then
            echo "[entrypoint] ERROR: the database check could not run (php failed)" >&2
            echo "[entrypoint] Check PHP's error log /var/log/php_errors.log in this container for details." >&2
            exit 1
        fi

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
    # As www-data, the user of php-fpm's workers: a log file that the
    # migrations create belongs to those who append to it.
    if ! gosu www-data php /var/www/yii migrate --interactive=0; then
        echo "[entrypoint] ERROR: database migrations failed" >&2
        echo "[entrypoint] Check the migration output above for details." >&2
        echo "[entrypoint] Common causes: schema conflicts, insufficient permissions, or a stuck lock." >&2
        exit 1
    fi
    echo "[entrypoint] migrations complete"
fi

# The log directory and its files belong to www-data when the command
# starts. php-fpm's workers, the queue-worker and the schedule-runner run as
# www-data, and Yii silently drops every log line of a process that cannot
# append to its log file, the errors of the web UI and the API included.
# Root may have created or rotated a log file since the loop above, such as
# the health check of a compose file of 2.13.1 or older (php yii
# health/check as root). app.log is created as www-data, so that a root
# process that logs later, such as `docker compose exec app php yii ...`,
# appends to it instead of creating it
# (tests/unit/docker/EntrypointLogOwnershipTest.php).
chown -R www-data:www-data /var/www/runtime/logs
gosu www-data touch /var/www/runtime/logs/app.log

# php-fpm needs root for the master process — pool config drops workers
# to www-data automatically. Every other command (queue-worker,
# schedule-runner, one-off `php yii ...` calls) must drop privileges
# up front, otherwise files written by the worker (git clones, .ansible
# cache dirs, artifacts) end up root-owned and the www-data web process
# can't touch them later. The symptom is a "Permission denied" error
# when clicking "Run Lint" on a freshly synced SCM project.
if [ "$1" = "php-fpm" ]; then
    exec "$@"
else
    exec gosu www-data "$@"
fi
