#!/bin/bash
###############################################################################
# FieldOps Service & Environmental Analytics Suite - Test Runner
# Executes all backend, frontend, and fullstack E2E tests within Docker
###############################################################################

set -e

COMPOSE_FILE="docker-compose.yml"
BACKEND_PASS=0
BACKEND_FAIL=0
FRONTEND_PASS=0
FRONTEND_FAIL=0
E2E_PASS=0
E2E_FAIL=0
EXIT_CODE=0

echo "=============================================="
echo " FieldOps Test Suite Runner"
echo "=============================================="
echo ""

# Ensure MySQL is running
echo "[1/5] Starting database service..."
docker-compose -f "$COMPOSE_FILE" up -d mysql
echo "Waiting for MySQL to be healthy..."
until docker-compose -f "$COMPOSE_FILE" exec -T mysql mysqladmin ping -h localhost -u root -pfieldops_root_pass --silent 2>/dev/null; do
    sleep 2
done
echo "MySQL is ready."
echo ""

# Run backend tests (unit + API with built-in PHP server)
echo "[2/5] Running backend tests..."
echo "----------------------------------------------"
if docker-compose -f "$COMPOSE_FILE" --profile test run --rm test-backend; then
    echo "Backend tests: PASSED"
    BACKEND_PASS=1
else
    echo "Backend tests: FAILED"
    BACKEND_FAIL=1
    EXIT_CODE=1
fi
echo ""

# Run frontend tests
echo "[3/5] Running frontend tests..."
echo "----------------------------------------------"
if docker-compose -f "$COMPOSE_FILE" --profile test run --rm test-frontend; then
    echo "Frontend tests: PASSED"
    FRONTEND_PASS=1
else
    echo "Frontend tests: FAILED"
    FRONTEND_FAIL=1
    EXIT_CODE=1
fi
echo ""

# Run fullstack E2E tests (real backend + DB + frontend)
echo "[4/5] Starting services for E2E tests..."
docker-compose -f "$COMPOSE_FILE" up -d mysql backend frontend
echo "Waiting for backend to be ready..."
until curl -sf http://localhost:8000/api/v1/auth/me > /dev/null 2>&1 || [ $? -eq 22 ]; do
    sleep 2
done
echo "Backend is ready."
echo ""

echo "[5/5] Running fullstack E2E tests..."
echo "----------------------------------------------"
if docker-compose -f "$COMPOSE_FILE" --profile e2e run --rm test-e2e; then
    echo "E2E tests: PASSED"
    E2E_PASS=1
else
    echo "E2E tests: FAILED"
    E2E_FAIL=1
    EXIT_CODE=1
fi
echo ""

# Summary
echo "=============================================="
echo " TEST SUMMARY"
echo "=============================================="
TOTAL_PASS=$((BACKEND_PASS + FRONTEND_PASS + E2E_PASS))
TOTAL_FAIL=$((BACKEND_FAIL + FRONTEND_FAIL + E2E_FAIL))
TOTAL=$((TOTAL_PASS + TOTAL_FAIL))
echo " Total Suites:  $TOTAL"
echo " Passed:        $TOTAL_PASS"
echo " Failed:        $TOTAL_FAIL"
echo ""
echo " Backend:       $([ $BACKEND_FAIL -eq 0 ] && echo 'PASS' || echo 'FAIL')"
echo " Frontend:      $([ $FRONTEND_FAIL -eq 0 ] && echo 'PASS' || echo 'FAIL')"
echo " Fullstack E2E: $([ $E2E_FAIL -eq 0 ] && echo 'PASS' || echo 'FAIL')"
echo ""
if [ $EXIT_CODE -eq 0 ]; then
    echo " Result: ALL TESTS PASSED"
else
    echo " Result: SOME TESTS FAILED"
fi
echo "=============================================="

exit $EXIT_CODE
