/**
 * Navigation Component Tests
 *
 * Tests the post-refactor contract: there is a single flat MENU_ITEMS
 * catalog, and the menu the user sees is derived from
 * router.hasAccess() at render time. Per-role menu configuration is
 * no longer maintained as a separate map (that was the source of the
 * drift bug where front_desk was offered "Cleansing" even though the
 * cleansing route is restricted to store_manager + administrator).
 */

// Mock localStorage
const localStorageMock = (function() {
    let s = {};
    return {
        getItem: function(k) { return s[k] || null; },
        setItem: function(k, v) { s[k] = String(v); },
        removeItem: function(k) { delete s[k]; },
        clear: function() { s = {}; },
    };
})();
if (!global.localStorage) {
    Object.defineProperty(global, 'localStorage', { value: localStorageMock });
}

const store = require('../../src/store/index');
const navigation = require('../../src/components/Navigation');
const router = require('../../src/router/index');

function labelsForRole(role) {
    store.setRoles([role]);
    return navigation.getMenuItems().map(function (i) { return i.label; });
}

function routesForRole(role) {
    store.setRoles([role]);
    return navigation.getMenuItems().map(function (i) { return i.route; });
}

describe('Navigation - per-role menu derives from route access', () => {
    beforeEach(() => {
        store.clear();
    });

    test('customer cannot see dashboard, admin, finance, audit logs', () => {
        const routes = routesForRole(router.ROLES.CUSTOMER);
        // customer is permitted on: orders, kiosk
        expect(routes).toContain('orders');
        expect(routes).toContain('kiosk');
        expect(routes).not.toContain('dashboard');
        expect(routes).not.toContain('admin');
        expect(routes).not.toContain('finance');
        expect(routes).not.toContain('audit-logs');
        expect(routes).not.toContain('cleansing');
    });

    test('front desk cannot see cleansing or admin', () => {
        // Regression test for the menu-drift bug: front desk used to
        // be offered Cleansing here, click it, and immediately 403.
        const routes = routesForRole(router.ROLES.FRONT_DESK);
        expect(routes).toContain('orders');
        expect(routes).toContain('kiosk');
        expect(routes).not.toContain('cleansing');
        expect(routes).not.toContain('admin');
        expect(routes).not.toContain('dashboard');
        expect(routes).not.toContain('finance');
    });

    test('technician sees queue and orders only', () => {
        const routes = routesForRole(router.ROLES.TECHNICIAN);
        expect(routes).toContain('technician-queue');
        expect(routes).toContain('orders');
        expect(routes).not.toContain('admin');
        expect(routes).not.toContain('finance');
        expect(routes).not.toContain('dashboard');
    });

    test('store manager sees ops surfaces but not admin', () => {
        const labels = labelsForRole(router.ROLES.STORE_MANAGER);
        expect(labels).toContain('Dashboard');
        expect(labels).toContain('Finance');
        expect(labels).toContain('Environmental');
        expect(labels).toContain('Cleansing');
        expect(labels).toContain('Audit Logs');
        expect(labels).not.toContain('Admin');
    });

    test('finance sees finance + audit + orders only', () => {
        const labels = labelsForRole(router.ROLES.FINANCE);
        expect(labels).toContain('Finance');
        expect(labels).toContain('Audit Logs');
        expect(labels).toContain('Orders');
        expect(labels).not.toContain('Admin');
        expect(labels).not.toContain('Dashboard');
    });

    test('administrator sees every menu item', () => {
        const labels = labelsForRole(router.ROLES.ADMINISTRATOR);
        ['Dashboard', 'Orders', 'Technician Queue', 'Finance',
         'Admin', 'Environmental', 'Cleansing', 'Audit Logs',
         'Kiosk'].forEach(function (l) {
            expect(labels).toContain(l);
        });
    });
});

describe('Navigation - MENU_ITEMS catalog shape', () => {
    test('every catalog entry has label, route, icon', () => {
        navigation.MENU_ITEMS.forEach(function (item) {
            expect(item.route).toBeTruthy();
            expect(item.icon).toBeTruthy();
            expect(item.label).toBeTruthy();
        });
    });

    test('every catalog entry maps to a real router route', () => {
        navigation.MENU_ITEMS.forEach(function (item) {
            expect(router.findRoute(item.route)).not.toBeNull();
        });
    });
});

describe('Navigation - getMenuItems', () => {
    beforeEach(() => {
        store.clear();
    });

    test('returns empty for no roles', () => {
        store.setRoles([]);
        expect(navigation.getMenuItems()).toEqual([]);
    });

    test('de-duplicates items for multi-role user', () => {
        store.setRoles([router.ROLES.ADMINISTRATOR, router.ROLES.STORE_MANAGER]);
        const routes = navigation.getMenuItems().map(function (i) { return i.route; });
        expect(routes.length).toBe([...new Set(routes)].length);
    });
});
