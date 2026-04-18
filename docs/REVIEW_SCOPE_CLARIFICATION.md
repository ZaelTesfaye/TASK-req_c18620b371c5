# Review Scope Clarification

## Scope Statement

This delivery is a **full-stack implementation** (ThinkPHP backend + Layui frontend + MySQL) that is **statically verifiable without runtime execution**. All code, configuration, database schemas, tests, and documentation are included in the repository for static review.

## Verification Boundary Mapping

| Dimension | Frontend-Verifiable | Backend-Verifiable | Manual-Verification-Only |
|-----------|--------------------|--------------------|--------------------------|
| Authentication flow | Login form, token storage, route guards | AuthService, session management, password policy | Runtime lockout timing |
| RBAC enforcement | Route-level role checks in router/index.js | Middleware role checks, controller guards | Cross-request session persistence |
| Order lifecycle | State machine UI transitions, form validation | Service-level state machine, business rules | Multi-user concurrent order editing |
| Pricing accuracy | Amount breakdown display, receipt rendering | Pricing engine arithmetic, rounding logic | Floating-point edge cases across DB round-trips |
| Coupon validation | Real-time feedback display | Server-side eligibility checks, redemption limits | Concurrent redemption race conditions |
| Invoice conditional validation | Frontend form field toggling | Backend conditional required field enforcement | N/A |
| Audit log immutability | Audit log search page, filter UI | Append-only insert, no update/delete paths | DB-level permission enforcement |
| Encryption at rest | N/A | EncryptionService encrypt/decrypt, key versioning | Key material security in container runtime |
| Dashboard metrics | Date picker, metric card rendering | Aggregation queries, formula definitions | Real-time accuracy with live data |
| Environmental fusion | Import form, bucket/lineage display | Alignment, fusion, derived metric computation | Sensor feed real-time ingestion |
| Cleansing pipeline | Batch list, preview, approve/rollback UI | Normalization, dedup, entity alignment logic | Large-scale batch performance |
| Reconciliation | Close/reopen UI, discrepancy display | Threshold calculation, statement generation | Concurrent drawer access |
| A/B experiments | Experiment config UI, holdout display | Sticky assignment, deterministic bucketing | Long-running experiment behavior |

## Excluded from Evidence

- `.tmp/` directory is excluded from all evidence and references
- `node_modules/` and `vendor/` are build artifacts, not reviewed
- Runtime performance metrics require live execution

## Note on Static Evidence

Where backend runtime behavior cannot be confirmed statically (e.g., actual HTTP response codes during live requests), explicit frontend-contract evidence plus manual-verification notes are provided. Test files contain assertions that validate expected behavior when executed in Docker.
