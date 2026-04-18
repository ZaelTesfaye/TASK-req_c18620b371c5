# questions.md

## 1. Offline Authentication Source of Truth

**Question:** Username/password authentication is offline, but it is not explicit whether user provisioning is fully local or imported from a central source.
**Assumption:** User accounts, roles, and store/workstation bindings are managed locally in the deployed instance.
**Solution:** Implemented local user and role administration with no dependency on external identity providers.

---

## 2. Store and Workstation Binding Rules

**Question:** Users are tied to a store and workstation for accountability, but it is unclear whether switching during an active shift is allowed.
**Assumption:** Store/workstation context is fixed for the active session unless a privileged user performs an explicit reassignment.
**Solution:** Enforced session-level store/workstation lock with audited reassignment actions for authorized roles only.

---

## 3. Role Boundary for Customer Actions

**Question:** Customers can place orders from front desk or kiosk, but it is not specified whether customer self-service supports profile history or only one-time order capture.
**Assumption:** Kiosk/customer flow supports guest-style order creation without exposing prior order history.
**Solution:** Implemented scoped customer order intake that captures order/contact data per transaction without broad account-level data access.

---

## 4. Order Lifecycle State Model

**Question:** Creation, edit, assignment, cancellation, and completion are required, but the exact state transitions and invalid transitions are not defined.
**Assumption:** Orders follow a controlled lifecycle: Draft -> Confirmed -> Assigned -> In Progress -> Completed, with Cancelled as terminal from permitted pre-completion states.
**Solution:** Added explicit state machine validation to block invalid transitions and ensure auditability of every status change.

---

## 5. Pricing, Tax, and Rounding Semantics

**Question:** Amount due in USD is required, but tax rules, discount order of operations, and rounding precision are not stated.
**Assumption:** Calculations use two-decimal USD precision with deterministic order: subtotal -> coupon discount -> tax -> final amount due.
**Solution:** Implemented a centralized pricing engine with strict decimal arithmetic and consistent receipt breakdown fields.

---

## 6. Coupon Eligibility and Stacking

**Question:** Local coupons are supported, but applicability rules (stacking, expiration, role/channel restrictions) are not defined.
**Assumption:** One coupon per order, validated by active window, store scope, minimum spend, and usage limits.
**Solution:** Enforced deterministic coupon validation rules and persisted rejection reasons for traceable user feedback.

---

## 7. Invoice Data Validation Scope

**Question:** Invoice details are optional, but required fields and validation constraints when invoice is requested are not explicit.
**Assumption:** When invoice issuance is requested, taxpayer/invoice identifiers and legal entity name become mandatory and format-validated.
**Solution:** Implemented conditional validation rules and blocked order confirmation until invoice-required fields are valid.

---

## 8. Cancellation Governance

**Question:** Cancellation reason is mandatory, but role boundaries and editability of reasons after cancellation are not defined.
**Assumption:** Front Desk and Store Manager can cancel; cancellation reason becomes immutable after save.
**Solution:** Applied permission checks for cancellation actions and stored immutable reason text in the audit trail.

---

## 9. Technician Assignment and Ownership

**Question:** Technician assignment is required, but it is unclear whether multi-technician assignment is permitted.
**Assumption:** Each order has exactly one primary technician at a time, with reassignment history retained.
**Solution:** Implemented single-active-assignee enforcement with reassignment logging and timestamp capture.

---

## 10. Completion Timestamp Source

**Question:** Completion timestamps are required, but timezone authority and time source are not defined.
**Assumption:** All persisted timestamps use server-local store timezone with ISO storage and localized display in MM/DD/YYYY for dashboard filtering.
**Solution:** Standardized timestamp storage/display conversion and applied consistent timezone handling across UI and API.

---

## 11. Complaint Rate Definition

**Question:** Complaint rate is required on operational dashboards, but complaint capture model and denominator are unspecified.
**Assumption:** Complaint rate = complaint-linked completed orders / total completed orders in the selected period.
**Solution:** Added complaint flagging with reason categories and computed rate from persisted transactional records.

---

## 12. Dashboard Metric Definitions

**Question:** Activity, conversion, retention, content quality, and zero-result search rate are required, but formulas are not specified.
**Assumption:** Each metric uses fixed formulas with clearly defined numerators/denominators and documented aggregation windows.
**Solution:** Implemented a metrics dictionary and consistent computation jobs to avoid per-screen interpretation drift.

---

## 13. CSV Export Contract

**Question:** CSV export is required, but required columns, encoding, and date formatting in exported files are not defined.
**Assumption:** Exports include deterministic column sets, UTF-8 encoding, and MM/DD/YYYY date fields aligned with on-screen filters.
**Solution:** Implemented schema-stable CSV generation with header versioning for offline sharing consistency.

---

## 14. Finance Reconciliation Closure Rules

**Question:** Daily reconciliation is required, but who can close a day and whether closure can be reopened is not defined.
**Assumption:** Finance role can close reconciliation; reopening requires Administrator override with mandatory reason.
**Solution:** Implemented close/reopen controls with privilege checks and immutable closure audit records.

---

## 15. Refund Workflow Constraints

**Question:** Refund orders are required, but partial-refund handling and linkage to original payment records are not explicit.
**Assumption:** Both full and partial refunds are supported and must reference original order and tender records.
**Solution:** Added refund entities with referential linkage, amount caps, and running refundable-balance validation.

---

## 16. Discrepancy Threshold Behavior

**Question:** Discrepancies above $1.00 must be flagged, but boundary handling and escalation behavior are not defined.
**Assumption:** Absolute variance > $1.00 triggers discrepancy status; exactly $1.00 does not.
**Solution:** Implemented deterministic threshold checks with reconciliation status transitions and manager-visible exception queues.

