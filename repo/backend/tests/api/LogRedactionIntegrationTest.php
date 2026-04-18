<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;
use app\logging\Logger;

/**
 * LogRedactionIntegrationTest — end-to-end verification that redaction is
 * actually applied to the log FILE that Logger writes to, not just to
 * in-memory return values like the unit-level LogRedactionTest checks.
 *
 * Two scenarios are exercised:
 *
 *   1. Direct Logger call with a login-shaped context containing a raw
 *      password. The emitted file must not contain that password verbatim.
 *
 *   2. Full HTTP login round-trip. Even if RequestLogMiddleware does not
 *      currently persist request bodies, we still assert the password
 *      isn't accidentally logged anywhere on disk during the request.
 */
class LogRedactionIntegrationTest extends TestCase
{
    private static bool $booted = false;
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('APP_URL') ?: 'http://localhost:8000';
        if (!self::$booted) {
            $app = new \think\App();
            $app->initialize();
            self::$booted = true;
        }
    }

    private function logFilePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs/app_' . date('Y-m-d') . '.log';
    }

    /**
     * Read the tail of the daily log file (last N bytes) so a large log
     * doesn't blow the test's memory. 2 MiB is enough to include the
     * writes the test triggered.
     */
    private function readRecentLog(): string
    {
        $path = $this->logFilePath();
        if (!file_exists($path)) { return ''; }
        $size = filesize($path);
        $readBytes = min($size, 2 * 1024 * 1024);
        $fh = fopen($path, 'r');
        if (!$fh) { return ''; }
        if ($size > $readBytes) {
            fseek($fh, -$readBytes, SEEK_END);
        }
        $tail = stream_get_contents($fh);
        fclose($fh);
        return $tail ?: '';
    }

    public function testDirectLoggerCallRedactsPasswordInEmittedLogFile(): void
    {
        $sentinel = 'SuperSecretPass_' . bin2hex(random_bytes(6));

        Logger::info('test', 'redaction_probe', 'Login attempt observed', [
            'username'   => 'probe-user',
            'password'   => $sentinel,
            'request_id' => 'probe-' . bin2hex(random_bytes(4)),
        ]);

        $tail = $this->readRecentLog();
        $this->assertNotEmpty($tail, 'Log file should exist and be non-empty after a Logger::info call');

        // The raw password must NOT appear anywhere in the emitted log line.
        $this->assertStringNotContainsString(
            $sentinel,
            $tail,
            'Raw password sentinel leaked into the log file — redaction pipeline is broken'
        );

        // A redaction placeholder must appear in its place. Logger uses
        // ***REDACTED*** as its canonical marker; [REDACTED] is accepted
        // as a compatible alternative.
        $this->assertTrue(
            strpos($tail, '***REDACTED***') !== false || strpos($tail, '[REDACTED]') !== false,
            'Expected a redaction placeholder (***REDACTED*** or [REDACTED]) in the log file'
        );
    }

    public function testRedactionSurvivesNestedContextStructures(): void
    {
        $sentinel = 'NestedPass_' . bin2hex(random_bytes(6));

        Logger::info('test', 'nested_probe', 'nested login payload', [
            'payload' => [
                'credentials' => [
                    'username' => 'u',
                    'password' => $sentinel,
                ],
                'meta' => ['trace' => 'ok'],
            ],
        ]);

        $tail = $this->readRecentLog();
        $this->assertStringNotContainsString(
            $sentinel,
            $tail,
            'Password sentinel nested inside the context leaked into the log file'
        );
    }

    public function testLoginRoundTripDoesNotLeakPasswordToLog(): void
    {
        $sentinel = 'IntegrationPass_' . bin2hex(random_bytes(6));

        // Issue a real HTTP login with a sentinel password. The actual user
        // doesn't need to exist — we only care that the password string
        // never ends up in the log file, whether the login succeeded or
        // failed.
        $ch = curl_init($this->baseUrl . '/api/v1/auth/login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'username' => 'probe_user_' . bin2hex(random_bytes(3)),
            'password' => $sentinel,
            'store_id' => 1,
            'workstation_id' => 1,
        ]));
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status === 0) {
            $this->markTestSkipped('Backend not reachable for HTTP log-redaction integration test');
        }

        // Allow a brief moment for any async log flushing (the file writer
        // uses LOCK_EX so reads should already be consistent, but give the
        // OS a tick).
        usleep(200 * 1000);

        $tail = $this->readRecentLog();
        $this->assertStringNotContainsString(
            $sentinel,
            $tail,
            'Sentinel password leaked into the application log during a real login round-trip'
        );
    }
}
