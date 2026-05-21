# Mock Data Disclosure

## Mock/Local Data Usage

### Seed Data (Demo Only)
- **Location:** `repo/backend/database/seeds/seed.sql`
- **Purpose:** Pre-populate roles, stores, workstations, users, coupons, metric definitions, formula versions, and sensor sources for demo and testing
- **Scope:** All seed users use a common demo password (`Demo12345678!`) — in production, each user would have a unique strong password

### Encryption Keys (Demo Only)
- **Location:** `repo/backend/database/seeds/seed.sql` — encryption_keys table
- **Purpose:** Provide a functional encryption key for field-level encryption during demo
- **Note:** In production, key material would be generated securely and stored outside the database

### Payment Processing (Mocked by Design)
- **Location:** `repo/backend/app/service/PaymentService.php`
- **Purpose:** All payments are offline tenders (cash, card-present recorded, house account). No external payment gateway is integrated — this is by design per the business requirement for offline-only operation
- **Comment in code:** `// Mocking Payment Gateway response for audit stability — all payments are recorded as offline tenders without external gateway calls.`

## Core Paths Are NOT Fake-Success-Only

The following core business logic paths implement **real** validation, rejection, and error handling:

| Path | Success Behavior | Failure/Error Behavior | Evidence |
|------|-----------------|----------------------|----------|
| Login | Session created, token returned | Invalid credentials, lockout after 5 failures | repo/backend/app/service/AuthService.php |
| RBAC | Request proceeds | 403 Forbidden returned | repo/backend/app/middleware/RbacMiddleware.php |
| Order state machine | Valid transition executed | 409 Conflict for invalid transition | repo/backend/app/service/OrderService.php |
| Coupon validation | Discount applied | Rejection with specific reason | repo/backend/app/service/CouponService.php |
| Invoice validation | Order confirmed | 400 with field-level errors | repo/backend/app/validate/OrderValidate.php |
| Refund processing | Refund recorded | Exceeds-limit rejection | repo/backend/app/service/PaymentService.php |
| Reconciliation close | Statement generated, discrepancy flagged | Conflict if already closed | repo/backend/app/service/FinanceService.php |
| Cleansing approval | Batch status updated | 403 for non-admin, 409 for wrong status | repo/backend/app/service/CleansingService.php |

## Fallback/Error State Implementations

- **Backend:** `repo/backend/app/common/ExceptionHandler.php` — global exception handler, sanitized error responses
- **Backend:** `repo/backend/app/middleware/RequestLogMiddleware.php` — catches unhandled exceptions, returns 500 with request_id
- **Frontend:** All pages implement `.is-error` state class for API failures
- **Frontend:** `repo/frontend/src/pages/forbidden.js` — 403 page for unauthorized access

## Test Coverage of Error Paths

- `repo/backend/tests/api/AuthApiTest.php` — invalid credentials, lockout, missing fields
- `repo/backend/tests/api/OrderApiTest.php` — invalid transitions (409), cross-store access (403/404)
- `repo/backend/tests/api/RbacApiTest.php` — 403 for every unauthorized role combination
- `repo/backend/tests/api/FinanceApiTest.php` — invalid tender type, refund exceeds limit, reopen without admin
