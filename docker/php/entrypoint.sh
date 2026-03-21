#!/bin/sh
set -e

# Ensure composer dependencies are installed.
# In development the source is volume-mounted, but vendor/ may not exist
# on the host. Install it if missing.
if [ ! -f /var/www/vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ not found — running composer install..."
    cd /var/www && composer install --no-interaction --optimize-autoloader
fi

exec "$@"
