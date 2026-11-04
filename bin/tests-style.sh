#!/usr/bin/env bash
# =============================================================================
# Code style: PSR-12, php-cs-fixer, PHPMD, PHPCPD
# =============================================================================

set -euo pipefail
# shellcheck source=tests-common.sh
source "$(dirname "$0")/tests-common.sh"

# Each check judges its tool by the tool's exit code: it passes on the code
# of a clean run, reports the findings on the code for findings, and fails
# on any other code with the code and the last lines the tool printed.
# Regression: the checks of phpcs and PHP-CS-Fixer read the number of
# findings from the report and took a report without a number for none
# (|| echo "0"), so a tool that ended before its report passed: a ruleset or
# a config it could not read, a PHP version newer than it supports, an
# exception, a fatal error. PHPMD passed when it printed nothing, and a
# fatal error prints nothing where php.ini only logs errors, as the app
# container's does: every tool runs with -d display_errors=stderr, which
# puts it into the output, and PHPCPD reported such a crash as clones
# (tests/unit/bin/StyleSuiteExitStatusTest.php).

# =============================================================================
# PHP_CodeSniffer — PSR-12
# =============================================================================
section "PHP_CodeSniffer (PSR-12)"

# phpcs exits 0 without errors, 1 with errors, 2 when phpcbf can fix some of
# them, 3 when it cannot run (a ruleset it cannot read, a standard that is
# not installed, a path that does not exist) and 255 on a fatal error.
if dc php vendor/bin/phpcs --version >/dev/null 2>&1; then
    PHPCS_RC=0
    PHPCS_OUT=$(dc php -d display_errors=stderr vendor/bin/phpcs \
        --standard=PSR12 \
        --extensions=php \
        --ignore=vendor,.composer,runtime,@runtime,web/assets,docker,migrations,tests \
        --warning-severity=0 \
        --report=summary \
        -q \
        . 2>&1) || PHPCS_RC=$?
    if [[ $PHPCS_RC -eq 0 ]]; then
        ok "PSR-12 check passed"
    elif [[ $PHPCS_RC -eq 1 || $PHPCS_RC -eq 2 ]]; then
        PHPCS_ERROR_COUNT=$(grep -m1 -oP 'A TOTAL OF \K\d+(?= ERROR)' <<< "$PHPCS_OUT" || echo "?")
        fail "PSR-12: $PHPCS_ERROR_COUNT error(s) found"
        tail -20 <<< "$PHPCS_OUT" | sed 's/^/     /'
    else
        fail "PSR-12: phpcs did not complete (exit $PHPCS_RC)"
        show_output_tail "$PHPCS_OUT"
    fi
else
    skip "phpcs not available"
fi

# =============================================================================
# PHP-CS-Fixer
# =============================================================================
section "PHP-CS-Fixer (Scrutinizer style)"

# The exit code of a dry run is a bit mask: 0 when no file needs fixing, 8
# when files do, 4 for a file it could not lint, 16 for a config it cannot
# use, 32 for an invalid rule option, 64 for an exception while fixing. It
# exits 1 on a PHP version newer than it supports, before it checks a file:
# 3.94 supports PHP syntax up to 8.5 (PHP_CS_FIXER_IGNORE_ENV would run it
# anyway, with the risk of wrong results), and 255 on a fatal error.
if dc php vendor/bin/php-cs-fixer --version >/dev/null 2>&1; then
    CSFIXER_RC=0
    CSFIXER_OUT=$(dc php -d display_errors=stderr vendor/bin/php-cs-fixer fix \
        --dry-run \
        --config=.php-cs-fixer.dist.php \
        --using-cache=no \
        2>&1) || CSFIXER_RC=$?
    if [[ $CSFIXER_RC -eq 0 ]]; then
        ok "PHP-CS-Fixer passed (0 fixable issues)"
    elif [[ $CSFIXER_RC -eq 8 ]]; then
        CSFIXER_COUNT=$(grep -m1 -oP 'Found \K\d+(?= of)' <<< "$CSFIXER_OUT" || echo "?")
        fail "PHP-CS-Fixer: $CSFIXER_COUNT file(s) need fixing — run: php vendor/bin/php-cs-fixer fix"
        grep -m 20 '^\s*[0-9]\+)' <<< "$CSFIXER_OUT" | sed 's/^/     /' || true
    else
        fail "PHP-CS-Fixer did not complete (exit $CSFIXER_RC)"
        show_output_tail "$CSFIXER_OUT"
    fi
else
    skip "php-cs-fixer not available"
fi

# =============================================================================
# PHPMD
# =============================================================================
section "PHPMD (complexity + unused code)"

