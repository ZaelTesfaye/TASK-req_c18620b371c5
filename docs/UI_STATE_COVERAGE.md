# UI State Coverage

Every prompt-critical interaction with required states and evidence.

| Interaction | loading | submitting | disabled | error | success | empty | Frontend Evidence | Test Evidence |
|-------------|---------|------------|----------|-------|---------|-------|-------------------|---------------|
| Login form submission | .is-loading on page load | .is-submitting on btn | .is-disabled on btn | Error message display | Redirect to dashboard | N/A | frontend/src/pages/login.js | frontend/tests/e2e/loginFlow.test.js |
| Order creation | .is-loading table | .is-submitting on submit btn | .is-disabled during submit | Validation errors inline | Order created toast | Empty items msg | frontend/src/pages/orders.js | frontend/tests/e2e/orderWorkflow.test.js |
| Kiosk order intake | .is-loading | .is-submitting | .is-disabled | Validation feedback | Receipt display | No items | frontend/src/pages/kiosk.js | frontend/tests/integration/orderFlow.test.js |
| Coupon application | N/A | .is-submitting on validate | .is-disabled | Rejection reason shown | Discount applied | N/A | frontend/src/pages/kiosk.js | frontend/tests/unit/validation.test.js |
| Technician accept job | .is-loading queue | .is-submitting | .is-disabled | Error toast | Status updated | No assigned jobs | frontend/src/pages/technicianQueue.js | frontend/tests/e2e/orderWorkflow.test.js |
| Cash drawer close | .is-loading | .is-submitting on close btn | .is-disabled | Close error | Statement generated | No drawer | frontend/src/pages/finance.js | frontend/tests/integration/orderFlow.test.js |
| Dashboard load | .is-loading | N/A | N/A | .is-error on fail | .is-success with data | .is-empty no data | frontend/src/pages/dashboard.js | frontend/tests/component/formStates.test.js |
| CSV export | N/A | .is-submitting | .is-disabled | Error toast | File download | N/A | frontend/src/pages/dashboard.js | frontend/tests/integration/orderFlow.test.js |
| Experiment start/stop | N/A | .is-submitting | .is-disabled | Error display | Status change | N/A | frontend/src/pages/admin.js | frontend/tests/e2e/orderWorkflow.test.js |
| Cleansing approve/rollback | N/A | .is-submitting | .is-disabled | Error display | Status updated | No batches | frontend/src/pages/cleansing.js | frontend/tests/e2e/orderWorkflow.test.js |
| Audit log search | .is-loading results | N/A | N/A | .is-error | .is-success results | .is-empty no results | frontend/src/pages/auditLogs.js | frontend/tests/integration/routeGuard.test.js |
| Environmental import | N/A | .is-submitting on import | .is-disabled | .is-error message | .is-success count | N/A | frontend/src/pages/environmental.js | frontend/tests/component/formStates.test.js |

## CSS State Classes

Defined in `frontend/src/styles/main.css`:
- `.is-loading` — Shows spinner animation, reduces opacity
- `.is-submitting` — Disables pointer events, shows "Processing..." state
- `.is-disabled` — Greyed out, cursor: not-allowed
- `.is-error` — Red border/text for error feedback
- `.is-success` — Green border/text for success confirmation
- `.is-empty` — Centered placeholder text, muted color

## Duplicate Submit Prevention

`frontend/src/services/api.js` implements request-in-flight guard using a key-based dedup map. Buttons receive `.is-submitting` + `disabled=true` during requests.
