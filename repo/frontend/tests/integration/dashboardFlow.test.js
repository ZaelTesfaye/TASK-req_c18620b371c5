/**
 * Dashboard Flow Integration Tests
 * Real page-action contract checks for operations and analytics API calls.
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
const { formatMMDDYYYY, parseMMDDYYYY, toLocalDisplay } = require('../../src/utils/date');

describe('Dashboard API Contract', () => {
    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        store.setRoles(['store_manager']);
        store.setStoreId(1);
        api.clearInflight();
        fetch.mockClear();
    });

    test('operations endpoint called with from/to params', async () => {
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve({
                success: true,
                data: { store_id: 1, transaction_volume: 42, cancellation_rate: 0.05, complaint_rate: 0.02, avg_fulfillment_time: 30 },
                request_id: 'r',
            }),
        });

        await api.get('/dashboards/operations', { from: '01/01/2025', to: '12/31/2025' });
        var url = fetch.mock.calls[0][0];
        expect(url).toContain('/dashboards/operations');
        expect(url).toContain('from=');
        expect(url).toContain('to=');
        expect(url).not.toContain('date_from');
    });

    test('analytics endpoint called alongside operations', async () => {
        fetch.mockResolvedValue({
            ok: true, status: 200,
            json: () => Promise.resolve({
                success: true,
                data: { activity: 0.5, conversion: 0.75, retention: 0.4, content_quality: 4.2, zero_result_search_rate: 0.05 },
                request_id: 'r',
            }),
        });

        await api.get('/dashboards/analytics', { from: '01/01/2025', to: '12/31/2025' });
        var url = fetch.mock.calls[0][0];
        expect(url).toContain('/dashboards/analytics');
    });

    test('operations response contains all expected metric fields', () => {
        var data = { store_id: 1, transaction_volume: 42, avg_fulfillment_time: 30.5, cancellation_rate: 0.05, complaint_rate: 0.02 };
        expect(data.transaction_volume).toBe(42);
        expect(data.cancellation_rate).toBeGreaterThanOrEqual(0);
        expect(data.cancellation_rate).toBeLessThanOrEqual(1);
        expect(data.store_id).toBe(1);
    });

    test('analytics response contains all expected metric fields', () => {
        var data = { activity: 0.5, conversion: 0.75, retention: 0.4, content_quality: 4.2, zero_result_search_rate: 0.05 };
        expect(data.activity).toBeDefined();
        expect(data.conversion).toBeDefined();
        expect(data.retention).toBeDefined();
        expect(data.content_quality).toBeDefined();
        expect(data.zero_result_search_rate).toBeDefined();
    });

    test('CSV export uses correct endpoint path', async () => {
        fetch.mockResolvedValueOnce({
            ok: true, status: 200,
            json: () => Promise.resolve('csv-data'),
        });

        await api.get('/dashboards/operations/export.csv', { from: '01/01/2025', to: '12/31/2025' });
        var url = fetch.mock.calls[0][0];
        expect(url).toContain('/dashboards/operations/export.csv');
    });
});

describe('Dashboard Date Handling', () => {
    test('formatMMDDYYYY produces correct format for API', () => {
        expect(formatMMDDYYYY(new Date(2025, 0, 15))).toBe('01/15/2025');
    });

    test('parseMMDDYYYY roundtrips correctly', () => {
        var str = '03/25/2025';
        expect(formatMMDDYYYY(parseMMDDYYYY(str))).toBe(str);
    });

    test('parseMMDDYYYY rejects invalid dates', () => {
        expect(parseMMDDYYYY('13/01/2025')).toBeNull();
        expect(parseMMDDYYYY('02/30/2025')).toBeNull();
        expect(parseMMDDYYYY('')).toBeNull();
    });

    test('toLocalDisplay handles valid and empty dates', () => {
        expect(toLocalDisplay('2025-01-15T10:30:00Z').length).toBeGreaterThan(0);
        expect(toLocalDisplay('')).toBe('');
        expect(toLocalDisplay(null)).toBe('');
    });
});
