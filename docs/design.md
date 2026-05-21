# System Design

## Architecture Overview

FieldOps Service & Environmental Analytics Suite is a full-stack web system for store operations. It combines:

- A ThinkPHP REST API backend
- A Layui single-page frontend (served by nginx)
- A MySQL 8.0 transactional datastore
- Docker Compose for local and test orchestration

At a high level, the frontend calls `/api/v1/*` endpoints, backend controllers apply validation and authorization rules, services enforce business workflows, and models/DB layers persist data.

## Layered Architecture

The backend follows a layered, role-aware architecture where responsibilities are separated:

1. API Layer (`repo/backend/app/controller` + `repo/backend/route/api.php`)

- Declares routes and HTTP verbs.
- Applies middleware (`auth`, `rbac`, `audit`) per endpoint.
- Parses request input and returns standardized responses.

2. Validation Layer (`repo/backend/app/validate`)

- Defines input constraints for auth/order/payment and related flows.
- Keeps shape checks out of business services.

3. Business Logic Layer (`repo/backend/app/service`)

- Implements workflows such as order lifecycle, cash-drawer reconciliation, experiment assignment, environmental metric derivation, and cleansing governance.
- Applies rules like state transitions, store isolation, role-sensitive field controls, and conflict checks.

4. Persistence Layer (`repo/backend/app/model` + ThinkPHP DB)

- Encapsulates data access for orders, users, operation logs, formulas, cleansing batches, and related entities.
- Uses MySQL with strict mode enabled and UTF-8 (`utf8mb4`).

5. Cross-Cutting Layer (`repo/backend/app/middleware`, `repo/backend/app/logging`)

- `AuthMiddleware`: bearer token session validation.
- `RbacMiddleware`: role checks per route.
- `AuditMiddleware`: immutable audit write on state-changing methods.
- Request logging and security event logging with redaction-aware logger.

## Directory Structure and Responsibilities

### Root

- `repo/docker-compose.yml`: container topology for app, db, and test profiles.
- `repo/run_tests.sh`: orchestrated backend and frontend test execution.
- `repo/README.md`: setup, runtime config, and operational notes.

### Backend (`repo/backend/`)

- `app/common`: shared infrastructure (`AppConfig`, `ResponseHelper`, exception handling).
- `app/controller`: HTTP endpoint handlers grouped by domain.
- `app/service`: domain/business orchestration.
- `app/model`: database persistence models.
- `app/validate`: request validation rules.
- `app/middleware`: auth, RBAC, audit, CORS, and request log middleware.
- `app/job` and `app/command`: scheduled/operational tasks such as audit archival.
- `config`: framework, middleware, route, db, scheduler settings.
- `database/migrations` and `database/seeds`: schema and bootstrap/demo data.
- `route/api.php`: full external API surface.
- `tests/api` and `tests/unit`: contract/security and unit-level verification.

### Frontend (`repo/frontend/`)

- `src/pages`: page-level modules by role/workflow.
- `src/components`: reusable UI components.
- `src/services`: API client and domain-specific service wrappers.
- `src/store`: auth/session and UI state.
- `src/router`: route guards and page navigation.
- `tests`: unit/integration/e2e frontend tests.

### Evidence and Project Docs (`docs/`)

- Coverage maps, role matrices, security/audit evidence, and review artifacts.
- Serves as the project governance and traceability layer.

## Key Design Decisions

1. Route-level middleware composition (`auth` + `rbac` + optional `audit`)

- Why: explicit and reviewable authorization policy per endpoint.
- Benefit: reduces hidden permission logic and supports audit evidence generation.

2. Standard API envelope (`ResponseHelper`)

- Success shape: `{"success": true, "data": ..., "request_id": ...}`
- Error shape: `{"success": false, "error_code": "...", "message": "...", "request_id": ...}`
- Why: frontend/client parsing stays consistent and trace IDs are always available.

3. Immutable audit-on-write behavior

- Why: operations with compliance/accountability needs must preserve who changed what and when.
- Benefit: forensic traceability and stronger governance controls.

4. Store/workstation binding in session context

- Why: this is a multi-store operational system where scope boundaries matter.
- Benefit: data isolation by default and reduced cross-store leakage risk.

5. MySQL-native bootstrap via mounted migration+seed SQL

- Why: deterministic local/test startup with no manual migration step.
- Benefit: fast onboarding and reproducible CI/local behavior.

6. Field-level encryption key versioning

- Why: supports secure rotation while preserving decryptability for historical data.
- Benefit: operational key hygiene and controlled cryptographic lifecycle.

7. Build-time configurable frontend API base URL

- Why: allows relative reverse-proxy setup in containerized deployment and explicit overrides for other environments.
- Benefit: simpler deployment topology with fewer runtime rewrites.

## Technology Choices and Justification

- ThinkPHP (Backend framework)
  - Lightweight MVC with straightforward routing/middleware patterns.
  - Good fit for role-gated CRUD+workflow APIs without heavyweight runtime overhead.

- MySQL 8.0 (Primary database)
  - Strong transactional consistency for order, payment, reconciliation, and audit trails.
  - Familiar operations model and deterministic initialization in Docker.

- Layui + Webpack (Frontend)
  - Practical for dashboard-heavy internal tools with role-specific views.
  - Build pipeline supports API base URL injection and test-friendly bundles.

- Docker Compose (Local + test orchestration)
  - Encodes complete topology, health checks, and profile-based test services.
  - Enables one-command boot and reproducible environments.

- Why no Redis in current architecture
  - Session and state consistency requirements are handled in primary datastore and app logic.
  - Current traffic and workflow complexity do not require separate cache invalidation paths.
  - Avoids operational overhead until latency/throughput metrics justify external cache.

- Why no PgBouncer/proxy pooler
  - Stack uses MySQL, not PostgreSQL; the equivalent concern is connection handling within PHP/MySQL setup.
  - Present deployment scope is single app+db topology where extra pooler complexity is not required.

- Why bearer-token session auth
  - Supports stateless frontend-to-API calls and works well across role-based endpoints.
  - Clear `Authorization: Bearer <token>` pattern simplifies middleware enforcement.

## End-to-End Data Flow

### Example: Create Order

1. User submits order form in frontend.
2. Frontend `api.js` attaches `Authorization` token and posts to `POST /api/v1/orders`.
3. `AuthMiddleware` validates token and injects user context.
4. `RbacMiddleware` verifies caller role is allowed (customer/front_desk/administrator).
5. Controller validates payload and delegates to `OrderService`.
6. `OrderService` enforces business rules (store scoping, initial state, coupon/tax rules) and writes to DB.
7. Response returns in standard success envelope with `request_id`.
8. `AuditMiddleware` logs the state-changing action in immutable operation logs.
9. Frontend updates local state and renders the created order.

### Example: Read Dashboard Analytics

1. Store manager requests analytics view.
2. Frontend calls `GET /api/v1/dashboards/analytics?from=...&to=...`.
3. Auth and RBAC pass for manager/admin roles.
4. Dashboard service aggregates scoped metrics from transactional tables.
5. API returns envelope with analytic payload (plus pagination where applicable).

## Separation Guarantees

- Transport concerns remain in controllers/middleware.
- Business invariants remain in services.
- Persistence remains in models/DB adapters.
- Authorization is route-declared and middleware-enforced.
- Error and success contracts remain centralized via `ResponseHelper`.

This separation keeps the codebase maintainable, testable, and auditable as domain complexity grows.
