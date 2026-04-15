#!/usr/bin/env bash
# =============================================================================
# PHPUnit: unit tests, integration tests, coverage, migration end-to-end
# =============================================================================

set -euo pipefail
# shellcheck source=tests-common.sh
source "$(dirname "$0")/tests-common.sh"

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
        MIGRATE_OUT=$(docker compose exec -T app bash -c \
            "DB_NAME=${SCRATCH_DB} php yii migrate --interactive=0 2>&1" || true)

        if echo "$MIGRATE_OUT" | grep -q "Migrated up successfully\|No new migrations found"; then
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
        DB_ROOT_PWD=$(grep -m1 '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)
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

        SETUP_OUT=$(php "$_PHP_HELPER" setup "$_DB_HOST" "$_DB_PORT" "$DB_ROOT_PWD" "$SCRATCH_DB" "$_DB_USER" 2>&1)

        if [[ "$SETUP_OUT" == "ok" ]]; then
            MIGRATE_OUT=$(DB_NAME=${SCRATCH_DB} php yii migrate --interactive=0 2>&1 || true)

            if echo "$MIGRATE_OUT" | grep -q "Migrated up successfully\|No new migrations found"; then
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

TEST_MIGRATE_OUT=$(dc sh -c 'DB_NAME=ansilume_test php yii migrate --interactive=0 2>&1' || true)
if echo "$TEST_MIGRATE_OUT" | grep -qP "applied|No new migrations"; then
    ok "Test database migrations up to date"
else
    fail "Test database migration failed"
    echo "$TEST_MIGRATE_OUT" | tail -15 | sed 's/^/     /'
fi

# =============================================================================
# PHPUnit — unit tests
# =============================================================================
section "PHPUnit — unit tests"

UNIT_OUT=$(dc php vendor/bin/phpunit --testsuite=Unit --colors=never 2>&1 || true)
if echo "$UNIT_OUT" | grep -qP "^OK|Tests: .* Failures: 0"; then
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
    COMBINED_OUT=$(dc php -d pcov.enabled=1 vendor/bin/phpunit \
        --testsuite=Unit,Integration \
        --coverage-text \
        --colors=never \
        2>&1 || true)

    # ── Integration pass/fail ────────────────────────────────────────────────
    if echo "$COMBINED_OUT" | grep -qP "^OK|Tests: .* Failures: 0"; then
        INT_SUMMARY=$(echo "$COMBINED_OUT" | grep -P "^OK|Tests:")
        ok "Integration tests passed ($INT_SUMMARY)"
    elif echo "$COMBINED_OUT" | grep -q "No tests executed"; then
        skip "Integration tests (no tests executed)"
    elif echo "$COMBINED_OUT" | grep -qi "Access denied\|Connection refused\|SQLSTATE\[HY000\] \[1044\]"; then
        skip "Integration tests (test database not available — run: php yii setup/test-db)"
    else
        fail "Integration tests failed"
        echo "$COMBINED_OUT" | tail -30 | sed 's/^/     /'
    fi

    # ── Coverage (from the same run) ─────────────────────────────────────────
    if echo "$COMBINED_OUT" | grep -q "Summary:"; then
        SUMMARY_BLOCK=$(echo "$COMBINED_OUT" | grep -A3 'Summary:')
        CLASS_COV=$(echo "$SUMMARY_BLOCK"  | grep -oP 'Classes:\s+\K[\d.]+%')
        METHOD_COV=$(echo "$COMBINED_OUT"  | grep -oP 'Methods:\s+\K[\d.]+%' | head -1)
        LINE_COV=$(echo "$COMBINED_OUT"    | grep -oP 'Lines:\s+\K[\d.]+%'   | head -1)
        ok "Coverage — Lines: ${LINE_COV}  Methods: ${METHOD_COV}  Classes: ${CLASS_COV}"
    elif echo "$COMBINED_OUT" | grep -qi "No code coverage driver"; then
        skip "Code coverage (no coverage driver — install pcov or xdebug)"
    else
        skip "Code coverage (could not parse output)"
    fi
fi

print_summary
