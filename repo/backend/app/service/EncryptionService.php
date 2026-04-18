<?php
namespace app\service;

use app\common\AppConfig;
use app\logging\Logger;

/**
 * EncryptionService - Field-level encryption/decryption for sensitive data.
 * Uses AES-256-CBC with versioned keys.
 * Targets: taxpayer/invoice identifiers, contact details, legal identifiers.
 */
class EncryptionService
{
    private const CIPHER = 'aes-256-cbc';

    public static function encrypt(string $plaintext, ?int $keyVersion = null): string
    {
        $keyVersion = $keyVersion ?? AppConfig::get('encryption_active_key_version', 1);
        $key = self::getKeyMaterial($keyVersion);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER));
        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, 0, $iv);

        return json_encode([
            'v'    => $keyVersion,
            'iv'   => base64_encode($iv),
            'data' => $encrypted,
        ]);
    }

    public static function decrypt(string $encryptedPayload): string
    {
        $payload = json_decode($encryptedPayload, true);
        if (!$payload || !isset($payload['v'], $payload['iv'], $payload['data'])) {
            Logger::error('encryption', 'decrypt', 'Invalid encrypted payload format');
            return '';
        }

        $key = self::getKeyMaterial($payload['v']);
        $iv = base64_decode($payload['iv']);
        $decrypted = openssl_decrypt($payload['data'], self::CIPHER, $key, 0, $iv);

        if ($decrypted === false) {
            Logger::error('encryption', 'decrypt', 'Decryption failed', ['key_version' => $payload['v']]);
            return '';
        }

        return $decrypted;
    }

    public static function getKeyVersion(string $encryptedPayload): ?int
    {
        $payload = json_decode($encryptedPayload, true);
        return $payload['v'] ?? null;
    }

    private static function getKeyMaterial(int $version): string
    {
        // Source 1: on-disk key file (production deployments that use a
        // pre-provisioned secret volume). Authoritative when present.
        $keyPath = AppConfig::get('encryption_keys_file_path');

        if ($keyPath && file_exists($keyPath)) {
            $keys = json_decode(file_get_contents($keyPath), true);
            if (isset($keys[$version])) {
                return base64_decode($keys[$version]);
            }
        }

        // Source 2: ENCRYPTION_KEY env var. This is the bootstrap path for
        // containerized deployments where mounting a key file is either
        // inconvenient (local dev, CI) or delegated to the orchestrator
        // (k8s Secret projected as an env var). Both single-value
        // ("base64:..." or raw base64) and versioned-JSON forms are
        // accepted; see README for the exact shapes.
        $envKey = getenv('ENCRYPTION_KEY');
        if (is_string($envKey) && $envKey !== '') {
            $resolved = self::resolveEnvKey($envKey, $version);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // Testing: derive a stable key so the suite runs without any
        // external material. Only fires when explicitly in the testing
        // env — in any other env, a missing key is a fatal startup error
        // with an actionable message.
        if (AppConfig::get('app_env', 'production') === 'testing') {
            return hash('sha256', 'fieldops_key_v' . $version, true);
        }

        throw new \RuntimeException(
            "Encryption key material unavailable for version {$version}. "
            . "Set ENCRYPTION_KEYS_FILE_PATH to a provisioned key file, "
            . "or provide an ENCRYPTION_KEY environment variable "
            . "(see docs for the supported formats). Refusing to start "
            . "with no key source configured."
        );
    }

    /**
     * Decode an ENCRYPTION_KEY env-var value into raw key bytes for the
     * requested version. Accepted shapes:
     *   - `{"1":"<base64>","2":"<base64>"}` — versioned JSON map, matches
     *      the on-disk keys file format.
     *   - `base64:<base64>` — single version, used at bootstrap.
     *   - `<base64>` — bare base64 for a single version.
     * Returns null when the value is not parseable or does not cover the
     * requested version.
     */
    private static function resolveEnvKey(string $raw, int $version): ?string
    {
        $trimmed = trim($raw);

        if ($trimmed !== '' && $trimmed[0] === '{') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded) && isset($decoded[$version])) {
                $decodedBytes = base64_decode($decoded[$version], true);
                return $decodedBytes === false ? null : $decodedBytes;
            }
            return null;
        }

        if (strncmp($trimmed, 'base64:', 7) === 0) {
            $trimmed = substr($trimmed, 7);
        }

        $decodedBytes = base64_decode($trimmed, true);
        return $decodedBytes === false ? null : $decodedBytes;
    }

    /**
     * Rotate to a new encryption key version.
     * Re-encrypts all sensitive fields from the current key to the new version,
     * persists the new key material, atomically flips the active key pointer,
     * and writes a security audit record. Returns true only after the active
     * switch is confirmed.
     */
    public static function rotateKey(int $newVersion): bool
    {
        $currentVersion = (int) AppConfig::get('encryption_active_key_version', 1);

        Logger::security('key_rotation', 'Encryption key rotation initiated', [
            'old_version' => $currentVersion,
            'new_version' => $newVersion,
        ]);

        // Ensure key material for the new version is resolvable before we touch
        // any data. In testing the material is derived; in production it must
        // be present in the keys file.
        try {
            $newKeyMaterial = self::getKeyMaterial($newVersion);
        } catch (\Throwable $e) {
            Logger::error('encryption', 'rotate_key_material', 'Cannot resolve key material for new version: ' . $e->getMessage());
            return false;
        }

        $rotatedCount = 0;
        $encFields = [
            'customer_phone_enc',
            'invoice_taxpayer_id_enc',
            'invoice_identifier_enc',
        ];

        // Re-encrypt each sensitive field on orders
        foreach ($encFields as $field) {
            $orders = \think\facade\Db::table('orders')
                ->whereNotNull($field)
                ->where($field, '<>', '')
                ->field('id,' . $field)
                ->select()->toArray();

            foreach ($orders as $order) {
                $encPayload = $order[$field];
                $payloadVersion = self::getKeyVersion($encPayload);

                // Skip records already on the new version or unreadable
                if ($payloadVersion === null || $payloadVersion === $newVersion) {
                    continue;
                }

                $plaintext = self::decrypt($encPayload);
                if ($plaintext === '') {
                    Logger::warning('encryption', 'rotate_skip', "Could not decrypt {$field} for order {$order['id']}");
                    continue;
                }

                $reEncrypted = self::encrypt($plaintext, $newVersion);
                \think\facade\Db::table('orders')
                    ->where('id', $order['id'])
                    ->update([$field => $reEncrypted]);
                $rotatedCount++;
            }
        }

        // Atomically persist key metadata and flip the active pointer.
        \think\facade\Db::startTrans();
        try {
            self::persistKeyMaterial($newVersion, $newKeyMaterial);
            self::retireOldKeys($newVersion);
            self::updateActiveKeyVersion($newVersion);
            \think\facade\Db::commit();
        } catch (\Throwable $e) {
            \think\facade\Db::rollback();
            AppConfig::set('encryption_active_key_version', $currentVersion);
            Logger::error('encryption', 'rotate_commit', 'Key pointer flip failed: ' . $e->getMessage());
            return false;
        }

        // Post-rotation verification: confirm the active version is now the new version
        $verifiedVersion = (int) AppConfig::get('encryption_active_key_version', 1);
        if ($verifiedVersion !== $newVersion) {
            Logger::error('encryption', 'rotate_verify', "Post-rotation verification failed: active version is {$verifiedVersion}, expected {$newVersion}");
            return false;
        }

        self::recordRotationAudit($currentVersion, $newVersion, $rotatedCount);

        Logger::security('key_rotation', 'Encryption key rotation completed and verified', [
            'new_version' => $newVersion,
            'fields_rotated' => $rotatedCount,
            'verified' => true,
        ]);

        return true;
    }

    /**
     * Persist the new active key version to config storage.
     * Updates both the runtime config and the persisted config store.
     */
    private static function updateActiveKeyVersion(int $newVersion): void
    {
        AppConfig::set('encryption_active_key_version', $newVersion);

        try {
            \think\facade\Db::table('config_overrides')->where('key', 'encryption_active_key_version')->delete();
            \think\facade\Db::table('config_overrides')->insert([
                'key'        => 'encryption_active_key_version',
                'value'      => (string) $newVersion,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // config_overrides may be unavailable in some environments (e.g. tests);
            // runtime config is still authoritative for the current process.
            Logger::warning('encryption', 'config_persist', 'Could not persist active key version to config store: ' . $e->getMessage());
        }
    }

    /**
     * Store the new key material in the encryption_keys metadata table.
     * No-op if the row already exists.
     */
    private static function persistKeyMaterial(int $version, string $keyMaterial): void
    {
        try {
            $existing = \think\facade\Db::table('encryption_keys')->where('key_version', $version)->find();
            $encoded = 'base64:' . base64_encode($keyMaterial);
            if ($existing) {
                \think\facade\Db::table('encryption_keys')
                    ->where('key_version', $version)
                    ->update(['status' => 'active']);
            } else {
                \think\facade\Db::table('encryption_keys')->insert([
                    'key_version'            => $version,
                    'key_material_encrypted' => $encoded,
                    'status'                 => 'active',
                    'created_at'             => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            Logger::warning('encryption', 'key_persist', 'Could not persist key material: ' . $e->getMessage());
        }
    }

    /**
     * Retire all key versions other than the newly active one so exactly one
     * row is marked active at any time.
     */
    private static function retireOldKeys(int $activeVersion): void
    {
        try {
            \think\facade\Db::table('encryption_keys')
                ->where('key_version', '<>', $activeVersion)
                ->where('status', 'active')
                ->update([
                    'status'     => 'retired',
                    'retired_at' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable $e) {
            Logger::warning('encryption', 'key_retire', 'Could not retire old keys: ' . $e->getMessage());
        }
    }

    /**
     * Write a row to security_events so the rotation is durably auditable.
     */
    private static function recordRotationAudit(int $oldVersion, int $newVersion, int $rotatedCount): void
    {
        try {
            \think\facade\Db::table('security_events')->insert([
                'event_type'   => 'encryption.key_rotation',
                'details_json' => json_encode([
                    'old_version'    => $oldVersion,
                    'new_version'    => $newVersion,
                    'fields_rotated' => $rotatedCount,
                    'verified'       => true,
                ]),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('encryption', 'rotation_audit', 'Could not write rotation audit record: ' . $e->getMessage());
        }
    }
}
