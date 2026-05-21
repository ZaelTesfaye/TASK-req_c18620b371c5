<?php
/**
 * Idempotent re-application of database/seeds/seed.sql.
 *
 * `docker-entrypoint-initdb.d` only runs on first init of the MySQL data
 * volume. If a deployed instance was brought up before seed.sql existed
 * (or if the volume was wiped after deploy), the `stores` and
 * `workstations` tables stay empty and the login page's
 * /api/v1/auth/bootstrap/stores dropdown breaks. This script re-applies
 * the seed against an already-initialised database.
 *
 * All inserts in seed.sql use `INSERT IGNORE`, so running this against a
 * partially-seeded or fully-seeded DB is safe and idempotent.
 *
 * Usage (against the running compose stack):
 *   docker compose exec backend php scripts/reseed.php
 */

$dbHost = getenv('DB_HOST') ?: 'mysql';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'fieldops';
$dbUser = getenv('DB_USER') ?: 'fieldops_user';
$dbPass = getenv('DB_PASSWORD') ?: 'fieldops_pass';

$seedPath = dirname(__DIR__) . '/database/seeds/seed.sql';
if (!is_file($seedPath)) {
    fwrite(STDERR, "[reseed] seed.sql not found at {$seedPath}\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "[reseed] DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$sql = file_get_contents($seedPath);

// Strip `--` line comments so they don't accidentally swallow a trailing
// `;` on the same line and merge two statements into one.
$sql = preg_replace('/^\s*--.*$/m', '', $sql);

// Split on `;` at end of line. seed.sql has no string literals containing
// `;`, so a simple split is sufficient and avoids pulling in a SQL parser.
$statements = array_filter(
    array_map('trim', preg_split('/;\s*[\r\n]+/', $sql)),
    fn($s) => $s !== ''
);

$applied = 0;
foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        $applied++;
    } catch (Throwable $e) {
        fwrite(STDERR, "[reseed] Statement failed: " . $e->getMessage() . "\n");
        fwrite(STDERR, "[reseed] SQL: " . substr($stmt, 0, 200) . "...\n");
        exit(1);
    }
}

$storeCount = (int) $pdo->query("SELECT COUNT(*) FROM stores")->fetchColumn();
$wsCount    = (int) $pdo->query("SELECT COUNT(*) FROM workstations")->fetchColumn();

echo "[reseed] Applied {$applied} statements. stores={$storeCount}, workstations={$wsCount}.\n";
