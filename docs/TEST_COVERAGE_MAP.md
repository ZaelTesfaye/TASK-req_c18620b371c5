# Test Coverage Map

Static mapping from high-risk requirements to tests and assertions.

| Risk/Requirement | Test File | Assertion Anchor | Coverage Label | Gap Fix |
|-----------------|-----------|-----------------|----------------|---------|
| 401 unauthenticated access | backend/tests/api/AuthApiTest.php | testAccessProtectedRouteWithoutToken | sufficient | N/A |
| 401 invalid token | backend/tests/api/AuthApiTest.php | testAccessProtectedRouteWithInvalidToken | sufficient | N/A |
| 403 role-based denial (customer→admin) | backend/tests/api/RbacApiTest.php | testCustomerCannotAccessAdmin | sufficient | N/A |
| 403 role-based denial (tech→admin) | backend/tests/api/RbacApiTest.php | testTechnicianCannotAccessAdmin | sufficient | N/A |
| 403 role-based denial (frontdesk→admin) | backend/tests/api/RbacApiTest.php | testFrontDeskCannotAccessAdmin | sufficient | N/A |
| 403 finance→reopen | backend/tests/api/FinanceApiTest.php | testReopenRequiresAdmin | sufficient | N/A |
| 403 manager→experiments | backend/tests/api/RbacApiTest.php | testNonAdminCannotManageExperiments | sufficient | N/A |
| 403 manager→approve batch | backend/tests/api/RbacApiTest.php | testManagerCannotApproveBatch | sufficient | N/A |
| 404 invalid resource IDs | backend/tests/api/OrderApiTest.php | testGetOrderNotFound | sufficient | N/A |
| 409 invalid state transition | backend/tests/api/OrderApiTest.php | testInvalidStateTransition | sufficient | N/A |
| Object-level store isolation | backend/tests/api/OrderApiTest.php | testCrossStoreAccessDenied | sufficient | N/A |
| Tenant/store isolation on queries | backend/tests/api/RbacApiTest.php | multiple tests | basically_covered | N/A |
| Pagination/filter/sort behavior | backend/tests/api/OrderApiTest.php | testListOrdersWithPagination | basically_covered | N/A |
| Date format MM/DD/YYYY boundaries | backend/tests/unit/DateParsingTest.php | testValidDateParsing, testLeapYearDate | sufficient | N/A |
| Audit log immutability | backend/app/service/AuditService.php | Append-only insert, no update/delete | basically_covered | Static schema evidence |
| Password policy enforcement | backend/tests/unit/PasswordPolicyTest.php | 8 test cases | sufficient | N/A |
| Lockout after 5 failures | backend/tests/api/AuthApiTest.php | testLoginInvalidCredentials | basically_covered | N/A |
| Discrepancy threshold boundary | backend/tests/unit/DiscrepancyThresholdTest.php | 8 test cases, $1.00 boundary | sufficient | N/A |
| Order state machine guards | backend/tests/unit/OrderStateMachineTest.php | 13 test cases | sufficient | N/A |
| Pricing engine arithmetic | backend/tests/unit/PricingEngineTest.php | 10 test cases | sufficient | N/A |
| Coupon eligibility validation | backend/tests/unit/CouponValidationTest.php | 11 test cases | sufficient | N/A |
| Confidence score/label assignment | backend/tests/unit/ConfidenceScoreTest.php | 8 test cases | sufficient | N/A |
| Cleansing normalization determinism | backend/tests/unit/CleansingNormalizationTest.php | 15 test cases | sufficient | N/A |
| Sensitive data redaction | backend/tests/unit/LogRedactionTest.php | 7 test cases | sufficient | N/A |
| Cancel requires reason | backend/tests/api/OrderApiTest.php | testCancelOrderRequiresReason | sufficient | N/A |
| Technician pricing mutation blocked | backend/tests/api/OrderApiTest.php | testTechnicianCannotAlterPricing | sufficient | N/A |
| Full order lifecycle | backend/tests/api/OrderApiTest.php | testOrderFullLifecycle | sufficient | N/A |
| Refund cap validation | backend/tests/api/FinanceApiTest.php | testRefundExceedsLimit | sufficient | N/A |
| Frontend role-based navigation | frontend/tests/component/navigation.test.js | 6 role tests | sufficient | N/A |
| Frontend form validation | frontend/tests/unit/validation.test.js | 15 test cases | sufficient | N/A |
| Frontend route guards | frontend/tests/integration/routeGuard.test.js | 9 test cases | sufficient | N/A |
| Frontend state classes | frontend/tests/component/formStates.test.js | 12 test cases | sufficient | N/A |
