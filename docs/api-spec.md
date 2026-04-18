# API Specification

Base URL: `/api/v1`

This document covers all external-facing endpoints defined in `backend/route/api.php`.

## Global Conventions

### Headers

Required on JSON APIs:

- `Content-Type: application/json`
- `Accept: application/json`

Required on protected APIs:

- `Authorization: Bearer <token>`

### Success Response Envelope

```json
{
  "success": true,
  "data": {},
  "request_id": "2e9c..."
}
```

### Error Response Envelope

```json
{
  "success": false,
  "error_code": "VALIDATION_ERROR",
  "message": "Validation failed",
  "fields": {
    "field_name": "reason"
  },
  "request_id": "2e9c..."
}
```

`fields` is optional and appears on validation errors.

### Auth and Permission Model

- `POST /auth/login` and `/auth/bootstrap/*` are public.
- All other endpoints require valid bearer token.
- Role permissions are enforced route-by-route via RBAC middleware.
- State-changing routes (`POST`, `PATCH`, `DELETE`) additionally use audit middleware where configured.

Role codes used by RBAC:

- `customer`
- `front_desk`
- `technician`
- `store_manager`
- `finance`
- `administrator`

## Endpoint Catalog

## Auth and Session

### POST /auth/login

- Description: Authenticate a user and issue a session token.
- Auth: Public.
- Request body:

| Field          | Type    | Required | Notes                                          |
| -------------- | ------- | -------- | ---------------------------------------------- |
| username       | string  | Yes      | Login username                                 |
| password       | string  | Yes      | Plaintext password over HTTPS/network boundary |
| store_id       | integer | Yes      | Selected store                                 |
| workstation_id | integer | Yes      | Selected workstation                           |

- Response body (`data`):

| Field               | Type     |
| ------------------- | -------- |
| token               | string   |
| user                | object   |
| user.id             | integer  |
| user.username       | string   |
| user.roles          | string[] |
| user.store_id       | integer  |
| user.workstation_id | integer  |

- Status codes: `200`, `401`, `403`, `423`, `500`.
- Example request:

```json
{
  "username": "admin",
  "password": "Demo12345678!",
  "store_id": 1,
  "workstation_id": 1
}
```

- Example response:

```json
{
  "success": true,
  "data": {
    "token": "eyJ...",
    "user": {
      "id": 1,
      "username": "admin",
      "roles": ["administrator"],
      "store_id": 1,
      "workstation_id": 1
    }
  },
  "request_id": "req-1"
}
```

### GET /auth/bootstrap/stores

- Description: Public bootstrap list for login store selector.
- Auth: Public.
- Request body: None.
- Response body (`data`): array of `{ id: integer, name: string }`.
- Status codes: `200`, `500`.
- Example response:

```json
{
  "success": true,
  "data": [{ "id": 1, "name": "Downtown" }],
  "request_id": "req-2"
}
```

### GET /auth/bootstrap/workstations

- Description: Public bootstrap list for login workstation selector.
- Auth: Public.
- Request body: None.
- Response body (`data`): array of `{ id: integer, name: string, store_id: integer }`.
- Status codes: `200`, `500`.
- Example response:

```json
{
  "success": true,
  "data": [{ "id": 1, "name": "Front Counter A", "store_id": 1 }],
  "request_id": "req-3"
}
```

### POST /auth/logout

- Description: Invalidate current session token.
- Auth: Any authenticated user.
- Request body: None.
- Response body (`data`): `{ logged_out: true }`.
- Status codes: `200`, `401`, `500`.
- Example response:

```json
{
  "success": true,
  "data": { "logged_out": true },
  "request_id": "req-4"
}
```

### POST /auth/password/reset

- Description: Reset current user password.
- Auth: Any authenticated user.
- Request body:

| Field        | Type   | Required |
| ------------ | ------ | -------- |
| old_password | string | Yes      |
| new_password | string | Yes      |

- Response body (`data`): `{ updated: true }`.
- Status codes: `200`, `400`, `401`, `500`.
- Example request:

```json
{
  "old_password": "OldSecret123!",
  "new_password": "NewSecret12345!"
}
```

### GET /auth/me

- Description: Return current authenticated principal context.
- Auth: Any authenticated user.
- Request body: None.
- Response body (`data`):

