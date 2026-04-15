#!/usr/bin/env bash
# =============================================================================
# PHPStan static analysis
# =============================================================================

set -euo pipefail
# shellcheck source=tests-common.sh
source "$(dirname "$0")/tests-common.sh"

section "PHPStan (level max)"

if [[ $FAST -eq 1 ]]; then
    skip "PHPStan (--fast mode)"
elif dc php vendor/bin/phpstan --version >/dev/null 2>&1; then
    PHPSTAN_OUT=$(dc php vendor/bin/phpstan analyse \
        --no-progress \
        --error-format=table \
        --configuration=phpstan.neon \
        2>&1 || true)
    if echo "$PHPSTAN_OUT" | grep -q "\[OK\]"; then
        ok "PHPStan level max passed"
    else
        fail "PHPStan found errors"
        echo "$PHPSTAN_OUT" | tail -30 | sed 's/^/     /'
    fi
else
    skip "phpstan not available"
fi

print_summary
