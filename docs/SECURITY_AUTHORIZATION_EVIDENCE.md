# Security Authorization Evidence

Explicit proof points for authentication and authorization enforcement.

## 1. Authentication Entry Points

| Entry Point | File | Mechanism |
|-------------|------|-----------|
| POST /api/v1/auth/login | repo/backend/app/controller/AuthController.php | AuthService validates credentials, checks lockout, verifies store/workstation binding |
| Session validation | repo/backend/app/middleware/AuthMiddleware.php | Bearer token extracted, hash validated against sessions table |
| Password reset | repo/backend/app/controller/AuthController.php | Requires old password verification + new password policy check |

## 2. Route-Level Authorization

All protected routes are guarded by `auth` middleware (session validation) + `rbac` middleware (role check).

**Route file:** `repo/backend/route/api.php`

| Endpoint Group | Allowed Roles | Guard |
|---------------|---------------|-------|
| Orders CRUD | customer, front_desk, technician, store_manager, finance, administrator | auth + rbac |
| Order cancel | front_desk, store_manager, administrator | auth + rbac |
| Payments | front_desk, finance, administrator | auth + rbac |
| Finance reconciliation | finance, store_manager, administrator | auth + rbac |
| Reopen reconciliation | administrator | auth + rbac |
| Dashboards | store_manager, administrator | auth + rbac |
| Experiments | administrator | auth + rbac |
| Cleansing approve/rollback | administrator | auth + rbac |
| Admin user management | administrator | auth + rbac |
| Audit logs | store_manager, administrator | auth + rbac |
| Security events | administrator | auth + rbac |

## 3. Function-Level Authorization

| Function | File | Guard Logic |
|----------|------|-------------|
| Cancel order | repo/backend/app/service/OrderService.php | Checks `array_intersect(roles, ['front_desk', 'store_manager', 'administrator'])` |
| Reopen cash drawer | repo/backend/app/service/FinanceService.php | Checks `in_array('administrator', roles)` |
| Approve cleansing batch | repo/backend/app/service/CleansingService.php | Checks `in_array('administrator', roles)` |
| Rollback cleansing batch | repo/backend/app/service/CleansingService.php | Checks `in_array('administrator', roles)` |
| Technician accept | repo/backend/app/service/OrderService.php | Checks `assigned_technician_id == user_id` |
| Add work notes | repo/backend/app/service/OrderService.php | Checks `assigned_technician_id == user_id || admin` |

## 4. Object-Level Authorization

| Entity | Scope Rule | File |
|--------|-----------|------|
| Orders | Store-scoped for non-admin; technician sees only assigned | repo/backend/app/service/OrderService.php |
| Cash drawer | Store-scoped | repo/backend/app/service/FinanceService.php |
| Dashboard metrics | Store-scoped for non-admin | repo/backend/app/service/DashboardService.php |
| Environmental data | Store-scoped | repo/backend/app/service/EnvironmentalService.php |
| Audit logs | Store-scoped for store_manager; admin sees all | repo/backend/app/service/AuditService.php |

## 5. Store/Workstation Isolation

- **Session binding:** Login validates `user_store_workstation_bindings` match
- **Query scoping:** All list/aggregate queries include `WHERE store_id = ?` for non-admin
- **Cross-store test:** `repo/backend/tests/api/OrderApiTest.php:testCrossStoreAccessDenied`

## 6. Admin/Internal/Debug Endpoint Protection

- No debug-only routes exist in production config
- All `/admin/*` routes require `administrator` role
- `APP_DEBUG=false` in production repo/docker-compose.yml
- `ExceptionHandler` hides stack traces when `APP_ENV=production`
- No bypass route or test-only backdoor in route definitions

## 7. Test Evidence

| Security Dimension | Test File | Key Assertions |
|-------------------|-----------|----------------|
| 401 unauthenticated | repo/backend/tests/api/AuthApiTest.php | No token → 401 |
| 401 invalid token | repo/backend/tests/api/AuthApiTest.php | Bad token → 401 |
| 403 customer→admin | repo/backend/tests/api/RbacApiTest.php | Customer role → 403 on admin endpoints |
| 403 tech→admin | repo/backend/tests/api/RbacApiTest.php | Technician role → 403 |
| 403 frontdesk→admin | repo/backend/tests/api/RbacApiTest.php | Front desk → 403 |
| 403 finance→reopen | repo/backend/tests/api/FinanceApiTest.php | Finance → 403 on reopen |
| 403 manager→experiments | repo/backend/tests/api/RbacApiTest.php | Manager → 403 |
| 403 manager→approve batch | repo/backend/tests/api/RbacApiTest.php | Manager → 403 on approve |
| Object scope rejection | repo/backend/tests/api/OrderApiTest.php | Cross-store access → 403/404 |
| Lockout behavior | repo/backend/tests/api/AuthApiTest.php | 5 failures → locked |