---

## 17. Experiment Randomization Unit

**Question:** A/B experiments with holdout are required, but randomization unit and assignment persistence are not specified.
**Assumption:** Assignment occurs at user/session key level and remains sticky for the experiment duration.
**Solution:** Implemented deterministic bucketing with immutable assignment records and fixed holdout percentages.

---

## 18. Experiment Safety and Rollback

**Question:** Experiment windows are defined, but behavior for early termination or rollback is not clarified.
**Assumption:** Administrators can stop experiments early; previously assigned traffic falls back to control immediately.
**Solution:** Added controlled stop/resume mechanisms with audit logs and automatic reversion to baseline UI behavior.

---

## 19. Operation Log Immutability Guarantees

**Question:** Logs must be immutable and auditable, but immutability enforcement method is not specified.
**Assumption:** Log records are append-only, update/delete operations are disallowed at application and database permission layers.
**Solution:** Implemented append-only audit log tables with restricted write paths and integrity-focused access controls.

---

## 20. Audit Log Searchability Contract

**Question:** Searchable logs are required, but minimum searchable fields and retention query expectations are undefined.
**Assumption:** Search supports user, role, store, workstation, action type, entity id, and time range with pagination.
**Solution:** Implemented indexed audit querying endpoints and filterable UI views for compliance and incident analysis.

---

## 21. Password Complexity Policy Details

**Question:** Minimum 12 characters and complexity are required, but exact complexity composition is not stated.
**Assumption:** Password must contain uppercase, lowercase, numeric, and special characters.
**Solution:** Enforced server-side password policy validation with explicit user-facing error messages.

---

## 22. Lockout Scope and Reset Logic

**Question:** Lockout after five failed attempts for 15 minutes is required, but lockout scope and reset behavior are unspecified.
**Assumption:** Lockout is account-based, failed-attempt counter resets on successful login, and lockout timer is enforced server-side.
**Solution:** Implemented account-level failed-auth tracking with timed lockout and clear authentication error states.

---

## 23. Sensitive Field Encryption Inventory

**Question:** Encryption at rest is required for sensitive fields, but the complete sensitive-field inventory is not explicitly listed.
**Assumption:** Sensitive fields include taxpayer/invoice identifiers, personal contact details, and any legally identifying metadata.
**Solution:** Defined and enforced a field-level encryption map with standardized encrypt/decrypt service boundaries.

---

## 24. Key Management and Rotation

**Question:** Key material is stored on the server, but rotation cadence, versioning, and re-encryption strategy are not defined.
**Assumption:** Encryption keys are versioned, rotatable, and referenced per encrypted record to enable staged re-encryption.
**Solution:** Implemented key-version metadata, controlled key rotation procedures, and backward-compatible decrypt support.

---

## 25. Environmental Data Time Alignment Edge Cases

**Question:** One-minute bucketing is defined, but late-arriving records, missing intervals, and out-of-order ingestion behavior are not explicit.
**Assumption:** Records are normalized to event-time buckets, late data is accepted within a configurable tolerance window, and gaps are marked as incomplete.
**Solution:** Added deterministic bucketing and completeness tagging rules to preserve reproducible analytics outputs.

---

## 26. Confidence Label Thresholds

**Question:** Confidence labels are required, but threshold definitions for high/medium/low confidence are unspecified.
**Assumption:** Confidence level is derived from completeness ratio, source consistency, and timestamp alignment quality.
**Solution:** Implemented configurable confidence scoring with threshold profiles and persisted score components.

---

## 27. Comfort Index Formula Governance

**Question:** Comfort index with configurable thresholds is required, but base formula ownership and version governance are not defined.
**Assumption:** A default formula is provided and any formula change requires versioning with effective-date control.
**Solution:** Implemented formula registry/versioning and stored formula version references on each derived metric row.

---

## 28. Derived Lineage Granularity

**Question:** Derived value lineage back to raw inputs is required, but required lineage granularity is not specified.
**Assumption:** Each derived value stores direct raw input references, transformation steps, and formula/version metadata.
**Solution:** Persisted structured lineage metadata to enable end-to-end traceability and reproducible recomputation.

---

## 29. Data Cleansing Rollback Scope

**Question:** Admin approval and rollback are required for cleansing batches, but rollback blast radius is not defined.
**Assumption:** Rollback reverts only records changed by the selected batch and restores prior canonical values.
**Solution:** Implemented batch-scoped change journaling and atomic rollback operations with integrity checks.

---

## 30. Deterministic Entity Alignment Rules

**Question:** Company normalization and similar-role merging are required, but tie-breakers when multiple candidates match are not defined.
**Assumption:** Tie-breakers use deterministic priority rules and confidence scoring; ambiguous matches are queued for manual review.
**Solution:** Implemented deterministic matching hierarchy with review queues for unresolved conflicts and full decision logs.

---

## 31. Authorization for Data Import Governance

**Question:** Authorized admins can approve or roll back cleansing batches, but exact role permissions are not explicitly mapped.
**Assumption:** Only Administrator can approve/rollback ingestion batches; Store Manager may review but cannot finalize.
**Solution:** Added role-gated approval endpoints and workflow state checks to enforce governance boundaries.

---

## 32. Static Verifiability Artifacts for Acceptance

**Question:** Delivery acceptance is static and evidence-based, but required artifacts that prove implementation completeness are not explicitly listed.
**Assumption:** Delivery includes clear setup instructions, API contracts, seed data guidance, and requirement-to-module traceability notes.
**Solution:** Produced explicit static verification artifacts so reviewers can map requirements to code without runtime execution.
