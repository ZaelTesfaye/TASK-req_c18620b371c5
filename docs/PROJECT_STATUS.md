# Project Status (Current)

Last updated: 2026-05-21

## Repository Layout

- Source code lives under `repo/`
- Backend: `repo/backend/`
- Frontend: `repo/frontend/`
- End-to-end and cross-stack tests: `repo/tests/`

## API Surface Snapshot

- Base path: `/api/v1`
- Route source: `repo/backend/route/api.php`
- Total endpoints: 76
- Method counts:
  - GET: 35
  - POST: 33
  - PATCH: 6
  - DELETE: 2

## Test Suite Snapshot

- Backend API test files: 23 (`repo/backend/tests/api/`)
- Backend unit test files: 21 (`repo/backend/tests/unit/`)
- Frontend unit test files: 7 (`repo/frontend/tests/unit/`)
- Frontend component test files: 4 (`repo/frontend/tests/component/`)
- Frontend integration test files: 16 (`repo/frontend/tests/integration/`)
- Frontend e2e test files: 5 (`repo/frontend/tests/e2e/`)

## Documentation Alignment Notes

- All docs in this directory now reference the current `repo/...` source paths.
- Route and role/access docs are aligned to `repo/backend/route/api.php`.
- Review/evidence docs were normalized to point at actual current file locations.