| Field          | Type     |
| -------------- | -------- |
| user_id        | integer  |
| username       | string   |
| roles          | string[] |
| store_id       | integer  |
| workstation_id | integer  |

- Status codes: `200`, `401`.

## Orders and Coupons

### POST /orders

- Description: Create a new service order.
- Auth: `customer`, `front_desk`, `administrator`.
- Request body:

| Field              | Type    | Required |
| ------------------ | ------- | -------- |
| customer_name      | string  | Yes      |
| customer_phone     | string  | No       |
| items              | array   | Yes      |
| items[].sku        | string  | Yes      |
| items[].qty        | integer | Yes      |
| items[].unit_price | number  | Yes      |
| notes              | string  | No       |

- Response body (`data`): created order object including `id`, `status`, totals, timestamps.
- Status codes: `200`, `400`, `401`, `403`, `409`, `500`.
- Example request:

```json
{
  "customer_name": "Jane Doe",
  "items": [{ "sku": "SRV-001", "qty": 1, "unit_price": 59.99 }],
  "notes": "Walk-in customer"
}
```

### GET /orders

- Description: List orders in caller scope.
- Auth: all roles.
- Query params: `page` (int), `page_size` (int), optional filters such as `status`.
- Request body: None.
- Response body (`data`): paginated object with `items`, `total`, `page`, `page_size`.
- Status codes: `200`, `401`, `500`.

### GET /orders/:id

- Description: Get an order by ID in caller scope.
- Auth: all roles.
- Request body: None.
- Response body (`data`): full order details.
- Status codes: `200`, `401`, `404`, `500`.

### PATCH /orders/:id

- Description: Update mutable order fields.
- Auth: `front_desk`, `technician`, `administrator`.
- Request body (partial):

| Field          | Type              | Required |
| -------------- | ----------------- | -------- |
| promised_at    | string(date-time) | No       |
| notes          | string            | No       |
| pricing fields | number            | No       |

Note: pricing fields from technician callers are restricted by business rules.

- Response body (`data`): updated order.
- Status codes: `200`, `400`, `401`, `403`, `404`, `409`, `500`.

### POST /orders/:id/confirm

- Description: Confirm order from draft state.
- Auth: `front_desk`, `administrator`.
- Request body: optional metadata (none required by route).
- Response body (`data`): updated order with confirmed status and receipt info.
- Status codes: `200`, `401`, `403`, `404`, `409`, `500`.

### POST /orders/:id/assign-technician

- Description: Assign technician to order.
- Auth: `front_desk`, `administrator`.
- Request body:

| Field         | Type    | Required |
| ------------- | ------- | -------- |
| technician_id | integer | Yes      |

- Response body (`data`): order with assigned technician.
- Status codes: `200`, `400`, `401`, `403`, `404`, `409`, `500`.

### POST /orders/:id/accept

- Description: Technician accepts assigned order.
- Auth: `technician`.
- Request body: None.
- Response body (`data`): updated order state.
- Status codes: `200`, `401`, `403`, `404`, `409`, `500`.

### POST /orders/:id/work-notes

- Description: Append technician/admin work note.
- Auth: `technician`, `administrator`.
- Request body:

| Field | Type   | Required |
| ----- | ------ | -------- |
| note  | string | Yes      |

- Response body (`data`): created note object and order linkage.
- Status codes: `200`, `400`, `401`, `403`, `404`, `500`.

### POST /orders/:id/complete

- Description: Mark order completed.
- Auth: `front_desk`, `technician`, `administrator`.
- Request body: optional completion metadata.
- Response body (`data`): updated order with completion timestamp.
- Status codes: `200`, `401`, `403`, `404`, `409`, `500`.

### POST /orders/:id/cancel

- Description: Cancel order with reason.
- Auth: `front_desk`, `store_manager`, `administrator`.
- Request body:

| Field  | Type   | Required |
| ------ | ------ | -------- |
| reason | string | Yes      |

- Response body (`data`): updated order in cancelled status.
- Status codes: `200`, `400`, `401`, `403`, `404`, `409`, `500`.

### GET /orders/:id/receipt

