#!/usr/bin/env bash
# =============================================================================
# PHPUnit: unit tests, integration tests, coverage, migration end-to-end
# =============================================================================

set -euo pipefail
# shellcheck source=tests-common.sh
source "$(dirname "$0")/tests-common.sh"

# Runs a command in the app container as www-data, the user of php-fpm's
# workers, or locally as dc does without Docker. The migrations run so: a
# console command logs to runtime/logs/app.log, and run as root, one that
# rotates a full log leaves copies that www-data cannot write
# (tests/unit/YiiExecUserTest.php). PHPUnit itself runs as root through dc,
# as the tests that drop privileges need (tests/unit/RunsUnprivileged.php),
# and logs to runtime/logs/test.log.
dc_www_data() {
    if [[ $DOCKER_AVAILABLE -eq 1 ]]; then
        docker compose exec -T -u www-data app "$@"
    else
        "$@"
    fi
}

# =============================================================================
# Migrations end-to-end on a clean database
# =============================================================================
section "Migrations (end-to-end)"

if [[ $FAST -eq 1 ]]; then
    skip "Migration run (--fast mode)"
elif [[ $DOCKER_AVAILABLE -eq 1 ]]; then
    DB_ROOT_PWD="${DB_ROOT_PASSWORD:-rootsecret}"
    SCRATCH_DB="ansilume_migrate_test"

    SETUP_SQL="DROP DATABASE IF EXISTS \`${SCRATCH_DB}\`; CREATE DATABASE \`${SCRATCH_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`${SCRATCH_DB}\`.* TO '${DB_USER:-ansilume}'@'%'; FLUSH PRIVILEGES;"

    if docker compose exec -T db mariadb -uroot -p"${DB_ROOT_PWD}" -e "${SETUP_SQL}" 2>/dev/null; then
        MIGRATE_OUT=$(dc_www_data bash -c \
            "DB_NAME=${SCRATCH_DB} php yii migrate --interactive=0 2>&1" || true)

        if grep -q "Migrated up successfully\|No new migrations found" <<< "$MIGRATE_OUT"; then
            MIGRATION_COUNT=$(echo "$MIGRATE_OUT" | grep -cP '^\*\*\* applied' || true)
            ok "All migrations applied cleanly on a fresh schema (${MIGRATION_COUNT} migrations)"
        else
            fail "Migrations failed on a fresh schema"
            echo "$MIGRATE_OUT" | tail -30 | sed 's/^/     /'
        fi

        docker compose exec -T db mariadb -uroot -p"${DB_ROOT_PWD}" \
            -e "DROP DATABASE IF EXISTS \`${SCRATCH_DB}\`;" 2>/dev/null || true
    else
        skip "Migration end-to-end test (cannot connect to DB container as root)"
    fi
else
    DB_ROOT_PWD="${DB_ROOT_PASSWORD:-}"
    if [[ -z "$DB_ROOT_PWD" ]] && [[ -f ".env" ]]; then
        DB_ROOT_PWD=$(grep -m1 '^DB_ROOT_PASSWORD=' .env | cut -d= -f2- || true)
    fi

    if [[ -n "$DB_ROOT_PWD" ]]; then
        SCRATCH_DB="ansilume_migrate_test"
        _DB_HOST="${DB_HOST:-db}"
        _DB_PORT="${DB_PORT:-3306}"
        _DB_USER="${DB_USER:-ansilume}"

        _PHP_HELPER=$(mktemp /tmp/ansilume_migrate_XXXXXX.php)
        cat > "$_PHP_HELPER" <<'PHPEOF'
