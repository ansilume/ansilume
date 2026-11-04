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
# queue-worker, notification-worker and schedule-runner containers run
# `php yii …` commands. Left at the default USER of the php-fpm base image
# they exec as root — and everything
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
    # The php-fpm base image ships without procps, so `ps` is unavailable.
    # Read PID 1's effective UID from /proc/1/status and compare it to
    # www-data's UID inside the container. The dev Dockerfile remaps
    # www-data's UID to the host UID (USER_ID build arg), so comparing by
    # name or a hardcoded 33 would both be wrong.
    for svc in queue-worker notification-worker schedule-runner; do
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
    # Seed E2E test data. As root, as dc runs both E2E data commands: the
    # seed makes files and directories that php-fpm's user may not read
    # (E2eUnreadableArtifactSeeder, E2eUnreadableProjectSeeder), which only
    # root can make. They run only where YII_ENV is dev or test, never on an
    # installation (tests/unit/YiiExecUserTest.php, AS_ROOT).
    # The seed and the Playwright run are judged by their exit codes: the
    # seed printed "already exists" for the items it found before it failed,
    # and a run that ended with an error after "N passed" printed no
    # "N failed". The run needs its "N passed" as well: Playwright exits 0
    # when every test was skipped (tests/unit/bin/E2eSuiteExitStatusTest.php).
    SEED_RC=0
    SEED_OUT=$(dc php yii e2e/seed 2>&1) || SEED_RC=$?
    if [[ $SEED_RC -eq 0 ]]; then
        # Build the Playwright image only (the specs are baked in) and run it
        # against the running stack: `run --build` would rebuild the images of
        # the stack too, each pulling its base image (build.pull).
        E2E_RC=0
        E2E_OUT=$({ docker compose --profile e2e build playwright && docker compose --profile e2e run --rm playwright; } 2>&1) || E2E_RC=$?
        # The summary's last "N passed" / "N failed" / "N skipped" lines. A
        # run passes only with exit 0, a "N passed" line and no "N failed"
        # with N > 0: a run whose every test was skipped exits 0 and ran no
        # test. The counts fill the message too.
        E2E_PASSED=$(grep -oP '\d+(?= passed)' <<< "$E2E_OUT" | tail -1 || true)
        E2E_FAILED=$(grep -oP '\d+(?= failed)' <<< "$E2E_OUT" | tail -1 || true)
        E2E_SKIPPED=$(grep -oP '\d+(?= skipped)' <<< "$E2E_OUT" | tail -1 || true)
        E2E_PASSED=${E2E_PASSED:-0}
        E2E_FAILED=${E2E_FAILED:-0}
        E2E_SKIPPED=${E2E_SKIPPED:-0}

        if [[ $E2E_RC -eq 0 ]] && [[ "$E2E_FAILED" -eq 0 ]] && [[ "$E2E_PASSED" -gt 0 ]]; then
            ok "Playwright E2E passed (${E2E_PASSED} tests, ${E2E_SKIPPED} skipped)"
        elif [[ "$E2E_PASSED" -gt 0 ]]; then
            fail "Playwright E2E: ${E2E_PASSED} passed, ${E2E_FAILED} failed, ${E2E_SKIPPED} skipped (exit ${E2E_RC})"
            tail -30 <<< "$E2E_OUT" | sed 's/^/     /'
        else
            fail "Playwright E2E tests did not run (exit ${E2E_RC})"
            show_output_tail "$E2E_OUT"
        fi

        # Teardown E2E data. It exits non-zero when e2e users or fixtures are
        # left behind, which the next seed would silently build on.
        TEARDOWN_RC=0
        TEARDOWN_OUT=$(dc php yii e2e/teardown 2>&1) || TEARDOWN_RC=$?
        if [[ $TEARDOWN_RC -eq 0 ]]; then
            ok "E2E teardown complete"
        else
            fail "E2E teardown failed (exit ${TEARDOWN_RC})"
            echo "$TEARDOWN_OUT" | tail -15 | sed 's/^/     /'
        fi
    else
        fail "E2E seed failed (exit ${SEED_RC})"
        show_output_tail "$SEED_OUT"
    fi
else
    skip "Playwright E2E tests (Docker not available)"
fi

print_summary
