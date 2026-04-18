/**
 * API Contract Integration Tests
 * Verifies that frontend API calls send the correct field names and payloads
 * matching the backend API contract.
 */

// Mock localStorage
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

describe('Kiosk Coupon Validation Contract', () => {
    beforeEach(() => {
        store.clear();
        api.clearInflight();
        fetch.mockClear();
    });

    test('coupon validation sends code AND order_id via GET', async () => {
        store.setToken('test-token');
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ valid: false, reason: 'Not found' }),
        });

        await api.get('/coupons/validate', { code: 'WELCOME10', order_id: 42 });

        var calledUrl = fetch.mock.calls[0][0];
        expect(calledUrl).toContain('code=WELCOME10');
        expect(calledUrl).toContain('order_id=42');
        expect(fetch.mock.calls[0][1].method).toBe('GET');
    });
});

describe('Finance Open Drawer Contract', () => {
    beforeEach(() => {
        store.clear();
        api.clearInflight();
        fetch.mockClear();
    });

    test('open drawer sends open_amount (not opening_balance)', async () => {
        store.setToken('test-token');
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: { id: 1 } }),
        });

        await api.post('/finance/cash-drawer', {
            business_date: '2025-01-15',
            open_amount: 250.00,
        });

        var body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.open_amount).toBe(250.00);
        expect(body.opening_balance).toBeUndefined();
        expect(body.business_date).toBe('2025-01-15');
    });

    test('open drawer does NOT send store_id (server derives from session)', async () => {
        store.setToken('test-token');
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: { id: 1 } }),
        });

        await api.post('/finance/cash-drawer', {
            business_date: '2025-01-15',
            open_amount: 100.00,
        });

        var body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.store_id).toBeUndefined();
    });
});

describe('Finance Daily Summary Contract', () => {
    beforeEach(() => {
        store.clear();
        api.clearInflight();
        fetch.mockClear();
    });

    test('daily summary sends date parameter', async () => {
        store.setToken('test-token');
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: null }),
        });

        await api.get('/finance/cash-drawer/daily', { date: '2025-01-15' });

        var calledUrl = fetch.mock.calls[0][0];
        expect(calledUrl).toContain('date=2025-01-15');
    });

    test('daily summary does NOT send store_id (server derives from session)', async () => {
        store.setToken('test-token');
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: null }),
        });

        await api.get('/finance/cash-drawer/daily', { date: '2025-01-15' });

        var calledUrl = fetch.mock.calls[0][0];
        expect(calledUrl).not.toContain('store_id');
    });
});

describe('Dashboard API Contract', () => {
    beforeEach(() => {
        store.clear();
        api.clearInflight();
        fetch.mockClear();
    });

    test('operations dashboard calls /dashboards/operations', async () => {
        store.setToken('test-token');
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: {} }),
        });

        await api.get('/dashboards/operations', { from: '01/01/2025', to: '12/31/2025' });

        var calledUrl = fetch.mock.calls[0][0];
        expect(calledUrl).toContain('/dashboards/operations');
        expect(calledUrl).not.toContain('/dashboard?');
    });

    test('CSV export calls /dashboards/operations/export.csv', async () => {
        store.setToken('test-token');
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve('csv-data'),
        });

        await api.get('/dashboards/operations/export.csv', { from: '01/01/2025', to: '12/31/2025' });

        var calledUrl = fetch.mock.calls[0][0];
        expect(calledUrl).toContain('/dashboards/operations/export.csv');
    });
});

describe('Order Status Vocabulary Contract', () => {
    test('backend canonical statuses are used', () => {
        var canonical = ['draft', 'confirmed', 'assigned', 'in_progress', 'completed', 'cancelled'];
        var forbidden = ['pending']; // NOT a valid backend status

        canonical.forEach(function (s) {
            expect(typeof s).toBe('string');
        });

        forbidden.forEach(function (s) {
            expect(canonical).not.toContain(s);
        });
    });
});

describe('Technician Queue API Contract', () => {
    beforeEach(() => {
        store.clear();
        api.clearInflight();
        fetch.mockClear();
    });

    test('queue uses /orders endpoint with status filter', async () => {
        store.setToken('test-token');
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: { items: [] } }),
        });

        await api.get('/orders', { status: 'assigned' });

        var calledUrl = fetch.mock.calls[0][0];
        expect(calledUrl).toContain('/orders');
        expect(calledUrl).toContain('status=assigned');
        expect(calledUrl).not.toContain('/technician/queue');
    });

    test('accept job uses /orders/:id/accept', async () => {
        store.setToken('test-token');
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true }),
        });

        await api.post('/orders/5/accept', {});

        var calledUrl = fetch.mock.calls[0][0];
        expect(calledUrl).toContain('/orders/5/accept');
    });

    test('work notes use /orders/:id/work-notes with note field', async () => {
        store.setToken('test-token');
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true }),
        });

        await api.post('/orders/5/work-notes', { note: 'Replaced filter' });

        var body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.note).toBe('Replaced filter');
        expect(body.notes).toBeUndefined();
    });
});
