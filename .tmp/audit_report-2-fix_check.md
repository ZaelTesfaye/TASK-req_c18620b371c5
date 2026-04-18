# Audit Report 7 - Fix Check

## Scope

- Source issue list reviewed: ./.tmp/audit_report-7.md
- Verification method: static code inspection only (no runtime, docker, or test execution)
- Output target: ./.tmp/audit_report-7-fix_check.md

## Summary

- Findings checked: 6 (F-001 to F-006)
- Fixed: 6
- Partially Fixed: 0
- Not Fixed: 0

## Finding-by-finding Status

1. F-001 - Coupon validate/apply flow lacks explicit order ownership check

- Status: Fixed
- Evidence:
  - Ownership guard enforced at coupon entry points: repo/backend/app/service/CouponService.php:19, repo/backend/app/service/CouponService.php:107, repo/backend/app/service/CouponService.php:187
  - Foreign-order ownership failures surfaced as HTTP errors in validation path: repo/backend/app/controller/OrderController.php:356
  - Cross-store negative tests added for validate/apply: repo/backend/tests/api/StoreIsolationTest.php:100, repo/backend/tests/api/StoreIsolationTest.php:130, repo/backend/tests/api/StoreIsolationTest.php:153

2. F-002 - A/B runtime assignment and variant application were only partially delivered

- Status: Fixed
- Evidence:
  - Runtime assignment endpoint exists: repo/backend/route/api.php:223
  - Controller handler exists: repo/backend/app/controller/ExperimentController.php:246
  - Frontend runtime client calls singular assignment endpoint: repo/frontend/src/services/experiments.js:7, repo/frontend/src/services/experiments.js:33
  - Kiosk consumes assignment and applies variant/holdout behavior at render time: repo/frontend/src/pages/kiosk.js:317, repo/frontend/src/pages/kiosk.js:321
  - Integration tests verify endpoint usage and variant rendering: repo/frontend/tests/integration/experimentVariantFlow.test.js:86, repo/frontend/tests/integration/experimentVariantFlow.test.js:92

3. F-003 - Encryption key provisioning inconsistency vs default production config

- Status: Fixed
- Evidence:
  - Encryption service supports key file + ENCRYPTION_KEY fallback and explicit fail-fast when no key source resolves: repo/backend/app/service/EncryptionService.php:60, repo/backend/app/service/EncryptionService.php:69, repo/backend/app/service/EncryptionService.php:93, repo/backend/app/service/EncryptionService.php:110
  - Compose now marks environment as development and labels ENCRYPTION_KEY as development placeholder with explicit production warnings: repo/docker-compose.yml:31, repo/docker-compose.yml:43, repo/docker-compose.yml:66
  - README includes explicit provisioning checklist and replacement requirement: repo/README.md:90, repo/README.md:117
- Residual note:
  - The committed compose key remains a known dev placeholder by design; production still depends on correct external secret provisioning.

4. F-004 - Possible MySQL compose option typo/incompatibility

- Status: Fixed
- Evidence:
  - MySQL image is pinned to 8.0 and command rationale/compatibility notes are documented inline, including 8.4 migration caution: repo/docker-compose.yml:4, repo/docker-compose.yml:18, repo/docker-compose.yml:25

5. F-005 - Coupon cross-store negative tests were missing

- Status: Fixed
- Evidence:
  - Dedicated cross-store coupon validate/apply tests present: repo/backend/tests/api/StoreIsolationTest.php:100, repo/backend/tests/api/StoreIsolationTest.php:130, repo/backend/tests/api/StoreIsolationTest.php:153
  - Additional foreign-order apply ownership test exists in order suite: repo/backend/tests/api/OrderApiTest.php:260, repo/backend/tests/api/OrderApiTest.php:285

6. F-006 - Frontend API base URL docs/config drift

- Status: Fixed
- Evidence:
  - API client uses build-time API_BASE_URL with fallback: repo/frontend/src/services/api.js:3, repo/frontend/src/services/api.js:8
  - Webpack injects API_BASE_URL at build time: repo/frontend/webpack.config.js:11, repo/frontend/webpack.config.js:40
  - Compose + README document API_BASE_URL values for app/test services: repo/docker-compose.yml:100, repo/docker-compose.yml:133, repo/README.md:79, repo/README.md:86

## Conclusion

All six issues from ./.tmp/audit_report-7.md are fixed based on current static evidence. Runtime confirmation (container startup and full test execution) is still recommended before final sign-off.
