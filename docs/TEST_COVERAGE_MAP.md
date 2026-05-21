# Test Coverage Map

Static mapping from high-risk requirements to tests and assertions.

| Risk/Requirement | Test File | Assertion Anchor | Coverage Label | Gap Fix |
|-----------------|-----------|-----------------|----------------|---------|
| 401 unauthenticated access | repo/backend/tests/api/AuthApiTest.php | testAccessProtectedRouteWithoutToken | sufficient | N/A |
| 401 invalid token | repo/backend/tests/api/AuthApiTest.php | testAccessProtectedRouteWithInvalidToken | sufficient | N/A |
| 403 role-based denial (customer→admin) | repo/backend/tests/api/RbacApiTest.php | testCustomerCannotAccessAdmin | sufficient | N/A |
| 403 role-based denial (tech→admin) | repo/backend/tests/api/RbacApiTest.php | testTechnicianCannotAccessAdmin | sufficient | N/A |
| 403 role-based denial (frontdesk→admin) | repo/backend/tests/api/RbacApiTest.php | testFrontDeskCannotAccessAdmin | sufficient | N/A |
| 403 finance→reopen | repo/backend/tests/api/FinanceApiTest.php | testReopenRequiresAdmin | sufficient | N/A |
| 403 manager→experiments | repo/backend/tests/api/RbacApiTest.php | testNonAdminCannotManageExperiments | sufficient | N/A |
| 403 manager→approve batch | repo/backend/tests/api/RbacApiTest.php | testManagerCannotApproveBatch | sufficient | N/A |
| 404 invalid resource IDs | repo/backend/tests/api/OrderApiTest.php | testGetOrderNotFound | sufficient | N/A |
| 409 invalid state transition | repo/backend/tests/api/OrderApiTest.php | testInvalidStateTransition | sufficient | N/A |
| Object-level store isolation | repo/backend/tests/api/OrderApiTest.php | testCrossStoreAccessDenied | sufficient | N/A |
| Tenant/store isolation on queries | repo/backend/tests/api/RbacApiTest.php | multiple tests | basically_covered | N/A |
| Pagination/filter/sort behavior | repo/backend/tests/api/OrderApiTest.php | testListOrdersWithPagination | basically_covered | N/A |
| Date format MM/DD/YYYY boundaries | repo/backend/tests/unit/DateParsingTest.php | testValidDateParsing, testLeapYearDate | sufficient | N/A |
| Audit log immutability | repo/backend/app/service/AuditService.php | Append-only insert, no update/delete | basically_covered | Static schema evidence |
| Password policy enforcement | repo/backend/tests/unit/PasswordPolicyTest.php | 8 test cases | sufficient | N/A |
| Lockout after 5 failures | repo/backend/tests/api/AuthApiTest.php | testLoginInvalidCredentials | basically_covered | N/A |
| Discrepancy threshold boundary | repo/backend/tests/unit/DiscrepancyThresholdTest.php | 8 test cases, $1.00 boundary | sufficient | N/A |
| Order state machine guards | repo/backend/tests/unit/OrderStateMachineTest.php | 13 test cases | sufficient | N/A |
| Pricing engine arithmetic | repo/backend/tests/unit/PricingEngineTest.php | 10 test cases | sufficient | N/A |
| Coupon eligibility validation | repo/backend/tests/unit/CouponValidationTest.php | 11 test cases | sufficient | N/A |
| Confidence score/label assignment | repo/backend/tests/unit/ConfidenceScoreTest.php | 8 test cases | sufficient | N/A |
| Cleansing normalization determinism | repo/backend/tests/unit/CleansingNormalizationTest.php | 15 test cases | sufficient | N/A |
| Sensitive data redaction | repo/backend/tests/unit/LogRedactionTest.php | 7 test cases | sufficient | N/A |
| Cancel requires reason | repo/backend/tests/api/OrderApiTest.php | testCancelOrderRequiresReason | sufficient | N/A |
| Technician pricing mutation blocked | repo/backend/tests/api/OrderApiTest.php | testTechnicianCannotAlterPricing | sufficient | N/A |
| Full order lifecycle | repo/backend/tests/api/OrderApiTest.php | testOrderFullLifecycle | sufficient | N/A |
| Refund cap validation | repo/backend/tests/api/FinanceApiTest.php | testRefundExceedsLimit | sufficient | N/A |
| Frontend role-based navigation | repo/frontend/tests/component/navigation.test.js | 6 role tests | sufficient | N/A |
| Frontend form validation | repo/frontend/tests/unit/validation.test.js | 15 test cases | sufficient | N/A |
| Frontend route guards | repo/frontend/tests/integration/routeGuard.test.js | 9 test cases | sufficient | N/A |
| Frontend state classes | repo/frontend/tests/component/formStates.test.js | 12 test cases | sufficient | N/A |
