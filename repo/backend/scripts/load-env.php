<?php
/**
 * Minimal .env loader for standalone PHP scripts.
 *
 * The runtime backend (php -S via public/index.php) bootstraps through
 * ThinkPHP's App constructor, which calls \think\Env::load() and seeds
 * env vars from /app/.env. CLI scripts (bootstrap-db.php, reseed.php,
 * seed-passwords.php, the PHPUnit bootstrap) do NOT go through that
 * path, so without their own loader the dev defaults shipped in
 * backend/.env.example never reach getenv(). Removing the hardcoded
 * `?: 'fieldops_pass'` fallbacks below them would then turn a missing
 * env var into a blank-password connection attempt and an opaque
 * "Access denied" — instead of a clear, actionable error.
 *
 * Resolution order per key:
 *   1. Existing process env (docker-compose env_file, k8s Secret,
 *      operator-set shell var, etc.) — never overridden.
 *   2. .env file at the given path — fills keys not yet set.
 *   3. Still missing — requireEnv() exits 78 (EX_CONFIG) with a
 *      pointer to .env.example.
 *
 * Parser intentionally minimal: KEY=value per line, `#` comments, blank
 * lines skipped, one matching pair of surrounding quotes stripped. No
 * variable expansion, no multi-line values. backend/.env.example is
 * authored to that subset.
 */

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            if ($key === '') {
                continue;
            }
            // Strip one matched pair of surrounding quotes if present.
            $len = strlen($val);
            if ($len >= 2
                && ($val[0] === '"' || $val[0] === "'")
                && $val[0] === $val[$len - 1]
            ) {
                $val = substr($val, 1, -1);
            }
            // Do not override values already provided by the process
            // environment — compose env_file / k8s Secret / explicit
            // operator export must win over committed dev defaults.
            $existing = getenv($key);
            if ($existing !== false && $existing !== '') {
                continue;
            }
            putenv($key . '=' . $val);
            $_ENV[$key]    = $val;
            $_SERVER[$key] = $val;
        }
    }
}

if (!function_exists('requireEnv')) {
    function requireEnv(string $key): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            fwrite(STDERR,
                "[env] FATAL: required env var {$key} is not set.\n" .
                "      Expected from docker-compose env_file or backend/.env\n" .
                "      (provisioned by the Dockerfile from .env.example).\n"
            );
            exit(78); // EX_CONFIG, matches sysexits.h convention
        }
        return $value;
    }
}
