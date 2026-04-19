#!/usr/bin/env bash
# =============================================================================
# Static checks: syntax, security, consistency, validation
# =============================================================================

set -euo pipefail
# shellcheck source=tests-common.sh
source "$(dirname "$0")/tests-common.sh"

# =============================================================================
# PHP syntax check
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
# Shell script checks
# =============================================================================
section "Shell script checks"

SHELL_SCRIPTS=$(find . -path ./vendor -prune -o -path ./node_modules -prune -o -path ./runtime -prune -o -path './@runtime' -prune -o \( -name '*.sh' -o -path './bin/*' \) -print | sed 's|^\./||' | sort)
SHELL_ERRORS=0

# ── Shebang ─────────────────────────────────────────────────────────────────
for script in $SHELL_SCRIPTS; do
    firstline=$(head -1 "$script")
    case "$firstline" in
        '#!'*) ;;
        *)
            fail "Missing shebang: $script"
            SHELL_ERRORS=$((SHELL_ERRORS+1))
            ;;
    esac
done

# ── Syntax ───────────────────────────────────────────────────────────────────
for script in $SHELL_SCRIPTS; do
    shebang=$(head -1 "$script")
    case "$shebang" in
        *bash*)  checker="bash" ;;
        *sh*)    checker="sh"   ;;
        *)       continue       ;;
    esac
    output=$($checker -n "$script" 2>&1)
    if [[ $? -ne 0 ]]; then
        fail "Syntax error ($checker): $script"
        echo "     $output"
        SHELL_ERRORS=$((SHELL_ERRORS+1))
    fi
done

# ── Executable bit (bin/ scripts must be executable) ─────────────────────────
for script in $SHELL_SCRIPTS; do
    case "$script" in
        bin/*)
            if [[ ! -x "$script" ]]; then
                fail "Not executable: $script"
                SHELL_ERRORS=$((SHELL_ERRORS+1))
            fi
            ;;
    esac
done

# ── ShellCheck (optional — skip if not installed) ────────────────────────────
if command -v shellcheck >/dev/null 2>&1; then
    SC_ERRORS=0
    for script in $SHELL_SCRIPTS; do
        sc_output=$(shellcheck -S warning "$script" 2>&1)
        if [[ $? -ne 0 ]]; then
            fail "ShellCheck warnings: $script"
            echo "$sc_output" | head -20 | sed 's/^/     /'
            SC_ERRORS=$((SC_ERRORS+1))
        fi
    done
    if [[ $SC_ERRORS -eq 0 ]]; then
        ok "ShellCheck passed"
    else
        SHELL_ERRORS=$((SHELL_ERRORS+SC_ERRORS))
    fi
else
    skip "ShellCheck (not installed — apt install shellcheck to enable)"
fi

if [[ $SHELL_ERRORS -eq 0 ]]; then
    ok "All shell scripts pass checks ($(echo "$SHELL_SCRIPTS" | wc -w) scripts)"
else
    fail "$SHELL_ERRORS shell script issue(s) found"
fi

# =============================================================================
# strict_types declarations
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
# Security: detect dangerous patterns
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
# XSS: unescaped output in views
# =============================================================================
section "XSS / output escaping (views)"

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
# CSRF safety
# =============================================================================
section "CSRF safety (no data-method='post')"

CSRF_ISSUES=$(grep -rn --include="*.php" -P "data-method.*post|data.method.*post|'method'\s*=>\s*'post'" views/ 2>/dev/null \
    | grep -vP ':[0-9]+:\s*//' \
    | grep -vP "ActiveForm::begin|Html::beginForm" || true)
if [[ -z "$CSRF_ISSUES" ]]; then
    ok "No data-method=\"post\" patterns found in views"
else
    fail "Found data-method=\"post\" — replace with explicit <form> + CSRF token"
    echo "$CSRF_ISSUES" | sed 's/^/     /'
fi

# =============================================================================
# Offline: no external CSS/JS/font references
# =============================================================================
section "Offline capability (no external assets)"

EXTERNAL_ASSETS=$(grep -rn --include="*.php" --include="*.html" --include="*.js" --include="*.css" \
    -P '(<link\b.*href|<script\b.*src|@import\s+url|url\()\s*[=\(]?\s*["\x27]?https?://' \
    views/ web/ assets/ 2>/dev/null \
    | grep -vP '^\s*//' \
    | grep -vP '// offline-ok' \
    || true)
if [[ -z "$EXTERNAL_ASSETS" ]]; then
    ok "No external CSS/JS/font references found — fully offline capable"
else
    fail "Found external asset references — app must work offline"
    echo "$EXTERNAL_ASSETS" | sed 's/^/     /'
fi

# =============================================================================
# Migration naming consistency
# =============================================================================
section "Migration naming consistency"

MIGRATION_ERRORS=0
while IFS= read -r file; do
    filename=$(basename "$file" .php)
    classname=$(grep -oP 'class\s+\K[A-Za-z0-9_]+' "$file" 2>/dev/null | head -1)
    if [[ -n "$classname" && "$classname" != "$filename" ]]; then
        echo -e "  ${RED}✘${NC}  $file: class '$classname' ≠ filename '$filename'"
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
# OpenAPI spec
# =============================================================================
section "OpenAPI spec"

