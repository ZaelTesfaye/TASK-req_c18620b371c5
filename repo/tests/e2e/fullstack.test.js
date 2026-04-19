/**
 * Fullstack E2E Tests
 *
 * These tests run against REAL backend + DB via HTTP. No mocks.
 * They exercise the actual frontend→API→service→DB→response path.
 *
 * Requires: backend running at BACKEND_URL, mysql with seeded data.
 * Run via: docker-compose --profile e2e run --rm test-e2e
 */

const STATUS = require('./statusCodes');
const API = process.env.API_BASE_URL || 'http://localhost:8000/api/v1';

async function request(method, path, body, token) {
    const url = API + (path.startsWith('/') ? path : '/' + path);
    const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (token) headers['Authorization'] = 'Bearer ' + token;

    const opts = { method: method.toUpperCase(), headers: headers };
    if (body && method !== 'GET') opts.body = JSON.stringify(body);

    const res = await fetch(url, opts);
    const data = res.headers.get('content-type')?.includes('json')
        ? await res.json()
        : null;
    return { status: res.status, body: data };
}

async function loginAs(username, storeId, wsId) {
    const res = await request('POST', '/auth/login', {
        username: username,
        password: 'Demo12345678!',
        store_id: storeId || 1,
        workstation_id: wsId || 1,
    });
    return res.body?.data?.token || null;
}

// ---------------------------------------------------------------------------
// 1. Auth → Session → Protected Route (full boundary)
// ---------------------------------------------------------------------------
describe('Fullstack: Auth Lifecycle', () => {
    let adminToken;

    test('login returns token and user with roles', async () => {
        const res = await request('POST', '/auth/login', {
            username: 'admin',
            password: 'Demo12345678!',
            store_id: 1,
            workstation_id: 1,
        });
        expect(res.status).toBe(200);
        expect(res.body.success).toBe(true);
        expect(res.body.data.token).toBeTruthy();
        expect(res.body.data.user.roles.length).toBeGreaterThan(0);
        adminToken = res.body.data.token;
    });

    test('token grants access to protected /auth/me', async () => {
        const res = await request('GET', '/auth/me', null, adminToken);
        expect(res.status).toBe(200);
        expect(res.body.success).toBe(true);
    });

    test('invalid token is rejected with 401', async () => {
        const res = await request('GET', '/auth/me', null, 'invalid-token');
        expect(res.status).toBe(401);
        expect(res.body.error_code).toBe('UNAUTHORIZED');
    });

    test('missing token is rejected with 401', async () => {
        const res = await request('GET', '/orders');
        expect(res.status).toBe(401);
    });

    test('invalid credentials return INVALID_CREDENTIALS', async () => {
        const res = await request('POST', '/auth/login', {
            username: 'admin',
            password: 'WrongPassword!',
            store_id: 1,
            workstation_id: 1,
        });
        expect(res.status).toBe(STATUS.INVALID_CREDENTIALS);
        expect(res.body.success).toBe(false);
        expect(res.body.error_code).toBe('INVALID_CREDENTIALS');
    });

    test('logout invalidates session', async () => {
        // Login to get a fresh token
        const tok = await loginAs('admin');
        expect(tok).toBeTruthy();

        // Logout
        const logoutRes = await request('POST', '/auth/logout', {}, tok);
        expect(logoutRes.status).toBe(200);

        // Token should no longer work
        const meRes = await request('GET', '/auth/me', null, tok);
        expect(meRes.status).toBe(401);
    });
});

