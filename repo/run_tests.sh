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

# Tear down any previous stack INCLUDING the MySQL data volume. The
# mysql:8.0 image only executes /docker-entrypoint-initdb.d/*.sql on the
# FIRST boot against an empty data directory. If a prior run left a
# partially-initialized or pre-seeded volume, init.sql and seed.sql never
# re-run, the `users`/`stores` tables end up missing or empty, and every
# backend test that hits the DB returns 500. Forcing a volume wipe here
# makes the backend suite deterministic across runs. Pass
# `SKIP_DB_RESET=1 ./run_tests.sh` to preserve the volume (useful when
# debugging a specific test against data you've set up by hand).
if [ "${SKIP_DB_RESET:-0}" != "1" ]; then
    echo "[0/5] Resetting stack and MySQL volume..."
    docker-compose -f "$COMPOSE_FILE" down -v --remove-orphans 2>/dev/null || true
    echo ""
fi

# Rebuild test images BEFORE each run. Without this the script silently
# reuses stale images whenever source files change — the backend and
# frontend tests then run against whatever code was baked in at the last
# `docker compose build`, not what's on disk. `--pull=false` keeps base
# images cached; only the project layers rebuild when inputs change.
echo "[1/5] Rebuilding test images..."
docker-compose -f "$COMPOSE_FILE" --profile test --profile e2e build --pull=false \
    backend test-backend test-frontend test-e2e
echo ""

# Ensure MySQL is running AND accepting TCP connections. The health
# condition is now TCP-based (see docker-compose.yml mysql healthcheck),
# so `up --wait` returns only once peer containers can actually connect.
echo "[2/5] Starting database service..."
docker-compose -f "$COMPOSE_FILE" up -d --wait mysql
echo "MySQL is ready (TCP 3306 reachable)."
echo ""

# Run backend tests (unit + API with built-in PHP server)
echo "[3/5] Running backend tests..."
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
echo "[4/5] Running frontend tests..."
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

# Run fullstack E2E tests (real backend + DB + frontend). Force recreate
# so the newly-built backend image is actually used — otherwise a
# previously running container from an older image keeps serving stale
# code and the E2E phase hangs at "Waiting for backend to be ready".
echo "[5/5] Starting services for E2E tests..."
docker-compose -f "$COMPOSE_FILE" up -d --force-recreate --no-deps mysql backend frontend
echo "Waiting for backend to be ready (max ~60s)..."
ATTEMPTS=0
until curl -sf http://localhost:8000/api/v1/auth/me > /dev/null 2>&1 || [ $? -eq 22 ]; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [ "$ATTEMPTS" -gt 30 ]; then
        echo "Backend did not become ready within 60s — check 'docker compose logs backend'."
        docker-compose -f "$COMPOSE_FILE" logs --tail=30 backend
        EXIT_CODE=1
        break
    fi
    sleep 2
done
echo "Backend is ready."
echo ""

echo "Running fullstack E2E tests..."
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
