<?php
/**
 * Idempotent database bootstrap. Applies init.sql (schema) and seed.sql
 * (reference data) in order, waits for MySQL to accept TCP, and exits 0
 * even when the DB is already fully provisioned.
 *
 * Runs unconditionally at container start (see Dockerfile CMD), so a
 * fresh `docker compose up` against an empty volume AND a restart
 * against a partially-initialised volume both converge on the same
 * known-good state. This replaces the previous reliance on
 * docker-entrypoint-initdb.d, which only fires on first MySQL volume
 * init and silently does nothing on every subsequent start — that
 * mismatch is what stranded the login page with an empty store
 * dropdown on the deployed instance.
 *
 * Manual invocation:
 *   docker compose exec backend php scripts/bootstrap-db.php
 *
 * Exit codes:
 *   0  - schema and seed applied (or already up to date)
 *   1  - DB unreachable after timeout
 *   2  - SQL execution failed
 */

$dbHost = getenv('DB_HOST') ?: 'mysql';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'fieldops';
$dbUser = getenv('DB_USER') ?: 'fieldops_user';
$dbPass = getenv('DB_PASSWORD') ?: 'fieldops_pass';

$root = dirname(__DIR__);
$initSql = $root . '/database/migrations/init.sql';
$seedSql = $root . '/database/seeds/seed.sql';

if (!is_file($initSql)) {
    fwrite(STDERR, "[bootstrap-db] init.sql not found at {$initSql}\n");
    exit(2);
}
if (!is_file($seedSql)) {
    fwrite(STDERR, "[bootstrap-db] seed.sql not found at {$seedSql}\n");
    exit(2);
}

// Wait for MySQL TCP to be reachable. The mysql container's healthcheck
// pings via mysqladmin which can go green before the network listener
// is fully ready under load, so probe directly. 60 attempts * 2s = 2min
// upper bound, matched to the mysql service `start_period`.
echo "[bootstrap-db] Waiting for {$dbHost}:{$dbPort} ...\n";
$ready = false;
for ($i = 0; $i < 60; $i++) {
    $sock = @fsockopen($dbHost, (int) $dbPort, $errno, $errstr, 2);
    if ($sock) {
        fclose($sock);
        $ready = true;
        echo "[bootstrap-db] {$dbHost}:{$dbPort} reachable after " . ($i + 1) . " attempt(s).\n";
        break;
    }
    sleep(2);
}
if (!$ready) {
    fwrite(STDERR, "[bootstrap-db] FATAL: {$dbHost}:{$dbPort} unreachable after 120s\n");
    exit(1);
}

// Connect WITHOUT specifying a database, so we can issue CREATE DATABASE
// if the target schema does not exist (covers freshly-provisioned MySQL
// instances where the docker-entrypoint-initdb.d MYSQL_DATABASE step
// was skipped).
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "[bootstrap-db] DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// CREATE DATABASE requires CREATE privilege — the docker-compose
// `fieldops_user` only has rights on the `fieldops` schema itself, so
// this fails with "Access denied" in the normal case. That's fine: the
// MySQL container's MYSQL_DATABASE env created the schema during first
// init. Swallow the permission error and let the USE below surface the
// real problem if the schema genuinely does not exist.
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $ignored) {
    // Insufficient privileges — proceed and trust that MYSQL_DATABASE
    // already created the schema.
}

try {
    $pdo->exec("USE `{$dbName}`");
} catch (Throwable $e) {
    fwrite(STDERR, "[bootstrap-db] FATAL: cannot USE database `{$dbName}`: " . $e->getMessage() . "\n");
    exit(2);
}

/**
 * Apply a .sql file. Honours `DELIMITER` directives so trigger /
 * procedure bodies that contain inline `;` (e.g.,
 * SIGNAL SQLSTATE ... SET MESSAGE_TEXT = '...';) are kept intact.
 * Without this, the trigger block in init.sql gets split at the first
 * internal `;` and PDO rejects the fragment as a syntax error.
 *
 * DELIMITER itself is a mysql-client directive (not real SQL), so the
 * line is consumed but never sent to the server.
 *
 * Limitations: this is a line-oriented splitter, not a tokenizer. It
 * assumes DELIMITER appears on its own line (the mysql client's
 * requirement) and that the chosen delimiter only appears at end of
 * line outside string literals. Both hold for our init/seed files.
 */
