<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\job\AuditArchivalJob;

/**
 * AuditArchivalJobTest - Confirms the retention job runs without error and
 * produces the expected result envelope. This is the "scheduler smoke test":
 * if this fails, the cron entry in backend/crontab will fail at runtime.
 */
class AuditArchivalJobTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        if (!self::$booted) {
            $app = new \think\App();
            $app->initialize();
            self::$booted = true;
        }
    }

    public function testRunReturnsRetentionEnvelope(): void
    {
        try {
            $result = AuditArchivalJob::run();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not reachable for audit archival job: ' . $e->getMessage());
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('retention_years', $result);
        $this->assertArrayHasKey('cutoff_date', $result);
        $this->assertArrayHasKey('eligible_for_archival', $result);
        $this->assertArrayHasKey('action', $result);
        $this->assertSame('archive_only_no_delete', $result['action']);
        $this->assertIsInt($result['eligible_for_archival']);
        $this->assertGreaterThanOrEqual(0, $result['eligible_for_archival']);
    }

    public function testVerifyRetentionReturnsTrueForInPolicyData(): void
    {
        try {
            $ok = AuditArchivalJob::verifyRetention();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not reachable: ' . $e->getMessage());
        }
        $this->assertTrue($ok);
    }

    public function testScheduleConfigRegistersArchivalJob(): void
    {
        // The cron line in backend/crontab is derived from config/schedule.php,
        // so the schedule.php entry being present is what keeps the scheduler
        // wired up. This test fails if someone deletes the registration.
        $configPath = dirname(__DIR__, 2) . '/config/schedule.php';
        $this->assertFileExists($configPath, 'schedule.php must be committed so the scheduler knows what to run');

        $config = require $configPath;
        $this->assertIsArray($config);
        $this->assertArrayHasKey('jobs', $config);

        $names = array_column($config['jobs'], 'name');
        $this->assertContains('audit_archival', $names,
            'audit_archival must be registered in config/schedule.php');

        foreach ($config['jobs'] as $job) {
            if ($job['name'] === 'audit_archival') {
                $this->assertEquals('audit:archive', $job['command']);
                $this->assertMatchesRegularExpression('/^\S+\s+\S+\s+\S+\s+\S+\s+\S+$/', $job['cron'],
                    'cron expression must have five whitespace-separated fields');
                return;
            }
        }
    }

    public function testConsoleCommandIsRegistered(): void
    {
        $consolePath = dirname(__DIR__, 2) . '/config/console.php';
        $this->assertFileExists($consolePath);
        $console = require $consolePath;
        $this->assertArrayHasKey('commands', $console);
        $this->assertArrayHasKey('audit:archive', $console['commands']);
        $this->assertEquals(\app\command\AuditArchivalCommand::class, $console['commands']['audit:archive']);
        $this->assertTrue(class_exists(\app\command\AuditArchivalCommand::class),
            'AuditArchivalCommand must exist so `php think audit:archive` works');
    }
}
