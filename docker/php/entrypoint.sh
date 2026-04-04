#!/bin/sh
set -e

# Always run composer install to ensure dependencies are up-to-date.
# This catches missing vendor/, new packages, and failed prior installs.
# flock prevents concurrent composer installs when multiple containers
# (app, runner-1, runner-2, …) start simultaneously on the same volume.
echo "[entrypoint] running composer install..."
cd /var/www && flock /var/www/.composer.install.lock composer install --no-interaction --optimize-autoloader

# Create runtime directories required by Yii2 and set permissions for www-data.
# These are git-ignored and must exist on every fresh checkout. The projects,
# artifacts, and logs subdirectories are required by the selftest playbook and
# by ArtifactService / ProjectService before the first job runs.
for dir in /var/www/runtime /var/www/runtime/projects /var/www/runtime/artifacts /var/www/runtime/logs /var/www/web/assets; do
    if [ ! -d "$dir" ]; then
        echo "[entrypoint] creating $dir"
        mkdir -p "$dir"
    fi
    chown -R www-data:www-data "$dir"
done

# Run database migrations automatically when starting the web/app container.
# Skip for worker/runner containers (they start with a specific command, not php-fpm).
# migrate --interactive=0 is idempotent — safe to run on every start.
if [ "$1" = "php-fpm" ]; then
    echo "[entrypoint] running migrations..."
    flock /var/www/.migrate.lock php /var/www/yii migrate --interactive=0
fi

exec "$@"
