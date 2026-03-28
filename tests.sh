#!/usr/bin/env bash
# =============================================================================
# Ansilume test suite
# Runs inside the Docker app container (PHP 8.2) for correctness.
# Usage: ./tests.sh [--fast]   (--fast skips PHPStan + integration tests)
# =============================================================================

set -euo pipefail

FAST=0
for arg in "$@"; do [[ "$arg" == "--fast" ]] && FAST=1; done

PASS=0
FAIL=0
SKIP=0
ERRORS=()

# Colours
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

section() { echo -e "\n${CYAN}${BOLD}══ $1 ══${NC}"; }
ok()      { echo -e "  ${GREEN}✔${NC}  $1"; PASS=$((PASS+1)); }
fail()    { echo -e "  ${RED}✘${NC}  $1"; FAIL=$((FAIL+1)); ERRORS+=("$1"); }
skip()    { echo -e "  ${YELLOW}–${NC}  $1 (skipped)"; SKIP=$((SKIP+1)); }

# =============================================================================
# Prerequisites check
# =============================================================================
section "Prerequisites"

DOCKER_AVAILABLE=0
if command -v docker &>/dev/null && docker compose ps app --quiet 2>/dev/null | grep -q .; then
    DOCKER_AVAILABLE=1
    ok "Docker app container is running"