- Description: Fetch receipt representation for confirmed/completed order.
- Auth: `customer`, `front_desk`, `store_manager`, `finance`, `administrator`.
- Request body: None.
- Response body (`data`): receipt summary (`receipt_no`, totals, paid amount, issued_at).
- Status codes: `200`, `401`, `403`, `404`, `500`.

### POST /orders/:id/apply-coupon

- Description: Apply coupon to order.
- Auth: `customer`, `front_desk`, `administrator`.
- Request body:

| Field | Type   | Required |
| ----- | ------ | -------- |
| code  | string | Yes      |

- Response body (`data`): order totals and coupon application details.
- Status codes: `200`, `400`, `401`, `403`, `404`, `409`, `500`.

### GET /coupons/validate

- Description: Validate coupon eligibility for an order.
- Auth: `customer`, `front_desk`, `administrator`.
- Query params:

| Field    | Type    | Required |
| -------- | ------- | -------- |
| code     | string  | Yes      |
| order_id | integer | Yes      |

- Response body (`data`): validation result (`valid`, `reason`, discount preview).
- Status codes: `200`, `400`, `401`, `403`, `404`, `500`.

## Payments and Refunds

### POST /orders/:id/payments

- Description: Record payment on an order.
- Auth: `front_desk`, `finance`, `administrator`.
- Request body:

| Field        | Type   | Required |
| ------------ | ------ | -------- |
| tender_type  | string | Yes      |
| amount       | number | Yes      |
| reference_no | string | No       |

- Response body (`data`): payment object with order linkage.
- Status codes: `200`, `400`, `401`, `403`, `404`, `500`.
- Example request:

```json
{
  "tender_type": "card_present",
  "amount": 59.99,
  "reference_no": "TXN-1001"
}
```

### POST /orders/:id/refunds

- Description: Record refund against original payment.
- Auth: `front_desk`, `finance`, `administrator`.
- Request body:

| Field               | Type    | Required |
| ------------------- | ------- | -------- |
| original_payment_id | integer | Yes      |
| amount              | number  | Yes      |
| reason              | string  | Yes      |

- Response body (`data`): refund object with resulting balances.
- Status codes: `200`, `400`, `401`, `403`, `404`, `409`, `500`.

## Finance and Reconciliation

### GET /finance/cash-drawer/daily

- Description: Fetch daily cash drawer summary.
- Auth: `finance`, `store_manager`, `administrator`.
- Query params: `store_id` (int, required), `date` (string, required).
- Response body (`data`): drawer header and totals.
- Status codes: `200`, `400`, `401`, `403`, `404`, `500`.

### POST /finance/cash-drawer

- Description: Open a cash drawer for business date.
- Auth: `finance`, `administrator`.
- Request body: `store_id` (int, required), `business_date` (date string, required), optional opening amounts.
- Response body (`data`): created drawer record.
- Status codes: `200`, `400`, `401`, `403`, `409`, `500`.

### POST /finance/cash-drawer/:id/close

- Description: Close open drawer and compute discrepancy.
- Auth: `finance`, `administrator`.
- Request body: `counted_total` (number, required).
- Response body (`data`): closed drawer with discrepancy values.
- Status codes: `200`, `400`, `401`, `403`, `404`, `409`, `500`.

### POST /finance/cash-drawer/:id/reopen

- Description: Reopen closed drawer (admin only).
- Auth: `administrator`.
- Request body: `reason` (string, required).
- Response body (`data`): reopened drawer.
- Status codes: `200`, `400`, `401`, `403`, `404`, `409`, `500`.

### GET /finance/reconciliation/exceptions

- Description: List reconciliation exceptions by store.
- Auth: `finance`, `store_manager`, `administrator`.
- Query params: `store_id` (int, required).
- Response body (`data`): exception rows.
- Status codes: `200`, `400`, `401`, `403`, `500`.

### GET /finance/reconciliation/:id/statement

- Description: Fetch reconciliation statement JSON.
- Auth: `finance`, `store_manager`, `administrator`.
- Response body (`data`): statement header + line items.
- Status codes: `200`, `401`, `403`, `404`, `500`.

### GET /finance/reconciliation/:id/statement.csv

- Description: Fetch reconciliation statement CSV export.
- Auth: `finance`, `store_manager`, `administrator`.
- Response content: CSV text payload.
- Status codes: `200`, `401`, `403`, `404`, `500`.

