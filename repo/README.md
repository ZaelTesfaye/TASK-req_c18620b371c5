**Project Type: Fullstack** (ThinkPHP API + Layui SPA + MySQL)

# FieldOps Service & Environmental Analytics Suite

Project overview: FieldOps Service & Environmental Analytics Suite for offline service stores. A single English-language web station for end-to-end scheduling, fulfillment, and performance oversight with role-gated access, immutable audit logging, environmental analytics, and data cleansing governance.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | ThinkPHP (PHP 8.x) |
| Frontend | Layui (JavaScript, Webpack) |
| Database | MySQL 8.0 |
| Containerization | Docker / Docker Compose |

## Quick Start

```bash
docker-compose up --build
```

This single command builds and starts all services. The database is auto-initialized via migrations (`backend/database/migrations/init.sql`) and seeds (`backend/database/seeds/seed.sql`) mounted into MySQL's `docker-entrypoint-initdb.d` directory.

## Service Ports

| Service | Port | Description |
|---------|------|-------------|
| MySQL | 3306 | Database server |
| Backend | 8000 | ThinkPHP REST API |
| Frontend | 3000 | Layui web application (Nginx) |

## Configuration

All environment variables are defined in `docker-compose.yml`. The following table lists every configurable parameter:

### MySQL Service

| Variable | Value | Description |
|----------|-------|-------------|
| `MYSQL_ROOT_PASSWORD` | `fieldops_root_pass` | MySQL root password |
| `MYSQL_DATABASE` | `fieldops` | Default database name |
| `MYSQL_USER` | `fieldops_user` | Application database user |
| `MYSQL_PASSWORD` | `fieldops_pass` | Application database password |

### Backend Service

| Variable | Value | Description |
|----------|-------|-------------|
| `APP_NAME` | `FieldOps Service Suite` | Application display name |
| `APP_ENV` | `production` | Environment mode (production/testing) |
| `APP_DEBUG` | `false` | Debug mode toggle |
| `APP_URL` | `http://localhost:8000` | Backend base URL |
| `API_PREFIX` | `/api/v1` | API route prefix |
| `DB_HOST` | `mysql` | Database hostname (Docker service name) |
| `DB_PORT` | `3306` | Database port |
| `DB_NAME` | `fieldops` | Database name |
| `DB_USER` | `fieldops_user` | Database user |
| `DB_PASSWORD` | `fieldops_pass` | Database password |
| `SESSION_TTL_MINUTES` | `480` | Session time-to-live (8 hours) |
| `LOCKOUT_MAX_ATTEMPTS` | `5` | Failed login attempts before lockout |
| `LOCKOUT_DURATION_MINUTES` | `15` | Lockout duration after max failed attempts |
| `PASSWORD_MIN_LENGTH` | `12` | Minimum password length |
| `DEFAULT_TAX_RATE` | `0.08` | Default tax rate (8%) |
| `AUDIT_LOG_RETENTION_YEARS` | `7` | Audit log retention period |
| `DISCREPANCY_THRESHOLD_USD` | `1.00` | Cash drawer discrepancy threshold |
| `DEFAULT_TIME_BUCKET_MINUTES` | `1` | Environmental data time bucket size |
| `LATE_ARRIVAL_TOLERANCE_MINUTES` | `5` | Late-arriving sensor data tolerance |
| `ENCRYPTION_ACTIVE_KEY_VERSION` | `1` | Active encryption key version |
| `ENCRYPTION_KEYS_FILE_PATH` | `/app/storage/keys/encryption.key` | Optional on-disk key file location (preferred when a secret volume is mounted) |
| `ENCRYPTION_KEY` | (dev default in `docker-compose.yml`) | Bootstrap fallback used when `ENCRYPTION_KEYS_FILE_PATH` is absent. **Replace before any deployment outside local dev** — see "Encryption key provisioning" below |
| `CSV_EXPORT_ENCODING` | `UTF-8` | CSV export character encoding |
| `ENABLE_EXPERIMENTS` | `true` | A/B experiment feature toggle |
| `ENABLE_TLS` | `false` | TLS toggle (offline deployment) |

### Frontend Service

| Variable | Value | Description |
|----------|-------|-------------|
| `API_BASE_URL` | `/api/v1` (build arg + env) | Backend API base URL baked into the bundle at build time via webpack's `DefinePlugin`. Defaults to the relative path so nginx can reverse-proxy; override with `--build-arg API_BASE_URL=...` or the `docker-compose.yml` `build.args` block for absolute URLs. |

### Test Backend Service

| Variable | Value | Description |
|----------|-------|-------------|
| `APP_ENV` | `testing` | Testing environment mode |
| `APP_DEBUG` | `true` | Debug mode enabled for tests |
| All other vars | Same as backend | Identical configuration for test parity |

### Test Frontend Service

