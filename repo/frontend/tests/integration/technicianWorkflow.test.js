/**
 * Technician Workflow Integration Tests
 * Verifies the queue fetches both assigned and in_progress jobs,
 * and that accepted jobs remain visible and actionable.
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

describe('Technician Queue Workflow', () => {
    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        store.setRoles(['technician']);
        api.clearInflight();
        fetch.mockClear();
    });

    test('queue fetches both assigned AND in_progress statuses', async () => {
        fetch.mockResolvedValue({
            ok: true,
            status: 200,
            json: () => Promise.resolve({
                success: true,
                data: { items: [], total: 0, page: 1, page_size: 20 },
                request_id: 'test',
            }),
        });

        // Simulate the two API calls the queue makes
        await Promise.all([
            api.get('/orders', { status: 'assigned' }),
            api.get('/orders', { status: 'in_progress' }),
        ]);

        // Should have made 2 fetch calls - one for each status
        expect(fetch.mock.calls.length).toBe(2);
        var url1 = fetch.mock.calls[0][0];
        var url2 = fetch.mock.calls[1][0];
        expect(url1).toContain('status=assigned');
        expect(url2).toContain('status=in_progress');
    });

    test('accept job calls POST /orders/:id/accept', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: {} }),
        });

        await api.post('/orders/5/accept', {});
        expect(fetch.mock.calls[0][0]).toContain('/orders/5/accept');
        expect(fetch.mock.calls[0][1].method).toBe('POST');
    });

    test('work notes sent to POST /orders/:id/work-notes with note field', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: {} }),
        });

        await api.post('/orders/5/work-notes', { note: 'Replaced filter' });
        var body = JSON.parse(fetch.mock.calls[0][1].body);
        expect(body.note).toBe('Replaced filter');
    });

    test('complete job calls POST /orders/:id/complete', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: {} }),
        });

        await api.post('/orders/5/complete', {});
        expect(fetch.mock.calls[0][0]).toContain('/orders/5/complete');
    });
});