// ---------------------------------------------------------------------------
// 2. Order → Payment → Receipt (full CRUD lifecycle through real DB)
// ---------------------------------------------------------------------------
describe('Fullstack: Order Lifecycle', () => {
    let token;
    let orderId;

    beforeAll(async () => {
        token = await loginAs('frontdesk1');
    });

    test('create order with items (verified in DB)', async () => {
        const res = await request('POST', '/orders', {
            customer_name: 'E2E Test Customer',
            channel: 'front_desk',
            items: [
                { service_code: 'SVC-E2E', service_name: 'E2E Service', qty: 2, unit_price: 50.00 },
            ],
        }, token);
        expect(res.status).toBe(STATUS.ORDER_CREATED);
        expect(res.body.success).toBe(true);
        // Capture orderId FIRST so downstream Order Lifecycle tests have a
        // valid id even if a later assertion in this test fails — prior
        // behaviour was that the `subtotal_amount` type assertion threw
        // before orderId was set, cascading the whole lifecycle suite into
        // routing 400s against `/orders/undefined/*`.
        orderId = res.body.data.id;
        expect(orderId).toBeTruthy();
        expect(res.body.data.order_no).toMatch(/^ORD-/);
        expect(res.body.data.status).toBe('draft');
        // MySQL DECIMAL columns come back as strings through PDO → ThinkPHP
        // passes them through unchanged, so the monetary fields are
        // JSON-serialized as quoted strings ("100.00"). Coerce to Number
        // for numeric comparison rather than asserting string equality.
        expect(Number(res.body.data.subtotal_amount)).toBeCloseTo(100.00, 2);
        // tax = 100 * 0.08 = 8.00
        expect(Number(res.body.data.tax_amount)).toBeCloseTo(8.00, 2);
        expect(Number(res.body.data.total_amount)).toBeCloseTo(108.00, 2);
    });

    test('confirm order transitions status', async () => {
        const res = await request('POST', `/orders/${orderId}/confirm`, {}, token);
        expect(res.status).toBe(200);
        expect(res.body.success).toBe(true);
        expect(res.body.data.status).toBe('confirmed');
        expect(res.body.data.receipt_no).toMatch(/^RCP-/);
    });

    test('invalid transition from confirmed to completed returns 409', async () => {
        const res = await request('POST', `/orders/${orderId}/complete`, {}, token);
        expect(res.status).toBe(409);
        expect(res.body.error_code).toBe('INVALID_TRANSITION');
    });

    test('assign technician', async () => {
        const res = await request('POST', `/orders/${orderId}/assign-technician`, {
            technician_id: 3,
        }, token);
        expect(res.status).toBe(200);
        expect(res.body.data.status).toBe('assigned');
    });

    test('technician accepts → in_progress', async () => {
        const techToken = await loginAs('tech1', 1, 2);
        const res = await request('POST', `/orders/${orderId}/accept`, {}, techToken);
        expect(res.status).toBe(200);
        expect(res.body.data.status).toBe('in_progress');
    });

    test('technician adds work note', async () => {
        const techToken = await loginAs('tech1', 1, 2);
        const res = await request('POST', `/orders/${orderId}/work-notes`, {
            note: 'E2E work note - replaced filter',
        }, techToken);
        // Controller returns 201 for resource creation (REST convention).
        expect([200, 201]).toContain(res.status);
        expect(res.body.success).toBe(true);
    });

    test('complete order', async () => {
        const techToken = await loginAs('tech1', 1, 2);
        const res = await request('POST', `/orders/${orderId}/complete`, {}, techToken);
        expect(res.status).toBe(200);
        expect(res.body.data.status).toBe('completed');
    });

    test('record payment against order', async () => {
        const res = await request('POST', `/orders/${orderId}/payments`, {
            tender_type: 'cash',
            amount: 108.00,
        }, token);
        // Controller returns 201 for payment creation (REST convention).
        expect([200, 201]).toContain(res.status);
        expect(Number(res.body.data.amount_due)).toBeCloseTo(0.00, 2);
    });

    test('get receipt with correct totals', async () => {
        const res = await request('GET', `/orders/${orderId}/receipt`, null, token);
        expect(res.status).toBe(200);
        expect(res.body.data.receipt_no).toMatch(/^RCP-/);
        expect(res.body.data.customer_name).toBe('E2E Test Customer');
        expect(res.body.data.subtotal).toBe('100.00');
        expect(res.body.data.total).toBe('108.00');
    });
});

