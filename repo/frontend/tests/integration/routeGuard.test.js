/**
 * Route Guard Integration Tests
 * Tests authentication and authorization flow using real router module.
 */

// Mock localStorage
const localStorageMock = (function() {
    let store = {};
    return {
        getItem: function(key) { return store[key] || null; },
        setItem: function(key, value) { store[key] = String(value); },
        removeItem: function(key) { delete store[key]; },
        clear: function() { store = {}; },
    };
})();
Object.defineProperty(global, 'localStorage', { value: localStorageMock });

const store = require('../../src/store/index');
const router = require('../../src/router/index');

describe('Route Guard - Authentication', () => {
    beforeEach(() => {
        store.clear();
        localStorageMock.clear();
    });

    afterEach(() => {
        router.destroy();
    });

    test('unauthenticated user cannot access protected orders route', () => {
        const route = router.findRoute('orders');
        expect(route.auth).toBe(true);
        expect(store.isAuthenticated()).toBe(false);
    });

    test('authenticated user can access protected route', () => {
        store.setToken('valid-token');
        expect(store.isAuthenticated()).toBe(true);
        const route = router.findRoute('orders');
        expect(route).not.toBeNull();
    });

    test('login route does not require auth', () => {
        const route = router.findRoute('login');
        expect(route.auth).toBe(false);
    });
});

describe('Route Guard - Authorization', () => {
    beforeEach(() => {
        store.clear();
        localStorageMock.clear();
    });

    afterEach(() => {
        router.destroy();
    });

    test('customer cannot access admin page', () => {
        store.setRoles([router.ROLES.CUSTOMER]);
        const route = router.findRoute('admin');
        expect(router.hasAccess(route)).toBe(false);
    });

    test('administrator can access all pages', () => {
        store.setRoles([router.ROLES.ADMINISTRATOR]);
        const routes = router.getRoutes();
        routes.forEach(route => {
            if (route.roles) {
                expect(router.hasAccess(route)).toBe(true);
            }
        });
    });

    test('technician cannot access finance page', () => {
        store.setRoles([router.ROLES.TECHNICIAN]);
        expect(router.hasAccess(router.findRoute('finance'))).toBe(false);
    });

    test('finance user can access finance page', () => {
        store.setRoles([router.ROLES.FINANCE]);
        expect(router.hasAccess(router.findRoute('finance'))).toBe(true);
    });

    test('store manager can access dashboards', () => {
        store.setRoles([router.ROLES.STORE_MANAGER]);
        expect(router.hasAccess(router.findRoute('dashboard'))).toBe(true);
    });

    test('store manager cannot access admin', () => {
        store.setRoles([router.ROLES.STORE_MANAGER]);
        expect(router.hasAccess(router.findRoute('admin'))).toBe(false);
    });

    test('customer cannot access environmental', () => {
        store.setRoles([router.ROLES.CUSTOMER]);
        expect(router.hasAccess(router.findRoute('environmental'))).toBe(false);
    });

    test('finance user can access audit-logs', () => {
        // Audit logs are accessible to finance, store_manager, and
        // administrator. Finance needs read-only access for reconciliation
        // investigations; the Finance role's Navigation menu surfaces the
        // "Audit Logs" entry accordingly.
        store.setRoles([router.ROLES.FINANCE]);
        expect(router.hasAccess(router.findRoute('audit-logs'))).toBe(true);
    });

    test('store manager can access audit-logs', () => {
        store.setRoles([router.ROLES.STORE_MANAGER]);
        expect(router.hasAccess(router.findRoute('audit-logs'))).toBe(true);
    });

    test('administrator can access audit-logs', () => {
        store.setRoles([router.ROLES.ADMINISTRATOR]);
        expect(router.hasAccess(router.findRoute('audit-logs'))).toBe(true);
    });

    test('front desk can access orders', () => {
        store.setRoles([router.ROLES.FRONT_DESK]);
        expect(router.hasAccess(router.findRoute('orders'))).toBe(true);
    });

    test('technician can access technician-queue', () => {
        store.setRoles([router.ROLES.TECHNICIAN]);
        expect(router.hasAccess(router.findRoute('technician-queue'))).toBe(true);
    });

    test('customer can access kiosk', () => {
        store.setRoles([router.ROLES.CUSTOMER]);
        expect(router.hasAccess(router.findRoute('kiosk'))).toBe(true);
    });
});

describe('Route Guard - Multi-step Order Flow', () => {
    test('order validation blocks progression when fields empty', () => {
        const { validateRequired } = require('../../src/utils/validation');
        const result = validateRequired('', 'Customer name');
        expect(result.valid).toBe(false);
    });

    test('order validation passes when fields filled', () => {
        const { validateRequired } = require('../../src/utils/validation');
        const result = validateRequired('John Doe', 'Customer name');
        expect(result.valid).toBe(true);
    });
});