| Variable | Value | Description |
|----------|-------|-------------|
| `API_BASE_URL` | `http://backend:8000/api/v1` | Backend API base URL for tests |

## Encryption key provisioning

> ⚠️ **The `ENCRYPTION_KEY` value in `docker-compose.yml` is a development
> placeholder only.** It is a fixed, publicly visible, deterministically
> derived value whose sole purpose is letting `docker-compose up` succeed
> on a fresh checkout without any manual key setup. It is NOT a secret —
> it is committed to version control and visible to anyone with read
> access to this repo. Using it in any environment that handles real
> customer data, production orders, or genuine sensitive fields would
> leak every field-level-encrypted row to anyone who can read the repo.
> It **must be replaced** with a cryptographically random, deploy-time
> secret before any non-local deployment. `docker-compose.yml` runs with
> `APP_ENV=development` specifically to make this boundary explicit.

The backend needs key material for AES-256-CBC field-level encryption. Two
sources are supported, checked in this order:

1. **Key file** at `ENCRYPTION_KEYS_FILE_PATH` (default
   `/app/storage/keys/encryption.key`). Preferred for production — mount a
   secret volume that contains a JSON document mapping key-version numbers
   to base64-encoded 32-byte keys, e.g.
   `{"1":"<base64>","2":"<base64>"}`.

2. **`ENCRYPTION_KEY` env var.** Used when the key file is absent. Accepts
   the same versioned-JSON shape as the key file, or a single-version
   shortcut (`base64:<base64>` / bare base64). `docker-compose.yml` ships a
   deterministic dev-only value (see the warning above) so the container
   starts without any manual provisioning during local development.

If neither source resolves a key for the active version, the backend
refuses to start with a clear error (`EncryptionService::getKeyMaterial`).
The testing env (`APP_ENV=testing`) derives a stable key deterministically
and does not require either source.

**Production checklist — before deploying outside local dev:**

1. **Do not reuse the committed `ENCRYPTION_KEY`.** That value is a known
   public constant; any data encrypted under it is effectively plaintext
   to anyone with the repo.
2. **Generate a fresh 32-byte random key** per environment:

    ```bash
    # 32 random bytes, base64-encoded, wrapped in the versioned-JSON shape:
    python3 -c "import os,base64; print('{\"1\":\"' + base64.b64encode(os.urandom(32)).decode() + '\"}')"
    ```

3. **Inject it via a real secret manager** — Kubernetes `Secret`, HashiCorp
   Vault, AWS Secrets Manager, SOPS, etc. Do not commit production keys
   to git, and do not copy them into a production `docker-compose.yml`.
4. **Prefer the key file** (`ENCRYPTION_KEYS_FILE_PATH`) over the env var
   in production, so the key material never appears in process listings
   or container metadata. Remove the `ENCRYPTION_KEY` line entirely in
   the production overlay.
5. **Set `APP_ENV=production`** on the production overlay so
   `AppConfig::isProduction()` reports correctly; the dev compose file
   deliberately runs with `APP_ENV=development`.

## Database Initialization

The database is auto-initialized when the MySQL container starts for the first time:

1. **Schema**: `backend/database/migrations/init.sql` creates all tables
2. **Seed Data**: `backend/database/seeds/seed.sql` inserts roles, stores, workstations, demo users, coupons, metric definitions, formula versions, and sensor sources

No manual migration steps are required.

## Running Tests

```bash
./run_tests.sh
```

This script:
1. Starts the MySQL service and waits for health check
2. Runs backend tests (PHPUnit) via the `test-backend` Docker profile
3. Runs frontend tests (Jest) via the `test-frontend` Docker profile
4. Prints a summary with pass/fail counts

## Demo Accounts

All demo accounts use the same password for local development: `Demo12345678!`
(defined in `backend/database/seeds/seed.sql` line 36).

| Username | Password | Role | Store | Workstation |
|----------|----------------|---------------|-------|-------------|
| admin | `Demo12345678!` | Administrator | 1 | 1 |
| frontdesk1 | `Demo12345678!` | Front Desk | 1 | 1 |
| tech1 | `Demo12345678!` | Technician | 1 | 2 |
| manager1 | `Demo12345678!` | Store Manager | 1 | 1 |
| finance1 | `Demo12345678!` | Finance | 1 | 1 |
| customer1 | `Demo12345678!` | Customer | 1 | 3 |

> **Note:** These are demo-only credentials intended for local development. Rotate before any deployment outside a sandbox environment and never reuse them in production.

## Verification

After `docker-compose up --build` finishes, confirm the stack is healthy with
the following checks.

1. **Backend login (returns a session token):**

    ```bash
    curl -X POST http://localhost:8000/api/v1/auth/login \
      -H "Content-Type: application/json" \
      -d '{"username":"admin","password":"Demo12345678!","store_id":1,"workstation_id":1}'
    ```

    A healthy response is HTTP 200 with a JSON body containing
    `"success": true` and `"data.token": "..."`. Capture the token for the
    next call.

