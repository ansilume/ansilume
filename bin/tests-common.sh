#!/usr/bin/env bash
# =============================================================================
# Shared test infrastructure — sourced by individual test scripts.
# Not meant to be executed directly.
# =============================================================================

# The scripts run with pipefail: a captured output reaches grep -q, head and
# awk with exit as a here-string (grep -q … <<< "$OUT"), never through
# echo "$OUT" |, which fails once such a command has stopped reading
# (tests/unit/bin/PipefailCapturedOutputTest.php).
#
# They run with set -e too: a check tests a command it expects to fail in a
# condition (if ! out=$(cmd); then), keeps its status after ||
# (rc=0; out=$(cmd) || rc=$?), or tolerates its failure inside the
# substitution (out=$(cmd || true)). A test of $? after a plain statement
# never runs when the statement failed: set -e has ended the suite there,
# before the check said what it found (tests/unit/bin/ErrexitExitStatusTest.php
# finds such a test of $?; a plain capture whose empty result is tested later
# fails the same way, and no test finds that). A suite that set -e ends
# before print_summary names the line and the command it ended at
# (report_early_end).

# Navigate to repo root
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

# Flags (used by sourcing scripts, not within this file)
# shellcheck disable=SC2034
{
FAST=0
SKIP_E2E=0
for arg in "$@"; do
  [[ "$arg" == "--fast" ]] && FAST=1
  [[ "$arg" == "--skip-e2e" ]] && SKIP_E2E=1
done
}

# Counters
PASS=0; FAIL=0; SKIP=0; ERRORS=()
START_TIME=$(date +%s)

# Colours
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

section() { echo -e "\n${CYAN}${BOLD}══ $1 ══${NC}"; }
ok()      { echo -e "  ${GREEN}✔${NC}  $1"; PASS=$((PASS+1)); }
fail()    { echo -e "  ${RED}✘${NC}  $1"; FAIL=$((FAIL+1)); ERRORS+=("$1"); }
skip()    { echo -e "  ${YELLOW}–${NC}  $1 (skipped)"; SKIP=$((SKIP+1)); }

# ─── Docker detection ───────────────────────────────────────────────────────
DOCKER_AVAILABLE=0
if command -v docker &>/dev/null && docker compose ps app --quiet 2>/dev/null | grep -q .; then
    DOCKER_AVAILABLE=1
fi

# Abort early if neither Docker nor local PHP is available
if [[ $DOCKER_AVAILABLE -eq 0 ]]; then
    if ! command -v php &>/dev/null; then
        echo -e "${RED}Error: Docker app container not running and PHP not found locally.${NC}"
        echo -e "Start Docker: ${BOLD}docker compose up -d${NC}"
        exit 1
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

# ─── Summary ────────────────────────────────────────────────────────────────
SUMMARY_REACHED=0

print_summary() {
    SUMMARY_REACHED=1
    local ELAPSED=$(( $(date +%s) - START_TIME ))
    local HOURS=$(( ELAPSED / 3600 ))
    local MINUTES=$(( (ELAPSED % 3600) / 60 ))
    local SECONDS_R=$(( ELAPSED % 60 ))
    local DURATION
    if [[ $HOURS -gt 0 ]]; then
        DURATION="${HOURS}h ${MINUTES}m ${SECONDS_R}s"
    elif [[ $MINUTES -gt 0 ]]; then
        DURATION="${MINUTES}m ${SECONDS_R}s"
    else
        DURATION="${SECONDS_R}s"
    fi

    echo -e "\n${BOLD}════════════════════════════════════════${NC}"
    echo -e "${BOLD}Results: ${GREEN}${PASS} passed${NC}  ${RED}${FAIL} failed${NC}  ${YELLOW}${SKIP} skipped${NC}  ${CYAN}(${DURATION})${NC}"

    if [[ $FAIL -gt 0 ]]; then
        echo -e "\n${RED}Failed checks:${NC}"
        for e in "${ERRORS[@]}"; do echo -e "  ${RED}✘${NC}  $e"; done
        echo ""
        exit 1
    fi

    echo -e "\n${GREEN}${BOLD}All checks passed.${NC}\n"
    exit 0
}

# ─── Early end ──────────────────────────────────────────────────────────────
# Every suite ends with print_summary. When set -e ends it earlier, at a
# command that failed outside a condition, name the line and the command
# instead of ending without a word (tests/unit/bin/SuiteEndedEarlyTest.php).
# The ERR trap fires where set -e ends the suite, in functions too
# (errtrace), and only notes the line: it fires in command substitutions as
# well, where the note is lost with the subshell. The command alone could
# mislead: for a pipeline it is the last command of the pipeline. set -u and
# an explicit exit end a suite without the ERR trap. printf %s prints the
# command as written: echo -e would expand its backslashes. The report goes
# to the suite's stdout as it was here, so that the redirection of a loop or
# a block it ended in (done > /dev/null) does not swallow it.
EARLY_END_LINE=""
exec {EARLY_END_FD}>&1
report_early_end() {
    local status="$1" ended_at="$2"
    if [[ $SUMMARY_REACHED -eq 1 || $status -eq 0 ]]; then
        return 0
    fi
    if [[ -n "$EARLY_END_LINE" ]]; then
        printf '\n  %b✘%b  The suite ended early (exit %s), before its summary, at %s: %s\n' "$RED" "$NC" "$status" "${EARLY_END_LINE##*/}" "$ended_at"
        echo "     set -e ends a suite where a command fails outside a condition; the checks after it did not run."
    else
        printf '\n  %b✘%b  The suite ended early (exit %s), before its summary, at: %s\n' "$RED" "$NC" "$status" "$ended_at"
        echo "     The checks after it did not run; the lines above say why."
    fi
}
set -o errtrace
trap 'EARLY_END_LINE="${BASH_SOURCE[0]:-$0}:${LINENO}"' ERR
trap 'report_early_end "$?" "$BASH_COMMAND" >&"$EARLY_END_FD"' EXIT
