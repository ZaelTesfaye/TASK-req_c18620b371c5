# Review Evidence Index

Maps static-audit report sections to precise file evidence.

## 1. Authentication Entry Points

| Evidence | File |
|----------|------|
| Login endpoint | repo/backend/app/controller/AuthController.php |
| Password validation | repo/backend/app/service/AuthService.php |
| Session management | repo/backend/app/service/AuthService.php |
| Lockout logic | repo/backend/app/service/AuthService.php |
| Password policy | repo/backend/app/service/AuthService.php |
| Auth middleware | repo/backend/app/middleware/AuthMiddleware.php |
| Login tests | repo/backend/tests/api/AuthApiTest.php |
| Password tests | repo/backend/tests/unit/PasswordPolicyTest.php |

## 2. Route/Function/Object Authorization

| Evidence | File |
|----------|------|
| RBAC middleware | repo/backend/app/middleware/RbacMiddleware.php |
| Route definitions with role guards | repo/backend/route/api.php |
| Object-level order scope | repo/backend/app/service/OrderService.php |
| Object-level finance scope | repo/backend/app/service/FinanceService.php |
| Admin-only endpoints | repo/backend/route/api.php (admin group) |
| Frontend route guards | repo/frontend/src/router/index.js |
| RBAC tests | repo/backend/tests/api/RbacApiTest.php |

## 3. Tenant/Store/Workstation Isolation

| Evidence | File |
|----------|------|
| Store-scoped order queries | repo/backend/app/service/OrderService.php |
| Technician assignment scope | repo/backend/app/service/OrderService.php |
| Store-scoped dashboard queries | repo/backend/app/service/DashboardService.php |
| Store-scoped environmental queries | repo/backend/app/service/EnvironmentalService.php |
| Cross-store access test | repo/backend/tests/api/OrderApiTest.php |
| Session binding validation | repo/backend/app/service/AuthService.php |

## 4. Immutable Audit Log Enforcement

| Evidence | File |
|----------|------|
| Append-only audit service | repo/backend/app/service/AuditService.php |
| Audit middleware | repo/backend/app/middleware/AuditMiddleware.php |
| Operation logs table (no UPDATE/DELETE) | repo/backend/database/migrations/init.sql |
| Audit search endpoint | repo/backend/app/controller/AuditController.php |
| Sensitive data redaction | repo/backend/app/logging/Logger.php |
| Archival job | repo/backend/app/job/AuditArchivalJob.php |
| Log redaction tests | repo/backend/tests/unit/LogRedactionTest.php |

## 5. Test Entry Points and Coverage

| Test Layer | Directory | Evidence |
|-----------|-----------|----------|
| Backend unit tests | repo/backend/tests/unit/ | PricingEngine, OrderStateMachine, Password, Discrepancy, Coupon, Confidence, Cleansing, Date, LogRedaction |
| Backend API tests | repo/backend/tests/api/ | Auth, Order, RBAC, Finance |
| Frontend unit tests | repo/frontend/tests/unit/ | validation, date, store |
| Frontend component tests | repo/frontend/tests/component/ | navigation, formStates |
| Frontend integration tests | repo/frontend/tests/integration/ | routeGuard, orderFlow |
| Frontend e2e tests | repo/frontend/tests/e2e/ | loginFlow, orderWorkflow |
| Test runner script | repo/run_tests.sh | Orchestrates all test suites |

## 6. Logging and Sensitive-Data Redaction

| Evidence | File |
|----------|------|
| Centralized logger | repo/backend/app/logging/Logger.php |
| Request/response logging | repo/backend/app/middleware/RequestLogMiddleware.php |
| Sensitive field list | repo/backend/app/logging/Logger.php |
| Sensitive pattern regex | repo/backend/app/logging/Logger.php |
| Redaction tests | repo/backend/tests/unit/LogRedactionTest.php |

## 7. Documentation-to-Code Consistency

| Document | Verified Against |
|----------|-----------------|
| repo/README.md ports | repo/docker-compose.yml |
| Route definitions | repo/backend/route/api.php |
| Config variables | repo/backend/config/app.php, repo/docker-compose.yml |
| DB schema | repo/backend/database/migrations/init.sql |
| Seed data | repo/backend/database/seeds/seed.sql |
| Test scripts | repo/run_tests.sh, phpunit.xml, package.json |
