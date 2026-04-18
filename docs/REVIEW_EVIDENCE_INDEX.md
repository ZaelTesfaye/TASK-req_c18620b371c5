# Review Evidence Index

Maps static-audit report sections to precise file evidence.

## 1. Authentication Entry Points

| Evidence | File |
|----------|------|
| Login endpoint | backend/app/controller/AuthController.php |
| Password validation | backend/app/service/AuthService.php |
| Session management | backend/app/service/AuthService.php |
| Lockout logic | backend/app/service/AuthService.php |
| Password policy | backend/app/service/AuthService.php |
| Auth middleware | backend/app/middleware/AuthMiddleware.php |
| Login tests | backend/tests/api/AuthApiTest.php |
| Password tests | backend/tests/unit/PasswordPolicyTest.php |

## 2. Route/Function/Object Authorization

| Evidence | File |
|----------|------|
| RBAC middleware | backend/app/middleware/RbacMiddleware.php |
| Route definitions with role guards | backend/route/api.php |
| Object-level order scope | backend/app/service/OrderService.php |
| Object-level finance scope | backend/app/service/FinanceService.php |
| Admin-only endpoints | backend/route/api.php (admin group) |
| Frontend route guards | frontend/src/router/index.js |
| RBAC tests | backend/tests/api/RbacApiTest.php |

## 3. Tenant/Store/Workstation Isolation

| Evidence | File |
|----------|------|
| Store-scoped order queries | backend/app/service/OrderService.php |
| Technician assignment scope | backend/app/service/OrderService.php |
| Store-scoped dashboard queries | backend/app/service/DashboardService.php |
| Store-scoped environmental queries | backend/app/service/EnvironmentalService.php |
| Cross-store access test | backend/tests/api/OrderApiTest.php |
| Session binding validation | backend/app/service/AuthService.php |

## 4. Immutable Audit Log Enforcement

| Evidence | File |
|----------|------|
| Append-only audit service | backend/app/service/AuditService.php |
| Audit middleware | backend/app/middleware/AuditMiddleware.php |
| Operation logs table (no UPDATE/DELETE) | backend/database/migrations/init.sql |
| Audit search endpoint | backend/app/controller/AuditController.php |
| Sensitive data redaction | backend/logging/Logger.php |
| Archival job | backend/app/job/AuditArchivalJob.php |
| Log redaction tests | backend/tests/unit/LogRedactionTest.php |

## 5. Test Entry Points and Coverage

| Test Layer | Directory | Evidence |
|-----------|-----------|----------|
| Backend unit tests | backend/tests/unit/ | PricingEngine, OrderStateMachine, Password, Discrepancy, Coupon, Confidence, Cleansing, Date, LogRedaction |
| Backend API tests | backend/tests/api/ | Auth, Order, RBAC, Finance |
| Frontend unit tests | frontend/tests/unit/ | validation, date, store |
| Frontend component tests | frontend/tests/component/ | navigation, formStates |
| Frontend integration tests | frontend/tests/integration/ | routeGuard, orderFlow |
| Frontend e2e tests | frontend/tests/e2e/ | loginFlow, orderWorkflow |
| Test runner script | run_tests.sh | Orchestrates all test suites |

## 6. Logging and Sensitive-Data Redaction

| Evidence | File |
|----------|------|
| Centralized logger | backend/logging/Logger.php |
| Request/response logging | backend/app/middleware/RequestLogMiddleware.php |
| Sensitive field list | backend/logging/Logger.php |
| Sensitive pattern regex | backend/logging/Logger.php |
| Redaction tests | backend/tests/unit/LogRedactionTest.php |

## 7. Documentation-to-Code Consistency

| Document | Verified Against |
|----------|-----------------|
| README.md ports | docker-compose.yml |
| Route definitions | backend/route/api.php |
| Config variables | backend/config/app.php, docker-compose.yml |
| DB schema | backend/database/migrations/init.sql |
| Seed data | backend/database/seeds/seed.sql |
| Test scripts | run_tests.sh, phpunit.xml, package.json |
