# Shift Accountability Evidence

## 1. Session Store/Workstation Lock Enforcement

**Implementation:** `repo/backend/app/service/AuthService.php:58-68`

Login requires `store_id` and `workstation_id`. The service validates the binding exists in `user_store_workstation_bindings` before creating a session. The session record captures `store_id` and `workstation_id`, which are immutable for the session lifetime.

**DB Evidence:** `sessions` table stores `store_id`, `workstation_id` per session.  
**Frontend Evidence:** `repo/frontend/src/pages/login.js` renders store/workstation dropdowns as required fields.  
**Test Evidence:** `repo/backend/tests/api/AuthApiTest.php:testInvalidStoreWorkstationBinding`

## 2. Shift Open/Close Workflow

**Implementation:** `repo/backend/database/migrations/init.sql` — `shift_sessions` table with `shift_start_at`, `shift_end_at`, `opened_by`, `closed_by`, `close_reason`.

**DB Evidence:** `shift_sessions(user_id, role_code, store_id, workstation_id, shift_start_at, shift_end_at)`  
**Workflow:** Shift is opened on login and tracked per user/store/workstation combination.

## 3. Reassignment Authorization and Audit

**Implementation:** `repo/backend/app/controller/AdminController.php:reassignBinding`

Only Administrator role can reassign store/workstation bindings. The action:
- Deactivates the current active binding
- Creates or activates a new binding
- Logs the reassignment in `operation_logs` with before/after values

**Route Guard:** `repo/backend/route/api.php` — `POST admin/bindings/reassign-store-workstation` restricted to `['administrator']`  
**Audit:** Every reassignment creates an immutable `operation_logs` entry.  
**Test Evidence:** `repo/backend/tests/api/RbacApiTest.php` verifies non-admin roles get 403 on admin endpoints.

## 4. Cross-Store/Workstation Access Rejection

**Implementation:** `repo/backend/app/service/OrderService.php:getOrder` and `listOrders`

Object-level scope checks enforce:
- Non-admin users can only access orders from their own store (`store_id` match)
- Technicians can only see orders assigned to them (`assigned_technician_id` match)
- Dashboard queries are store-scoped unless Administrator

**Test Evidence:**
- `repo/backend/tests/api/OrderApiTest.php:testCrossStoreAccessDenied` — Tech2 (Store 2) cannot access Store 1 orders
- `repo/backend/tests/api/RbacApiTest.php` — multiple role restriction tests

## 5. Binding Table Schema

```sql
user_store_workstation_bindings(
  id, user_id, store_id, workstation_id, active,
  effective_from, effective_to, assigned_by
)
```

- `active` flag controls current binding
- `effective_from/to` provide temporal audit trail
- `assigned_by` records which admin performed the assignment
