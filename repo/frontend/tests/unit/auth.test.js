/**
 * Auth Service Unit Tests
 * Tests login, logout, getMe, token management, role retrieval.
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

// Mock fetch globally
global.fetch = jest.fn();

const store = require('../../src/store/index');
const auth = require('../../src/services/auth');

describe('Auth Service', () => {
    beforeEach(() => {
        store.clear();
        localStorageMock.clear();
        fetch.mockClear();
    });

    describe('login', () => {
        test('stores token and user on successful login', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({
                    token: 'abc123',
                    user: { id: 1, username: 'admin', roles: ['administrator'] },
                }),
            });

            const user = await auth.login('admin', 'Demo12345678!', 1, 1);
            expect(user.username).toBe('admin');
            expect(store.getToken()).toBe('abc123');
            expect(store.getUser()).toEqual({ id: 1, username: 'admin', roles: ['administrator'] });
            expect(store.getRoles()).toEqual(['administrator']);
        });

        test('stores storeId and workstationId on login', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({
                    token: 'xyz789',
                    user: { id: 2, username: 'tech', roles: ['technician'] },
                }),
            });

            await auth.login('tech', 'pass', 3, 5);
            expect(store.getStoreId()).toBe(3);
            expect(store.getWorkstationId()).toBe(5);
        });

        test('sends correct payload to auth/login endpoint', async () => {
            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({
                    token: 'tok',
                    user: { id: 1, username: 'u', roles: [] },
                }),
            });

            await auth.login('admin', 'pass123', 2, 4);
            const body = JSON.parse(fetch.mock.calls[0][1].body);
            expect(body.username).toBe('admin');
            expect(body.password).toBe('pass123');
            expect(body.store_id).toBe(2);
            expect(body.workstation_id).toBe(4);
        });
    });

    describe('logout', () => {
        test('clears store on logout', async () => {
            store.setToken('token');
            store.setUser({ id: 1 });
            store.setRoles(['administrator']);

            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({}),
            });

            await auth.logout();
            expect(store.getToken()).toBeNull();
            expect(store.getUser()).toBeNull();
            expect(store.getRoles()).toEqual([]);
        });

        test('clears store even if logout API fails', async () => {
            store.setToken('token');
            fetch.mockRejectedValueOnce(new Error('Network error'));

            await auth.logout();
            expect(store.getToken()).toBeNull();
        });
    });

    describe('getMe', () => {
        test('updates store with user data from /auth/me', async () => {
            store.setToken('valid-token');

            fetch.mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () => Promise.resolve({
                    user: { id: 1, username: 'admin', roles: ['administrator', 'store_manager'] },
                }),
            });

            const user = await auth.getMe();
            expect(user.username).toBe('admin');
            expect(store.getRoles()).toEqual(['administrator', 'store_manager']);
        });
    });

    describe('isAuthenticated', () => {
        test('returns false when no token', () => {
            expect(auth.isAuthenticated()).toBe(false);
        });

        test('returns true when token exists', () => {
            store.setToken('some-token');
            expect(auth.isAuthenticated()).toBe(true);
        });
    });

    describe('getToken / setToken / clearToken', () => {
        test('getToken returns current token', () => {
            store.setToken('test-token');
            expect(auth.getToken()).toBe('test-token');
        });

        test('setToken persists token', () => {
            auth.setToken('new-token');
            expect(store.getToken()).toBe('new-token');
        });

        test('clearToken removes token', () => {
            store.setToken('token');
            auth.clearToken();
            expect(store.getToken()).toBeNull();
        });
    });

    describe('getRoles', () => {
        test('returns current roles', () => {
            store.setRoles(['finance', 'administrator']);
            expect(auth.getRoles()).toEqual(['finance', 'administrator']);
        });

        test('returns empty array when no roles', () => {
            expect(auth.getRoles()).toEqual([]);
        });
    });
});
