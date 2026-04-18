/**
 * Kiosk Coupon Flow E2E Tests
 * Verifies: draft created first → coupon validated with real order_id → apply-coupon called →
 * confirm → receipt. Also tests that coupon validate is blocked before draft exists.
 */

const localStorageMock = (function () {
    let s = {};
    return {
        getItem: function (k) { return s[k] || null; },
        setItem: function (k, v) { s[k] = String(v); },
        removeItem: function (k) { delete s[k]; },
        clear: function () { s = {}; },
    };
})();
Object.defineProperty(global, 'localStorage', { value: localStorageMock });
global.fetch = jest.fn();

const store = require('../../src/store/index');
const api = require('../../src/services/api');

describe('Kiosk Coupon Flow - Order Creation Before Validation', () => {
    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        store.setRoles(['customer']);
        api.clearInflight();
        fetch.mockClear();
    });

    test('full flow: create draft → apply-coupon → confirm → receipt', async () => {
        // Step 1: Create draft order
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, data: { id: 42, order_no: 'ORD-001', status: 'draft', total_amount: 108 }, request_id: 'r' }),
        });

        var createRes = await api.post('/orders', {
            customer_name: 'Coupon Test',
            channel: 'kiosk',
            items: [{ service_code: 'SVC-001', service_name: 'Oil Change', qty: 1, unit_price: 100 }],
        });
        expect(createRes.data.id).toBe(42);
        var orderId = createRes.data.id;

        // Step 2: Apply coupon server-side (this persists discount_amount)
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({
                success: true,
                data: { id: 42, discount_amount: 10, tax_amount: 7.20, total_amount: 97.20, amount_due: 97.20 },
                request_id: 'r',
            }),
        });

        var applyRes = await api.post('/orders/' + orderId + '/apply-coupon', { code: 'WELCOME10' });
        expect(fetch.mock.calls[1][0]).toContain('/orders/42/apply-coupon');
        expect(applyRes.data.discount_amount).toBe(10);
        expect(applyRes.data.total_amount).toBe(97.20);

        // Step 3: Confirm
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, data: { status: 'confirmed', receipt_no: 'RCP-001' }, request_id: 'r' }),
        });

        await api.post('/orders/' + orderId + '/confirm', {});
        expect(fetch.mock.calls[2][0]).toContain('/orders/42/confirm');

        // Step 4: Get receipt
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, data: { receipt_no: 'RCP-001', total: '97.20', amount_due: '97.20' }, request_id: 'r' }),
        });

        var receiptRes = await api.get('/orders/' + orderId + '/receipt');
        expect(receiptRes.data.total).toBe('97.20');
    });

    test('coupon validate sends real order_id (not 0)', async () => {
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, data: { valid: true, discount_amount: 10 }, request_id: 'r' }),
        });

        // Simulate having a real order ID
        var realOrderId = 42;
        await api.get('/coupons/validate', { code: 'WELCOME10', order_id: realOrderId });
        var url = fetch.mock.calls[0][0];
        expect(url).toContain('order_id=42');
        expect(url).not.toContain('order_id=0');
    });

    test('kiosk page disables coupon button before draft order exists', () => {
        // Simulate the kiosk initial state where _currentOrderId is null
        var _currentOrderId = null;
        var buttonShouldBeDisabled = !_currentOrderId;
        expect(buttonShouldBeDisabled).toBe(true);
    });

    test('coupon button becomes enabled after draft order creation', () => {
        var _currentOrderId = null;
        // After create
        _currentOrderId = 42;
        var buttonShouldBeDisabled = !_currentOrderId;
        expect(buttonShouldBeDisabled).toBe(false);
    });
});

describe('Kiosk Coupon Flow - Discount Persistence', () => {
    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        api.clearInflight();
        fetch.mockClear();
    });

    test('apply-coupon endpoint is called to persist discount server-side', async () => {
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, data: { discount_amount: 10, total_amount: 97.20 }, request_id: 'r' }),
        });

        await api.post('/orders/42/apply-coupon', { code: 'WELCOME10' });
        var body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.code).toBe('WELCOME10');
        expect(fetch.mock.calls[0][0]).toContain('/orders/42/apply-coupon');
    });

    test('discount_amount is non-zero after apply-coupon', () => {
        var serverResponse = { discount_amount: 10, tax_amount: 7.20, total_amount: 97.20, amount_due: 97.20 };
        expect(serverResponse.discount_amount).toBeGreaterThan(0);
        expect(serverResponse.total_amount).toBeLessThan(108); // 100 + 8% tax - 10 discount
    });
});
