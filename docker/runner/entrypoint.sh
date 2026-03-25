#!/bin/sh
set -e

# Ensure runtime directory exists for token cache
mkdir -p /var/www/runtime

exec "$@"
