#!/bin/sh
set -e

# The image runs this as www-data (USER in docker/runner/Dockerfile), so what
# it creates belongs to www-data, the log directory included: unlike the
# entrypoints of the app image it has nothing to hand to www-data
# (tests/unit/docker/EntrypointLogOwnershipTest.php).
#
# Ensure runtime directories exist. The selftest playbook and ArtifactService
# both expect these to be present before the first job runs.
mkdir -p /var/www/runtime/projects /var/www/runtime/artifacts /var/www/runtime/logs /var/www/runtime/ansible-home /var/www/runtime/git-home

exec "$@"