<?php
[, $action, $host, $port, $rootPwd, $db, $user] = $argv + [1=>'',2=>'',3=>'',4=>'',5=>'',6=>'',7=>''];
try {
    $pdo = new PDO("mysql:host={$host};port={$port}", 'root', $rootPwd);
    if ($action === 'setup') {
        $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
        $pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$db}`.* TO '{$user}'@'%'");
        $pdo->exec("FLUSH PRIVILEGES");
    } elseif ($action === 'drop') {
        $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
    }
    echo 'ok';
} catch (Exception $e) {
    echo 'error: ' . $e->getMessage();
    exit(1);
}
PHPEOF

        # The helper prints its error and exits 1: || true keeps the error
        # for the skip below, where set -e ended the suite without it.
        SETUP_OUT=$(php "$_PHP_HELPER" setup "$_DB_HOST" "$_DB_PORT" "$DB_ROOT_PWD" "$SCRATCH_DB" "$_DB_USER" 2>&1 || true)

        if [[ "$SETUP_OUT" == "ok" ]]; then
            MIGRATE_OUT=$(DB_NAME=${SCRATCH_DB} php yii migrate --interactive=0 2>&1 || true)

            if grep -q "Migrated up successfully\|No new migrations found" <<< "$MIGRATE_OUT"; then
                MIGRATION_COUNT=$(echo "$MIGRATE_OUT" | grep -cP '^\*\*\* applied' || true)
                ok "All migrations applied cleanly on a fresh schema (${MIGRATION_COUNT} migrations)"
            else
                fail "Migrations failed on a fresh schema"
                echo "$MIGRATE_OUT" | tail -30 | sed 's/^/     /'
            fi

            php "$_PHP_HELPER" drop "$_DB_HOST" "$_DB_PORT" "$DB_ROOT_PWD" "$SCRATCH_DB" 2>/dev/null || true
        else
            skip "Migration end-to-end test (cannot connect to DB as root: ${SETUP_OUT})"
        fi

        rm -f "$_PHP_HELPER"
    else
        skip "Migration end-to-end test (Docker not available and DB_ROOT_PASSWORD not set)"
    fi
fi

# =============================================================================
# Test database migrations
# =============================================================================
section "Test database migrations"

TEST_MIGRATE_OUT=$(dc_www_data sh -c 'DB_NAME=ansilume_test php yii migrate --interactive=0 2>&1' || true)
if grep -qP "applied|No new migrations" <<< "$TEST_MIGRATE_OUT"; then
    ok "Test database migrations up to date"
else
    fail "Test database migration failed"
    echo "$TEST_MIGRATE_OUT" | tail -15 | sed 's/^/     /'
fi

# =============================================================================
# PHPUnit — unit tests
# =============================================================================
section "PHPUnit — unit tests"

# Every PHPUnit run gets 1G instead of the container's CLI memory_limit
# (256M): the coverage run below outgrew it with 2.11.0's tests. See
# tests/unit/bin/PhpunitMemoryLimitTest.php.
UNIT_OUT=$(dc php -d memory_limit=1G vendor/bin/phpunit --testsuite=Unit --colors=never 2>&1 || true)
if grep -qP "^OK|Tests: .* Failures: 0" <<< "$UNIT_OUT"; then
    SUMMARY=$(echo "$UNIT_OUT" | grep -P "^OK|Tests:")
    ok "Unit tests passed ($SUMMARY)"
else
    fail "Unit tests failed"
    echo "$UNIT_OUT" | tail -30 | sed 's/^/     /'
fi

# =============================================================================
# PHPUnit — integration tests + coverage
# =============================================================================
section "PHPUnit — integration tests + coverage"

if [[ $FAST -eq 1 ]]; then
    skip "Integration tests (--fast mode)"
    skip "Code coverage (--fast mode)"
else
    # Coverage keeps, for every covered line, the tests that ran it, so the
    # run's memory grows with the number of tests: 10444 tests peaked at
    # 254.5 MB and crashed at the container's 256M (2026-10-13).
    COMBINED_OUT=$(dc php -d pcov.enabled=1 -d memory_limit=1G vendor/bin/phpunit \
        --testsuite=Unit,Integration \
        --coverage-text \
        --colors=never \
        2>&1 || true)

    # ── Integration pass/fail ────────────────────────────────────────────────
    if grep -qP "^OK|Tests: .* Failures: 0" <<< "$COMBINED_OUT"; then
        INT_SUMMARY=$(echo "$COMBINED_OUT" | grep -P "^OK|Tests:")
        ok "Integration tests passed ($INT_SUMMARY)"
    elif grep -q "No tests executed" <<< "$COMBINED_OUT"; then
        skip "Integration tests (no tests executed)"
    elif grep -qi "Access denied\|Connection refused\|SQLSTATE\[HY000\] \[1044\]" <<< "$COMBINED_OUT"; then
        skip "Integration tests (test database not available — create it: docker compose exec -T db sh -c 'mariadb -uroot -p\"\$MYSQL_ROOT_PASSWORD\" < /docker-entrypoint-initdb.d/init-test-db.sql', then migrate it: docker compose exec -T -u www-data app sh -c 'DB_NAME=ansilume_test php yii migrate --interactive=0')"
    else
        fail "Integration tests failed"
        # PHPUnit prints the failing-test details BEFORE the --coverage-text
        # report, so a blind `tail` here only ever shows the per-class coverage
        # table — which is exactly why an integration regression sat undiagnosed
        # on red CI for weeks. Drop the coverage report first, then tail the
        # real test output (FAILURES!/ERRORS! block + summary).
        awk '/Code Coverage Report/{exit} {print}' <<< "$COMBINED_OUT" | tail -40 | sed 's/^/     /'
    fi

    # ── Coverage (from the same run) ─────────────────────────────────────────
    # A "Summary:" without figures (a run that died before its report after
    # an error that names DbErrorSummary::of(), or a total of 0, which has no
    # percentage) cannot be parsed: the figures are read with || true, so
    # that set -e does not end the suite and the skip below says so.
    LINE_COV=""
    if grep -q "Summary:" <<< "$COMBINED_OUT"; then
        SUMMARY_BLOCK=$(echo "$COMBINED_OUT" | grep -A3 'Summary:')
        CLASS_COV=$(echo "$SUMMARY_BLOCK"  | grep -oP 'Classes:\s+\K[\d.]+%' || true)
        METHOD_COV=$(grep -m1 -oP 'Methods:\s+\K[\d.]+%' <<< "$COMBINED_OUT" || true)
        LINE_COV=$(grep -m1 -oP 'Lines:\s+\K[\d.]+%' <<< "$COMBINED_OUT" || true)
    fi
    if [[ -n "$LINE_COV" ]]; then
        ok "Coverage — Lines: ${LINE_COV}  Methods: ${METHOD_COV}  Classes: ${CLASS_COV}"
    elif grep -qi "No code coverage driver" <<< "$COMBINED_OUT"; then
        skip "Code coverage (no coverage driver — install pcov or xdebug)"
    else
        skip "Code coverage (could not parse output)"
    fi
fi

print_summary
