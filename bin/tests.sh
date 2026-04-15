#!/usr/bin/env bash
# =============================================================================
# Ansilume test suite — sequential runner
#
# Runs all bin/tests-*.sh suites one after another.
# For parallel execution, run the individual scripts concurrently.
# =============================================================================

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

RED='\033[0;31m'; GREEN='\033[0;32m'; CYAN='\033[0;36m'
BOLD='\033[1m'; NC='\033[0m'

TOTAL=0; PASSED=0; FAILED=0; FAILED_NAMES=()
START_TIME=$(date +%s)

for script in "$SCRIPT_DIR"/tests-*.sh; do
    name="$(basename "$script")"
    [[ "$name" == "tests-common.sh" ]] && continue

    TOTAL=$((TOTAL+1))
    echo -e "\n${BOLD}▶ ${name}${NC}"
    echo -e "${BOLD}────────────────────────────────────────${NC}"

    if "$script" "$@"; then
        PASSED=$((PASSED+1))
    else
        FAILED=$((FAILED+1))
        FAILED_NAMES+=("$name")
    fi
done

# ─── Overall summary ────────────────────────────────────────────────────────
ELAPSED=$(( $(date +%s) - START_TIME ))
HOURS=$(( ELAPSED / 3600 ))
MINUTES=$(( (ELAPSED % 3600) / 60 ))
SECONDS_R=$(( ELAPSED % 60 ))
if [[ $HOURS -gt 0 ]]; then
    DURATION="${HOURS}h ${MINUTES}m ${SECONDS_R}s"
elif [[ $MINUTES -gt 0 ]]; then
    DURATION="${MINUTES}m ${SECONDS_R}s"
else
    DURATION="${SECONDS_R}s"
fi

echo -e "\n${BOLD}════════════════════════════════════════${NC}"
echo -e "${BOLD}Overall: ${GREEN}${PASSED}/${TOTAL} suites passed${NC}  ${CYAN}(${DURATION})${NC}"

if [[ $FAILED -gt 0 ]]; then
    echo -e "\n${RED}Failed suites:${NC}"
    for n in "${FAILED_NAMES[@]}"; do echo -e "  ${RED}✘${NC}  $n"; done
    echo ""
    exit 1
fi

echo -e "\n${GREEN}${BOLD}All test suites passed.${NC}\n"
exit 0
