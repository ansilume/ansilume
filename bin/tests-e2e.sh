#!/usr/bin/env bash
# =============================================================================
# Playwright E2E browser tests
# =============================================================================

set -euo pipefail
# shellcheck source=tests-common.sh
source "$(dirname "$0")/tests-common.sh"

# =============================================================================
# Worker/schedule-runner privilege drop (regression: prebuilt lint EACCES)
#
# queue-worker and schedule-runner containers run `php yii …` commands. Left
# at the php:8.2-fpm image default USER they exec as root — and everything
# they write to the shared runtime volume becomes root-owned. The www-data
# pool workers in the app container then can't mkdir inside those trees, so
# clicking "Run Lint" on a freshly synced SCM project fails with
#   [Errno 13] Permission denied: '.../.ansible/tmp/ansible-local-…'.
# entrypoint.sh / entrypoint-prod.sh drop to www-data via gosu for any
# command that is not `php-fpm`. This section asserts the drop is actually
# in effect by inspecting the running main process, not just the script.
# =============================================================================
section "Container user (worker runs as www-data)"

if [[ $DOCKER_AVAILABLE -eq 1 ]]; then
    # The php:8.2-fpm base image ships without procps, so `ps` is unavailable.
    # Read PID 1's effective UID from /proc/1/status and compare it to
    # www-data's UID inside the container. The dev Dockerfile remaps
    # www-data's UID to the host UID (USER_ID build arg), so comparing by
    # name or a hardcoded 33 would both be wrong.
    for svc in queue-worker schedule-runner; do
        uids=$(docker compose exec -T "$svc" sh -c '
            set -e
            pid1_uid=$(awk "/^Uid:/ {print \$2}" /proc/1/status)
            wwwdata_uid=$(id -u www-data)
            echo "${pid1_uid} ${wwwdata_uid}"
        ' 2>/dev/null || true)

        if [[ -z "$uids" ]]; then
            skip "$svc not running or /proc not readable"
            continue
        fi

        pid1_uid=$(echo "$uids" | awk '{print $1}')
        wwwdata_uid=$(echo "$uids" | awk '{print $2}')

        if [[ "$pid1_uid" == "$wwwdata_uid" ]]; then
            ok "$svc main process runs as www-data (uid=$pid1_uid)"
        else
            fail "$svc main process uid=$pid1_uid (expected www-data uid=$wwwdata_uid) — gosu drop missing"
        fi
    done
else
    skip "Container user check (Docker not available)"
fi

section "Playwright E2E tests"

if [[ $FAST -eq 1 ]] || [[ $SKIP_E2E -eq 1 ]]; then
    skip "Playwright E2E tests (skipped)"
elif [[ $DOCKER_AVAILABLE -eq 1 ]]; then
    # Seed E2E test data
    SEED_OUT=$(dc php yii e2e/seed 2>&1 || true)
    if echo "$SEED_OUT" | grep -q "complete\|already exists"; then
        # Build and run Playwright container
        E2E_OUT=$(docker compose --profile e2e run --build --rm playwright 2>&1 || true)
        E2E_PASSED=$(echo "$E2E_OUT" | grep -oP '\d+(?= passed)' || echo "0")
        E2E_FAILED=$(echo "$E2E_OUT" | grep -oP '\d+(?= failed)' || echo "0")

        if [[ "$E2E_FAILED" -eq 0 ]] && [[ "$E2E_PASSED" -gt 0 ]]; then
            ok "Playwright E2E passed (${E2E_PASSED} tests)"
        elif [[ "$E2E_PASSED" -gt 0 ]]; then
            fail "Playwright E2E: ${E2E_PASSED} passed, ${E2E_FAILED} failed"
            echo "$E2E_OUT" | tail -30 | sed 's/^/     /'
        else
            fail "Playwright E2E tests did not run"
            echo "$E2E_OUT" | tail -30 | sed 's/^/     /'
        fi

        # Teardown E2E data (silent)
        dc php yii e2e/teardown >/dev/null 2>&1 || true
    else
        fail "E2E seed failed"
        echo "$SEED_OUT" | tail -10 | sed 's/^/     /'
    fi
else
    skip "Playwright E2E tests (Docker not available)"
fi

print_summary
