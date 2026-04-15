#!/usr/bin/env bash
# =============================================================================
# Playwright E2E browser tests
# =============================================================================

set -euo pipefail
# shellcheck source=tests-common.sh
source "$(dirname "$0")/tests-common.sh"

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
