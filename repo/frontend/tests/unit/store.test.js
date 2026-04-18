/**
 * Store Unit Tests
 * Tests state management for user session data.
 */

// Mock localStorage for jsdom
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

describe('Store', () => {
    beforeEach(() => {
        store.clear();
        localStorageMock.clear();
    });

    test('initializes with null values', () => {
        expect(store.getUser()).toBeNull();
        expect(store.getToken()).toBeNull();
        expect(store.getRoles()).toEqual([]);
    });

    test('sets and gets user', () => {
        store.setUser({ id: 1, username: 'admin' });
        expect(store.getUser()).toEqual({ id: 1, username: 'admin' });
    });

    test('sets and gets token', () => {
        store.setToken('abc123');
        expect(store.getToken()).toBe('abc123');
    });

    test('sets and gets roles', () => {
        store.setRoles(['administrator', 'store_manager']);
        expect(store.getRoles()).toEqual(['administrator', 'store_manager']);
    });

    test('sets and gets store context', () => {
        store.setStoreId(1);
        store.setWorkstationId(2);
        expect(store.getStoreId()).toBe(1);
        expect(store.getWorkstationId()).toBe(2);
    });

    test('clear resets all state', () => {
        store.setUser({ id: 1 });
        store.setToken('token');
        store.setRoles(['administrator']);
        store.clear();
        expect(store.getUser()).toBeNull();
        expect(store.getToken()).toBeNull();
        expect(store.getRoles()).toEqual([]);
    });

    test('hasRole checks correctly', () => {
        store.setRoles(['administrator', 'finance']);
        expect(store.hasRole('administrator')).toBe(true);
        expect(store.hasRole('technician')).toBe(false);
    });

    test('isAuthenticated returns true when token exists', () => {
        store.setToken('valid-token');
        expect(store.isAuthenticated()).toBe(true);
    });

    test('isAuthenticated returns false when no token', () => {
        expect(store.isAuthenticated()).toBe(false);
    });
});