## Dashboards

### GET /dashboards/operations

- Description: Operational KPI dashboard.
- Auth: `store_manager`, `administrator`.
- Query params: `from` and `to` date strings in `MM/DD/YYYY`.
- Response body (`data`): KPI metrics and trend series.
- Status codes: `200`, `400`, `401`, `403`, `500`.

### GET /dashboards/operations/export.csv

- Description: CSV export of operations dashboard.
- Auth: `store_manager`, `administrator`.
- Query params: `from`, `to`.
- Response content: CSV text payload.
- Status codes: `200`, `400`, `401`, `403`, `500`.

### GET /dashboards/analytics

- Description: Analytics dashboard payload.
- Auth: `store_manager`, `administrator`.
- Query params: `from`, `to`.
- Response body (`data`): analytics aggregates.
- Status codes: `200`, `400`, `401`, `403`, `500`.

## Announcements

### GET /announcements

- Description: List announcements.
- Auth: `front_desk`, `store_manager`, `administrator`.
- Response body (`data`): array of announcement objects.
- Status codes: `200`, `401`, `403`, `500`.

### POST /announcements

- Description: Create announcement.
- Auth: `store_manager`, `administrator`.
- Request body: `title` (string, required), `body` (string, required), optional publish fields.
- Response body (`data`): created announcement.
- Status codes: `200`, `400`, `401`, `403`, `500`.

### GET /announcements/:id

- Description: Read announcement by ID.
- Auth: `front_desk`, `store_manager`, `administrator`.
- Response body (`data`): announcement object.
- Status codes: `200`, `401`, `403`, `404`, `500`.

### PATCH /announcements/:id

- Description: Update announcement.
- Auth: `store_manager`, `administrator`.
- Request body: partial fields (`title`, `body`, publish state).
- Response body (`data`): updated announcement.
- Status codes: `200`, `400`, `401`, `403`, `404`, `500`.

### DELETE /announcements/:id

- Description: Delete announcement.
- Auth: `administrator`.
- Request body: None.
- Response body (`data`): `{ deleted: true }`.
- Status codes: `200`, `401`, `403`, `404`, `500`.

## Events

### GET /events

- Description: List event definitions.
- Auth: `store_manager`, `administrator`.
- Response body (`data`): array of event definitions.
- Status codes: `200`, `401`, `403`, `500`.

### POST /events

- Description: Create event definition.
- Auth: `administrator`.
- Request body: `key` (string, required), `name` (string, required), optional metadata.
- Response body (`data`): created event.
- Status codes: `200`, `400`, `401`, `403`, `500`.

### GET /events/:id

- Description: Read event definition.
- Auth: `store_manager`, `administrator`.
- Response body (`data`): event definition object.
- Status codes: `200`, `401`, `403`, `404`, `500`.

### PATCH /events/:id

- Description: Update event definition.
- Auth: `administrator`.
- Request body: partial updatable event fields.
- Response body (`data`): updated event.
- Status codes: `200`, `400`, `401`, `403`, `404`, `500`.

### DELETE /events/:id

- Description: Delete event definition.
- Auth: `administrator`.
- Response body (`data`): `{ deleted: true }`.
- Status codes: `200`, `401`, `403`, `404`, `500`.

### POST /events/track

- Description: Track runtime event with actor context.
- Auth: all authenticated roles.
- Request body: `event_key` (string, required), optional `context` (object).
- Response body (`data`): tracking acknowledgement.
- Status codes: `200`, `400`, `401`, `500`.

## Experiments

### GET /experiments

- Description: List experiments.
- Auth: `administrator`.
- Response body (`data`): array of experiments.
- Status codes: `200`, `401`, `403`, `500`.

### POST /experiments

- Description: Create experiment.
- Auth: `administrator`.
- Request body:

| Field              | Type   | Required |
| ------------------ | ------ | -------- |
| key                | string | Yes      |
| name               | string | Yes      |
| holdout_percent    | number | No       |
| randomization_unit | string | No       |
| variants           | array  | No       |

- Response body (`data`): created experiment.
- Status codes: `200`, `400`, `401`, `403`, `500`.

### GET /experiments/:id

