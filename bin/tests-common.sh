#!/usr/bin/env bash
# =============================================================================
# Shared test infrastructure — sourced by individual test scripts.
# Not meant to be executed directly.
# =============================================================================

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
print_summary() {
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