OPENAPI_FILE="web/openapi.yaml"
if [[ ! -f "$OPENAPI_FILE" ]]; then
    fail "$OPENAPI_FILE is missing — the API spec is mandatory (see CLAUDE.md)"
else
    OPENAPI_OUT=$(dc php -r '
        require "vendor/autoload.php";
        try {
            $data = Symfony\Component\Yaml\Yaml::parseFile("web/openapi.yaml");
            if (!is_array($data)) { fwrite(STDERR, "openapi.yaml did not parse as a mapping\n"); exit(1); }
            foreach (["openapi","info","paths","components"] as $key) {
                if (!array_key_exists($key, $data)) { fwrite(STDERR, "openapi.yaml missing top-level key: $key\n"); exit(1); }
            }
            $refs = [];
            $walk = function ($node) use (&$walk, &$refs) {
                if (is_array($node)) {
                    foreach ($node as $k => $v) {
                        if ($k === "\$ref" && is_string($v)) { $refs[] = $v; }
                        else { $walk($v); }
                    }
                }
            };
            $walk($data);
            $missing = [];
            foreach ($refs as $ref) {
                if (strpos($ref, "#/") !== 0) { continue; }
                $parts = explode("/", substr($ref, 2));
                $cur = $data;
                foreach ($parts as $p) {
                    if (!is_array($cur) || !array_key_exists($p, $cur)) { $missing[] = $ref; continue 2; }
                    $cur = $cur[$p];
                }
            }
            if ($missing) { fwrite(STDERR, "unresolved \$refs: " . implode(", ", array_unique($missing)) . "\n"); exit(1); }
            echo "ok:" . count($data["paths"]) . ":" . count($refs);
        } catch (\Throwable $e) {
            fwrite(STDERR, "openapi.yaml parse error: " . $e->getMessage() . "\n");
            exit(1);
        }
    ' 2>&1 || true)
    if [[ "$OPENAPI_OUT" =~ ^ok: ]]; then
        PATHS=$(echo "$OPENAPI_OUT" | cut -d: -f2)
        REFS=$(echo "$OPENAPI_OUT" | cut -d: -f3)
        ok "OpenAPI spec valid (${PATHS} paths, ${REFS} \$refs resolved)"
    else
        fail "OpenAPI spec invalid: $OPENAPI_OUT"
    fi
fi

# =============================================================================
# Composer
# =============================================================================
section "Composer"

if dc composer validate --no-check-all --quiet 2>&1; then
    ok "composer.json is valid"
else
    fail "composer.json validation failed"
fi

AUDIT=$(dc composer audit --no-dev 2>&1 || true)
if echo "$AUDIT" | grep -qiP "CVE-|GHSA-|Found \d+ vulnerab"; then
    fail "composer audit found security advisories"
    echo "$AUDIT" | head -20 | sed 's/^/     /'
else
    ok "No known security vulnerabilities in dependencies"
fi

# =============================================================================
# Consistency checks
# =============================================================================
section "Consistency checks"

CTRL_ISSUES=$(grep -rLn "extends BaseController\|extends Base.*Controller\|extends Controller\|extends \\\yii" controllers/ 2>/dev/null \
    | grep -v "^Binary" | grep -v '/traits/' || true)
if [[ -z "$CTRL_ISSUES" ]]; then
    ok "All controllers extend a base controller"
else
    fail "Controller(s) with unexpected base class"
    echo "$CTRL_ISSUES" | sed 's/^/     /'
fi

MODEL_ISSUES=$(grep -rL "extends ActiveRecord\|extends Model\|extends \\\yii\|extends FormModel" models/ 2>/dev/null \
    | grep -v "^Binary" || true)
if [[ -z "$MODEL_ISSUES" ]]; then
    ok "All models extend an ActiveRecord/Model base"
else
    fail "Model(s) with unexpected base class"
    echo "$MODEL_ISSUES" | sed 's/^/     /'
fi

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
# Ansible Lint
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
# Docker privilege drop (regression: "Run Lint" EACCES on SCM projects)
# =============================================================================
#
# queue-worker and schedule-runner containers run `php yii …` which, left
# to their own devices, exec as root (the php:8.2-fpm base image's default
# USER). When the worker clones an SCM project and runs LintService's
# post-sync auto-lint, the cache dir ends up root-owned. The web container
# (app) later runs as www-data via php-fpm pool config — it can no longer
# write to that dir, so "Run Lint" in the UI fails with EACCES. The fix is
# to gosu-drop to www-data in the entrypoint for every non-php-fpm command.
# If this regression lands again, users only notice in production — catch
# it at lint time instead.
section "Docker privilege drop (gosu www-data for non-php-fpm)"

for dockerfile in docker/php/Dockerfile docker/php/Dockerfile.prod; do
    if ! grep -qE '^\s+gosu\s*\\?$' "$dockerfile"; then
        fail "$dockerfile missing gosu in apt-get install"
    else
        ok "$dockerfile installs gosu"
    fi
done

for entrypoint in docker/php/entrypoint.sh docker/php/entrypoint-prod.sh; do
    if ! grep -qE 'exec\s+gosu\s+www-data' "$entrypoint"; then
        fail "$entrypoint does not drop privileges to www-data for non-php-fpm commands"
    else
        ok "$entrypoint drops privileges to www-data"
    fi
done

print_summary
