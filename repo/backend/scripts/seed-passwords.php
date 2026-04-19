<?php
/**
 * Reset demo-user password hashes and clear any lockout state.
 *
 * seed.sql ships a placeholder bcrypt hash that does NOT correspond to
 * "Demo12345678!" (it's the well-known PHP-docs example hash for the
 * string "rasmuslerdorf"). Using it means every login call to
 * AuthService::login() hits password_verify() false, increments
 * failed_attempts, and after five attempts locks the user out —
 * cascading every downstream test that needs a token.
 *
 * This script regenerates a fresh bcrypt hash of the real test password
 * using PHP's own password_hash, writes it to every demo user, and
 * clears failed_attempts + lockout_until so the run starts from a clean
 * state. Safe to run repeatedly; idempotent.
 *
 * Invoked by:
 *   backend/scripts/run-phpunit.sh     — before PHPUnit starts
 *   run_tests.sh                       — before E2E phase (via docker exec)
 */

$dbHost = getenv('DB_HOST') ?: 'mysql';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'fieldops';
$dbUser = getenv('DB_USER') ?: 'fieldops_user';
$dbPass = getenv('DB_PASSWORD') ?: 'fieldops_pass';

$testPassword = getenv('SEED_DEMO_PASSWORD') ?: 'Demo12345678!';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "[seed-passwords] DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Wait briefly if the `users` table doesn't exist yet (seed.sql may still
// be running). Bail after 30 seconds so we don't hang forever.
for ($i = 0; $i < 30; $i++) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt && $stmt->fetchColumn()) {
        break;
    }
    sleep(1);
}

$hash = password_hash($testPassword, PASSWORD_BCRYPT, ['cost' => 10]);

$stmt = $pdo->prepare(
    "UPDATE users
     SET password_hash = :h,
         failed_attempts = 0,
         lockout_until = NULL"
);
$stmt->execute([':h' => $hash]);
$updated = $stmt->rowCount();

// Also wipe any pending session rows from a previous botched run so
// AuthMiddleware doesn't hand out a token tied to stale state.
$pdo->exec("DELETE FROM sessions");

// Wipe per-user test state left behind by a prior PHPUnit run so the
// E2E phase starts from the same "fresh seed" baseline. The PHPUnit
// suite redeems WELCOME10 against frontdesk1 (see
// testApplyValidCouponDiscountsOrder), which consumes that coupon's
// usage_limit_per_user=1 slot — leaving the kiosk E2E flow unable to
// re-apply the same coupon, because the validator hits the per-user
// limit before it ever checks dates or min-spend. Clearing redemptions
// here (before E2E calls /coupons/validate) restores the clean state
// without having to wipe the whole MySQL volume between phases.
// Safe during PHPUnit's own pre-run seeding too: the table is empty on
// a freshly-seeded DB, so the DELETE is a no-op.
$pdo->exec("DELETE FROM coupon_redemptions");

echo "[seed-passwords] Reset {$updated} demo password hashes and cleared lockout/session/redemption state.\n";
