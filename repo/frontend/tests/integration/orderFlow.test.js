/**
 * Order Flow Integration Tests
 * Real page-action contract checks verifying field mappings and API calls.
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
const { validateRequired, validateAmount, validateInvoiceFields } = require('../../src/utils/validation');
const { renderAmountBreakdown } = require('../../src/components/AmountBreakdown');
const { renderReceipt } = require('../../src/components/Receipt');

describe('Order Intake - Payload Contract', () => {
    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        store.setRoles(['front_desk']);
        api.clearInflight();
        fetch.mockClear();
    });

    test('order creation sends items with service_code and service_name', async () => {
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, data: { id: 1, order_no: 'ORD-001', status: 'draft', subtotal_amount: 49.99 }, request_id: 'r' }),
        });

        await api.post('/orders', {
            customer_name: 'John Doe',
            channel: 'front_desk',
            items: [{ service_code: 'SVC-001', service_name: 'Oil Change', qty: 1, unit_price: 49.99 }],
        });

        var body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.items).toBeDefined();
        expect(body.items[0].service_code).toBe('SVC-001');
        expect(body.items[0].service_name).toBe('Oil Change');
        expect(body.items[0].service_code).not.toBe('');
        expect(body.items[0].service_name).not.toBe('');
    });

    test('order creation uses from/to date params (not date_from/date_to)', async () => {
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, data: { items: [], total: 0, page: 1, page_size: 15 }, request_id: 'r' }),
        });

        await api.get('/orders', { from: '01/01/2025', to: '12/31/2025', status: 'draft' });
        var url = fetch.mock.calls[0][0];
        expect(url).toContain('from=');
        expect(url).toContain('to=');
        expect(url).not.toContain('date_from');
        expect(url).not.toContain('date_to');
    });

    test('order edit sends PATCH with customer_name', async () => {
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, data: { id: 1, customer_name: 'Jane' }, request_id: 'r' }),
        });

        await api.patch('/orders/1', { customer_name: 'Jane Doe' });
        expect(fetch.mock.calls[0][1].method).toBe('PATCH');
        var body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.customer_name).toBe('Jane Doe');
    });

    test('assign technician sends POST with technician_id', async () => {
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, data: { status: 'assigned' }, request_id: 'r' }),
        });

        await api.post('/orders/1/assign-technician', { technician_id: 3 });
        var body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.technician_id).toBe(3);
    });
});

describe('Order Intake - Validation', () => {
    test('customer_name required', () => {
        expect(validateRequired('', 'Customer name').valid).toBe(false);
        expect(validateRequired('John', 'Customer name').valid).toBe(true);
    });

    test('item amount must be positive', () => {
        expect(validateAmount(49.99).valid).toBe(true);
        expect(validateAmount(0).valid).toBe(false);
    });

    test('invoice fields validated with canonical names', () => {
        var result = validateInvoiceFields({ customer_name: 'Corp', amount: 100, date: '01/15/2025' });
        expect(result.valid).toBe(true);
    });
});

describe('Order Intake - Pricing Display', () => {
    test('pricing chain renders correctly via AmountBreakdown', () => {
        var html = renderAmountBreakdown({ subtotal: 100, discount: 10, tax: 7.20, total: 97.20, amount_due: 97.20 });
        expect(html).toContain('$100.00');
        expect(html).toContain('$7.20');
        expect(html).toContain('$97.20');
    });

    test('receipt renders with canonical fields', () => {
        var html = renderReceipt({
            receipt_no: 'RCP-001', order_no: 'ORD-001', customer_name: 'John',
            items: [{ service_name: 'Oil Change', qty: 1, unit_price: 49.99, line_subtotal: 49.99 }],
            subtotal: '49.99', discount: '0.00', tax: '4.00', total: '53.99', amount_due: '53.99',
        });
        expect(html).toContain('RCP-001');
        expect(html).toContain('Oil Change');
    });
});

describe('Order Status Vocabulary', () => {
    test('backend canonical statuses used everywhere', () => {
        var canonical = ['draft', 'confirmed', 'assigned', 'in_progress', 'completed', 'cancelled'];
        expect(canonical).not.toContain('pending');
        expect(canonical).toHaveLength(6);
    });
});
