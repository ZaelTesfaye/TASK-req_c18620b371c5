/**
 * Navigation Component Tests
 * Tests shipped Navigation module for role-specific menu rendering.
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

describe('Navigation Component - Role Menus', () => {
    beforeEach(() => {
        store.clear();
    });

    test('customer sees Dashboard, My Orders, Kiosk', () => {
        const items = navigation.MENU_CONFIG[router.ROLES.CUSTOMER];
        const labels = items.map(i => i.label);
        expect(labels).toContain('Dashboard');
        expect(labels).toContain('My Orders');
        expect(labels).toContain('Kiosk');
        expect(labels).not.toContain('Admin');
    });

    test('front desk sees Orders and Cleansing', () => {
        const items = navigation.MENU_CONFIG[router.ROLES.FRONT_DESK];
        const labels = items.map(i => i.label);
        expect(labels).toContain('Orders');
        expect(labels).toContain('Cleansing');
        expect(labels).not.toContain('Admin');
    });

    test('technician sees Technician Queue', () => {
        const items = navigation.MENU_CONFIG[router.ROLES.TECHNICIAN];
        const labels = items.map(i => i.label);
        expect(labels).toContain('Technician Queue');
        expect(labels).not.toContain('Admin');
        expect(labels).not.toContain('Finance');
    });

    test('store manager sees Finance and Environmental', () => {
        const items = navigation.MENU_CONFIG[router.ROLES.STORE_MANAGER];
        const labels = items.map(i => i.label);
        expect(labels).toContain('Finance');
        expect(labels).toContain('Environmental');
        expect(labels).toContain('Audit Logs');
    });

    test('finance sees Finance and Audit Logs', () => {
        const items = navigation.MENU_CONFIG[router.ROLES.FINANCE];
        const labels = items.map(i => i.label);
        expect(labels).toContain('Finance');
        expect(labels).toContain('Audit Logs');
    });

    test('administrator sees all admin menus', () => {
        const items = navigation.MENU_CONFIG[router.ROLES.ADMINISTRATOR];
        const labels = items.map(i => i.label);
        expect(labels).toContain('Admin');
        expect(labels).toContain('Environmental');
        expect(labels).toContain('Cleansing');
        expect(labels).toContain('Audit Logs');
        expect(labels).toContain('Kiosk');
    });
});

describe('Navigation Component - getMenuItems', () => {
    beforeEach(() => {
        store.clear();
    });

    test('returns items for customer', () => {
        store.setRoles([router.ROLES.CUSTOMER]);
        const items = navigation.getMenuItems();
        expect(items.length).toBeGreaterThan(0);
    });

    test('returns empty for no roles', () => {
        store.setRoles([]);
        expect(navigation.getMenuItems()).toEqual([]);
    });

    test('deduplicates items for multi-role user', () => {
        store.setRoles([router.ROLES.ADMINISTRATOR, router.ROLES.STORE_MANAGER]);
        const items = navigation.getMenuItems();
        const routes = items.map(i => i.route);
        expect(routes.length).toBe([...new Set(routes)].length);
    });
});

describe('Navigation Component - CSS Classes', () => {
    test('roleToCssClass maps all roles', () => {
        expect(navigation.roleToCssClass(router.ROLES.CUSTOMER)).toBe('menu-role-customer');
        expect(navigation.roleToCssClass(router.ROLES.FRONT_DESK)).toBe('menu-role-frontdesk');
        expect(navigation.roleToCssClass(router.ROLES.TECHNICIAN)).toBe('menu-role-technician');
        expect(navigation.roleToCssClass(router.ROLES.STORE_MANAGER)).toBe('menu-role-storemanager');
        expect(navigation.roleToCssClass(router.ROLES.FINANCE)).toBe('menu-role-finance');
        expect(navigation.roleToCssClass(router.ROLES.ADMINISTRATOR)).toBe('menu-role-administrator');
    });

    test('unknown role returns empty string', () => {
        expect(navigation.roleToCssClass('unknown')).toBe('');
    });

    test('getPrimaryRoleClass returns highest privilege', () => {
        store.setRoles([router.ROLES.CUSTOMER, router.ROLES.ADMINISTRATOR]);
        expect(navigation.getPrimaryRoleClass()).toBe('menu-role-administrator');
    });
});
