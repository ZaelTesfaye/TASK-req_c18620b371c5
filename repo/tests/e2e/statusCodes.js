/**
 * Canonical HTTP status codes returned by the backend controllers.
 *
 * This is the single source of truth for tests that assert against API
 * response codes. Keep these values aligned with the controllers under
 * backend/app/controller/ — any drift should be a deliberate change here,
 * not a silent mismatch in a test file.
 */

module.exports = {
    OK: 200,

    // Resource creation. Matches ResponseHelper::success($data, 201) used by:
    //   - OrderController::create  (POST /orders)
    //   - OrderController::addWorkNote (POST /orders/{id}/work-notes)
    //   - FinanceController::openDrawer (POST /finance/cash-drawer)
    CREATED: 201,
    ORDER_CREATED: 201,
    DRAWER_OPENED: 201,
    WORK_NOTE_CREATED: 201,

    BAD_REQUEST: 400,
    VALIDATION_ERROR: 400,

    // AuthController::login — INVALID_CREDENTIALS is mapped to 401.
    UNAUTHORIZED: 401,
    INVALID_CREDENTIALS: 401,

    // AuthController::login — ACCOUNT_LOCKED / INACTIVE / INVALID_BINDING map to 403.
    FORBIDDEN: 403,
    ACCOUNT_LOCKED: 403,

    NOT_FOUND: 404,
    CONFLICT: 409,
    INVALID_TRANSITION: 409,

    INTERNAL_SERVER_ERROR: 500,
};
