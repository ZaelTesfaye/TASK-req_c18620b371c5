# Endpoint Role Matrix

Every API endpoint defined in `repo/backend/route/api.php` with its method, allowed roles, denied roles, object scope rule, validator, error cases, and linked test.

Base path: `/api/v1`

## Auth & Session

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `auth/login` | POST | All (no auth required) | None | N/A - public endpoint | `AuthController.php:23` - validates username, password, store_id, workstation_id | 401: invalid credentials; 403: account locked, account inactive, invalid store/workstation binding | `repo/backend/tests/api/AuthApiTest.php` (testLoginSuccess, testLoginInvalidCredentials, testInvalidStoreWorkstationBinding) |
| `auth/logout` | POST | All authenticated | Unauthenticated | Session-scoped: logs out current session only | None | 401: no active session or invalid token | `repo/backend/tests/api/AuthApiTest.php` (testLogoutSuccess) |
| `auth/password/reset` | POST | All authenticated | Unauthenticated | User-scoped: can only reset own password | `AuthController.php:95-98` - validates old_password and new_password | 401: unauthenticated; 400: invalid current password, weak new password | `repo/backend/tests/unit/PasswordPolicyTest.php` |
| `auth/me` | GET | All authenticated | Unauthenticated | Returns current user context only | None | 401: unauthenticated or expired session | `repo/backend/tests/api/AuthApiTest.php` (testGetMeAuthenticated, testGetMeUnauthenticated) |

## Orders

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `orders` | POST | customer, front_desk, administrator | technician, store_manager, finance | Store-scoped: order created in user's bound store | `OrderController.php:23-25` - requires customer_name and items | 401: no token; 403: technician/manager/finance denied | `repo/backend/tests/api/OrderApiTest.php` (testCreateOrderAsFrontDesk, testCreateOrderAsCustomer, testTechnicianCannotCreateOrder) |
| `orders` | GET | customer, front_desk, technician, store_manager, finance, administrator | None (all roles) | Store-scoped: non-admin sees only own store; technician sees only assigned orders | None | 401: no token | `repo/backend/tests/api/OrderApiTest.php` (testListOrdersWithPagination) |
| `orders/:id` | GET | customer, front_desk, technician, store_manager, finance, administrator | None | Store-scoped: cross-store returns null (404); technician only sees assigned | None | 401: no token; 404: not found or scope violation | `repo/backend/tests/api/OrderApiTest.php` (testGetOrderNotFound, testCrossStoreAccessDenied) |
| `orders/:id` | PATCH | front_desk, technician, administrator | customer, store_manager, finance | Store-scoped; technician pricing fields silently stripped | `OrderService.php:227-229` - strips pricing for technician role | 401: no token; 403: cross-store access; 404: not found | `repo/backend/tests/api/OrderApiTest.php` (testTechnicianCannotAlterPricing) |
| `orders/:id/confirm` | POST | front_desk, administrator | customer, technician, store_manager, finance | Store-scoped; validates state=draft | None | 401: no token; 403: role denied or cross-store; 409: invalid transition (not draft) | `repo/backend/tests/api/OrderApiTest.php` (testOrderFullLifecycle, testInvalidStateTransition) |
| `orders/:id/assign-technician` | POST | front_desk, administrator | customer, technician, store_manager, finance | Store-scoped; technician must have technician role | `OrderController.php:147-148` - validates technician_id | 401: no token; 403: role denied; 400: invalid technician | `repo/backend/tests/api/OrderApiTest.php` (testOrderFullLifecycle) |
| `orders/:id/accept` | POST | technician | customer, front_desk, store_manager, finance, administrator | Must be assigned technician for this order | None | 401: no token; 403: not assigned technician or role denied; 409: invalid transition | `repo/backend/tests/api/OrderApiTest.php` (testOrderFullLifecycle) |
| `orders/:id/work-notes` | POST | technician, administrator | customer, front_desk, store_manager, finance | Must be assigned technician or administrator | `OrderController.php:205-208` - validates note is non-empty | 401: no token; 403: not assigned technician | `repo/frontend/tests/e2e/orderWorkflow.test.js` |
| `orders/:id/complete` | POST | front_desk, technician, administrator | customer, store_manager, finance | Store-scoped; validates state=in_progress | None | 401: no token; 403: role denied; 409: invalid transition | `repo/backend/tests/api/OrderApiTest.php` (testOrderFullLifecycle) |
| `orders/:id/cancel` | POST | front_desk, store_manager, administrator | customer, technician, finance | Store-scoped; validates pre-completion state; reason required | `OrderController.php:263-268` - validates reason non-empty | 401: no token; 403: role denied; 409: cannot cancel completed/cancelled; 400: missing reason | `repo/backend/tests/api/OrderApiTest.php` (testCancelOrderRequiresReason) |
| `orders/:id/receipt` | GET | customer, front_desk, store_manager, finance, administrator | technician | Store-scoped; requires confirmed order with receipt_no | None | 401: no token; 403: role denied; 404: no receipt or not confirmed | `repo/frontend/tests/e2e/orderWorkflow.test.js` |
| `orders/:id/apply-coupon` | POST | customer, front_desk, administrator | technician, store_manager, finance | Store-scoped; one coupon per order enforced | `OrderController.php:315-318` - validates coupon code non-empty | 401: no token; 403: role denied; 400: invalid/expired/store-mismatch/used coupon | `repo/backend/tests/unit/CouponValidationTest.php` |
| `coupons/validate` | GET | customer, front_desk, administrator | technician, store_manager, finance | Validation-only; returns eligibility | `OrderController.php:348-352` - validates code and order_id | 401: no token; 403: role denied | `repo/backend/tests/unit/CouponValidationTest.php` |