2. **Authenticated profile fetch (confirms session + RBAC middleware):**

    ```bash
    TOKEN="<paste-token-from-step-1>"
    curl http://localhost:8000/api/v1/auth/me \
      -H "Authorization: Bearer ${TOKEN}"
    ```

    Expect HTTP 200 with `data.username == "admin"` and a non-empty
    `data.roles` array.

3. **Public bootstrap endpoint (confirms nginx → backend + unauthenticated path):**

    ```bash
    curl http://localhost:8000/api/v1/auth/bootstrap/stores
    ```

    Expect HTTP 200 and a JSON array of `{id, name}` store rows.

4. **Frontend UI:** browse to `http://localhost:3000` and log in with any
    demo account from the table above. A successful login redirects to the
    dashboard or the role-appropriate landing page.

If all four checks pass, the backend, database, frontend, and the nginx
reverse-proxy wiring are working end-to-end.

## Feature Map by Role

### Customer
- Place service orders via kiosk or front desk channel
- Apply locally issued coupons
- Enter optional invoice details
- View order receipt after confirmation
- Track events

### Front Desk
- Create, edit, and cancel orders (cancellation reason required)
- Assign technicians to orders
- Record completion timestamps
- Record payments (cash, card-present, house account)
- Process refunds
- View announcements
- Apply coupons on behalf of customers
- Data cleansing batch review (view only)

### Technician
- Accept assigned jobs
- Log work notes on orders
- Mark order completion
- Cannot alter pricing
- Track events

### Store Manager
- View operational dashboards (transaction volume, avg fulfillment time, cancellation rate, complaint rate)
- View analytics dashboards (activity, conversion, retention, content quality, zero-result search rate)
- Export dashboard data to CSV
- View reconciliation exceptions and statements
- View announcements, create/edit announcements
- View environmental analytics (aligned buckets, derived metrics, formulas)
- Import environmental CSV data
- View cleansing batches and manual review queue
- View audit logs
- Manage store and workstation listings

### Finance
- Review daily cash drawer totals
- Record payments and process refunds
- View reconciliation screens, exceptions, and statements
- Export reconciliation to CSV
- Track events

### Administrator
- Full access to all features above
- User management (create users, assign roles)
- Reassign store/workstation bindings (audited)
- Encryption key rotation
- Reopen closed cash drawers (with mandatory reason)
- Define events and manage A/B experiments (create, start, stop, view assignments)
- Approve or rollback data cleansing batches
- Create/update/delete environmental formulas
- Import sensor feed data
- View security events
- Delete announcements and events
- All admin-only endpoints

## Project Structure

```
backend/
  app/
    common/          AppConfig, ExceptionHandler, ResponseHelper
    controller/      AuthController, OrderController, PaymentController,
                     FinanceController, DashboardController, AdminController,
                     AuditController, AnnouncementController, EventController,
                     ExperimentController, EnvironmentalController, CleansingController
    middleware/       AuthMiddleware, RbacMiddleware, AuditMiddleware,
                     CorsMiddleware, RequestLogMiddleware
    service/          AuthService, OrderService, PaymentService, FinanceService,
                     DashboardService, CouponService, AuditService,
                     EncryptionService, EnvironmentalService, ExperimentService,
                     CleansingService
    validate/        AuthValidate, OrderValidate, PaymentValidate
    job/             AuditArchivalJob
  config/            app.php, database.php, middleware.php, route.php
  database/
    migrations/      init.sql (full schema)
    seeds/           seed.sql (demo data)
  logging/           Logger.php (redaction-aware structured logging)
  route/             api.php (all API route definitions)
  tests/
    api/             AuthApiTest, OrderApiTest, FinanceApiTest, RbacApiTest
    unit/            PasswordPolicyTest, OrderStateMachineTest, PricingEngineTest,
                     CouponValidationTest, DiscrepancyThresholdTest, DateParsingTest,
                     ConfidenceScoreTest, CleansingNormalizationTest, LogRedactionTest
frontend/
  src/
    components/      Navigation.js
    router/          index.js (route definitions with role guards)
    services/        api.js, auth.js
    store/           index.js (client state management)
    utils/           date.js, validation.js
    styles/          main.css
    app.js           Application shell and login page
    index.html       Entry point
  tests/
    component/       formStates.test.js, navigation.test.js
    e2e/             loginFlow.test.js, orderWorkflow.test.js
    integration/     orderFlow.test.js, routeGuard.test.js
    unit/            date.test.js, store.test.js, validation.test.js
docs/                Documentation artifacts
docker-compose.yml   Service orchestration
run_tests.sh         One-command test runner
```
