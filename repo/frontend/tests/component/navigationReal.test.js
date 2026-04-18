/**
 * Navigation Component Tests (Real)
 * Tests actual Navigation module imports: menu configuration, role CSS classes, menu items.
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
const navigation = require('../../src/components/Navigation');
const router = require('../../src/router/index');

describe('Navigation Component (Real)', () => {
    beforeEach(() => {
        store.clear();
        localStorageMock.clear();
    });

    describe('MENU_CONFIG', () => {
        test('customer menu contains Dashboard, My Orders, Kiosk', () => {
            const items = navigation.MENU_CONFIG[router.ROLES.CUSTOMER];
            const labels = items.map(i => i.label);
            expect(labels).toContain('Dashboard');
            expect(labels).toContain('My Orders');
            expect(labels).toContain('Kiosk');
        });

        test('front desk menu contains Orders and Cleansing', () => {
            const items = navigation.MENU_CONFIG[router.ROLES.FRONT_DESK];
            const labels = items.map(i => i.label);
            expect(labels).toContain('Orders');
            expect(labels).toContain('Cleansing');
        });

        test('technician menu contains Technician Queue', () => {
            const items = navigation.MENU_CONFIG[router.ROLES.TECHNICIAN];
            const labels = items.map(i => i.label);
            expect(labels).toContain('Technician Queue');
        });

        test('store manager menu contains Finance and Environmental', () => {
            const items = navigation.MENU_CONFIG[router.ROLES.STORE_MANAGER];
            const labels = items.map(i => i.label);
            expect(labels).toContain('Finance');
            expect(labels).toContain('Environmental');
            expect(labels).toContain('Audit Logs');
        });

        test('finance menu contains Finance and Audit Logs', () => {
            const items = navigation.MENU_CONFIG[router.ROLES.FINANCE];
            const labels = items.map(i => i.label);
            expect(labels).toContain('Finance');
            expect(labels).toContain('Audit Logs');
        });

        test('administrator menu contains Admin', () => {
            const items = navigation.MENU_CONFIG[router.ROLES.ADMINISTRATOR];
            const labels = items.map(i => i.label);
            expect(labels).toContain('Admin');
            expect(labels).toContain('Environmental');
            expect(labels).toContain('Cleansing');
            expect(labels).toContain('Audit Logs');
            expect(labels).toContain('Kiosk');
        });

        test('all menu items have route and icon', () => {
            Object.values(navigation.MENU_CONFIG).forEach(items => {
                items.forEach(item => {
                    expect(item.route).toBeTruthy();
                    expect(item.icon).toBeTruthy();
                    expect(item.label).toBeTruthy();
                });
            });
        });
    });

    describe('roleToCssClass', () => {
        test('returns correct class for each role', () => {
            expect(navigation.roleToCssClass(router.ROLES.CUSTOMER)).toBe('menu-role-customer');
            expect(navigation.roleToCssClass(router.ROLES.FRONT_DESK)).toBe('menu-role-frontdesk');
            expect(navigation.roleToCssClass(router.ROLES.TECHNICIAN)).toBe('menu-role-technician');
            expect(navigation.roleToCssClass(router.ROLES.STORE_MANAGER)).toBe('menu-role-storemanager');
            expect(navigation.roleToCssClass(router.ROLES.FINANCE)).toBe('menu-role-finance');
            expect(navigation.roleToCssClass(router.ROLES.ADMINISTRATOR)).toBe('menu-role-administrator');
        });

        test('returns empty string for unknown role', () => {
            expect(navigation.roleToCssClass('unknown')).toBe('');
        });
    });

    describe('getMenuItems', () => {
        test('returns customer menu items for customer role', () => {
            store.setRoles([router.ROLES.CUSTOMER]);
            const items = navigation.getMenuItems();
            expect(items.length).toBeGreaterThan(0);
            const routes = items.map(i => i.route);
            expect(routes).toContain('dashboard');
            expect(routes).toContain('kiosk');
        });

        test('returns empty array when no roles', () => {
            store.setRoles([]);
            const items = navigation.getMenuItems();
            expect(items).toEqual([]);
        });

        test('deduplicates menu items for multi-role user', () => {
            store.setRoles([router.ROLES.ADMINISTRATOR, router.ROLES.STORE_MANAGER]);
            const items = navigation.getMenuItems();
            const routes = items.map(i => i.route);
            // Check no duplicates
            const unique = [...new Set(routes)];
            expect(routes.length).toBe(unique.length);
        });

        test('returns admin-specific items for administrator', () => {
            store.setRoles([router.ROLES.ADMINISTRATOR]);
            const items = navigation.getMenuItems();
            const routes = items.map(i => i.route);
            expect(routes).toContain('admin');
        });

        test('technician does not get admin menu', () => {
            store.setRoles([router.ROLES.TECHNICIAN]);
            const items = navigation.getMenuItems();
            const routes = items.map(i => i.route);
            expect(routes).not.toContain('admin');
        });
    });

    describe('getPrimaryRoleClass', () => {
        test('returns administrator class for admin user', () => {
            store.setRoles([router.ROLES.ADMINISTRATOR]);
            expect(navigation.getPrimaryRoleClass()).toBe('menu-role-administrator');
        });

        test('returns highest privilege class for multi-role user', () => {
            store.setRoles([router.ROLES.CUSTOMER, router.ROLES.ADMINISTRATOR]);
            expect(navigation.getPrimaryRoleClass()).toBe('menu-role-administrator');
        });

        test('returns empty string for no roles', () => {
            store.setRoles([]);
            expect(navigation.getPrimaryRoleClass()).toBe('');
        });

        test('store_manager outranks technician', () => {
            store.setRoles([router.ROLES.TECHNICIAN, router.ROLES.STORE_MANAGER]);
            expect(navigation.getPrimaryRoleClass()).toBe('menu-role-storemanager');
        });
    });

    describe('render', () => {
        test('renders nothing to null container', () => {
            expect(() => navigation.render(null, 'dashboard')).not.toThrow();
        });

        test('renders empty for user with no roles', () => {
            store.setRoles([]);
            const container = { innerHTML: '' };
            navigation.render(container, 'dashboard');
            expect(container.innerHTML).toBe('');
        });

        test('renders HTML with nav items for administrator', () => {
            store.setRoles([router.ROLES.ADMINISTRATOR]);
            const container = { innerHTML: '' };
            navigation.render(container, 'dashboard');
            expect(container.innerHTML).toContain('layui-nav');
            expect(container.innerHTML).toContain('Admin');
            expect(container.innerHTML).toContain('#/admin');
        });

        test('marks active page with layui-this class', () => {
            store.setRoles([router.ROLES.ADMINISTRATOR]);
            const container = { innerHTML: '' };
            navigation.render(container, 'admin');
            expect(container.innerHTML).toContain('layui-this');
        });
    });
});