- Description: Read experiment details.
- Auth: `administrator`.
- Status codes: `200`, `401`, `403`, `404`, `500`.

### PATCH /experiments/:id

- Description: Update draft experiment.
- Auth: `administrator`.
- Request body: partial editable fields (`name`, `holdout_percent`, `randomization_unit`).
- Status codes: `200`, `400`, `401`, `403`, `404`, `409`, `500`.

### POST /experiments/:id/start

- Description: Start experiment.
- Auth: `administrator`.
- Request body: None.
- Status codes: `200`, `401`, `403`, `404`, `409`, `500`.

### POST /experiments/:id/stop

- Description: Stop running experiment.
- Auth: `administrator`.
- Request body: None.
- Status codes: `200`, `401`, `403`, `404`, `409`, `500`.

### GET /experiments/:id/assignments

- Description: List assignment records for experiment.
- Auth: `administrator`.
- Status codes: `200`, `401`, `403`, `404`, `500`.

### GET /experiments/:id/assignment

- Description: Return current caller assignment for experiment.
- Auth: any authenticated user.
- Request body: None.
- Response body (`data`): assignment object (`variant`, `subject_key`, `assigned_at`).
- Status codes: `200`, `401`, `404`, `500`.

## Environmental Analytics

### POST /environment/import/csv

- Description: Import environmental records from CSV payload.
- Auth: `store_manager`, `administrator`.
- Request body:

| Field     | Type    | Required |
| --------- | ------- | -------- |
| source_id | integer | Yes      |
| records   | array   | Yes      |

- Status codes: `200`, `400`, `401`, `403`, `404`, `500`.

### POST /environment/import/sensor-feed

- Description: Import sensor feed records.
- Auth: `administrator`.
- Request body: `source_id` (int, required), `records` (array, required).
- Status codes: `200`, `400`, `401`, `403`, `404`, `500`.

### GET /environment/aligned-buckets

- Description: Read aligned time buckets.
- Auth: `store_manager`, `administrator`.
- Query params: typical scope/time filters.
- Status codes: `200`, `401`, `403`, `500`.

### POST /environment/align-buckets

- Description: Trigger bucket alignment operation.
- Auth: `store_manager`, `administrator`.
- Request body: alignment job parameters.
- Status codes: `200`, `400`, `401`, `403`, `500`.

### GET /environment/derived-metrics

- Description: List derived metrics.
- Auth: `store_manager`, `administrator`.
- Status codes: `200`, `401`, `403`, `500`.

### POST /environment/compute-derived-metrics

- Description: Compute derived metrics from aligned inputs.
- Auth: `store_manager`, `administrator`.
- Request body: computation parameters/time window.
- Status codes: `200`, `400`, `401`, `403`, `500`.

### GET /environment/lineage/:id

- Description: Get lineage details for derived metric.
- Auth: `store_manager`, `administrator`.
- Response body (`data`): lineage object with source refs and transformation steps.
- Status codes: `200`, `401`, `403`, `404`, `500`.

### GET /environment/formulas

- Description: List active formulas.
- Auth: `store_manager`, `administrator`.
- Status codes: `200`, `401`, `403`, `500`.

### GET /environment/formulas/:id

- Description: Read formula version.
- Auth: `store_manager`, `administrator`.
- Status codes: `200`, `401`, `403`, `404`, `500`.

### POST /environment/formulas

- Description: Create formula version.
- Auth: `administrator`.
- Request body: `formula_key` (string, required), `formula_expression` (string, required), optional thresholds/meta.
- Status codes: `200`, `400`, `401`, `403`, `500`.

### PATCH /environment/formulas/:id

- Description: Update non-superseded formula.
- Auth: `administrator`.
- Request body: partial formula fields.
- Status codes: `200`, `400`, `401`, `403`, `404`, `409`, `500`.

## Data Cleansing Governance

### POST /cleansing/import

- Description: Create cleansing batch from imported rows.
- Auth: `store_manager`, `administrator`.
- Request body: `source_name` (string, required), `rows` (array, required).
- Status codes: `200`, `400`, `401`, `403`, `500`.

### GET /cleansing/batches

- Description: List cleansing batches.
- Auth: `store_manager`, `administrator`.
- Response body (`data`): paginated batches.
- Status codes: `200`, `401`, `403`, `500`.

