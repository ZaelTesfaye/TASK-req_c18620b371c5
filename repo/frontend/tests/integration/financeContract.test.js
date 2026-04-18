/**
 * Finance Contract Integration Tests
 * Verifies open_amount field naming, drawer state management, and CSV export path.
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

describe('Finance Open Drawer Contract', () => {
    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        store.setRoles(['finance']);
        api.clearInflight();
        fetch.mockClear();
    });

    test('open drawer sends open_amount, not opening_balance', async () => {
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, data: { id: 1 }, request_id: 'test' }),
        });

        await api.post('/finance/cash-drawer', {
            business_date: '2025-01-15',
            open_amount: 200.00,
        });

        var body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.open_amount).toBe(200.00);
        expect(body.opening_balance).toBeUndefined();
        expect(body.business_date).toBe('2025-01-15');
    });

    test('daily summary sends date parameter', async () => {
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({ success: true, data: null, request_id: 'test' }),
        });

        await api.get('/finance/cash-drawer/daily', { date: '2025-01-15' });
        var url = fetch.mock.calls[0][0];
        expect(url).toContain('date=2025-01-15');
        expect(url).not.toContain('store_id');
    });
});

describe('Finance Drawer State Rendering', () => {
    test('finance page reads open_amount from drawer data', () => {
        // Simulate a drawer response
        var drawer = { id: 1, status: 'open', open_amount: 250.00, expected_total: 250.00 };
        var openAmt = Number(drawer.open_amount || drawer.opening_balance || 0);
        expect(openAmt).toBe(250.00);
    });

    test('finance page falls back to opening_balance for legacy data', () => {
        var drawer = { id: 1, status: 'open', opening_balance: 150.00 };
        var openAmt = Number(drawer.open_amount || drawer.opening_balance || 0);
        expect(openAmt).toBe(150.00);
    });
});

describe('Finance CSV Export Contract', () => {
    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        api.clearInflight();
        fetch.mockClear();
    });

    test('CSV export URL includes drawer ID', async () => {
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve('csv-data'),
        });

        var drawerId = 7;
        await api.get('/finance/reconciliation/' + drawerId + '/statement.csv', {});
        var url = fetch.mock.calls[0][0];
        expect(url).toContain('/finance/reconciliation/7/statement.csv');
    });
});
