#!/bin/bash
###############################################################################
# Backend test runner (runs inside test-backend container).
# Starts PHP's built-in server with diagnostics enabled, runs PHPUnit, then
# dumps every relevant log source so 500-producing exceptions are always
# visible in `docker compose` output instead of being silently swallowed.
###############################################################################

set +e

PHP_SERVER_LOG=/tmp/php-server.log
PHP_ERROR_LOG=/tmp/php-errors.log

# MySQL readiness: the `mysql` service's healthcheck pings mysqladmin on
# the *container's own* localhost, which goes green before MySQL is
# accepting external TCP connections — other containers then hit
# "SQLSTATE[HY000] [2002] Connection refused" on the first query and the
# whole backend suite 500s on login. Do an explicit cross-container TCP
# probe to `${DB_HOST}:${DB_PORT}` before starting the PHP server.
DB_HOST="${DB_HOST:-mysql}"
DB_PORT="${DB_PORT:-3306}"

echo "[run-phpunit] Waiting for ${DB_HOST}:${DB_PORT} to accept TCP connections..."
for i in $(seq 1 60); do
    if php -r "exit(@fsockopen('${DB_HOST}', ${DB_PORT}, \$e, \$s, 2) ? 0 : 1);"; then
        echo "[run-phpunit] ${DB_HOST}:${DB_PORT} reachable after ${i} attempt(s)."
        break
    fi
    if [ "${i}" -eq 60 ]; then
        echo "[run-phpunit] FATAL: ${DB_HOST}:${DB_PORT} unreachable after 60 attempts."
        echo "[run-phpunit] DNS resolution diagnostic:"
        getent hosts "${DB_HOST}" || echo "(getent could not resolve ${DB_HOST})"
        exit 2
    fi
    sleep 1
done

# Regenerate demo-user password hashes against the real test password
# and clear lockout state. The seed.sql hash is a PHP-docs placeholder
# (hashes "rasmuslerdorf", not "Demo12345678!"), so without this every
# test's loginAs() fails password_verify and, after five attempts,
# locks each demo account out — cascading every downstream assertion.
php scripts/seed-passwords.php || {
    echo "[run-phpunit] FATAL: could not reset demo password hashes."
    exit 3
}

# Start the PHP built-in server with error logging turned on so uncaught
# exceptions from AuthService / middleware land in $PHP_ERROR_LOG.
php \
    -d log_errors=1 \
    -d "error_log=${PHP_ERROR_LOG}" \
    -d display_errors=stderr \
    -S 0.0.0.0:8000 -t public/ public/router.php \
    > "${PHP_SERVER_LOG}" 2>&1 &
SERVER_PID=$!

# Give the server a moment to come up before phpunit starts firing requests.
sleep 2

vendor/bin/phpunit --configuration phpunit.xml --testdox
PHPUNIT_EXIT=$?

# Terminate the PHP server so we get clean log output.
kill "${SERVER_PID}" 2>/dev/null || true
sleep 1

dump() {
    local label="$1"
    local path="$2"
    local lines="${3:-200}"
    echo ""
    echo "================================================================"
    echo "  ${label}"
    echo "================================================================"
    if [ -f "${path}" ]; then
        tail -n "${lines}" "${path}"
    else
        echo "(${path} not found)"
    fi
}

dump "PHP built-in server log"       "${PHP_SERVER_LOG}"
dump "PHP error_log (uncaught errors)" "${PHP_ERROR_LOG}"

echo ""
echo "================================================================"
echo "  Application log (storage/logs)"
echo "================================================================"
if compgen -G "storage/logs/*.log" > /dev/null; then
    for f in storage/logs/*.log; do
        echo "--- ${f} ---"
        tail -n 200 "${f}"
    done
else
    echo "(no storage/logs/*.log files)"
fi

echo ""
echo "================================================================"
echo "  ThinkPHP runtime log"
echo "================================================================"
RUNTIME_LOGS=$(find runtime -name '*.log' 2>/dev/null)
if [ -n "${RUNTIME_LOGS}" ]; then
    for f in ${RUNTIME_LOGS}; do
        echo "--- ${f} ---"
        tail -n 200 "${f}"
    done
else
    echo "(no runtime/**/*.log files)"
fi

exit "${PHPUNIT_EXIT}"