## Payments & Refunds

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `orders/:id/payments` | POST | front_desk, finance, administrator | customer, technician, store_manager | Order must exist | `PaymentController.php:21-22` - requires tender_type and amount | 401: no token; 403: role denied; 400: invalid tender_type; 404: order not found | `repo/backend/tests/api/FinanceApiTest.php` (testRecordPaymentSuccess, testInvalidTenderType) |
| `orders/:id/refunds` | POST | front_desk, finance, administrator | customer, technician, store_manager | Refund linked to original payment; amount capped at refundable balance | `PaymentController.php:56-57` - requires original_payment_id, amount, reason | 401: no token; 403: role denied; 400: exceeds refundable balance | `repo/backend/tests/api/FinanceApiTest.php` (testRefundExceedsLimit) |

## Finance & Reconciliation

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `finance/cash-drawer/daily` | GET | finance, store_manager, administrator | customer, front_desk, technician | Store-scoped query | `FinanceController.php:18-20` - requires store_id and date | 401: no token; 403: customer/tech/frontdesk denied; 404: no drawer found | `repo/backend/tests/api/RbacApiTest.php` (testCustomerCannotAccessFinance, testFinanceCanAccessFinance) |
| `finance/cash-drawer` | POST | finance, administrator | customer, front_desk, technician, store_manager | Creates drawer for specified store/date | `FinanceController.php:46-48` - requires store_id and business_date | 401: no token; 403: role denied; 409: drawer already exists for date | `repo/backend/tests/api/FinanceApiTest.php` (testOpenCashDrawer) |
| `finance/cash-drawer/:id/close` | POST | finance, administrator | customer, front_desk, technician, store_manager | Must be open drawer | `FinanceController.php:78` - requires counted_total | 401: no token; 403: role denied; 409: drawer not open | `repo/backend/tests/api/FinanceApiTest.php` |
| `finance/cash-drawer/:id/reopen` | POST | administrator | customer, front_desk, technician, store_manager, finance | Must be closed drawer; reason mandatory | `FinanceController.php:108-110` - requires reason | 401: no token; 403: all non-admin denied (finance included); 409: not closed; 400: missing reason | `repo/backend/tests/api/FinanceApiTest.php` (testReopenRequiresAdmin, testFinanceCannotReopenDrawer, testReopenRequiresReason) |
| `finance/reconciliation/exceptions` | GET | finance, store_manager, administrator | customer, front_desk, technician | Store-scoped: flagged discrepancy drawers | `FinanceController.php:138-140` - requires store_id | 401: no token; 403: role denied | `repo/backend/tests/api/FinanceApiTest.php` (testGetReconciliationExceptions) |
| `finance/reconciliation/:id/statement` | GET | finance, store_manager, administrator | customer, front_desk, technician | Reads specific statement | None | 401: no token; 403: role denied; 404: not found | `repo/backend/tests/api/FinanceApiTest.php` |
| `finance/reconciliation/:id/statement.csv` | GET | finance, store_manager, administrator | customer, front_desk, technician | CSV export of statement | None | 401: no token; 403: role denied; 404: not found | `repo/backend/tests/api/FinanceApiTest.php` |

## Dashboards & Exports

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `dashboards/operations` | GET | store_manager, administrator | customer, front_desk, technician, finance | Store-scoped | `DashboardController.php:21-22` - requires from and to (MM/DD/YYYY) | 401: no token; 403: customer/tech/frontdesk/finance denied; 400: invalid date format | `repo/backend/tests/api/RbacApiTest.php` (testCustomerCannotAccessDashboard, testManagerCanAccessDashboard) |
| `dashboards/operations/export.csv` | GET | store_manager, administrator | customer, front_desk, technician, finance | Store-scoped CSV export | `DashboardController.php:48-49` - requires from and to | 401: no token; 403: role denied | `repo/backend/tests/api/RbacApiTest.php` |
| `dashboards/analytics` | GET | store_manager, administrator | customer, front_desk, technician, finance | Store-scoped | `DashboardController.php:70-71` - requires from and to | 401: no token; 403: role denied | `repo/backend/tests/api/RbacApiTest.php` |

