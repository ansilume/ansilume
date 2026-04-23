#!/bin/sh
set -e

# Ensure runtime directories exist. The selftest playbook and ArtifactService
# both expect these to be present before the first job runs.
mkdir -p /var/www/runtime/projects /var/www/runtime/artifacts /var/www/runtime/logs /var/www/runtime/ansible-home

exec "$@"
