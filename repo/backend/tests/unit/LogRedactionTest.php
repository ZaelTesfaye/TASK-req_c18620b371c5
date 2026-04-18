<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\logging\Logger;

/**
 * LogRedactionTest - Tests sensitive data redaction in logging.
 */
class LogRedactionTest extends TestCase
{
    public function testPasswordFieldRedacted(): void
    {
        $data = ['username' => 'admin', 'password' => 'SecretPass123!'];
        $redacted = Logger::redactSensitive($data);
        $this->assertEquals('***REDACTED***', $redacted['password']);
        $this->assertEquals('admin', $redacted['username']);
    }

    public function testTokenFieldRedacted(): void
    {
        $data = ['token' => 'abc123xyz'];
        $redacted = Logger::redactSensitive($data);
        $this->assertEquals('***REDACTED***', $redacted['token']);
    }

    public function testTaxpayerIdRedacted(): void
    {
        $data = ['taxpayer_id' => '12-3456789'];
        $redacted = Logger::redactSensitive($data);
        $this->assertEquals('***REDACTED***', $redacted['taxpayer_id']);
    }

    public function testNestedDataRedacted(): void
    {
        $data = ['user' => ['name' => 'Admin', 'password_hash' => 'hash123']];
        $redacted = Logger::redactSensitive($data);
        $this->assertEquals('***REDACTED***', $redacted['user']['password_hash']);
        $this->assertEquals('Admin', $redacted['user']['name']);
    }

    public function testNonSensitiveDataPreserved(): void
    {
        $data = ['id' => 1, 'status' => 'active', 'store_id' => 5];
        $redacted = Logger::redactSensitive($data);
        $this->assertEquals($data, $redacted);
    }

    public function testSSNPatternRedacted(): void
    {
        $message = 'User SSN is 123-45-6789 on file';
        $redacted = Logger::redactString($message);
        $this->assertStringNotContainsString('123-45-6789', $redacted);
    }

    public function testKeyMaterialRedacted(): void
    {
        $data = ['key_material' => 'super_secret_key_value'];
        $redacted = Logger::redactSensitive($data);
        $this->assertEquals('***REDACTED***', $redacted['key_material']);
    }
}
