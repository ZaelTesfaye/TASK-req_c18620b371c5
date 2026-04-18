<?php
/**
 * FieldOps Service & Environmental Analytics Suite
 * API Route Definitions - Base path: /api/v1
 *
 * All routes enforce authentication via 'auth' middleware except login.
 * RBAC middleware enforces role-level access.
 * Audit middleware logs state-changing operations.
 */

use think\facade\Route;

// ============================================================
// Auth & Session (no auth middleware on login)
// ============================================================
Route::group('api/v1', function () {

    Route::post('auth/login', 'AuthController/login');

    // Public bootstrap endpoints for the login page. These expose only the
    // id/name columns required to populate store/workstation dropdowns.
    // Full listings remain behind auth at /admin/stores and /admin/workstations.
    Route::get('auth/bootstrap/stores', 'AuthController/bootstrapStores');
    Route::get('auth/bootstrap/workstations', 'AuthController/bootstrapWorkstations');

    Route::group('', function () {
        Route::post('auth/logout', 'AuthController/logout')->middleware('audit');
        Route::post('auth/password/reset', 'AuthController/resetPassword')->middleware('audit');
        Route::get('auth/me', 'AuthController/me');
    })->middleware('auth');

    // ============================================================
    // Orders - Role-gated
    // ============================================================
    Route::group('', function () {
        Route::post('orders', 'OrderController/create')
            ->middleware('rbac', ['roles' => ['customer', 'front_desk', 'administrator']])
            ->middleware('audit');

        Route::get('orders', 'OrderController/list')
            ->middleware('rbac', ['roles' => ['customer', 'front_desk', 'technician', 'store_manager', 'finance', 'administrator']]);

        Route::get('orders/:id', 'OrderController/read')
            ->middleware('rbac', ['roles' => ['customer', 'front_desk', 'technician', 'store_manager', 'finance', 'administrator']]);

        Route::patch('orders/:id', 'OrderController/update')
            ->middleware('rbac', ['roles' => ['front_desk', 'technician', 'administrator']])
            ->middleware('audit');

        Route::post('orders/:id/confirm', 'OrderController/confirm')
            ->middleware('rbac', ['roles' => ['front_desk', 'administrator']])
            ->middleware('audit');

        Route::post('orders/:id/assign-technician', 'OrderController/assignTechnician')
            ->middleware('rbac', ['roles' => ['front_desk', 'administrator']])
            ->middleware('audit');

        Route::post('orders/:id/accept', 'OrderController/accept')
            ->middleware('rbac', ['roles' => ['technician']])
            ->middleware('audit');

        Route::post('orders/:id/work-notes', 'OrderController/addWorkNote')
            ->middleware('rbac', ['roles' => ['technician', 'administrator']])
            ->middleware('audit');

        Route::post('orders/:id/complete', 'OrderController/complete')
            ->middleware('rbac', ['roles' => ['front_desk', 'technician', 'administrator']])
            ->middleware('audit');

        Route::post('orders/:id/cancel', 'OrderController/cancel')
            ->middleware('rbac', ['roles' => ['front_desk', 'store_manager', 'administrator']])
            ->middleware('audit');

        Route::get('orders/:id/receipt', 'OrderController/receipt')
            ->middleware('rbac', ['roles' => ['customer', 'front_desk', 'store_manager', 'finance', 'administrator']]);

        Route::post('orders/:id/apply-coupon', 'OrderController/applyCoupon')
            ->middleware('rbac', ['roles' => ['customer', 'front_desk', 'administrator']])
            ->middleware('audit');

        Route::get('coupons/validate', 'OrderController/validateCoupon')
            ->middleware('rbac', ['roles' => ['customer', 'front_desk', 'administrator']]);
    })->middleware('auth');

    // ============================================================
    // Payments & Refunds
    // ============================================================
    Route::group('', function () {
        Route::post('orders/:id/payments', 'PaymentController/recordPayment')
            ->middleware('rbac', ['roles' => ['front_desk', 'finance', 'administrator']])
            ->middleware('audit');

        Route::post('orders/:id/refunds', 'PaymentController/processRefund')
            ->middleware('rbac', ['roles' => ['front_desk', 'finance', 'administrator']])
            ->middleware('audit');
    })->middleware('auth');

    // ============================================================
    // Finance & Reconciliation
    // ============================================================
    Route::group('', function () {
        Route::get('finance/cash-drawer/daily', 'FinanceController/getDailyDrawer')
            ->middleware('rbac', ['roles' => ['finance', 'store_manager', 'administrator']]);

        Route::post('finance/cash-drawer', 'FinanceController/openDrawer')
            ->middleware('rbac', ['roles' => ['finance', 'administrator']])
            ->middleware('audit');

        Route::post('finance/cash-drawer/:id/close', 'FinanceController/closeDrawer')
            ->middleware('rbac', ['roles' => ['finance', 'administrator']])
            ->middleware('audit');

        Route::post('finance/cash-drawer/:id/reopen', 'FinanceController/reopenDrawer')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::get('finance/reconciliation/exceptions', 'FinanceController/getExceptions')
            ->middleware('rbac', ['roles' => ['finance', 'store_manager', 'administrator']]);

        Route::get('finance/reconciliation/:id/statement', 'FinanceController/getReconciliationStatement')
            ->middleware('rbac', ['roles' => ['finance', 'store_manager', 'administrator']]);

        Route::get('finance/reconciliation/:id/statement.csv', 'FinanceController/getReconciliationStatementCsv')
            ->middleware('rbac', ['roles' => ['finance', 'store_manager', 'administrator']]);
    })->middleware('auth');

    // ============================================================
    // Dashboards & Exports
    // ============================================================
    Route::group('', function () {
        Route::get('dashboards/operations', 'DashboardController/operations')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::get('dashboards/operations/export.csv', 'DashboardController/exportOperationsCsv')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::get('dashboards/analytics', 'DashboardController/analytics')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);
    })->middleware('auth');

    // ============================================================
    // Announcements
    // ============================================================
    Route::group('', function () {
        Route::get('announcements', 'AnnouncementController/list')
            ->middleware('rbac', ['roles' => ['front_desk', 'store_manager', 'administrator']]);

        Route::post('announcements', 'AnnouncementController/create')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']])
            ->middleware('audit');

        Route::get('announcements/:id', 'AnnouncementController/read')
            ->middleware('rbac', ['roles' => ['front_desk', 'store_manager', 'administrator']]);

        Route::patch('announcements/:id', 'AnnouncementController/update')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']])
            ->middleware('audit');

        Route::delete('announcements/:id', 'AnnouncementController/delete')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');
    })->middleware('auth');

    // ============================================================
    // Events
    // ============================================================
    Route::group('', function () {
        Route::get('events', 'EventController/list')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::post('events', 'EventController/create')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::get('events/:id', 'EventController/read')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::patch('events/:id', 'EventController/update')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::delete('events/:id', 'EventController/delete')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::post('events/track', 'EventController/track')
            ->middleware('rbac', ['roles' => ['customer', 'front_desk', 'technician', 'store_manager', 'finance', 'administrator']])
            ->middleware('audit');
    })->middleware('auth');

    // ============================================================
    // Experiments
    // ============================================================
    Route::group('', function () {
        Route::get('experiments', 'ExperimentController/list')
            ->middleware('rbac', ['roles' => ['administrator']]);

        Route::post('experiments', 'ExperimentController/create')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::get('experiments/:id', 'ExperimentController/read')
            ->middleware('rbac', ['roles' => ['administrator']]);

        Route::patch('experiments/:id', 'ExperimentController/update')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::post('experiments/:id/start', 'ExperimentController/start')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::post('experiments/:id/stop', 'ExperimentController/stop')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::get('experiments/:id/assignments', 'ExperimentController/assignments')
            ->middleware('rbac', ['roles' => ['administrator']]);

        // Runtime per-user variant lookup. Any authenticated caller can
        // fetch their own sticky assignment; this is the endpoint the
        // user-facing pages hit on load to decide which variant to render.
        Route::get('experiments/:id/assignment', 'ExperimentController/getAssignment');
    })->middleware('auth');

    // ============================================================
    // Environmental Analytics
    // ============================================================
    Route::group('', function () {
        Route::post('environment/import/csv', 'EnvironmentalController/importCsv')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']])
            ->middleware('audit');

        Route::post('environment/import/sensor-feed', 'EnvironmentalController/sensorFeed')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::get('environment/aligned-buckets', 'EnvironmentalController/getAlignedBuckets')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::post('environment/align-buckets', 'EnvironmentalController/alignBuckets')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']])
            ->middleware('audit');

        Route::get('environment/derived-metrics', 'EnvironmentalController/getDerivedMetrics')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::post('environment/compute-derived-metrics', 'EnvironmentalController/computeDerivedMetrics')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']])
            ->middleware('audit');

        Route::get('environment/lineage/:id', 'EnvironmentalController/getLineage')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::get('environment/formulas', 'EnvironmentalController/listFormulas')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::get('environment/formulas/:id', 'EnvironmentalController/readFormula')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::post('environment/formulas', 'EnvironmentalController/createFormula')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::patch('environment/formulas/:id', 'EnvironmentalController/updateFormula')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');
    })->middleware('auth');

    // ============================================================
    // Data Cleansing Governance
    // ============================================================
    Route::group('', function () {
        Route::post('cleansing/import', 'CleansingController/import')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']])
            ->middleware('audit');

        Route::get('cleansing/batches', 'CleansingController/listBatches')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::get('cleansing/batches/:id/preview', 'CleansingController/preview')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::post('cleansing/batches/:id/approve', 'CleansingController/approve')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::post('cleansing/batches/:id/rollback', 'CleansingController/rollback')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::get('cleansing/manual-review-queue', 'CleansingController/reviewQueue')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);
    })->middleware('auth');

    // ============================================================
    // Audit & Security
    // ============================================================
    Route::group('', function () {
        Route::get('audit/logs', 'AuditController/logs')
            ->middleware('rbac', ['roles' => ['store_manager', 'administrator']]);

        Route::get('security/events', 'AuditController/securityEvents')
            ->middleware('rbac', ['roles' => ['administrator']]);
    })->middleware('auth');

    // ============================================================
    // Admin
    // ============================================================
    Route::group('', function () {
        Route::post('admin/users', 'AdminController/createUser')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::patch('admin/users/:id/roles', 'AdminController/updateUserRoles')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::post('admin/bindings/reassign-store-workstation', 'AdminController/reassignBinding')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::post('admin/encryption/keys/rotate', 'AdminController/rotateEncryptionKey')
            ->middleware('rbac', ['roles' => ['administrator']])
            ->middleware('audit');

        Route::get('admin/users', 'AdminController/listUsers')
            ->middleware('rbac', ['roles' => ['administrator']]);

        Route::get('admin/stores', 'AdminController/listStores')
            ->middleware('rbac', ['roles' => ['administrator', 'store_manager']]);

        Route::get('admin/workstations', 'AdminController/listWorkstations')
            ->middleware('rbac', ['roles' => ['administrator', 'store_manager']]);
    })->middleware('auth');
});
