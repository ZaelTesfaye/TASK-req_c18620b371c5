# Prompt Clause Checklist

Every explicit prompt clause and `questions.md` assumption mapped to implementation evidence.

| clause_id | prompt_or_assumption_text | backend_anchor | frontend_anchor | db_anchor | test_anchor | status |
|-----------|--------------------------|----------------|-----------------|-----------|-------------|--------|
| P01 | Layui-based UI with role-gated sign-in and workspace selector | backend/app/middleware/AuthMiddleware.php | frontend/src/pages/login.js | sessions, user_store_workstation_bindings | frontend/tests/e2e/loginFlow.test.js | implemented |
| P02 | Binds each user to a specific store and workstation for shift accountability | backend/app/service/AuthService.php:58-68 | frontend/src/pages/login.js | user_store_workstation_bindings, shift_sessions | backend/tests/api/AuthApiTest.php | implemented |
| P03 | Roles: Customer, Front Desk, Technician, Store Manager, Finance, Administrator | backend/database/seeds/seed.sql | frontend/src/components/Navigation.js | roles | frontend/tests/component/navigation.test.js | implemented |
| P04 | Customers place orders at front desk or kiosk-style view | backend/app/controller/OrderController.php | frontend/src/pages/kiosk.js | orders (channel field) | backend/tests/api/OrderApiTest.php | implemented |
| P05 | Apply locally issued coupons | backend/app/service/CouponService.php | frontend/src/pages/kiosk.js | coupons, coupon_redemptions | backend/tests/unit/CouponValidationTest.php | implemented |
| P06 | Optional invoice details with validation | backend/app/validate/OrderValidate.php:30-42 | frontend/src/pages/kiosk.js | orders (invoice fields) | frontend/tests/unit/validation.test.js | implemented |
| P07 | Real-time validation feedback | backend/app/validate/OrderValidate.php | frontend/src/utils/validation.js | N/A | frontend/tests/unit/validation.test.js | implemented |
| P08 | Clear amount due breakdown in USD | backend/app/service/OrderService.php:165-170 | frontend/src/components/AmountBreakdown.js | orders (amount fields) | backend/tests/unit/PricingEngineTest.php | implemented |
| P09 | On-screen receipt after confirmation | backend/app/service/OrderService.php:270-290 | frontend/src/components/Receipt.js | orders (receipt_no) | frontend/tests/component/formStates.test.js | implemented |
| P10 | Front Desk: create, edit, cancel with required reason | backend/app/service/OrderService.php | frontend/src/pages/orders.js | orders, order_status_history | backend/tests/api/OrderApiTest.php | implemented |
| P11 | Front Desk: assign technicians, record completion | backend/app/service/OrderService.php:216-268 | frontend/src/pages/orders.js | order_assignments | backend/tests/api/OrderApiTest.php | implemented |
| P12 | Technicians: accept jobs, log work notes, mark completion, cannot alter pricing | backend/app/service/OrderService.php:290-325 | frontend/src/pages/technicianQueue.js | order_work_notes | backend/tests/api/OrderApiTest.php | implemented |
| P13 | Store Manager: operational dashboards | backend/app/service/DashboardService.php | frontend/src/pages/dashboard.js | metric_definitions, metric_points | backend/tests/api/RbacApiTest.php | implemented |
| P14 | Dashboard metrics: transaction volume, avg fulfillment, cancellation rate, complaint rate | backend/app/service/DashboardService.php:16-80 | frontend/src/pages/dashboard.js | orders | frontend/tests/integration/orderFlow.test.js | implemented |
| P15 | Date ranges in MM/DD/YYYY | backend/app/service/OrderService.php:355-367 | frontend/src/utils/date.js | N/A | backend/tests/unit/DateParsingTest.php | implemented |
| P16 | Export to CSV for offline sharing | backend/app/service/DashboardService.php:130-145 | frontend/src/pages/dashboard.js | N/A | backend/tests/api/RbacApiTest.php | implemented |
| P17 | Finance: daily cash drawer totals and reconciliation | backend/app/service/FinanceService.php | frontend/src/pages/finance.js | cash_drawer_daily, reconciliation_statements | backend/tests/api/FinanceApiTest.php | implemented |
| P18 | Operations/Analytics dashboard: activity, conversion, retention, content quality, zero-result search rate | backend/app/service/DashboardService.php:82-155 | frontend/src/pages/dashboard.js | metric_definitions | backend/tests/api/RbacApiTest.php | implemented |
| P19 | A/B configurations with holdout group | backend/app/service/ExperimentService.php | frontend/src/pages/admin.js | experiments, experiment_variants, experiment_assignments | frontend/tests/e2e/orderWorkflow.test.js | implemented |
| P20 | ThinkPHP REST-style API | backend/route/api.php | frontend/src/services/api.js | N/A | backend/tests/api/AuthApiTest.php | implemented |
| P21 | Tiered permissions on every endpoint | backend/app/middleware/RbacMiddleware.php | frontend/src/router/index.js | user_roles | backend/tests/api/RbacApiTest.php | implemented |
| P22 | Immutable auditable operation logs | backend/app/service/AuditService.php | frontend/src/pages/auditLogs.js | operation_logs | backend/tests/unit/LogRedactionTest.php | implemented |
| P23 | Logs: user, role, store/workstation, timestamp, action, before/after | backend/app/service/AuditService.php:30-55 | N/A | operation_logs | backend/tests/api/RbacApiTest.php | implemented |
| P24 | Logs searchable, retained 7 years | backend/app/service/AuditService.php:60-100, backend/app/job/AuditArchivalJob.php | frontend/src/pages/auditLogs.js | operation_logs (indexes) | backend/tests/api/RbacApiTest.php | implemented |
| P25 | Offline auth: username + password, min 12 chars, complexity, lockout 5/15min | backend/app/service/AuthService.php | frontend/src/pages/login.js | users | backend/tests/unit/PasswordPolicyTest.php | implemented |
| P26 | Passwords salted and hashed | backend/app/service/AuthService.php:175-182 | N/A | users (password_hash, password_salt) | backend/tests/unit/PasswordPolicyTest.php | implemented |
| P27 | Sensitive fields encrypted at rest | backend/app/service/EncryptionService.php | N/A | encryption_keys | backend/tests/unit/LogRedactionTest.php | implemented |
| P28 | Offline tenders: cash, card-present, house account | backend/app/service/PaymentService.php | frontend/src/pages/orders.js | payments | backend/tests/api/FinanceApiTest.php | implemented |
| P29 | Payment records, refund orders, end-of-day reconciliation | backend/app/service/FinanceService.php | frontend/src/pages/finance.js | payments, refunds, reconciliation_statements | backend/tests/api/FinanceApiTest.php | implemented |
| P30 | Flag discrepancy when expected-counted > $1.00 | backend/app/service/FinanceService.php:68-69 | frontend/src/pages/finance.js | cash_drawer_daily (discrepancy_flag) | backend/tests/unit/DiscrepancyThresholdTest.php | implemented |
| P31 | Environmental metric fusion: sensor/CSV, 1-min buckets, zone mapping | backend/app/service/EnvironmentalService.php | frontend/src/pages/environmental.js | sensor_raw_records, sensor_fusion_records, sensor_aligned_buckets | backend/tests/unit/ConfidenceScoreTest.php | implemented |
| P32 | Confidence labels based on completeness | backend/app/service/EnvironmentalService.php:185-190 | frontend/src/pages/environmental.js | sensor_aligned_buckets | backend/tests/unit/ConfidenceScoreTest.php | implemented |
| P33 | Moving averages and rate-of-change | backend/app/service/EnvironmentalService.php:135-210 | frontend/src/pages/environmental.js | derived_metrics | backend/tests/unit/ConfidenceScoreTest.php | implemented |
| P34 | Comfort index with configurable thresholds | backend/app/service/EnvironmentalService.php:215-280 | frontend/src/pages/environmental.js | formula_versions, derived_metrics | backend/tests/unit/ConfidenceScoreTest.php | implemented |
| P35 | Derived value lineage back to raw inputs | backend/app/service/EnvironmentalService.php | frontend/src/pages/environmental.js | derived_lineage | backend/tests/unit/ConfidenceScoreTest.php | implemented |
| P36 | Data cleansing: parsing, denoising, dedup, entity alignment | backend/app/service/CleansingService.php | frontend/src/pages/cleansing.js | cleansing_batches, cleansing_results | backend/tests/unit/CleansingNormalizationTest.php | implemented |
| P37 | Admin approve/rollback batches | backend/app/service/CleansingService.php:95-160 | frontend/src/pages/cleansing.js | cleansing_batches | backend/tests/api/RbacApiTest.php | implemented |
| A01 | Local user provisioning only | backend/app/service/AuthService.php | N/A | users | backend/tests/api/AuthApiTest.php | implemented |
| A02 | Session-locked store/workstation | backend/app/service/AuthService.php:58-68 | frontend/src/pages/login.js | sessions | backend/tests/api/AuthApiTest.php | implemented |
| A03 | Guest-style customer order intake | backend/app/controller/OrderController.php | frontend/src/pages/kiosk.js | orders | backend/tests/api/OrderApiTest.php | implemented |
| A04 | Order state machine: Draft->Confirmed->Assigned->InProgress->Completed | backend/app/service/OrderService.php:25-32 | frontend/src/pages/orders.js | order_status_history | backend/tests/unit/OrderStateMachineTest.php | implemented |
| A05 | Price order: subtotal->coupon->tax->final, 2-decimal USD | backend/app/service/OrderService.php:335-350 | frontend/src/components/AmountBreakdown.js | orders | backend/tests/unit/PricingEngineTest.php | implemented |
| A06 | One coupon per order | backend/app/service/CouponService.php:30-35 | N/A | coupon_redemptions | backend/tests/unit/CouponValidationTest.php | implemented |
| A07 | Invoice required fields conditional | backend/app/validate/OrderValidate.php:30-42 | frontend/src/utils/validation.js | orders | frontend/tests/unit/validation.test.js | implemented |
| A08 | Only Front Desk and Store Manager can cancel; reason immutable | backend/app/service/OrderService.php:180-215 | frontend/src/pages/orders.js | orders | backend/tests/api/OrderApiTest.php | implemented |
| A09 | Single active technician per order | backend/app/service/OrderService.php:216-268 | N/A | order_assignments | backend/tests/api/OrderApiTest.php | implemented |
| A10 | ISO timestamps, MM/DD/YYYY display | backend/app/service/OrderService.php:355-367 | frontend/src/utils/date.js | N/A | backend/tests/unit/DateParsingTest.php | implemented |
| A11-A32 | All remaining assumptions | See REQUIREMENTS_TRACEABILITY.md | See respective pages | See init.sql | See respective tests | implemented |
