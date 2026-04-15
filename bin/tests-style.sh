#!/usr/bin/env bash
# =============================================================================
# Code style: PSR-12, php-cs-fixer, PHPMD, PHPCPD
# =============================================================================

set -euo pipefail
# shellcheck source=tests-common.sh
source "$(dirname "$0")/tests-common.sh"

# =============================================================================
# PHP_CodeSniffer — PSR-12
# =============================================================================
section "PHP_CodeSniffer (PSR-12)"

if dc php vendor/bin/phpcs --version >/dev/null 2>&1; then
    PHPCS_OUT=$(dc php vendor/bin/phpcs \
        --standard=PSR12 \
        --extensions=php \
        --ignore=vendor,.composer,runtime,@runtime,web/assets,docker,migrations,tests \
        --warning-severity=0 \
        --report=summary \
        -q \
        . 2>&1 || true)
    PHPCS_ERROR_COUNT=$(echo "$PHPCS_OUT" | grep -oP 'A TOTAL OF \K\d+(?= ERROR)' || echo "0")
    if [[ "$PHPCS_ERROR_COUNT" -eq 0 ]]; then
        ok "PSR-12 check passed"
    else
        fail "PSR-12: $PHPCS_ERROR_COUNT error(s) found"
        echo "$PHPCS_OUT" | tail -20 | sed 's/^/     /'
    fi
else
    skip "phpcs not available"
fi

# =============================================================================
# PHP-CS-Fixer
# =============================================================================
section "PHP-CS-Fixer (Scrutinizer style)"

if dc php vendor/bin/php-cs-fixer --version >/dev/null 2>&1; then
    CSFIXER_OUT=$(dc php vendor/bin/php-cs-fixer fix \
        --dry-run \
        --config=.php-cs-fixer.dist.php \
        --using-cache=no \
        2>&1 || true)
    CSFIXER_COUNT=$(echo "$CSFIXER_OUT" | grep -oP 'Found \K\d+(?= of)' || echo "0")
    if [[ "$CSFIXER_COUNT" -eq 0 ]]; then
        ok "PHP-CS-Fixer passed (0 fixable issues)"
    else
        fail "PHP-CS-Fixer: $CSFIXER_COUNT file(s) need fixing — run: php vendor/bin/php-cs-fixer fix"
        echo "$CSFIXER_OUT" | grep '^\s*[0-9]\+)' | head -20 | sed 's/^/     /'
    fi
else
    skip "php-cs-fixer not available"
fi

# =============================================================================
# PHPMD
# =============================================================================
section "PHPMD (complexity + unused code)"

if dc php vendor/bin/phpmd --version >/dev/null 2>&1; then
    PHPMD_OUT=""
    for phpmd_dir in controllers services commands jobs components helpers models; do
        PHPMD_OUT+=$(dc php vendor/bin/phpmd \
            "$phpmd_dir" text phpmd.xml \
            --suffixes php \
            --exclude vendor,tests \
            2>&1 || true)
    done
    if [[ -z "$PHPMD_OUT" ]]; then
        ok "PHPMD passed (no violations)"
    else
        PHPMD_COUNT=$(echo "$PHPMD_OUT" | grep -c . || echo "0")
        fail "PHPMD: ${PHPMD_COUNT} violation(s) found"
        echo "$PHPMD_OUT" | head -20 | sed 's/^/     /'
    fi
else
    skip "phpmd not available"
fi

# =============================================================================
# PHPCPD
# =============================================================================
section "PHPCPD (copy-paste detection)"

if dc php vendor/bin/phpcpd --version >/dev/null 2>&1; then
    PHPCPD_OUT=$(dc php vendor/bin/phpcpd \
        --min-lines=15 \
        --min-tokens=70 \
        --exclude=vendor --exclude=tests --exclude=migrations --exclude=views \
        . 2>&1 || true)
    if echo "$PHPCPD_OUT" | grep -q "0.00% duplicated"; then
        ok "PHPCPD passed (no duplications)"
    elif echo "$PHPCPD_OUT" | grep -qP "Found \d+ clones"; then
        CLONE_COUNT=$(echo "$PHPCPD_OUT" | grep -oP 'Found \K\d+(?= clones)')
        fail "PHPCPD: ${CLONE_COUNT} clone(s) found"
        echo "$PHPCPD_OUT" | head -20 | sed 's/^/     /'
    else
        ok "PHPCPD passed"
    fi
else
    skip "phpcpd not available"
fi

print_summary