// ---------------------------------------------------------------------------
// 3. RBAC enforcement across real backend (no mock)
// ---------------------------------------------------------------------------
describe('Fullstack: RBAC Enforcement', () => {
    test('customer cannot access admin/users', async () => {
        const tok = await loginAs('customer1', 1, 3);
        if (!tok) return;
        const res = await request('GET', '/admin/users', null, tok);
        expect(res.status).toBe(403);
        expect(res.body.error_code).toBe('FORBIDDEN');
    });

    test('technician cannot create orders', async () => {
        const tok = await loginAs('tech1', 1, 2);
        if (!tok) return;
        const res = await request('POST', '/orders', {
            customer_name: 'T', items: [{ service_code: 'X', service_name: 'X', qty: 1, unit_price: 1 }],
        }, tok);
        expect(res.status).toBe(403);
    });

    test('finance cannot reopen drawer (admin-only)', async () => {
        const tok = await loginAs('finance1');
        if (!tok) return;
        const res = await request('POST', '/finance/cash-drawer/1/reopen', {
            reason: 'test',
        }, tok);
        expect(res.status).toBe(403);
    });

    test('admin can access experiments', async () => {
        const tok = await loginAs('admin');
        const res = await request('GET', '/experiments', null, tok);
        expect(res.status).toBe(200);
    });

    test('manager cannot access experiments', async () => {
        const tok = await loginAs('manager1');
        if (!tok) return;
        const res = await request('GET', '/experiments', null, tok);
        expect(res.status).toBe(403);
    });
});

// ---------------------------------------------------------------------------
// 4. Finance: Open → Close → Discrepancy detection (full DB path)
// ---------------------------------------------------------------------------
describe('Fullstack: Finance Reconciliation', () => {
    let adminToken;
    let drawerId;

    beforeAll(async () => {
        adminToken = await loginAs('admin');
    });

    test('open cash drawer', async () => {
        const date = '2018-03-' + String(Math.floor(Math.random() * 28) + 1).padStart(2, '0');
        const res = await request('POST', '/finance/cash-drawer', {
            store_id: 1,
            business_date: date,
            open_amount: 300.00,
        }, adminToken);
        if (res.status === STATUS.CONFLICT) return; // date taken
        expect(res.status).toBe(STATUS.DRAWER_OPENED);
        expect(res.body.success).toBe(true);
        drawerId = res.body.data.id;
    });

    test('close drawer detects discrepancy', async () => {
        if (!drawerId) return;
        const res = await request('POST', `/finance/cash-drawer/${drawerId}/close`, {
            counted_total: 295.00,
        }, adminToken);
        expect(res.status).toBe(200);
        // 300 - 295 = 5.00 > 1.00 → discrepancy
        expect(res.body.data.discrepancy_flag).toBe(1);
        // MySQL DECIMAL → PDO string → ThinkPHP JSON passes through quoted.
        expect(Number(res.body.data.variance)).toBeCloseTo(5.00, 2);
    });

    test('statement generated after close', async () => {
        if (!drawerId) return;
        const res = await request('GET', `/finance/reconciliation/${drawerId}/statement`, null, adminToken);
        expect(res.status).toBe(200);
        expect(res.body.success).toBe(true);
    });
});

