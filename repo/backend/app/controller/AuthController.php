<?php
namespace app\controller;

use app\common\ResponseHelper;
use app\logging\Logger;
use app\service\AuthService;
use think\facade\Db;
use think\Request;

/**
 * AuthController - Authentication endpoints: login, logout, password reset, current user.
 */
class AuthController
{
    /**
     * POST /auth/login
     */
    public function login(Request $request)
    {
        $username = $request->post('username', '');
        $password = $request->post('password', '');
        $storeId = (int) $request->post('store_id', 0);
        $workstationId = (int) $request->post('workstation_id', 0);

        if (empty($username) || empty($password) || $storeId <= 0 || $workstationId <= 0) {
            $resp = ResponseHelper::validationError('username, password, store_id, and workstation_id are required');
            return json($resp['data'], $resp['code']);
        }

        $result = AuthService::login($username, $password, $storeId, $workstationId);

        if (!$result['success']) {
            $statusMap = [
                'INVALID_CREDENTIALS' => 401,
                'ACCOUNT_LOCKED'      => 403,
                'ACCOUNT_INACTIVE'    => 403,
                'INVALID_BINDING'     => 403,
            ];
            $status = $statusMap[$result['error_code']] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'auth.login',
            'entity_type' => 'session',
            'entity_id'   => $result['data']['user']['id'],
            'before'      => null,
            'after'       => ['username' => $result['data']['user']['username'], 'store_id' => $storeId],
        ];

        $resp = ResponseHelper::success($result['data']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /auth/logout
     */
    public function logout(Request $request)
    {
        $userContext = $request->userContext;
        $tokenHash = $userContext['token_hash'] ?? '';

        if (empty($tokenHash)) {
            $resp = ResponseHelper::unauthorized('No active session');
            return json($resp['data'], $resp['code']);
        }

        $result = AuthService::logout($tokenHash);

        $request->auditData = [
            'action'      => 'auth.logout',
            'entity_type' => 'session',
            'entity_id'   => $userContext['user_id'],
            'before'      => ['session_id' => $userContext['session_id']],
            'after'       => null,
        ];

        if (!$result) {
            $resp = ResponseHelper::error('LOGOUT_FAILED', 'Unable to logout or session already ended', 400);
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success(['message' => 'Logged out successfully']);
        return json($resp['data'], $resp['code']);
    }

    /**
     * POST /auth/password/reset
     */
    public function resetPassword(Request $request)
    {
        $userContext = $request->userContext;
        $oldPassword = $request->post('old_password', '');
        $newPassword = $request->post('new_password', '');

        if (empty($oldPassword) || empty($newPassword)) {
            $resp = ResponseHelper::validationError('old_password and new_password are required');
            return json($resp['data'], $resp['code']);
        }

        $result = AuthService::resetPassword($userContext['user_id'], $oldPassword, $newPassword);

        if (!$result['success']) {
            $statusMap = [
                'NOT_FOUND'        => 404,
                'INVALID_PASSWORD' => 400,
                'WEAK_PASSWORD'    => 400,
            ];
            $status = $statusMap[$result['error_code']] ?? 400;
            $resp = ResponseHelper::error($result['error_code'], $result['message'], $status);
            return json($resp['data'], $resp['code']);
        }

        $request->auditData = [
            'action'      => 'auth.password_reset',
            'entity_type' => 'user',
            'entity_id'   => $userContext['user_id'],
            'before'      => null,
            'after'       => ['password_changed' => true],
        ];

        $resp = ResponseHelper::success(['message' => $result['message']]);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /auth/me
     */
    public function me(Request $request)
    {
        $userContext = $request->userContext;
        $resp = ResponseHelper::success($userContext);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /auth/bootstrap/stores
     * Unauthenticated: returns the minimal list (id, name) needed to
     * populate the login-page store dropdown. No other columns are exposed.
     */
    public function bootstrapStores(Request $request)
    {
        try {
            $stores = Db::table('stores')
                ->field('id,name')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            // Login is the only entry point to the app; a hard 500 here
            // strands the user on a blank dropdown with no escape. Log
            // the cause and return an empty list with a 503 so the
            // client can render a "try again" hint while preserving the
            // standard JSON envelope (no HTML, no localized framework
            // page).
            Logger::error('auth', 'bootstrap_stores_failed', $e->getMessage(), [
                'exception_class' => get_class($e),
            ]);
            $resp = ResponseHelper::error('STORES_UNAVAILABLE', 'Store list is temporarily unavailable', 503);
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($stores);
        return json($resp['data'], $resp['code']);
    }

    /**
     * GET /auth/bootstrap/workstations
     * Unauthenticated: returns minimal workstation info (id, store_id, name)
     * scoped by the requested store_id, for the login-page dropdown.
     */
    public function bootstrapWorkstations(Request $request)
    {
        try {
            $storeId = (int) $request->get('store_id', 0);
            $query = Db::table('workstations')->field('id,store_id,name');
            if ($storeId > 0) {
                $query->where('store_id', $storeId);
            }
            $workstations = $query->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            Logger::error('auth', 'bootstrap_workstations_failed', $e->getMessage(), [
                'exception_class' => get_class($e),
            ]);
            $resp = ResponseHelper::error('WORKSTATIONS_UNAVAILABLE', 'Workstation list is temporarily unavailable', 503);
            return json($resp['data'], $resp['code']);
        }

        $resp = ResponseHelper::success($workstations);
        return json($resp['data'], $resp['code']);
    }
}