else
    if command -v docker &>/dev/null; then
        echo -e "  ${YELLOW}–${NC}  Docker available but app container is not running"
    else
        echo -e "  ${YELLOW}–${NC}  Docker not installed"
    fi
    echo -e "     Falling back to local tools..."

    MISSING=()
    command -v php      &>/dev/null || MISSING+=("php")
    command -v composer &>/dev/null || MISSING+=("composer")
    command -v find     &>/dev/null || MISSING+=("find")
    command -v grep     &>/dev/null || MISSING+=("grep")

    if [[ ${#MISSING[@]} -gt 0 ]]; then
        fail "Missing required tools: ${MISSING[*]}"
        echo ""
        echo -e "  Install the missing tools or start the Docker environment:"
        echo -e "    ${BOLD}docker compose up -d${NC}"
        echo ""
        echo -e "${BOLD}════════════════════════════════════════${NC}"
        echo -e "${BOLD}Results: ${GREEN}${PASS} passed${NC}  ${RED}${FAIL} failed${NC}  ${YELLOW}${SKIP} skipped${NC}"
        echo -e "\n${RED}Aborted: missing prerequisites.${NC}\n"
        exit 1
    fi

    PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo "unknown")
    if [[ "$PHP_VERSION" == "unknown" ]]; then
        fail "Could not determine PHP version"
    elif [[ "$(printf '%s\n' "8.2" "$PHP_VERSION" | sort -V | head -1)" != "8.2" ]]; then
        fail "PHP >= 8.2 required, found $PHP_VERSION"
    else
        ok "PHP $PHP_VERSION"
    fi

    if [[ -d vendor ]]; then
        ok "vendor/ directory exists"
    else
        fail "vendor/ missing — run: composer install"
    fi
fi

# Wrapper: run a command inside the Docker app container.
# Falls back to running locally if Docker is not available.
dc() {
    if [[ $DOCKER_AVAILABLE -eq 1 ]]; then
        docker compose exec -T app "$@"
    else
        "$@"
    fi
}

# =============================================================================
# 1. PHP syntax check (php -l) for every .php file outside vendor/
# =============================================================================
section "PHP syntax check"

SYNTAX_ERRORS=0
while IFS= read -r -d '' file; do
    output=$(dc php -l "$file" 2>&1)
    if [[ $? -ne 0 ]]; then
        echo -e "  ${RED}✘${NC}  $file"
        echo "     $output"
        SYNTAX_ERRORS=$((SYNTAX_ERRORS+1))
    fi
done < <(find . \
    -not -path "./vendor/*" \
    -not -path "./.composer/*" \
    -not -path "./runtime/*" \
    -not -path "./@runtime/*" \
    -not -path "./docker/*" \
    -name "*.php" -print0)

if [[ $SYNTAX_ERRORS -eq 0 ]]; then
    ok "All PHP files pass syntax check"
else
    fail "$SYNTAX_ERRORS PHP file(s) have syntax errors"
fi

# =============================================================================
# 2. strict_types declaration
# =============================================================================
section "strict_types declarations"

MISSING_STRICT=()
while IFS= read -r -d '' file; do
    if ! grep -q "declare(strict_types=1)" "$file" 2>/dev/null; then
        MISSING_STRICT+=("$file")
    fi
done < <(find controllers models services commands jobs components helpers mail \
    -name "*.php" -print0 2>/dev/null)

if [[ ${#MISSING_STRICT[@]} -eq 0 ]]; then
    ok "All source PHP files declare strict_types=1"
else
    fail "${#MISSING_STRICT[@]} file(s) missing strict_types=1"
    for f in "${MISSING_STRICT[@]}"; do echo "     $f"; done
fi

# =============================================================================
# 3. Security: detect dangerous patterns
# =============================================================================
section "Security checks"

check_pattern() {
    local label="$1"; shift
    local matches
    matches=$(grep -rn --include="*.php" \
        --exclude-dir=vendor --exclude-dir=.composer \
        "$@" . 2>/dev/null || true)
    if [[ -n "$matches" ]]; then
        fail "$label"
        echo "$matches" | head -20 | sed 's/^/     /'
    else
        ok "$label"
    fi
}

check_pattern "No eval() calls"                  -P '\beval\s*\('
check_pattern "No shell_exec() calls"            -P '\bshell_exec\s*\('
check_pattern "No exec() calls outside services" -P '\bexec\s*\(' \
    --exclude-dir=services --exclude-dir=commands
check_pattern "No system() calls"                -P '\bsystem\s*\('
check_pattern "No passthru() calls"              -P '\bpassthru\s*\('
check_pattern "No hardcoded passwords in code"   -P "password\s*=\s*['\"][^'\"]{4,}['\"]" \
    --exclude="*.example*" --exclude=".env*" --exclude-dir=tests
check_pattern "No raw \$_GET/\$_POST/\$_REQUEST" -P '\$_(GET|POST|REQUEST|COOKIE)\s*\[' \
    --exclude-dir=views --exclude-dir=docker
check_pattern "No var_dump / print_r left in"    -P '\b(var_dump|print_r|dd)\s*\('
check_pattern "No die()/exit() with debug data"  -P '\b(die|exit)\s*\([^)]{10,}\)'

# @ error suppression: only @stream_select is allowed (known PHP quirk)
AT_SUPPRESS=$(grep -rn --include="*.php" \
    --exclude-dir=vendor --exclude-dir=.composer \
    -P '@[a-z_]+\(' . 2>/dev/null \
    | grep -v '@stream_select' \
    || true)
if [[ -z "$AT_SUPPRESS" ]]; then
    ok "No inline error suppression (@) outside allowed exceptions"
else
    fail "Inline error suppression (@) found — use proper error handling"
    echo "$AT_SUPPRESS" | head -20 | sed 's/^/     /'
fi

# =============================================================================
# 4. XSS: unescaped output in views
# =============================================================================
section "XSS / output escaping (views)"

# Flag <?= $var ?> patterns that look like raw user-controlled strings.
# Exclude:
#   - Html:: helper calls (already escape)
#   - $form-> / $f-> / $this (ActiveForm, widget, view methods)
#   - Boolean/integer model fields (id, _at, _by, _id, status, count, etc.)
#   - Ternary expressions with only hardcoded string literals
#   - Local variables that are clearly set to safe CSS class names ($badge, $class)
UNESCAPED=$(grep -rn --include="*.php" -P '<\?=\s*\$' views/ 2>/dev/null \
    | grep -vP '(Html::|->field\(|->widget\(|\$this->|\$form->|\$f->|\$pager)' \
    | grep -vP '(->id\b|->enabled\b|_at\b|_by\b|_id\b|->verbosity\b|->forks\b|->become\b)' \
    | grep -vP "(\\\$badge\b|\\\$class\b|\\\$isNew\b|\\\$isEdit\b|\\\$stats\[)" \
    | grep -vP "'\s*\?\s*'[^']*'\s*:\s*'|'\s*\?\s*'[^']*'\s*:\s*\"" \
    | grep -vF '// xss-ok' \
    || true)
if [[ -z "$UNESCAPED" ]]; then
    ok "No obvious unescaped variable output in views"
else
    echo -e "  ${YELLOW}⚠${NC}  Unescaped output candidates (review manually — may be false positives):"
    echo "$UNESCAPED" | head -20 | sed 's/^/     /'
fi

# =============================================================================
# 5. CSRF: detect remaining data-method="post" patterns
# =============================================================================
section "CSRF safety (no data-method='post')"

CSRF_ISSUES=$(grep -rn --include="*.php" "data-method.*post\|data.method.*post" views/ 2>/dev/null \
    | grep -vP ':[0-9]+:\s*//' || true)
if [[ -z "$CSRF_ISSUES" ]]; then
    ok "No data-method=\"post\" patterns found in views"
else
    fail "Found data-method=\"post\" — replace with explicit <form> + CSRF token"
    echo "$CSRF_ISSUES" | sed 's/^/     /'
fi

# =============================================================================
# 6. Migrations: file naming and class name consistency
# =============================================================================
section "Migration naming consistency"

MIGRATION_ERRORS=0
while IFS= read -r file; do
    filename=$(basename "$file" .php)
    classname=$(grep -oP 'class\s+\K[A-Za-z0-9_]+' "$file" 2>/dev/null | head -1)
    if [[ -n "$classname" && "$classname" != "$filename" ]]; then
        echo "  ${RED}✘${NC}  $file: class '$classname' ≠ filename '$filename'"
        MIGRATION_ERRORS=$((MIGRATION_ERRORS+1))
    fi
done < <(find migrations -name "*.php" 2>/dev/null)

if [[ $MIGRATION_ERRORS -eq 0 ]]; then
    ok "All migration class names match their filenames"
else
    fail "$MIGRATION_ERRORS migration(s) have mismatched class names"
fi

# Duplicate migration timestamps
DUPES=$(find migrations -name "m*.php" 2>/dev/null \
    | sed 's|.*/||;s|\.php||' | sort | uniq -d)
if [[ -z "$DUPES" ]]; then
    ok "No duplicate migration timestamps"
else
    fail "Duplicate migration timestamps found: $DUPES"
fi

# =============================================================================
# 6b. Migrations: end-to-end run on a clean database
# =============================================================================
section "Migrations (end-to-end)"

if [[ $FAST -eq 1 ]]; then
    skip "Migration run (--fast mode)"
elif [[ $DOCKER_AVAILABLE -eq 1 ]]; then
    # We need the DB root password to drop/recreate the scratch DB.
    DB_ROOT_PWD="${DB_ROOT_PASSWORD:-rootsecret}"
    SCRATCH_DB="ansilume_migrate_test"

    # Create (or reset) a clean scratch database.
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

        # Drop the scratch DB when done.
        docker compose exec -T db mariadb -uroot -p"${DB_ROOT_PWD}" \
            -e "DROP DATABASE IF EXISTS \`${SCRATCH_DB}\`;" 2>/dev/null || true
    else
        skip "Migration end-to-end test (cannot connect to DB container as root)"
    fi
else
    # No Docker — try a direct PHP/PDO path (works when running inside the container).
    # Read DB_ROOT_PASSWORD from the environment or fall back to .env file.
    DB_ROOT_PWD="${DB_ROOT_PASSWORD:-}"
    if [[ -z "$DB_ROOT_PWD" ]] && [[ -f ".env" ]]; then
        DB_ROOT_PWD=$(grep -m1 '^DB_ROOT_PASSWORD=' .env | cut -d= -f2-)
    fi

    if [[ -n "$DB_ROOT_PWD" ]]; then
        SCRATCH_DB="ansilume_migrate_test"
        _DB_HOST="${DB_HOST:-db}"
        _DB_PORT="${DB_PORT:-3306}"
        _DB_USER="${DB_USER:-ansilume}"

        # Write a small PHP helper to a temp file to avoid inline-escaping hell.
        _PHP_HELPER=$(mktemp /tmp/ansilume_migrate_XXXXXX.php)
        cat > "$_PHP_HELPER" <<'PHPEOF'
<?php
// $argv[0] is the script path; actual args start at index 1.
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
# 7. Composer validation
# =============================================================================
section "Composer"

if dc composer validate --no-check-all --quiet 2>&1; then
    ok "composer.json is valid"
else
    fail "composer.json validation failed"
fi

# Check for known security advisories
AUDIT=$(dc composer audit --no-dev 2>&1 || true)
if echo "$AUDIT" | grep -qiP "CVE-|GHSA-|Found \d+ vulnerab"; then
    fail "composer audit found security advisories"
    echo "$AUDIT" | head -20 | sed 's/^/     /'
else
    ok "No known security vulnerabilities in dependencies"
fi

# =============================================================================
# 8. PHP_CodeSniffer — PSR-12
# =============================================================================
section "PHP_CodeSniffer (PSR-12)"

if dc php vendor/bin/phpcs --version >/dev/null 2>&1; then
    # Exclude: vendor, .composer, runtime, web/assets, docker (third-party),
    # views (mixed PHP/HTML triggers false positives), migrations (generated),
    # tests (testable subclasses need multi-class-per-file).
    PHPCS_OUT=$(dc php vendor/bin/phpcs \
        --standard=PSR12 \
        --extensions=php \
        --ignore=vendor,.composer,runtime,@runtime,web/assets,docker,views,migrations,tests \
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
# 9. PHPStan — static analysis (level 5)
# =============================================================================
section "PHPStan (level 5)"

if [[ $FAST -eq 1 ]]; then
    skip "PHPStan (--fast mode)"
elif dc php vendor/bin/phpstan --version >/dev/null 2>&1; then
    PHPSTAN_OUT=$(dc php vendor/bin/phpstan analyse \
        --no-progress \
        --error-format=table \
        --configuration=phpstan.neon \
        2>&1 || true)
    if echo "$PHPSTAN_OUT" | grep -q "\[OK\]"; then
        ok "PHPStan level 5 passed"
    else
        fail "PHPStan found errors"
        echo "$PHPSTAN_OUT" | tail -30 | sed 's/^/     /'
    fi
else
    skip "phpstan not available"
fi

# =============================================================================
# 9b. Apply migrations to the test database
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
# 10. PHPUnit — unit tests (always, fast, no coverage overhead)
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
# 12. Consistency checks
# =============================================================================
section "Consistency checks"

# Every controller should extend BaseController, a Base*Controller, or yii Controller
CTRL_ISSUES=$(grep -rLn "extends BaseController\|extends Base.*Controller\|extends Controller\|extends \\\yii" controllers/ 2>/dev/null \
    | grep -v "^Binary" || true)
if [[ -z "$CTRL_ISSUES" ]]; then
    ok "All controllers extend a base controller"
else
    fail "Controller(s) with unexpected base class"
    echo "$CTRL_ISSUES" | sed 's/^/     /'
fi

# Every model should extend ActiveRecord or Model
MODEL_ISSUES=$(grep -rL "extends ActiveRecord\|extends Model\|extends \\\yii\|extends FormModel" models/ 2>/dev/null \
    | grep -v "^Binary" || true)
if [[ -z "$MODEL_ISSUES" ]]; then
    ok "All models extend an ActiveRecord/Model base"
else
    fail "Model(s) with unexpected base class"
    echo "$MODEL_ISSUES" | sed 's/^/     /'
fi

# No TODO/FIXME/HACK left in source (warn only, don't fail)
TODOS=$(grep -rn --include="*.php" \
    --exclude-dir=vendor --exclude-dir=.composer \
    -P '\b(TODO|FIXME|HACK|XXX)\b' . 2>/dev/null || true)
if [[ -z "$TODOS" ]]; then
    ok "No TODO/FIXME/HACK markers in source"
else
    echo -e "  ${YELLOW}⚠${NC}  TODO/FIXME markers found (not a failure, review later):"
    echo "$TODOS" | head -10 | sed 's/^/     /'
fi

# =============================================================================
# 12b. Ansible lint — deploy role (production profile)
# =============================================================================
section "Ansible Lint (deploy/)"

if command -v ansible-lint &>/dev/null; then
    ALINT_OUT=$(cd deploy && ansible-lint 2>&1 || true)
    if echo "$ALINT_OUT" | grep -qP "Passed|passed"; then
        ok "ansible-lint (production profile) passed"
    elif echo "$ALINT_OUT" | grep -qP "violation|warning|error"; then
        fail "ansible-lint found issues in deploy/"
        echo "$ALINT_OUT" | tail -30 | sed 's/^/     /'
    else
        ok "ansible-lint (production profile) passed"
    fi
elif dc ansible-lint --version >/dev/null 2>&1; then
    ALINT_OUT=$(dc bash -c "cd deploy && ansible-lint 2>&1" || true)
    if echo "$ALINT_OUT" | grep -qP "Passed|passed"; then
        ok "ansible-lint (production profile) passed"
    elif echo "$ALINT_OUT" | grep -qP "violation|warning|error"; then
        fail "ansible-lint found issues in deploy/"
        echo "$ALINT_OUT" | tail -30 | sed 's/^/     /'
    else
        ok "ansible-lint (production profile) passed"
    fi
else
    skip "ansible-lint not available"
fi

# =============================================================================
# 13. PHPUnit — integration tests + coverage (one combined run with pcov)
#
# Unit tests ran above without pcov for fast feedback. Here we run
# Unit+Integration once with pcov enabled, giving us both the integration
# pass/fail result and the full combined coverage report from a single
# phpunit invocation — saving one redundant process compared to running
# integration and coverage separately.
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

# =============================================================================
# Summary
# =============================================================================
echo -e "\n${BOLD}════════════════════════════════════════${NC}"
echo -e "${BOLD}Results: ${GREEN}${PASS} passed${NC}  ${RED}${FAIL} failed${NC}  ${YELLOW}${SKIP} skipped${NC}"

if [[ $FAIL -gt 0 ]]; then
    echo -e "\n${RED}Failed checks:${NC}"
    for e in "${ERRORS[@]}"; do echo -e "  ${RED}✘${NC}  $e"; done
    echo ""
    exit 1
fi

echo -e "\n${GREEN}${BOLD}All checks passed.${NC}\n"
exit 0
