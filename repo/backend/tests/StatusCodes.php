<?php
/**
 * Canonical HTTP status codes returned by backend controllers.
 *
 * Single source of truth for tests that assert against API response codes.
 * Controllers under app/controller/ are the authoritative implementation —
 * keep constants here aligned and treat any mismatch as a deliberate change
 * rather than silent drift in a single test file.
 */

namespace tests;

final class StatusCodes
{
    public const OK = 200;

    /**
     * Resource creation. Matches ResponseHelper::success($data, 201) returned
     * by OrderController::create, OrderController::addWorkNote, and
     * FinanceController::openDrawer.
     */
    public const CREATED = 201;
    public const ORDER_CREATED = 201;
    public const DRAWER_OPENED = 201;
    public const WORK_NOTE_CREATED = 201;

    public const BAD_REQUEST = 400;
    public const VALIDATION_ERROR = 400;

    /** AuthController::login maps INVALID_CREDENTIALS to 401. */
    public const UNAUTHORIZED = 401;
    public const INVALID_CREDENTIALS = 401;

    /** AuthController::login maps ACCOUNT_LOCKED/INACTIVE/INVALID_BINDING to 403. */
    public const FORBIDDEN = 403;
    public const ACCOUNT_LOCKED = 403;

    public const NOT_FOUND = 404;
    public const CONFLICT = 409;
    public const INVALID_TRANSITION = 409;

    public const INTERNAL_SERVER_ERROR = 500;
}
