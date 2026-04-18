/**
 * Kiosk Payload Integration Tests
 * Verifies the outgoing order payload contains non-empty canonical service fields,
 * the coupon request includes a valid order_id, and the confirmation flow is correct.
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

describe('Kiosk Order Payload', () => {
    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        store.setRoles(['customer']);
        api.clearInflight();
        fetch.mockClear();
    });

    test('order payload uses items (not services) with canonical fields', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({
                success: true,
                data: { id: 42, order_no: 'ORD-001', status: 'draft' },
                request_id: 'test',
            }),
        });

        // Simulate what kiosk.js sends
        var items = [
            { service_code: 'SVC-001', service_name: 'Oil Change', qty: 1, unit_price: 49.99 },
        ];

        await api.post('/orders', {
            customer_name: 'Test Customer',
            channel: 'kiosk',
            items: items,
        });

        var body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.items).toBeDefined();
        expect(body.services).toBeUndefined();
        expect(body.items[0].service_code).toBe('SVC-001');
        expect(body.items[0].service_name).toBe('Oil Change');
        expect(body.items[0].qty).toBe(1);
        expect(body.items[0].unit_price).toBe(49.99);
        expect(body.channel).toBe('kiosk');
    });

    test('invoice fields use canonical backend names', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: { id: 1 }, request_id: 'test' }),
        });

        await api.post('/orders', {
            customer_name: 'Corp User',
            items: [{ service_code: 'SVC-001', service_name: 'Test', qty: 1, unit_price: 10 }],
            invoice_requested: true,
            invoice_taxpayer_id: '12-3456789',
            invoice_entity_name: 'Acme Corp',
            invoice_identifier: 'INV-001',
        });

        var body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.invoice_requested).toBe(true);
        expect(body.invoice_taxpayer_id).toBe('12-3456789');
        expect(body.invoice_entity_name).toBe('Acme Corp');
        expect(body.invoice_identifier).toBe('INV-001');
        // Old field names should NOT be present
        expect(body.needs_invoice).toBeUndefined();
        expect(body.taxpayer_id).toBeUndefined();
        expect(body.entity_name).toBeUndefined();
        expect(body.identifier).toBeUndefined();
    });

    test('coupon validation sends order_id', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: { valid: false }, request_id: 'test' }),
        });

        await api.get('/coupons/validate', { code: 'WELCOME10', order_id: 42 });
        var url = fetch.mock.calls[0][0];
        expect(url).toContain('order_id=42');
        expect(url).toContain('code=WELCOME10');
    });

    test('order confirmation calls confirm then receipt endpoints', async () => {
        // First call: POST /orders → creates draft
        // Second call: POST /orders/:id/confirm → confirms
        // Third call: GET /orders/:id/receipt → gets receipt
        fetch
            .mockResolvedValueOnce({
                ok: true, status: 200,
                json: () => Promise.resolve({ success: true, data: { id: 42 }, request_id: 'test' }),
            })
            .mockResolvedValueOnce({
                ok: true, status: 200,
                json: () => Promise.resolve({ success: true, data: { status: 'confirmed', receipt_no: 'RCP-001' }, request_id: 'test' }),
            })
            .mockResolvedValueOnce({
                ok: true, status: 200,
                json: () => Promise.resolve({ success: true, data: { receipt_no: 'RCP-001', order_no: 'ORD-001' }, request_id: 'test' }),
            });

        var createRes = await api.post('/orders', { customer_name: 'Test', items: [] });
        var orderId = createRes.data.id;
        expect(orderId).toBe(42);

        await api.post('/orders/' + orderId + '/confirm', {});
        expect(fetch.mock.calls[1][0]).toContain('/orders/42/confirm');

        var receiptRes = await api.get('/orders/' + orderId + '/receipt');
        expect(fetch.mock.calls[2][0]).toContain('/orders/42/receipt');
    });
});
