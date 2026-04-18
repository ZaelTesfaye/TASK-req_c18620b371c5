<?php
namespace app\service;

use app\common\AppConfig;
use app\logging\Logger;
use think\facade\Db;

/**
 * AuthService - Handles authentication, session management, password policy, lockout.
 * Offline-only: no external auth provider dependency.
 */
class AuthService
{
    /**
     * Authenticate user with username, password, store_id, workstation_id
     */
    public static function login(string $username, string $password, int $storeId, int $workstationId): array
    {
        $user = Db::table('users')->where('username', $username)->find();

        if (!$user) {
            Logger::security('login_failed', 'User not found', ['username' => '***REDACTED***']);
            return ['success' => false, 'error_code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid username or password'];
        }

        // Check lockout
        if ($user['lockout_until'] && strtotime($user['lockout_until']) > time()) {
            $remainingMinutes = ceil((strtotime($user['lockout_until']) - time()) / 60);
            Logger::security('login_locked', 'Account is locked', ['user_id' => $user['id']]);

            Db::table('security_events')->insert([
                'event_type' => 'login_locked_attempt',
                'user_id' => $user['id'],
                'details_json' => json_encode(['lockout_until' => $user['lockout_until']]),
            ]);

            return [
                'success' => false,
                'error_code' => 'ACCOUNT_LOCKED',
                'message' => "Account is locked. Try again in {$remainingMinutes} minute(s).",
            ];
        }

        // Check status
        if ($user['status'] !== 'active') {
            Logger::security('login_inactive', 'Account is not active', ['user_id' => $user['id']]);
            return ['success' => false, 'error_code' => 'ACCOUNT_INACTIVE', 'message' => 'Account is not active'];
        }

        // Verify password
        if (!self::verifyPassword($password, $user['password_hash'])) {
            return self::handleFailedAttempt($user);
        }

        // Validate store/workstation binding
        $binding = Db::table('user_store_workstation_bindings')
            ->where('user_id', $user['id'])
            ->where('store_id', $storeId)
            ->where('workstation_id', $workstationId)
            ->where('active', 1)
            ->find();

        if (!$binding) {
            Logger::security('login_binding_invalid', 'Store/workstation binding not found', [
                'user_id' => $user['id'],
                'store_id' => $storeId,
                'workstation_id' => $workstationId,
            ]);
            return ['success' => false, 'error_code' => 'INVALID_BINDING', 'message' => 'User is not bound to the selected store/workstation'];
        }

        // Reset failed attempts on successful login
        Db::table('users')->where('id', $user['id'])->update([
            'failed_attempts' => 0,
            'lockout_until' => null,
        ]);

        // Create session
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $ttl = AppConfig::get('session_ttl_minutes', 480);
        $expiresAt = date('Y-m-d H:i:s', time() + ($ttl * 60));

        Db::table('sessions')->insert([
            'user_id' => $user['id'],
            'token_hash' => $tokenHash,
            'store_id' => $storeId,
            'workstation_id' => $workstationId,
            'login_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
            'source_type' => 'web',
        ]);

        // Get user roles
        $roles = Db::table('user_roles')
            ->alias('ur')
            ->join('roles r', 'ur.role_id = r.id')
            ->where('ur.user_id', $user['id'])
            ->column('r.code');

        Logger::security('login_success', 'User logged in successfully', [
            'user_id' => $user['id'],
            'store_id' => $storeId,
            'workstation_id' => $workstationId,
        ]);

        Db::table('security_events')->insert([
            'event_type' => 'login_success',
            'user_id' => $user['id'],
            'details_json' => json_encode([
                'store_id' => $storeId,
                'workstation_id' => $workstationId,
            ]),
        ]);

        return [
            'success' => true,
            'data' => [
                'token' => $token,
                'expires_at' => $expiresAt,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'roles' => $roles,
                    'store_id' => $storeId,
                    'workstation_id' => $workstationId,
                ],
            ],
        ];
    }

    public static function logout(string $tokenHash): bool
    {
        $session = Db::table('sessions')
            ->where('token_hash', $tokenHash)
            ->whereNull('logout_at')
            ->find();

        if (!$session) {
            return false;
        }

        Db::table('sessions')->where('id', $session['id'])->update([
            'logout_at' => date('Y-m-d H:i:s'),
        ]);

        Logger::security('logout', 'User logged out', ['user_id' => $session['user_id']]);

        return true;
    }

    public static function validateSession(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);
        $session = Db::table('sessions')
            ->where('token_hash', $tokenHash)
            ->whereNull('logout_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->find();

        if (!$session) {
            return null;
        }

        $user = Db::table('users')->where('id', $session['user_id'])->find();
        if (!$user || $user['status'] !== 'active') {
            return null;
        }

        $roles = Db::table('user_roles')
            ->alias('ur')
            ->join('roles r', 'ur.role_id = r.id')
            ->where('ur.user_id', $user['id'])
            ->column('r.code');

        return [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'roles' => $roles,
            'store_id' => $session['store_id'],
            'workstation_id' => $session['workstation_id'],
            'session_id' => $session['id'],
            'token_hash' => $tokenHash,
        ];
    }

    public static function resetPassword(int $userId, string $oldPassword, string $newPassword): array
    {
        $user = Db::table('users')->where('id', $userId)->find();
        if (!$user) {
            return ['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'User not found'];
        }

        if (!self::verifyPassword($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'error_code' => 'INVALID_PASSWORD', 'message' => 'Current password is incorrect'];
        }

        $validation = self::validatePasswordPolicy($newPassword);
        if (!$validation['valid']) {
            return ['success' => false, 'error_code' => 'WEAK_PASSWORD', 'message' => $validation['message']];
        }

        $salt = bin2hex(random_bytes(16));
        $hash = self::hashPassword($newPassword);

        Db::table('users')->where('id', $userId)->update([
            'password_hash' => $hash,
            'password_salt' => $salt,
        ]);

        Logger::security('password_reset', 'Password reset successfully', ['user_id' => $userId]);

        return ['success' => true, 'message' => 'Password updated successfully'];
    }

    public static function adminResetPassword(int $userId, string $newPassword): array
    {
        $validation = self::validatePasswordPolicy($newPassword);
        if (!$validation['valid']) {
            return ['success' => false, 'error_code' => 'WEAK_PASSWORD', 'message' => $validation['message']];
        }

        $salt = bin2hex(random_bytes(16));
        $hash = self::hashPassword($newPassword);

        Db::table('users')->where('id', $userId)->update([
            'password_hash' => $hash,
            'password_salt' => $salt,
        ]);

        Logger::security('admin_password_reset', 'Admin reset user password', ['user_id' => $userId]);

        return ['success' => true, 'message' => 'Password updated successfully'];
    }

    public static function validatePasswordPolicy(string $password): array
    {
        $minLength = AppConfig::get('password_min_length', 12);

        if (strlen($password) < $minLength) {
            return ['valid' => false, 'message' => "Password must be at least {$minLength} characters long"];
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter'];
        }

        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one lowercase letter'];
        }

        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one digit'];
        }

        if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one special character'];
        }

        return ['valid' => true, 'message' => ''];
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    private static function handleFailedAttempt(array $user): array
    {
        $maxAttempts = AppConfig::get('lockout_max_attempts', 5);
        $lockoutMinutes = AppConfig::get('lockout_duration_minutes', 15);
        $newAttempts = $user['failed_attempts'] + 1;

        $updateData = ['failed_attempts' => $newAttempts];

        if ($newAttempts >= $maxAttempts) {
            $lockoutUntil = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
            $updateData['lockout_until'] = $lockoutUntil;

            Logger::security('account_locked', 'Account locked after failed attempts', [
                'user_id' => $user['id'],
                'attempts' => $newAttempts,
                'lockout_until' => $lockoutUntil,
            ]);

            Db::table('security_events')->insert([
                'event_type' => 'account_locked',
                'user_id' => $user['id'],
                'details_json' => json_encode([
                    'failed_attempts' => $newAttempts,
                    'lockout_until' => $lockoutUntil,
                ]),
            ]);
        }

        Db::table('users')->where('id', $user['id'])->update($updateData);

        Logger::security('login_failed', 'Invalid password', [
            'user_id' => $user['id'],
            'attempts' => $newAttempts,
        ]);

        $remaining = $maxAttempts - $newAttempts;
        $message = 'Invalid username or password';
        if ($remaining > 0 && $remaining <= 2) {
            $message .= ". {$remaining} attempt(s) remaining before lockout.";
        } elseif ($remaining <= 0) {
            $message = "Account locked for {$lockoutMinutes} minutes due to too many failed attempts.";
        }

        return ['success' => false, 'error_code' => 'INVALID_CREDENTIALS', 'message' => $message];
    }
}