$splitSql = function (string $sql): array {
    // Strip MySQL line comments (both full-line and trailing inline).
    // Inline form requires whitespace before `--` per MySQL grammar, so
    // matching `\s--` avoids eating `--` inside an identifier or
    // numeric literal. Without this strip, lines like
    //   (8, 2); -- frontdesk2 -> Front Desk
    // no longer end with `;` after the comment, the statement boundary
    // is missed, and the rest of the file accumulates into one giant
    // multi-statement blob that PDO::exec rejects.
    $sql = preg_replace('/^\s*--[^\n]*$/m', '', $sql);
    $sql = preg_replace('/[ \t]+--[^\n]*$/m', '', $sql);
    $lines = preg_split('/\r?\n/', $sql);
    $delimiter = ';';
    $statements = [];
    $buffer = '';
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trimmed, $m)) {
            $pending = trim($buffer);
            if ($pending !== '') {
                $statements[] = $pending;
            }
            $buffer = '';
            $delimiter = $m[1];
            continue;
        }
        $buffer .= $line . "\n";
        $candidate = rtrim($buffer);
        if ($candidate !== '' && str_ends_with($candidate, $delimiter)) {
            $stmt = trim(substr($candidate, 0, -strlen($delimiter)));
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }
    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }
    return $statements;
};

/**
 * Statement-class detection: trigger/function/procedure DDL is treated
 * as best-effort, not load-bearing. Audit-log immutability triggers
 * are a hardening layer; they're not required for login to work, and
 * managed MySQL services (RDS, Cloud SQL) commonly forbid CREATE
 * TRIGGER for non-admin users. Letting a SUPER-privilege error crash
 * the whole bootstrap turned the deploy into a 502 loop. Now we warn
 * and continue.
 */
$isOptionalDdl = function (string $stmt): bool {
    return (bool) preg_match('/^\s*(CREATE|DROP)\s+(TRIGGER|FUNCTION|PROCEDURE)\b/i', $stmt);
};

$applySqlFile = function (string $path) use ($pdo, $splitSql, $isOptionalDdl): int {
    $statements = $splitSql(file_get_contents($path));
    $count = 0;
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
            $count++;
        } catch (Throwable $e) {
            if ($isOptionalDdl($stmt)) {
                fwrite(STDERR, "[bootstrap-db] WARN: optional DDL skipped in " . basename($path) . ": " . $e->getMessage() . "\n");
                continue;
            }
            fwrite(STDERR, "[bootstrap-db] Statement failed in " . basename($path) . ": " . $e->getMessage() . "\n");
            fwrite(STDERR, "[bootstrap-db] SQL (first 200 chars): " . substr($stmt, 0, 200) . "\n");
            exit(2);
        }
    }
    return $count;
};

$schemaCount = $applySqlFile($initSql);
echo "[bootstrap-db] Applied {$schemaCount} schema statements from init.sql\n";

$seedCount = $applySqlFile($seedSql);
echo "[bootstrap-db] Applied {$seedCount} seed statements from seed.sql\n";

// Regenerate demo password hashes against the canonical demo password
// (seed.sql ships a placeholder bcrypt that hashes "rasmuslerdorf",
// not "Demo12345678!"). Idempotent: safe to re-run on every boot.
$hash = password_hash('Demo12345678!', PASSWORD_BCRYPT, ['cost' => 10]);
$stmt = $pdo->prepare(
    "UPDATE users SET password_hash = :h, failed_attempts = 0, lockout_until = NULL"
);
$stmt->execute([':h' => $hash]);

$storeCount = (int) $pdo->query("SELECT COUNT(*) FROM stores")->fetchColumn();
$wsCount    = (int) $pdo->query("SELECT COUNT(*) FROM workstations")->fetchColumn();
$userCount  = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

echo "[bootstrap-db] Ready: stores={$storeCount}, workstations={$wsCount}, users={$userCount}.\n";
exit(0);
