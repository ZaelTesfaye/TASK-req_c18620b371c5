# Audit Report 6 - Fix Verification

Date: 2026-04-18
Scope: Verification of previously reported findings `F-001`, `F-002`, `F-003` from `.tmp/audit_report-6.md`.
Method: Static code review only (no runtime execution).

## Verdict

- Overall: **Mostly Fixed (2 fixed, 1 partially fixed)**

## Finding-by-finding Results

### F-001 - Receipt/order-detail item fields mismatch
- Previous issue: Frontend rendered non-canonical fields (`quantity`/`amount`/`price`) instead of backend canonical item fields.
- Current status: **Fixed**
- Evidence:
  - `repo/frontend/src/pages/kiosk.js:148` uses `items[i].qty`
  - `repo/frontend/src/pages/kiosk.js:149` uses `items[i].unit_price`
  - `repo/frontend/src/pages/kiosk.js:150` uses `items[i].line_subtotal`
  - `repo/frontend/src/pages/orders.js:277` uses `item.qty`
  - `repo/frontend/src/pages/orders.js:278` uses `item.unit_price`
  - `repo/frontend/src/pages/orders.js:279` uses `item.line_subtotal`
- Conclusion: Receipt and order-detail rendering now align with canonical backend item shape.

### F-002 - API test status-contract inconsistency
- Previous issue: Some tests expected `200` where controllers return `201` (and login invalid-credential mismatch concerns).
- Current status: **Partially Fixed**
- Evidence of fixes:
  - Controllers still return `201` for create endpoints:
    - `repo/backend/app/controller/OrderController.php:43`
    - `repo/backend/app/controller/FinanceController.php:70`
  - Canonical status constants now explicitly define creation as `201`:
    - `repo/tests/e2e/statusCodes.js`
    - `repo/backend/tests/StatusCodes.php`
  - E2E invalid login checks canonical 401 constant:
    - `repo/tests/e2e/fullstack.test.js:83`
  - Main order creation test checks canonical created constant:
    - `repo/tests/e2e/fullstack.test.js:121`
  - Finance API/contract tests check open-drawer as `DRAWER_OPENED` (201) or `CONFLICT` (409):
    - `repo/backend/tests/api/FinanceApiTest.php:125`
    - `repo/backend/tests/api/ContractTest.php:115`
- Residual gap:
  - One E2E test still allows both `200` and `201` for order creation (`expect([200, 201]).toContain(res.status)`), which is lenient rather than fully canonical:
    - `repo/tests/e2e/fullstack.test.js:410`
- Conclusion: Most contract drift is corrected, but one permissive assertion remains.

### F-003 - Missing scheduling documentation reference
- Previous issue: `docs/scheduling.md` referenced but missing.
- Current status: **Fixed**
- Evidence:
  - Reference remains in command docblock:
    - `repo/backend/app/command/AuditArchivalCommand.php:17`
  - File now exists:
    - `repo/docs/scheduling.md`
  - New doc explicitly references the command:
    - `repo/docs/scheduling.md:4`
- Conclusion: Documentation consistency issue is resolved.

## Final Assessment

- `F-001`: Fixed
- `F-002`: Partially Fixed (one non-canonical permissive assertion remains)
- `F-003`: Fixed

Recommended final cleanup for full closure of `F-002`:
- Update `repo/tests/e2e/fullstack.test.js:410` to assert `201` (or `STATUS.ORDER_CREATED`) only.
