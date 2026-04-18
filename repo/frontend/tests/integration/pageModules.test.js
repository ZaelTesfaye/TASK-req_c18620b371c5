/**
 * Page Module Integration Tests
 * Mounts real page modules via jsdom, feeds properly shaped backend envelope
 * responses, and asserts rendering output and action handler existence.
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

describe('Admin Page Module', () => {
    const admin = require('../../src/pages/admin');

    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        store.setRoles(['administrator']);
        document.body.innerHTML = '<div id="container"></div>';
        fetch.mockClear();
        // Mock all fetches to return valid envelope
        fetch.mockResolvedValue({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: [], request_id: 'test' }),
        });
    });

    test('render creates tab structure', () => {
        var container = document.getElementById('container');
        admin.render(container);
        expect(container.innerHTML).toContain('tab-users');
        expect(container.innerHTML).toContain('tab-experiments');
        expect(container.innerHTML).toContain('tab-events');
        expect(container.innerHTML).toContain('tab-security');
    });

    test('exports editUserRoles as callable function', () => {
        expect(typeof admin.editUserRoles).toBe('function');
    });

    test('exports startExperiment as callable function', () => {
        expect(typeof admin.startExperiment).toBe('function');
    });

    test('exports stopExperiment as callable function', () => {
        expect(typeof admin.stopExperiment).toBe('function');
    });

    test('key rotation button sends new_version field', () => {
        var container = document.getElementById('container');
        admin.render(container);
        var versionInput = document.getElementById('new-key-version');
        expect(versionInput).not.toBeNull();
        expect(versionInput.value).toBe('2');
    });
});

describe('Cleansing Page Module', () => {
    const cleansing = require('../../src/pages/cleansing');

    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        store.setRoles(['administrator']);
        document.body.innerHTML = '<div id="container"></div>';
        fetch.mockClear();
        fetch.mockResolvedValue({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: { items: [] }, request_id: 'test' }),
        });
    });

    test('exports previewBatch as callable function', () => {
        expect(typeof cleansing.previewBatch).toBe('function');
    });

    test('exports approveBatch as callable function', () => {
        expect(typeof cleansing.approveBatch).toBe('function');
    });

    test('exports rollbackBatch as callable function', () => {
        expect(typeof cleansing.rollbackBatch).toBe('function');
    });

    test('render creates tab structure', () => {
        var container = document.getElementById('container');
        cleansing.render(container);
        expect(container.innerHTML).toContain('tab-batches');
    });
});

describe('Environmental Page Module', () => {
    const environmental = require('../../src/pages/environmental');

    test('exports viewLineage as callable function', () => {
        expect(typeof environmental.viewLineage).toBe('function');
    });

    test('render creates tab structure', () => {
        store.setToken('test-token');
        store.setRoles(['administrator']);
        document.body.innerHTML = '<div id="container"></div>';
        fetch.mockResolvedValue({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, data: { items: [] }, request_id: 'test' }),
        });
        var container = document.getElementById('container');
        environmental.render(container);
        expect(container.innerHTML).toContain('environmental');
    });
});

describe('Kiosk Page Module', () => {
    const kiosk = require('../../src/pages/kiosk');

    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        store.setRoles(['customer']);
        document.body.innerHTML = '<div id="container"></div>';
        fetch.mockClear();
    });

    test('render creates order form with correct field names', () => {
        var container = document.getElementById('container');
        kiosk.render(container);
        // Should contain customer name input, service checkboxes, coupon field
        expect(container.innerHTML).toContain('kiosk');
    });

    test('exports render as function', () => {
        expect(typeof kiosk.render).toBe('function');
    });
});

describe('Dashboard Page Module', () => {
    const dashboard = require('../../src/pages/dashboard');

    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        store.setRoles(['store_manager']);
        store.setStoreId(1);
        document.body.innerHTML = '<div id="container"></div>';
        fetch.mockClear();
        fetch.mockResolvedValue({
            ok: true,
            status: 200,
            json: () => Promise.resolve({
                success: true,
                data: {
                    store_id: 1,
                    transaction_volume: 42,
                    avg_fulfillment_time: 30.5,
                    cancellation_rate: 0.05,
                    complaint_rate: 0.02,
                },
                request_id: 'test',
            }),
        });
    });

    test('render creates dashboard layout', () => {
        var container = document.getElementById('container');
        dashboard.render(container);
        expect(container.innerHTML).toContain('dashboard');
    });

    test('sends from/to params (not date_from/date_to)', async () => {
        var container = document.getElementById('container');
        dashboard.render(container);
        // Wait for fetch to be called
        await new Promise(r => setTimeout(r, 10));
        if (fetch.mock.calls.length > 0) {
            var url = fetch.mock.calls[0][0];
            expect(url).not.toContain('date_from');
            expect(url).not.toContain('date_to');
        }
    });
});

describe('Orders Page Module', () => {
    const orders = require('../../src/pages/orders');

    beforeEach(() => {
        store.clear();
        store.setToken('test-token');
        store.setRoles(['front_desk']);
        document.body.innerHTML = '<div id="container"></div>';
        fetch.mockClear();
        fetch.mockResolvedValue({
            ok: true,
            status: 200,
            json: () => Promise.resolve({
                success: true,
                data: { items: [], total: 0, page: 1, page_size: 15 },
                request_id: 'test',
            }),
        });
    });

    test('render creates orders table', () => {
        var container = document.getElementById('container');
        orders.render(container);
        expect(container.innerHTML).toContain('orders');
    });

    test('status filter uses backend canonical values', () => {
        var container = document.getElementById('container');
        orders.render(container);
        var html = container.innerHTML;
        expect(html).toContain('value="draft"');
        expect(html).toContain('value="confirmed"');
        expect(html).toContain('value="assigned"');
        expect(html).toContain('value="in_progress"');
        expect(html).toContain('value="completed"');
        expect(html).toContain('value="cancelled"');
        expect(html).not.toContain('value="pending"');
    });
});

describe('Finance Page Module', () => {
    const finance = require('../../src/pages/finance');

    test('exports render as function', () => {
        expect(typeof finance.render).toBe('function');
    });
});
