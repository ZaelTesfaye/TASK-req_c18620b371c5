/**
 * Router Unit Tests
 * Tests route definitions, role checks, hash parsing, auth guards.
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

describe('Router', () => {
    beforeEach(() => {
        store.clear();
        localStorageMock.clear();
    });

    afterEach(() => {
        router.destroy();
    });

    describe('ROLES constants', () => {
        test('defines all six roles as snake_case codes matching backend', () => {
            expect(router.ROLES.CUSTOMER).toBe('customer');
            expect(router.ROLES.FRONT_DESK).toBe('front_desk');
            expect(router.ROLES.TECHNICIAN).toBe('technician');
            expect(router.ROLES.STORE_MANAGER).toBe('store_manager');
            expect(router.ROLES.FINANCE).toBe('finance');
            expect(router.ROLES.ADMINISTRATOR).toBe('administrator');
        });

        test('has ROLE_LABELS for UI display', () => {
            expect(router.ROLE_LABELS['customer']).toBe('Customer');
            expect(router.ROLE_LABELS['front_desk']).toBe('Front Desk');
            expect(router.ROLE_LABELS['administrator']).toBe('Administrator');
        });

        test('ALL_ROLES contains all six', () => {
            expect(router.ALL_ROLES).toHaveLength(6);
        });
    });

    describe('findRoute', () => {
        test('finds login route', () => {
            const route = router.findRoute('login');
            expect(route).not.toBeNull();
            expect(route.page).toBe('login');
            expect(route.auth).toBe(false);
        });

        test('finds orders route with correct roles', () => {
            const route = router.findRoute('orders');
            expect(route).not.toBeNull();
            expect(route.auth).toBe(true);
            expect(route.roles).toContain(router.ROLES.CUSTOMER);
            expect(route.roles).toContain(router.ROLES.FRONT_DESK);
            expect(route.roles).toContain(router.ROLES.ADMINISTRATOR);
        });

        test('finds admin route restricted to administrator', () => {
            const route = router.findRoute('admin');
            expect(route).not.toBeNull();
            expect(route.roles).toEqual([router.ROLES.ADMINISTRATOR]);
        });

        test('returns null for unknown route', () => {
            expect(router.findRoute('nonexistent')).toBeNull();
        });

        test('finds finance route with correct roles', () => {
            const route = router.findRoute('finance');
            expect(route.roles).toContain(router.ROLES.FINANCE);
            expect(route.roles).toContain(router.ROLES.STORE_MANAGER);
            expect(route.roles).toContain(router.ROLES.ADMINISTRATOR);
        });

        test('finds environmental route with correct roles', () => {
            const route = router.findRoute('environmental');
            expect(route.roles).toContain(router.ROLES.STORE_MANAGER);
            expect(route.roles).toContain(router.ROLES.ADMINISTRATOR);
            expect(route.roles).not.toContain(router.ROLES.CUSTOMER);
        });

        test('finds audit-logs route with correct roles', () => {
            const route = router.findRoute('audit-logs');
            expect(route.roles).toContain(router.ROLES.STORE_MANAGER);
            expect(route.roles).toContain(router.ROLES.FINANCE);
            expect(route.roles).toContain(router.ROLES.ADMINISTRATOR);
        });

        test('finds cleansing route', () => {
            const route = router.findRoute('cleansing');
            expect(route).not.toBeNull();
            expect(route.auth).toBe(true);
        });

        test('finds kiosk route', () => {
            const route = router.findRoute('kiosk');
            expect(route).not.toBeNull();
            expect(route.roles).toContain(router.ROLES.CUSTOMER);
        });

        test('finds technician-queue route', () => {
            const route = router.findRoute('technician-queue');
            expect(route.roles).toContain(router.ROLES.TECHNICIAN);
        });

        test('finds dashboard route restricted to store_manager and administrator', () => {
            const route = router.findRoute('dashboard');
            expect(route.roles).toContain(router.ROLES.STORE_MANAGER);
            expect(route.roles).toContain(router.ROLES.ADMINISTRATOR);
            expect(route.roles).not.toContain(router.ROLES.CUSTOMER);
            expect(route.roles).not.toContain(router.ROLES.TECHNICIAN);
        });
    });

    describe('hasAccess', () => {
        test('returns true when roles is null (public route)', () => {
            const route = { path: 'login', auth: false, roles: null };
            expect(router.hasAccess(route)).toBe(true);
        });

        test('returns true when user has matching role', () => {
            store.setRoles([router.ROLES.ADMINISTRATOR]);
            const route = router.findRoute('admin');
            expect(router.hasAccess(route)).toBe(true);
        });

        test('returns false when user lacks required role', () => {
            store.setRoles([router.ROLES.CUSTOMER]);
            const route = router.findRoute('admin');
            expect(router.hasAccess(route)).toBe(false);
        });

        test('returns true with multi-role user if any role matches', () => {
            store.setRoles([router.ROLES.TECHNICIAN, router.ROLES.STORE_MANAGER]);
            const route = router.findRoute('environmental');
            expect(router.hasAccess(route)).toBe(true);
        });

        test('customer cannot access finance route', () => {
            store.setRoles([router.ROLES.CUSTOMER]);
            const route = router.findRoute('finance');
            expect(router.hasAccess(route)).toBe(false);
        });

        test('technician cannot access admin route', () => {
            store.setRoles([router.ROLES.TECHNICIAN]);
            const route = router.findRoute('admin');
            expect(router.hasAccess(route)).toBe(false);
        });

        test('finance user can access audit-logs', () => {
            store.setRoles([router.ROLES.FINANCE]);
            const route = router.findRoute('audit-logs');
            expect(router.hasAccess(route)).toBe(true);
        });

        test('front_desk can access orders', () => {
            store.setRoles([router.ROLES.FRONT_DESK]);
            const route = router.findRoute('orders');
            expect(router.hasAccess(route)).toBe(true);
        });
    });

    describe('getRoutes', () => {
        test('returns a copy of routes array', () => {
            const routes = router.getRoutes();
            expect(Array.isArray(routes)).toBe(true);
            expect(routes.length).toBeGreaterThan(0);
        });

        test('returns all defined routes', () => {
            const routes = router.getRoutes();
            const paths = routes.map(r => r.path);
            expect(paths).toContain('login');
            expect(paths).toContain('dashboard');
            expect(paths).toContain('orders');
            expect(paths).toContain('admin');
            expect(paths).toContain('finance');
            expect(paths).toContain('environmental');
            expect(paths).toContain('cleansing');
            expect(paths).toContain('audit-logs');
            expect(paths).toContain('kiosk');
            expect(paths).toContain('technician-queue');
        });

        test('all protected routes require auth', () => {
            const routes = router.getRoutes();
            routes.forEach(route => {
                if (route.path !== 'login') {
                    expect(route.auth).toBe(true);
                }
            });
        });
    });

    describe('getCurrentRoute', () => {
        test('returns null before initialization', () => {
            expect(router.getCurrentRoute()).toBeNull();
        });
    });

    describe('destroy', () => {
        test('cleans up router state', () => {
            router.destroy();
            expect(router.getCurrentRoute()).toBeNull();
        });
    });
});