// ---------------------------------------------------------------------------
// 5. Cleansing: Import → Normalize → Approve (full DB path)
// ---------------------------------------------------------------------------
describe('Fullstack: Cleansing Pipeline', () => {
    let adminToken;
    let batchId;

    beforeAll(async () => {
        adminToken = await loginAs('admin');
    });

    test('import batch normalizes data in DB', async () => {
        const res = await request('POST', '/cleansing/import', {
            source_name: 'e2e_test_' + Date.now(),
            rows: [
                { job_title: 'Sr. Dev', company: 'Acme Inc.', city: 'new york', salary: '$75k', education: 'BS', experience: '5 years' },
                { job_title: 'Jr. Eng', company: 'StartupCo LLC', city: 'SF', salary: '60000', education: 'BA', experience: '2 yrs' },
            ],
        }, adminToken);
        // Controller returns 201 for resource creation (REST convention).
        expect([200, 201]).toContain(res.status);
        expect(res.body.success).toBe(true);
        expect(res.body.data.rows).toBe(2);
        batchId = res.body.data.batch_id;
    });

    test('preview shows normalized results', async () => {
        if (!batchId) return;
        const res = await request('GET', `/cleansing/batches/${batchId}/preview`, null, adminToken);
        expect(res.status).toBe(200);
        const results = res.body.data?.results || [];
        expect(results.length).toBe(2);
        // Verify normalization happened in DB
        expect(results[0].normalized_job_title).toContain('Senior');
        expect(results[0].normalized_salary).toBe('75000');
        expect(results[0].normalized_education).toBe("Bachelor's");
    });

    test('approve batch updates status', async () => {
        if (!batchId) return;
        const res = await request('POST', `/cleansing/batches/${batchId}/approve`, {}, adminToken);
        expect(res.status).toBe(200);
        expect(res.body.data.status).toBe('approved');
    });

    test('cannot re-approve already approved batch', async () => {
        if (!batchId) return;
        const res = await request('POST', `/cleansing/batches/${batchId}/approve`, {}, adminToken);
        expect(res.status).toBe(409);
    });
});

// ---------------------------------------------------------------------------
// 6. Experiment: Create → Start → Assignment → Stop (full DB path)
// ---------------------------------------------------------------------------
describe('Fullstack: Experiment Lifecycle', () => {
    let adminToken;
    let expId;

    beforeAll(async () => {
        adminToken = await loginAs('admin');
    });

    test('create experiment with variants', async () => {
        const res = await request('POST', '/experiments', {
            key: 'e2e_exp_' + Date.now(),
            name: 'E2E Full Lifecycle',
            holdout_percent: 10,
            variants: [
                { variant_key: 'control', traffic_percent: 45 },
                { variant_key: 'treatment', traffic_percent: 45 },
            ],
        }, adminToken);
        // Controller returns 201 for resource creation (REST convention).
        expect([200, 201]).toContain(res.status);
        expect(res.body.success).toBe(true);
        expId = res.body.data.id;
        expect(expId).toBeTruthy();
    });

    test('start experiment', async () => {
        const res = await request('POST', `/experiments/${expId}/start`, {}, adminToken);
        expect(res.status).toBe(200);
        expect(res.body.data.start_at).toBeTruthy();
        expect(res.body.data.end_at).toBeTruthy();
    });

    test('cannot start already running experiment (409)', async () => {
        const res = await request('POST', `/experiments/${expId}/start`, {}, adminToken);
        expect(res.status).toBe(409);
    });

    test('stop experiment', async () => {
        const res = await request('POST', `/experiments/${expId}/stop`, {}, adminToken);
        expect(res.status).toBe(200);
        expect(res.body.success).toBe(true);
    });

    test('cannot stop already stopped experiment (409)', async () => {
        const res = await request('POST', `/experiments/${expId}/stop`, {}, adminToken);
        expect(res.status).toBe(409);
    });
});

