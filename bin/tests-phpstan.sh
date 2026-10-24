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
    # The parallel workers would inherit the container's CLI memory_limit
    # (256M). On a 4-core CI runner each worker analyses a quarter of the
    # files and runs out of it (the CI run of v2.10.0); PHPStan hands
    # --memory-limit to every worker. See tests/unit/bin/PhpstanMemoryLimitTest.php.
    PHPSTAN_OUT=$(dc php vendor/bin/phpstan analyse \
        --no-progress \
        --error-format=table \
        --memory-limit=1G \
        --configuration=phpstan.neon \
        2>&1 || true)
    if grep -q "\[OK\]" <<< "$PHPSTAN_OUT"; then
        ok "PHPStan level max passed"
    else
        fail "PHPStan found errors"
        echo "$PHPSTAN_OUT" | tail -30 | sed 's/^/     /'
    fi
else
    skip "phpstan not available"
fi

print_summary
