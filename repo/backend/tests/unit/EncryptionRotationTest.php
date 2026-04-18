<?php
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\service\EncryptionService;

/**
 * EncryptionRotationTest - Verifies that data encrypted under the old key
 * can still be read after rotation, and that new writes use the new key.
 */
class EncryptionRotationTest extends TestCase
{
    public function testEncryptWithExplicitKeyVersion(): void
    {
        $plaintext = 'sensitive-data-123';
        $encrypted = EncryptionService::encrypt($plaintext, 1);
        $this->assertEquals(1, EncryptionService::getKeyVersion($encrypted));

        $encrypted2 = EncryptionService::encrypt($plaintext, 2);
        $this->assertEquals(2, EncryptionService::getKeyVersion($encrypted2));
    }

    public function testDataEncryptedUnderOldKeyReadableAfterNewKeyExists(): void
    {
        $plaintext = 'taxpayer-id-987654';

        // Encrypt under version 1
        $encV1 = EncryptionService::encrypt($plaintext, 1);
        $this->assertEquals(1, EncryptionService::getKeyVersion($encV1));

        // Encrypt same data under version 2
        $encV2 = EncryptionService::encrypt($plaintext, 2);
        $this->assertEquals(2, EncryptionService::getKeyVersion($encV2));

        // Both should decrypt to the same plaintext
        $this->assertEquals($plaintext, EncryptionService::decrypt($encV1));
        $this->assertEquals($plaintext, EncryptionService::decrypt($encV2));
    }

    public function testReEncryptChangesKeyVersion(): void
    {
        $plaintext = 'phone-555-1234';

        $encV1 = EncryptionService::encrypt($plaintext, 1);
        $this->assertEquals(1, EncryptionService::getKeyVersion($encV1));

        // Simulate re-encryption (what rotateKey does)
        $decrypted = EncryptionService::decrypt($encV1);
        $encV3 = EncryptionService::encrypt($decrypted, 3);

        $this->assertEquals(3, EncryptionService::getKeyVersion($encV3));
        $this->assertEquals($plaintext, EncryptionService::decrypt($encV3));
    }

    public function testRotateKeyReturnsTrue(): void
    {
        // In test env, rotateKey should succeed (may re-encrypt zero records)
        $result = EncryptionService::rotateKey(99);
        $this->assertTrue($result);
    }

    public function testAfterRotationNewEncryptionsUseUpdatedKeyVersion(): void
    {
        // Get the current active version
        $originalVersion = (int) \app\common\AppConfig::get('encryption_active_key_version', 1);

        // Rotate to a new version
        $newVersion = $originalVersion + 100; // Use a high version to avoid conflicts
        $result = EncryptionService::rotateKey($newVersion);
        $this->assertTrue($result);

        // Verify the active version was updated
        $activeVersion = (int) \app\common\AppConfig::get('encryption_active_key_version', 1);
        $this->assertEquals($newVersion, $activeVersion);

        // Encrypt something without specifying a version — should use the new active version
        $encrypted = EncryptionService::encrypt('test-data-after-rotation');
        $this->assertEquals($newVersion, EncryptionService::getKeyVersion($encrypted));

        // Verify decryption still works
        $this->assertEquals('test-data-after-rotation', EncryptionService::decrypt($encrypted));

        // Restore original version
        \app\common\AppConfig::set('encryption_active_key_version', $originalVersion);
    }

    public function testDifferentVersionsProduceDifferentCiphertext(): void
    {
        $plaintext = 'same-data';
        $encV1 = EncryptionService::encrypt($plaintext, 1);
        $encV2 = EncryptionService::encrypt($plaintext, 2);

        // Different key versions produce different ciphertext
        $this->assertNotEquals($encV1, $encV2);

        // But both decrypt to the same value
        $this->assertEquals($plaintext, EncryptionService::decrypt($encV1));
        $this->assertEquals($plaintext, EncryptionService::decrypt($encV2));
    }
}
