# Audit Mutation Coverage

Every mutating endpoint mapped to service method, audit logger call, and verifying test.

| Endpoint | Method | Service Method | Audit Logger Call | Verifying Test |
|----------|--------|---------------|-------------------|----------------|
| POST /auth/login | POST | AuthService::login | security_events insert | repo/backend/tests/api/AuthApiTest.php |
| POST /auth/logout | POST | AuthService::logout | Logger::security('logout') | repo/backend/tests/api/AuthApiTest.php |
| POST /auth/password/reset | POST | AuthService::resetPassword | Logger::security('password_reset') | repo/backend/tests/api/AuthApiTest.php |
| POST /orders | POST | OrderService::createOrder | AuditMiddleware via $request->auditData | repo/backend/tests/api/OrderApiTest.php |
| PATCH /orders/:id | PATCH | OrderService::updateOrder | AuditMiddleware via $request->auditData | repo/backend/tests/api/OrderApiTest.php |
| POST /orders/:id/confirm | POST | OrderService::transitionStatus | AuditMiddleware + order_status_history | repo/backend/tests/api/OrderApiTest.php |
| POST /orders/:id/assign-technician | POST | OrderService::assignTechnician | AuditMiddleware + order_assignments | repo/backend/tests/api/OrderApiTest.php |
| POST /orders/:id/accept | POST | OrderService::acceptOrder | AuditMiddleware + order_status_history | repo/backend/tests/api/OrderApiTest.php |
| POST /orders/:id/work-notes | POST | OrderService::addWorkNote | AuditMiddleware via $request->auditData | repo/backend/tests/api/OrderApiTest.php |
| POST /orders/:id/complete | POST | OrderService::transitionStatus | AuditMiddleware + order_status_history | repo/backend/tests/api/OrderApiTest.php |
| POST /orders/:id/cancel | POST | OrderService::cancelOrder | AuditMiddleware + order_status_history | repo/backend/tests/api/OrderApiTest.php |
| POST /orders/:id/apply-coupon | POST | CouponService::applyCoupon | AuditMiddleware via $request->auditData | repo/backend/tests/api/OrderApiTest.php |
| POST /orders/:id/payments | POST | PaymentService::recordPayment | AuditMiddleware via $request->auditData | repo/backend/tests/api/FinanceApiTest.php |
| POST /orders/:id/refunds | POST | PaymentService::processRefund | AuditMiddleware via $request->auditData | repo/backend/tests/api/FinanceApiTest.php |
| POST /finance/cash-drawer | POST | FinanceService::openDrawer | AuditMiddleware via $request->auditData | repo/backend/tests/api/FinanceApiTest.php |
| POST /finance/cash-drawer/:id/close | POST | FinanceService::closeDrawer | AuditMiddleware + reconciliation_actions | repo/backend/tests/api/FinanceApiTest.php |
| POST /finance/cash-drawer/:id/reopen | POST | FinanceService::reopenDrawer | AuditMiddleware + reconciliation_actions + Logger::security | repo/backend/tests/api/FinanceApiTest.php |
| POST /announcements | POST | Db::table('announcements')->insert | AuditMiddleware via $request->auditData | repo/backend/tests/api/RbacApiTest.php |
| PATCH /announcements/:id | PATCH | Db::table('announcements')->update | AuditMiddleware via $request->auditData | repo/backend/tests/api/RbacApiTest.php |
| DELETE /announcements/:id | DELETE | Db::table('announcements')->delete | AuditMiddleware via $request->auditData | repo/backend/tests/api/RbacApiTest.php |
| POST /events | POST | Db::table('events')->insert | AuditMiddleware via $request->auditData | repo/backend/tests/api/RbacApiTest.php |
| POST /events/track | POST | Db::table('event_logs')->insert | event_logs record | repo/backend/tests/api/RbacApiTest.php |
| POST /experiments | POST | ExperimentService::create | AuditMiddleware via $request->auditData | repo/backend/tests/api/RbacApiTest.php |
| POST /experiments/:id/start | POST | ExperimentService::start | AuditMiddleware + Logger::info | repo/backend/tests/api/RbacApiTest.php |
| POST /experiments/:id/stop | POST | ExperimentService::stop | AuditMiddleware + Logger::info | repo/backend/tests/api/RbacApiTest.php |
| POST /environment/import/csv | POST | EnvironmentalService::importCsv | AuditMiddleware + Logger::info | repo/backend/tests/api/RbacApiTest.php |
| POST /environment/formulas | POST | Db::table('formula_versions')->insert | AuditMiddleware via $request->auditData | repo/backend/tests/api/RbacApiTest.php |
| POST /cleansing/import | POST | CleansingService::importBatch | AuditMiddleware + Logger::info | repo/backend/tests/api/RbacApiTest.php |
| POST /cleansing/batches/:id/approve | POST | CleansingService::approveBatch | AuditMiddleware + Logger::info | repo/backend/tests/api/RbacApiTest.php |
| POST /cleansing/batches/:id/rollback | POST | CleansingService::rollbackBatch | AuditMiddleware + Logger::info | repo/backend/tests/api/RbacApiTest.php |
| POST /admin/users | POST | Db::table('users')->insert | AuditMiddleware via $request->auditData | repo/backend/tests/api/RbacApiTest.php |
| PATCH /admin/users/:id/roles | PATCH | Db::table('user_roles') operations | AuditMiddleware via $request->auditData | repo/backend/tests/api/RbacApiTest.php |
| POST /admin/bindings/reassign-store-workstation | POST | Binding deactivate + create | AuditMiddleware via $request->auditData | repo/backend/tests/api/RbacApiTest.php |
| POST /admin/encryption/keys/rotate | POST | EncryptionService::rotateKey | AuditMiddleware + Logger::security | repo/backend/tests/api/RbacApiTest.php |

## Audit Record Structure

Every `operation_logs` entry contains:
- `actor_user_id` — who performed the action
- `actor_role_code` — role at time of action
- `store_id` — store context
- `workstation_id` — workstation context
- `action` — operation name
- `entity_type` — target entity
- `entity_id` — target entity ID
- `before_json` — state before mutation (null for creates)
- `after_json` — state after mutation
- `request_id` — correlates with request logs
- `ip` — client IP
- `user_agent` — client identifier
- `created_at` — immutable timestamp