### GET /cleansing/batches/:id/preview

- Description: Preview batch normalization results.
- Auth: `store_manager`, `administrator`.
- Status codes: `200`, `401`, `403`, `404`, `500`.

### POST /cleansing/batches/:id/approve

- Description: Approve pending batch and apply changes.
- Auth: `administrator`.
- Request body: optional approval notes.
- Status codes: `200`, `401`, `403`, `404`, `409`, `500`.

### POST /cleansing/batches/:id/rollback

- Description: Roll back approved batch.
- Auth: `administrator`.
- Request body: rollback reason/details.
- Status codes: `200`, `401`, `403`, `404`, `409`, `500`.

### GET /cleansing/manual-review-queue

- Description: List unresolved manual-review items.
- Auth: `store_manager`, `administrator`.
- Status codes: `200`, `401`, `403`, `500`.

## Audit and Security

### GET /audit/logs

- Description: Search immutable operation logs.
- Auth: `store_manager`, `administrator`.
- Query params: optional filters such as `user_id`, `role`, `store_id`, `action`, `entity_type`, time range.
- Status codes: `200`, `401`, `403`, `500`.

### GET /security/events

- Description: View security event stream.
- Auth: `administrator`.
- Status codes: `200`, `401`, `403`, `500`.

## Admin

### POST /admin/users

- Description: Create system user.
- Auth: `administrator`.
- Request body:

| Field          | Type     | Required |
| -------------- | -------- | -------- |
| username       | string   | Yes      |
| password       | string   | Yes      |
| display_name   | string   | No       |
| role_codes     | string[] | No       |
| store_id       | integer  | No       |
| workstation_id | integer  | No       |

- Status codes: `200`, `400`, `401`, `403`, `409`, `500`.

### PATCH /admin/users/:id/roles

- Description: Replace role assignments for user.
- Auth: `administrator`.
- Request body: `role_codes` (string[], required).
- Status codes: `200`, `400`, `401`, `403`, `404`, `500`.

### POST /admin/bindings/reassign-store-workstation

- Description: Reassign user binding to store/workstation.
- Auth: `administrator`.
- Request body: `user_id` (int), `new_store_id` (int), `new_workstation_id` (int), all required.
- Status codes: `200`, `400`, `401`, `403`, `404`, `500`.

### POST /admin/encryption/keys/rotate

- Description: Rotate active encryption key version.
- Auth: `administrator`.
- Request body: `new_version` (int > 0, required).
- Status codes: `200`, `400`, `401`, `403`, `500`.

### GET /admin/users

- Description: List users.
- Auth: `administrator`.
- Response body (`data`): array of user summaries with role bindings.
- Status codes: `200`, `401`, `403`, `500`.

### GET /admin/stores

- Description: List stores.
- Auth: `administrator`, `store_manager`.
- Response body (`data`): array of stores.
- Status codes: `200`, `401`, `403`, `500`.

### GET /admin/workstations

- Description: List workstations.
- Auth: `administrator`, `store_manager`.
- Response body (`data`): array of workstations.
- Status codes: `200`, `401`, `403`, `500`.

## Canonical Error Examples

### 401 Unauthorized

```json
{
  "success": false,
  "error_code": "UNAUTHORIZED",
  "message": "Authentication required",
  "request_id": "req-401"
}
```

### 403 Forbidden

```json
{
  "success": false,
  "error_code": "FORBIDDEN",
  "message": "You do not have permission to access this resource",
  "request_id": "req-403"
}
```

### 400 Validation Error

```json
{
  "success": false,
  "error_code": "VALIDATION_ERROR",
  "message": "Validation failed",
  "fields": {
    "reason": "reason is required"
  },
  "request_id": "req-400"
}
```

### 404 Not Found

```json
{
  "success": false,
  "error_code": "NOT_FOUND",
  "message": "Resource not found",
  "request_id": "req-404"
}
```

### 409 Conflict

```json
{
  "success": false,
  "error_code": "CONFLICT",
  "message": "Invalid state transition",
  "request_id": "req-409"
}
```

### 500 Internal Error

```json
{
  "success": false,
  "error_code": "INTERNAL_ERROR",
  "message": "Internal server error",
  "request_id": "req-500"
}
```