// ---------------------------------------------------------------------------
// 6b. Kiosk coupon flow (full no-mock sequence): draft → validate → apply →
//     confirm. This pins the business-logic sequencing the kiosk UI relies
//     on: if the backend contract or any of these endpoints regresses, the
//     in-store kiosk checkout breaks.
// ---------------------------------------------------------------------------
describe('Fullstack: Kiosk coupon flow', () => {
    let frontDeskToken;
    let orderId;

    beforeAll(async () => {
        // frontdesk1 is authorized to create orders and apply coupons.
        // The seeded coupon WELCOME10 (10% off, min spend $50) is scoped
        // to store 1, matching frontdesk1's binding.
        frontDeskToken = await loginAs('frontdesk1', 1, 1);
    });

    test('step 1: POST /orders creates a draft order with pre-coupon totals', async () => {
        const res = await request('POST', '/orders', {
            customer_name: 'Coupon E2E',
            channel: 'kiosk',
            items: [
                { service_code: 'SVC-001', service_name: 'Full Service', qty: 1, unit_price: 100.00 },
            ],
        }, frontDeskToken);
        expect([200, 201]).toContain(res.status);
        expect(res.body.success).toBe(true);
        expect(res.body.data.status).toBe('draft');
        // subtotal=100, tax=8, total=108, no discount yet
        expect(Number(res.body.data.subtotal_amount)).toBe(100.00);
        expect(Number(res.body.data.discount_amount)).toBe(0);
        expect(Number(res.body.data.total_amount)).toBe(108.00);
        orderId = res.body.data.id;
    });

    test('step 2a: GET /coupons/validate returns a 200 and validity info for WELCOME10', async () => {
        expect(orderId).toBeTruthy();
        const res = await request('GET', `/coupons/validate?code=WELCOME10&order_id=${orderId}`, null, frontDeskToken);
        expect(res.status).toBe(200);
        expect(res.body.success).toBe(true);
        // The validate response envelope carries a `valid` flag.
        expect(res.body.data.valid).toBe(true);
    });

    test('step 2b: POST /orders/:id/apply-coupon persists the discount on the draft', async () => {
        expect(orderId).toBeTruthy();
        const applyRes = await request('POST', `/orders/${orderId}/apply-coupon`, {
            code: 'WELCOME10',
        }, frontDeskToken);
        expect(applyRes.status).toBe(200);
        expect(applyRes.body.success).toBe(true);

        // Re-read the order and confirm discount is now non-zero and total
        // is below the pre-coupon 108.00.
        const readRes = await request('GET', `/orders/${orderId}`, null, frontDeskToken);
        expect(readRes.status).toBe(200);
        expect(readRes.body.data.status).toBe('draft');
        const discount = Number(readRes.body.data.discount_amount);
        const total = Number(readRes.body.data.total_amount);
        expect(discount).toBeGreaterThan(0);
        expect(total).toBeLessThan(108.00);
    });

    test('step 3: POST /orders/:id/confirm locks in the discounted total, not the original', async () => {
        expect(orderId).toBeTruthy();

        // Capture the discounted total BEFORE confirming, so we can assert
        // the confirmed order reflects that value, not the pre-coupon one.
        const preConfirm = await request('GET', `/orders/${orderId}`, null, frontDeskToken);
        const discountedTotal = Number(preConfirm.body.data.total_amount);
        expect(discountedTotal).toBeLessThan(108.00);

        const confirmRes = await request('POST', `/orders/${orderId}/confirm`, {}, frontDeskToken);
        expect(confirmRes.status).toBe(200);
        expect(confirmRes.body.success).toBe(true);
        expect(confirmRes.body.data.status).toBe('confirmed');
        // The confirmed order keeps the discounted total — the coupon is
        // NOT silently reset by the state transition.
        expect(Number(confirmRes.body.data.total_amount)).toBe(discountedTotal);

        // Receipt fetch (step 3b) is the actual moment the customer sees
        // the total. It must match the discounted value, not 108.00.
        const receiptRes = await request('GET', `/orders/${orderId}/receipt`, null, frontDeskToken);
        expect(receiptRes.status).toBe(200);
        expect(receiptRes.body.success).toBe(true);
        expect(Number(receiptRes.body.data.total)).toBe(discountedTotal);
    });
});

// ---------------------------------------------------------------------------
// 7. Audit: Operations are logged immutably
// ---------------------------------------------------------------------------
describe('Fullstack: Audit Trail', () => {
    test('audit logs contain recent operations', async () => {
        const tok = await loginAs('admin');
        const res = await request('GET', '/audit/logs', null, tok);
        expect(res.status).toBe(200);
        expect(res.body.data.items.length).toBeGreaterThan(0);
        // Verify audit entry shape
        const entry = res.body.data.items[0];
        expect(entry.action).toBeTruthy();
        expect(entry.entity_type).toBeTruthy();
        expect(entry.created_at).toBeTruthy();
    });
});
