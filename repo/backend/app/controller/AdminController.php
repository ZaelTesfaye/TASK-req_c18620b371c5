<?php
namespace app\controller;

use app\common\ResponseHelper;
use app\service\AuthService;
use app\service\EncryptionService;
use think\facade\Db;
use think\Request;

/**
 * AdminController - User management, role assignment, binding reassignment,
 * and encryption key rotation.
 */
class AdminController
{
    /**
     * POST /admin/users
     * Create a new user account.
     */
    public function createUser(Request $request)
    {
        $userContext = $request->userContext;
        $data = $request->post();

        if (empty($data['username']) || empty($data['password'])) {
            $resp = ResponseHelper::validationError('username and password are required');
            return json($resp['data'], $resp['code']);
        }

        // Check unique username
        $existing = Db::table('users')->where('username', $data['username'])->find();
        if ($existing) {
            $resp = ResponseHelper::conflict('Username already exists');
            return json($resp['data'], $resp['code']);
        }

        // Validate password policy. Return WEAK_PASSWORD (not the generic
        // VALIDATION_ERROR) so clients can distinguish "policy failure"
        // from "missing field" — the test suite asserts this specific
        // error_code, and the distinction also matters to the admin UI
        // which surfaces a policy hint only on WEAK_PASSWORD.
        $validation = AuthService::validatePasswordPolicy($data['password']);
        if (!$validation['valid']) {
            $resp = ResponseHelper::error('WEAK_PASSWORD', $validation['message'], 400);
            return json($resp['data'], $resp['code']);
        }

        $passwordHash = AuthService::hashPassword($data['password']);
        $salt = bin2hex(random_bytes(16));

        $userId = Db::table('users')->insertGetId([
            'username'        => $data['username'],
            'password_hash'   => $passwordHash,
            'password_salt'   => $salt,
            'display_name'    => $data['display_name'] ?? $data['username'],
            'status'          => $data['status'] ?? 'active',
            'failed_attempts' => 0,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        // Assign roles if provided
        if (!empty($data['role_codes'])) {
            foreach ($data['role_codes'] as $roleCode) {
                $role = Db::table('roles')->where('code', $roleCode)->find();
                if ($role) {
                    Db::table('user_roles')->insert([
                        'user_id' => $userId,
                        'role_id' => $role['id'],
                    ]);
                }
            }
        }

        // Create bindings if provided
        if (!empty($data['bindings'])) {
            foreach ($data['bindings'] as $binding) {
                Db::table('user_store_workstation_bindings')->insert([
                    'user_id'        => $userId,
                    'store_id'       => $binding['store_id'],
                    'workstation_id' => $binding['workstation_id'],
                    'active'         => 1,
                ]);
            }
        }

        $user = Db::table('users')->where('id', $userId)->field('id,username,display_name,status,created_at')->find();

        $request->auditData = [
            'action'      => 'admin.create_user',
            'entity_type' => 'user',
            'entity_id'   => $userId,
            'before'      => null,
            'after'       => $user,
        ];

        $resp = ResponseHelper::success($user, 201);
        return json($resp['data'], $resp['code']);
    }

    /**
     * PATCH /admin/users/{id}/roles
     * Update role assignments for a user.
     */
    public function updateUserRoles(Request $request, int $id)
    {
        $userContext = $request->userContext;
        $user = Db::table('users')->where('id', $id)->find();

        if (!$user) {
            $resp = ResponseHelper::notFound('User not found');
            return json($resp['data'], $resp['code']);
        }

        $roleCodes = $request->put('role_codes', []);
        if (empty($roleCodes) || !is_array($roleCodes)) {
            $resp = ResponseHelper::validationError('role_codes array is required');
            return json($resp['data'], $resp['code']);
        }

        // Get current roles for audit
        $beforeRoles = Db::table('user_roles')
            ->alias('ur')
            ->join('roles r', 'ur.role_id = r.id')
            ->where('ur.user_id', $id)
            ->column('r.code');

        // Replace all roles
        Db::table('user_roles')->where('user_id', $id)->delete();

        $assignedRoles = [];
        foreach ($roleCodes as $roleCode) {
            $role = Db::table('roles')->where('code', $roleCode)->find();
            if ($role) {
                Db::table('user_roles')->insert([
                    'user_id' => $id,
                    'role_id' => $role['id'],
                ]);
                $assignedRoles[] = $roleCode;
            }
        }

        $request->auditData = [
            'action'      => 'admin.update_user_roles',
            'entity_type' => 'user',
            'entity_id'   => $id,
            'before'      => ['roles' => $beforeRoles],
            'after'       => ['roles' => $assignedRoles],
        ];

        $resp = ResponseHelper::success(['user_id' => $id, 'roles' => $assignedRoles]);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /admin/bindings/reassign
     * Reassign a user's store/workstation binding.
     */
    public function reassignBinding(Request $request)
    {
        $userContext = $request->userContext;
        $userId = (int) $request->post('user_id', 0);
        $oldStoreId = (int) $request->post('old_store_id', 0);
        $oldWorkstationId = (int) $request->post('old_workstation_id', 0);
        $newStoreId = (int) $request->post('new_store_id', 0);
        $newWorkstationId = (int) $request->post('new_workstation_id', 0);

        if ($userId <= 0 || $newStoreId <= 0 || $newWorkstationId <= 0) {
            $resp = ResponseHelper::validationError('user_id, new_store_id, and new_workstation_id are required');
            return json($resp['data'], $resp['code']);
        }

        $user = Db::table('users')->where('id', $userId)->find();
        if (!$user) {
            $resp = ResponseHelper::notFound('User not found');
            return json($resp['data'], $resp['code']);
        }

        // Deactivate old binding if specified
        $before = null;
        if ($oldStoreId > 0 && $oldWorkstationId > 0) {
            $oldBinding = Db::table('user_store_workstation_bindings')
                ->where('user_id', $userId)
                ->where('store_id', $oldStoreId)
                ->where('workstation_id', $oldWorkstationId)
                ->where('active', 1)
                ->find();

            if ($oldBinding) {
                Db::table('user_store_workstation_bindings')
                    ->where('id', $oldBinding['id'])
                    ->update(['active' => 0]);
                $before = $oldBinding;
            }
        }

        // Check if new binding already exists
        $existingNew = Db::table('user_store_workstation_bindings')
            ->where('user_id', $userId)
            ->where('store_id', $newStoreId)
            ->where('workstation_id', $newWorkstationId)
            ->find();

        if ($existingNew) {
            Db::table('user_store_workstation_bindings')
                ->where('id', $existingNew['id'])
                ->update(['active' => 1]);
        } else {
            Db::table('user_store_workstation_bindings')->insert([
                'user_id'        => $userId,
                'store_id'       => $newStoreId,
                'workstation_id' => $newWorkstationId,
                'active'         => 1,
            ]);
        }

        $after = Db::table('user_store_workstation_bindings')
            ->where('user_id', $userId)
            ->where('store_id', $newStoreId)
            ->where('workstation_id', $newWorkstationId)
            ->where('active', 1)
            ->find();

        $request->auditData = [
            'action'      => 'admin.reassign_binding',
            'entity_type' => 'user_binding',
            'entity_id'   => $userId,
            'before'      => $before,
            'after'       => $after,
        ];

        $resp = ResponseHelper::success([
            'user_id'        => $userId,
            'store_id'       => $newStoreId,
            'workstation_id' => $newWorkstationId,
        ]);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /admin/users
     * List all users with their roles.
     */
    public function listUsers(Request $request)
    {
        $users = Db::table('users')
            ->field('id,username,display_name,status,failed_attempts,created_at')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($users as &$user) {
            $user['roles'] = Db::table('user_roles')
                ->alias('ur')
                ->join('roles r', 'ur.role_id = r.id')
                ->where('ur.user_id', $user['id'])
                ->column('r.code');

            $user['bindings'] = Db::table('user_store_workstation_bindings')
                ->where('user_id', $user['id'])
                ->where('active', 1)
                ->field('store_id,workstation_id')
                ->select()
                ->toArray();
        }

        $resp = ResponseHelper::success($users);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /admin/stores
     * List all stores.
     */
    public function listStores(Request $request)
    {
        $stores = Db::table('stores')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $resp = ResponseHelper::success($stores);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /admin/workstations
     * List all workstations, optionally filtered by store_id.
     */
    public function listWorkstations(Request $request)
    {
        $storeId = $request->get('store_id');
        $query = Db::table('workstations');
        if ($storeId) {
            $query->where('store_id', $storeId);
        }
        $workstations = $query->order('id', 'asc')->select()->toArray();

        $resp = ResponseHelper::success($workstations);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /admin/encryption/keys/rotate
     * Trigger encryption key rotation.
     */
    public function rotateEncryptionKey(Request $request)
    {
        $userContext = $request->userContext;
        $newVersion = (int) $request->post('new_version', 0);

        if ($newVersion <= 0) {
            $resp = ResponseHelper::validationError('new_version is required and must be positive');
            return json($resp['data'], $resp['code']);
        }

        $result = EncryptionService::rotateKey($newVersion);

        if (!$result) {
            // rotateKey() only returns true after re-encryption, the metadata
            // commit, and the active-pointer verification have all succeeded.
            $resp = ResponseHelper::internalError('Key rotation failed or active key switch could not be confirmed');
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'admin.rotate_encryption_key',
            'entity_type' => 'encryption_key',
            'entity_id'   => $newVersion,
            'before'      => null,
            'after'       => ['new_version' => $newVersion, 'active' => true],
        ];

        $resp = ResponseHelper::success([
            'message'     => 'Encryption key rotation completed',
            'new_version' => $newVersion,
            'active'      => true,
        ]);
        return json($resp['data'], $resp['code']);
    }
}