if dc php vendor/bin/phpmd --version >/dev/null 2>&1; then
    # Every line PHPMD prints counts as a violation. PHPMD 3 takes the
    # format and the ruleset as options: the positional form of PHPMD 2
    # (phpmd <dir> text phpmd.xml) prints a deprecation, and without
    # --no-progress its progress bar goes to stderr. It no longer splits
    # --exclude vendor,tests at the comma. $(…) drops the last newline of a
    # directory's output, which glued its last violation to the first of the
    # next directory, so the outputs are joined with one
    # (tests/unit/bin/StyleSuitePhpmdTest.php). PHPMD exits 0 without
    # violations and 2 with them; 3 when it could not process a file, 1 for
    # an exception and 255 for a fatal error, such as a ruleset it cannot
    # find, which printed nothing and passed. The check stops at the first
    # directory PHPMD did not complete: a ruleset it cannot read fails every
    # directory alike.
    PHPMD_OUT=""
    PHPMD_INCOMPLETE=0
    for phpmd_dir in controllers services commands jobs components helpers models; do
        PHPMD_RC=0
        PHPMD_DIR_OUT=$(dc php -d display_errors=stderr vendor/bin/phpmd analyze "$phpmd_dir" \
            --format text \
            --ruleset phpmd.xml \
            --suffixes php \
            --exclude vendor --exclude tests \
            --no-progress \
            2>&1) || PHPMD_RC=$?
        if [[ $PHPMD_RC -ne 0 && $PHPMD_RC -ne 2 ]]; then
            fail "PHPMD did not complete for $phpmd_dir/ (exit $PHPMD_RC)"
            show_output_tail "$PHPMD_DIR_OUT"
            PHPMD_INCOMPLETE=1
            break
        fi
        if [[ -n "$PHPMD_DIR_OUT" ]]; then
            PHPMD_OUT+="${PHPMD_OUT:+$'\n'}${PHPMD_DIR_OUT}"
        fi
    done
    if [[ -n "$PHPMD_OUT" ]]; then
        PHPMD_COUNT=$(grep -c . <<< "$PHPMD_OUT" || true)
        fail "PHPMD: ${PHPMD_COUNT} violation(s) found"
        head -20 <<< "$PHPMD_OUT" | sed 's/^/     /'
    elif [[ $PHPMD_INCOMPLETE -eq 0 ]]; then
        ok "PHPMD passed (no violations)"
    fi
else
    skip "phpmd not available"
fi

# =============================================================================
# PHPCPD
# =============================================================================
section "PHPCPD (copy-paste detection)"

if dc php vendor/bin/phpcpd --version >/dev/null 2>&1; then
    # phpcpd 7 exits 0 without clones and 1 with them, after "Found N code
    # clones" (plural for one too), but 1 as well for an argument error and
    # for "No files found to scan", and 255 on a fatal error. An exit 1 is
    # clones only with that line; anything else did not complete. Up to
    # 2.14.0 every code but 0 counted as clones, so a fatal error failed as
    # "? clone(s) found" with nothing under it but phpcpd's version. The
    # text match before that never matched phpcpd 7's "Found N code clones"
    # wording, so duplicated code passed silently.
    # runtime/ is git-ignored scratch space and never shipped (phpcs and
    # php-cs-fixer skip it too); throwaway copies of classes there, such as
    # runtime/verify-*/Old*.php, were reported as clones.
    PHPCPD_RC=0
    PHPCPD_OUT=$(dc php -d display_errors=stderr vendor/bin/phpcpd \
        --min-lines=15 \
        --min-tokens=70 \
        --exclude=vendor --exclude=tests --exclude=migrations --exclude=views \
        --exclude=runtime --exclude=@runtime \
        . 2>&1) || PHPCPD_RC=$?
    CLONE_COUNT=""
    if [[ $PHPCPD_RC -eq 1 ]]; then
        CLONE_COUNT=$(grep -m1 -oP 'Found \K\d+(?= code clones)' <<< "$PHPCPD_OUT" || true)
    fi
    if [[ $PHPCPD_RC -eq 0 ]]; then
        ok "PHPCPD passed (no duplications)"
    elif [[ -n "$CLONE_COUNT" ]]; then
        fail "PHPCPD: ${CLONE_COUNT} clone(s) found"
        head -20 <<< "$PHPCPD_OUT" | sed 's/^/     /'
    else
        fail "PHPCPD did not complete (exit $PHPCPD_RC)"
        show_output_tail "$PHPCPD_OUT"
    fi
else
    skip "phpcpd not available"
fi

print_summary
