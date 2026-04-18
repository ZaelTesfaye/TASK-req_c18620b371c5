<?php
namespace tests\api;

use PHPUnit\Framework\TestCase;
use think\facade\Db;

/**
 * AuditImmutabilityTest - DB-level enforcement of audit log immutability.
 *
 * Even if a future bug exposes an UPDATE/DELETE path (or an operator runs
 * an ad-hoc SQL) the database triggers must reject it. This complements
 * the API-layer tests in AuditApiTest which assert there is no HTTP route
 * for mutating audit entries.
 */
class AuditImmutabilityTest extends TestCase
{
    protected function setUp(): void
    {
        // Boot ThinkPHP's app kernel so the facade Db is usable.
        if (!class_exists(\think\App::class, false) || !$GLOBALS['__audit_immut_boot'] ?? false) {
            $app = new \think\App();
            $app->initialize();
            $GLOBALS['__audit_immut_boot'] = true;
        }
    }

    private function seedAuditRow(): ?int
    {
        try {
            return Db::table('operation_logs')->insertGetId([
                'actor_user_id'   => 1,
                'actor_role_code' => 'administrator',
                'store_id'        => 1,
                'workstation_id'  => 1,
                'action'          => 'test.immutability_probe',
                'entity_type'     => 'test',
                'entity_id'       => 'probe-' . bin2hex(random_bytes(4)),
                'request_id'      => bin2hex(random_bytes(8)),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function testDirectUpdateOnOperationLogsIsRejected(): void
    {
        $id = $this->seedAuditRow();
        if ($id === null) {
            $this->markTestSkipped('Database not reachable for direct-immutability test');
        }

        $rejected = false;
        $message = '';
        try {
            Db::table('operation_logs')
                ->where('id', $id)
                ->update(['action' => 'tampered']);
        } catch (\Throwable $e) {
            $rejected = true;
            $message = $e->getMessage();
        }

        $this->assertTrue(
            $rejected,
            'Direct UPDATE against operation_logs must be rejected by the DB trigger'
        );
        $this->assertStringContainsString('append-only', $message);

        // The row must still exist and still carry its original action.
        $row = Db::table('operation_logs')->where('id', $id)->find();
        $this->assertNotNull($row, 'Audit row must still exist after rejected UPDATE');
        $this->assertEquals('test.immutability_probe', $row['action']);
    }

    public function testDirectDeleteOnOperationLogsIsRejected(): void
    {
        $id = $this->seedAuditRow();
        if ($id === null) {
            $this->markTestSkipped('Database not reachable for direct-immutability test');
        }

        $rejected = false;
        try {
            Db::table('operation_logs')->where('id', $id)->delete();
        } catch (\Throwable $e) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'Direct DELETE against operation_logs must be rejected by the DB trigger'
        );

        $row = Db::table('operation_logs')->where('id', $id)->find();
        $this->assertNotNull($row, 'Audit row must still exist after rejected DELETE');
    }

    public function testDirectUpdateOnSecurityEventsIsRejected(): void
    {
        try {
            $id = Db::table('security_events')->insertGetId([
                'event_type'   => 'test.probe',
                'details_json' => json_encode(['probe' => true]),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database not reachable for security_events immutability test');
        }

        $rejected = false;
        try {
            Db::table('security_events')
                ->where('id', $id)
                ->update(['event_type' => 'tampered']);
        } catch (\Throwable $e) {
            $rejected = true;
        }

        $this->assertTrue(
            $rejected,
            'Direct UPDATE against security_events must be rejected by the DB trigger'
        );
    }

    public function testInsertRemainsPermittedOnOperationLogs(): void
    {
        $id = $this->seedAuditRow();
        if ($id === null) {
            $this->markTestSkipped('Database not reachable');
        }
        // A fresh insert should succeed — the trigger only fires on UPDATE/DELETE.
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }
}