## Announcements

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `announcements` | GET | front_desk, store_manager, administrator | customer, technician, finance | All visible announcements | None | 401: no token; 403: role denied | `repo/backend/tests/api/RbacApiTest.php` |
| `announcements` | POST | store_manager, administrator | customer, front_desk, technician, finance | Creates announcement | Title and body required | 401: no token; 403: role denied | `repo/backend/tests/api/RbacApiTest.php` |
| `announcements/:id` | GET | front_desk, store_manager, administrator | customer, technician, finance | By ID | None | 401: no token; 403: role denied; 404: not found | N/A |
| `announcements/:id` | PATCH | store_manager, administrator | customer, front_desk, technician, finance | By ID | None | 401: no token; 403: role denied; 404: not found | N/A |
| `announcements/:id` | DELETE | administrator | customer, front_desk, technician, store_manager, finance | By ID | None | 401: no token; 403: all non-admin denied; 404: not found | N/A |

## Events

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `events` | GET | store_manager, administrator | customer, front_desk, technician, finance | All active events | None | 401: no token; 403: role denied | N/A |
| `events` | POST | administrator | customer, front_desk, technician, store_manager, finance | Creates event definition | Key and name required | 401: no token; 403: all non-admin denied | N/A |
| `events/:id` | GET | store_manager, administrator | customer, front_desk, technician, finance | By ID | None | 401: no token; 403: role denied; 404: not found | N/A |
| `events/:id` | PATCH | administrator | customer, front_desk, technician, store_manager, finance | By ID | None | 401: no token; 403: non-admin denied; 404: not found | N/A |
| `events/:id` | DELETE | administrator | customer, front_desk, technician, store_manager, finance | By ID | None | 401: no token; 403: non-admin denied; 404: not found | N/A |
| `events/track` | POST | customer, front_desk, technician, store_manager, finance, administrator | None (all authenticated) | Logs event with user context | Event key required | 401: no token | N/A |

## Experiments

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `experiments` | GET | administrator | customer, front_desk, technician, store_manager, finance | All experiments | None | 401: no token; 403: non-admin denied | `repo/backend/tests/api/RbacApiTest.php` (testNonAdminCannotManageExperiments, testAdminCanManageExperiments) |
| `experiments` | POST | administrator | customer, front_desk, technician, store_manager, finance | Creates experiment | `ExperimentController.php:25-26` - key and name required | 401: no token; 403: non-admin denied | `repo/frontend/tests/e2e/orderWorkflow.test.js` |
| `experiments/:id` | GET | administrator | customer, front_desk, technician, store_manager, finance | By ID with variants | None | 401: no token; 403: non-admin denied; 404: not found | N/A |
| `experiments/:id` | PATCH | administrator | customer, front_desk, technician, store_manager, finance | Only draft experiments updatable | Allowed: name, holdout_percent, randomization_unit | 401: no token; 403: non-admin denied; 409: not in draft status | N/A |
| `experiments/:id/start` | POST | administrator | customer, front_desk, technician, store_manager, finance | Must be draft | None | 401: no token; 403: non-admin denied; 409: not draft | `repo/frontend/tests/integration/orderFlow.test.js` |
| `experiments/:id/stop` | POST | administrator | customer, front_desk, technician, store_manager, finance | Must be running | None | 401: no token; 403: non-admin denied; 409: not running | `repo/frontend/tests/integration/orderFlow.test.js` |
| `experiments/:id/assignments` | GET | administrator | customer, front_desk, technician, store_manager, finance | All assignments for experiment | None | 401: no token; 403: non-admin denied; 404: experiment not found | N/A |

## Environmental Analytics

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `environment/import/csv` | POST | store_manager, administrator | customer, front_desk, technician, finance | Store-scoped via source | `EnvironmentalController.php:21-22` - requires source_id and records | 401: no token; 403: role denied; 400: invalid source; 404: source not found | N/A |
| `environment/import/sensor-feed` | POST | administrator | customer, front_desk, technician, store_manager, finance | By source_id | `EnvironmentalController.php:58-59` - requires source_id and records | 401: no token; 403: non-admin denied | N/A |
| `environment/aligned-buckets` | GET | store_manager, administrator | customer, front_desk, technician, finance | Store-scoped | None | 401: no token; 403: role denied | N/A |
| `environment/derived-metrics` | GET | store_manager, administrator | customer, front_desk, technician, finance | Store-scoped | None | 401: no token; 403: role denied | N/A |
| `environment/lineage/:id` | GET | store_manager, administrator | customer, front_desk, technician, finance | By derived metric ID | None | 401: no token; 403: role denied; 404: not found | N/A |
| `environment/formulas` | GET | store_manager, administrator | customer, front_desk, technician, finance | All active formulas | None | 401: no token; 403: role denied | N/A |
| `environment/formulas` | POST | administrator | customer, front_desk, technician, store_manager, finance | Creates versioned formula; expires previous | `EnvironmentalController.php:204-205` - requires formula_key and formula_expression | 401: no token; 403: non-admin denied | N/A |
| `environment/formulas/:id` | PATCH | administrator | customer, front_desk, technician, store_manager, finance | Cannot update superseded formula | None | 401: no token; 403: non-admin denied; 409: formula already superseded | N/A |

