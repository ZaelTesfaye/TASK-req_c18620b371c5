<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\EncryptionService;

/**
 * EncryptionServiceTest - Tests AES-256-CBC field encryption/decryption.
 * Covers: encrypt, decrypt, key version extraction, roundtrip integrity.
 */
class EncryptionServiceTest extends TestCase
{
    public function testEncryptReturnsJsonPayload(): void
    {
        $encrypted = EncryptionService::encrypt('sensitive data');
        $payload = json_decode($encrypted, true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('v', $payload);
        $this->assertArrayHasKey('iv', $payload);
        $this->assertArrayHasKey('data', $payload);
    }

    public function testDecryptRecoversOriginalPlaintext(): void
    {
        $original = 'taxpayer-id-123456';
        $encrypted = EncryptionService::encrypt($original);
        $decrypted = EncryptionService::decrypt($encrypted);

        $this->assertEquals($original, $decrypted);
    }

    public function testRoundtripWithSpecialCharacters(): void
    {
        $original = "O'Malley & Sons: 50% off! #1";
        $encrypted = EncryptionService::encrypt($original);
        $decrypted = EncryptionService::decrypt($encrypted);

        $this->assertEquals($original, $decrypted);
    }

    public function testRoundtripWithEmptyString(): void
    {
        $encrypted = EncryptionService::encrypt('');
        $decrypted = EncryptionService::decrypt($encrypted);

        $this->assertEquals('', $decrypted);
    }

    public function testRoundtripWithUnicodeCharacters(): void
    {
        $original = '日本語テスト - Unicode 🎉';
        $encrypted = EncryptionService::encrypt($original);
        $decrypted = EncryptionService::decrypt($encrypted);

        $this->assertEquals($original, $decrypted);
    }

    public function testGetKeyVersionFromEncryptedPayload(): void
    {
        $encrypted = EncryptionService::encrypt('test', 1);
        $version = EncryptionService::getKeyVersion($encrypted);

        $this->assertEquals(1, $version);
    }

    public function testGetKeyVersionFromDifferentVersion(): void
    {
        $encrypted = EncryptionService::encrypt('test', 2);
        $version = EncryptionService::getKeyVersion($encrypted);

        $this->assertEquals(2, $version);
    }

    public function testGetKeyVersionReturnsNullForInvalidPayload(): void
    {
        $version = EncryptionService::getKeyVersion('not-json');
        $this->assertNull($version);
    }

    public function testGetKeyVersionReturnsNullForMissingVersionField(): void
    {
        $version = EncryptionService::getKeyVersion(json_encode(['data' => 'test']));
        $this->assertNull($version);
    }

    public function testDecryptReturnsEmptyStringForInvalidPayload(): void
    {
        $result = EncryptionService::decrypt('not-valid-json');
        $this->assertEquals('', $result);
    }

    public function testDecryptReturnsEmptyStringForMissingFields(): void
    {
        $result = EncryptionService::decrypt(json_encode(['v' => 1]));
        $this->assertEquals('', $result);
    }

    public function testEncryptProducesDifferentCiphertextEachTime(): void
    {
        $plaintext = 'same input';
        $encrypted1 = EncryptionService::encrypt($plaintext);
        $encrypted2 = EncryptionService::encrypt($plaintext);

        // Due to random IV, ciphertext should differ
        $this->assertNotEquals($encrypted1, $encrypted2);

        // But both should decrypt to same value
        $this->assertEquals($plaintext, EncryptionService::decrypt($encrypted1));
        $this->assertEquals($plaintext, EncryptionService::decrypt($encrypted2));
    }

    public function testEncryptUsesSpecifiedKeyVersion(): void
    {
        $encrypted = EncryptionService::encrypt('test data', 3);
        $payload = json_decode($encrypted, true);

        $this->assertEquals(3, $payload['v']);
    }

    public function testRotateKeyReturnsTrue(): void
    {
        $result = EncryptionService::rotateKey(5);
        $this->assertTrue($result);
    }

    public function testLongPlaintextEncryptsAndDecrypts(): void
    {
        $original = str_repeat('A', 10000);
        $encrypted = EncryptionService::encrypt($original);
        $decrypted = EncryptionService::decrypt($encrypted);

        $this->assertEquals($original, $decrypted);
    }
}
