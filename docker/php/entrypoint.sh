#!/bin/sh
set -e

# Always run composer install to ensure dependencies are up-to-date.
# This catches missing vendor/, new packages, and failed prior installs.
# flock prevents concurrent composer installs when multiple containers
# (app, runner-1, runner-2, …) start simultaneously on the same volume.
echo "[entrypoint] running composer install..."
if ! cd /var/www || ! flock /var/www/.composer.install.lock composer install --no-interaction --optimize-autoloader; then
    echo "[entrypoint] ERROR: composer install failed" >&2
    echo "[entrypoint] Check disk space and network connectivity." >&2
    exit 1
fi

# Verify autoload works after install
if ! php -r "require '/var/www/vendor/autoload.php';" 2>/dev/null; then
    echo "[entrypoint] ERROR: vendor/autoload.php is broken after composer install" >&2
    echo "[entrypoint] Try removing vendor/ and restarting: rm -rf /var/www/vendor && docker compose restart" >&2
    exit 1
fi

# Create runtime directories required by Yii2 and set permissions for www-data.
# These are git-ignored and must exist on every fresh checkout. The projects,
# artifacts, and logs subdirectories are required by the selftest playbook and
# by ArtifactService / ProjectService before the first job runs.
#
for dir in /var/www/runtime /var/www/runtime/projects /var/www/runtime/artifacts /var/www/runtime/logs /var/www/web/assets; do
    if [ ! -d "$dir" ]; then
        echo "[entrypoint] creating $dir"
        mkdir -p "$dir"
    fi
    chown -R www-data:www-data "$dir"
done

# /tmp/ansible is ANSIBLE_HOME. ansible-inventory / ansible-playbook
# create /tmp/ansible/tmp/ansible-local-<pid>-* at run time. If the
# parent /tmp/ansible/tmp is not world-writable they blow up with
# EACCES. Distinct users run ansible through this container
# (www-data via php-fpm, root via `docker compose exec`); chowning to
# one of them is not enough because the other recreates the directory
# after its own runs.
#
# Mirror /tmp's sticky-writable permission (1777) on BOTH /tmp/ansible
# and /tmp/ansible/tmp. The `tmp` subdir is the one ansible actually
# writes under, so forgetting it leaves the bug in place even though
# the parent is shared. This was the root cause of the "Parse Inventory"
# button surfacing a permission error operators frequently missed.
mkdir -p /tmp/ansible/tmp
chmod 1777 /tmp/ansible /tmp/ansible/tmp

# Run database migrations automatically when starting the web/app container.
# Skip for worker/runner containers (they start with a specific command, not php-fpm).
# migrate --interactive=0 is idempotent — safe to run on every start.
if [ "$1" = "php-fpm" ]; then
    # Source .env if present — in dev the compose file mounts the repo but
    # does not inject DB_* as container environment variables.
    if [ -z "${DB_HOST:-}" ] && [ -f /var/www/.env ]; then
        set -a
        # shellcheck disable=SC1091
        . /var/www/.env
        set +a
    fi

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
    if ! flock /var/www/.migrate.lock php /var/www/yii migrate --interactive=0; then
        echo "[entrypoint] ERROR: database migrations failed" >&2
        echo "[entrypoint] Check the migration output above for details." >&2
        echo "[entrypoint] Common causes: schema conflicts, insufficient permissions, or a stuck lock." >&2
        exit 1
    fi
    echo "[entrypoint] migrations complete"
fi

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