## Data Cleansing Governance

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `cleansing/import` | POST | store_manager, administrator | customer, front_desk, technician, finance | Creates batch for review | `CleansingController.php:22-23` - requires source_name and rows | 401: no token; 403: role denied | N/A |
| `cleansing/batches` | GET | store_manager, administrator | customer, front_desk, technician, finance | All batches paginated | None | 401: no token; 403: role denied | N/A |
| `cleansing/batches/:id/preview` | GET | store_manager, administrator | customer, front_desk, technician, finance | By batch ID | None | 401: no token; 403: role denied | `repo/frontend/tests/e2e/orderWorkflow.test.js` (store_manager can view) |
| `cleansing/batches/:id/approve` | POST | administrator | customer, front_desk, technician, store_manager, finance | Must be pending_review | None | 401: no token; 403: non-admin denied (store_manager cannot approve); 409: not pending | `repo/backend/tests/api/RbacApiTest.php` (testManagerCannotApproveBatch) |
| `cleansing/batches/:id/rollback` | POST | administrator | customer, front_desk, technician, store_manager, finance | Must be approved batch | None | 401: no token; 403: non-admin denied; 409: invalid status | N/A |
| `cleansing/manual-review-queue` | GET | store_manager, administrator | customer, front_desk, technician, finance | All unresolved review items | None | 401: no token; 403: role denied | N/A |

## Audit & Security

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `audit/logs` | GET | store_manager, administrator | customer, front_desk, technician, finance | Filtered by user, role, store, workstation, action, entity, time range | None | 401: no token; 403: role denied | `repo/backend/tests/api/RbacApiTest.php` (testManagerCanViewAuditLogs, testFrontDeskCannotViewAuditLogs) |
| `security/events` | GET | administrator | customer, front_desk, technician, store_manager, finance | All security events | None | 401: no token; 403: non-admin denied | `repo/backend/tests/api/RbacApiTest.php` (testNonAdminCannotViewSecurityEvents) |

## Admin

| Endpoint | Method | Allowed Roles | Denied Roles | Object Scope Rule | Validator | 401/403/409 Cases | Linked Test |
|----------|--------|--------------|-------------|-------------------|-----------|-------------------|-------------|
| `admin/users` | POST | administrator | customer, front_desk, technician, store_manager, finance | Creates user with roles and bindings | `AdminController.php:25-26` - requires username and password; password policy validated | 401: no token; 403: non-admin denied; 409: username already exists | `repo/backend/tests/api/RbacApiTest.php` (testAdminCanAccessAdmin) |
| `admin/users/:id/roles` | PATCH | administrator | customer, front_desk, technician, store_manager, finance | Replaces user role assignments | `AdminController.php:112-113` - requires role_codes array | 401: no token; 403: non-admin denied; 404: user not found | N/A |
| `admin/bindings/reassign-store-workstation` | POST | administrator | customer, front_desk, technician, store_manager, finance | Deactivates old binding, creates/activates new | `AdminController.php:164-165` - requires user_id, new_store_id, new_workstation_id | 401: no token; 403: non-admin denied; 404: user not found | N/A |
| `admin/encryption/keys/rotate` | POST | administrator | customer, front_desk, technician, store_manager, finance | Rotates encryption key version | `AdminController.php:245-246` - requires new_version > 0 | 401: no token; 403: non-admin denied | N/A |
| `admin/users` | GET | administrator | customer, front_desk, technician, store_manager, finance | Lists all users | None | 401: no token; 403: non-admin denied | `repo/backend/tests/api/RbacApiTest.php` (testCustomerCannotAccessAdmin, testTechnicianCannotAccessAdmin, testFrontDeskCannotAccessAdmin, testAdminCanAccessAdmin) |
| `admin/stores` | GET | administrator, store_manager | customer, front_desk, technician, finance | Lists all stores | None | 401: no token; 403: role denied | N/A |
| `admin/workstations` | GET | administrator, store_manager | customer, front_desk, technician, finance | Lists all workstations | None | 401: no token; 403: role denied | N/A |
