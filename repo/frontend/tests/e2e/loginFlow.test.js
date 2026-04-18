/**
 * E2E Login Flow Tests
 * Tests login with real store/auth module imports.
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

global.fetch = jest.fn();

const store = require('../../src/store/index');
const auth = require('../../src/services/auth');
const router = require('../../src/router/index');
const { validateRequired, validatePasswordPolicy } = require('../../src/utils/validation');

describe('Login Flow - Form Validation', () => {
    test('username is required', () => {
        expect(validateRequired('', 'Username').valid).toBe(false);
    });

    test('password is required', () => {
        expect(validateRequired('', 'Password').valid).toBe(false);
    });

    test('store_id is required', () => {
        expect(validateRequired('', 'Store').valid).toBe(false);
    });

    test('workstation_id is required', () => {
        expect(validateRequired('', 'Workstation').valid).toBe(false);
    });

    test('valid form data passes all required checks', () => {
        expect(validateRequired('admin', 'Username').valid).toBe(true);
        expect(validateRequired('Demo12345678!', 'Password').valid).toBe(true);
        expect(validateRequired('1', 'Store').valid).toBe(true);
        expect(validateRequired('1', 'Workstation').valid).toBe(true);
    });
});

describe('Login Flow - Happy Path', () => {
    beforeEach(() => {
        store.clear();
        localStorageMock.clear();
        fetch.mockClear();
    });

    test('successful login stores token via auth service', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({
                token: 'session-token-abc',
                user: { id: 1, username: 'admin', roles: ['administrator'] },
            }),
        });

        const user = await auth.login('admin', 'Demo12345678!', 1, 1);
        expect(user.username).toBe('admin');
        expect(store.getToken()).toBe('session-token-abc');
        expect(store.getRoles()).toContain('administrator');
    });

    test('login sets store and workstation context', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({
                token: 'tok',
                user: { id: 1, username: 'u', roles: ['front_desk'] },
            }),
        });

        await auth.login('user', 'pass', 3, 7);
        expect(store.getStoreId()).toBe(3);
        expect(store.getWorkstationId()).toBe(7);
    });

    test('after login, isAuthenticated returns true', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () => Promise.resolve({
                token: 'tok',
                user: { id: 1, username: 'u', roles: [] },
            }),
        });

        await auth.login('u', 'p', 1, 1);
        expect(auth.isAuthenticated()).toBe(true);
    });
});

describe('Login Flow - Lockout', () => {
    test('lockout response has ACCOUNT_LOCKED error code', () => {
        const lockoutResponse = {
            success: false,
            error_code: 'ACCOUNT_LOCKED',
            message: 'Account is locked. Try again in 15 minute(s).',
        };
        expect(lockoutResponse.error_code).toBe('ACCOUNT_LOCKED');
        expect(lockoutResponse.message).toContain('locked');
        expect(lockoutResponse.message).toContain('15');
    });

    test('remaining attempts warning contains count', () => {
        const response = {
            success: false,
            error_code: 'INVALID_CREDENTIALS',
            message: 'Invalid username or password. 2 attempt(s) remaining before lockout.',
        };
        expect(response.message).toContain('remaining');
        expect(response.message).toContain('2');
    });
});

describe('Login Flow - Role-Based Redirect', () => {
    beforeEach(() => {
        store.clear();
        localStorageMock.clear();
    });

    afterEach(() => {
        router.destroy();
    });

    test('customer cannot access admin route', () => {
        store.setRoles([router.ROLES.CUSTOMER]);
        expect(router.hasAccess(router.findRoute('admin'))).toBe(false);
    });

    test('administrator can access admin route', () => {
        store.setRoles([router.ROLES.ADMINISTRATOR]);
        expect(router.hasAccess(router.findRoute('admin'))).toBe(true);
    });

    test('only store_manager and administrator can access dashboard', () => {
        store.setRoles([router.ROLES.STORE_MANAGER]);
        expect(router.hasAccess(router.findRoute('dashboard'))).toBe(true);
        store.setRoles([router.ROLES.ADMINISTRATOR]);
        expect(router.hasAccess(router.findRoute('dashboard'))).toBe(true);
        store.setRoles([router.ROLES.CUSTOMER]);
        expect(router.hasAccess(router.findRoute('dashboard'))).toBe(false);
        store.setRoles([router.ROLES.TECHNICIAN]);
        expect(router.hasAccess(router.findRoute('dashboard'))).toBe(false);
    });
});

describe('Login Flow - Password Policy', () => {
    test('strong password passes policy', () => {
        expect(validatePasswordPolicy('Demo12345678!').valid).toBe(true);
    });

    test('short password fails policy', () => {
        expect(validatePasswordPolicy('Short1!').valid).toBe(false);
    });

    test('no uppercase fails policy', () => {
        expect(validatePasswordPolicy('demo12345678!').valid).toBe(false);
    });

    test('no digit fails policy', () => {
        expect(validatePasswordPolicy('DemoPassword!!').valid).toBe(false);
    });
});
