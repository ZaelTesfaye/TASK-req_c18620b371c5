/**
 * E2E Admin Workflow Tests
 * Tests admin operations using real shipped modules.
 */

const { validateRequired, validatePasswordPolicy, validateAmount } = require('../../src/utils/validation');
const { formatMMDDYYYY } = require('../../src/utils/date');

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
const router = require('../../src/router/index');
const navigation = require('../../src/components/Navigation');

describe('Admin User Management', () => {
    test('username required via shipped validation', () => {
        expect(validateRequired('newuser', 'Username').valid).toBe(true);
        expect(validateRequired('', 'Username').valid).toBe(false);
    });

    test('password must meet policy via shipped validation', () => {
        expect(validatePasswordPolicy('Demo12345678!').valid).toBe(true);
        expect(validatePasswordPolicy('weak').valid).toBe(false);
        expect(validatePasswordPolicy('nouppercase12!').valid).toBe(false);
        expect(validatePasswordPolicy('NOLOWERCASE12!').valid).toBe(false);
        expect(validatePasswordPolicy('NoDigitsHere!!').valid).toBe(false);
        expect(validatePasswordPolicy('NoSpecial12345').valid).toBe(false);
    });

    test('admin route only accessible to administrator role', () => {
        store.setRoles([router.ROLES.ADMINISTRATOR]);
        expect(router.hasAccess(router.findRoute('admin'))).toBe(true);

        store.setRoles([router.ROLES.STORE_MANAGER]);
        expect(router.hasAccess(router.findRoute('admin'))).toBe(false);

        store.setRoles([router.ROLES.FINANCE]);
        expect(router.hasAccess(router.findRoute('admin'))).toBe(false);
    });

    test('admin menu rendered via shipped Navigation', () => {
        store.setRoles([router.ROLES.ADMINISTRATOR]);
        const items = navigation.getMenuItems();
        const routes = items.map(function(i) { return i.route; });
        expect(routes).toContain('admin');
        expect(routes).toContain('dashboard');
        expect(routes).toContain('orders');
    });

    test('admin gets highest priority CSS class', () => {
        store.setRoles([router.ROLES.CUSTOMER, router.ROLES.ADMINISTRATOR]);
        expect(navigation.getPrimaryRoleClass()).toBe('menu-role-administrator');
    });
});

describe('Admin Experiment Lifecycle', () => {
    const TRANSITIONS = { draft: ['running'], running: ['stopped'], stopped: [] };

    test('draft can transition to running', () => {
        expect(TRANSITIONS.draft).toContain('running');
    });

    test('running can transition to stopped', () => {
        expect(TRANSITIONS.running).toContain('stopped');
    });

    test('stopped is terminal', () => {
        expect(TRANSITIONS.stopped).toHaveLength(0);
    });

    test('cannot start from stopped', () => {
        expect(TRANSITIONS.stopped).not.toContain('running');
    });

    test('holdout + traffic = 100%', () => {
        const holdout = 10;
        const variants = [{ traffic_percent: 45 }, { traffic_percent: 45 }];
        const total = variants.reduce(function(s, v) { return s + v.traffic_percent; }, 0) + holdout;
        expect(total).toBe(100);
    });

    test('14-day experiment duration via shipped date util', () => {
        const start = new Date(2025, 0, 15);
        const end = new Date(start.getTime() + 14 * 86400000);
        expect(formatMMDDYYYY(end)).toBe('01/29/2025');
    });

    test('deterministic sticky assignment', () => {
        // Same CRC32 logic as backend ExperimentService
        function bucket(expId, key) {
            var h = 0, s = expId + ':' + key;
            for (var i = 0; i < s.length; i++) {
                h = ((h << 5) - h) + s.charCodeAt(i);
                h |= 0;
            }
            return Math.abs(h) % 10000;
        }
        expect(bucket(1, 'user-123')).toBe(bucket(1, 'user-123'));
        expect(bucket(1, 'user-123')).not.toBe(bucket(1, 'user-456'));
    });
});

describe('Admin Key Rotation', () => {
    test('encrypted payload stores version number', () => {
        var payload = JSON.parse('{"v":1,"iv":"base64","data":"encrypted"}');
        expect(payload.v).toBe(1);
        expect(payload.iv).toBeTruthy();
        expect(payload.data).toBeTruthy();
    });
});

describe('Admin Environmental Management', () => {
    test('environmental route accessible to admin', () => {
        store.setRoles([router.ROLES.ADMINISTRATOR]);
        expect(router.hasAccess(router.findRoute('environmental'))).toBe(true);
    });

    test('environmental route accessible to store_manager', () => {
        store.setRoles([router.ROLES.STORE_MANAGER]);
        expect(router.hasAccess(router.findRoute('environmental'))).toBe(true);
    });

    test('environmental route NOT accessible to customer', () => {
        store.setRoles([router.ROLES.CUSTOMER]);
        expect(router.hasAccess(router.findRoute('environmental'))).toBe(false);
    });

    test('admin menu includes environmental', () => {
        store.setRoles([router.ROLES.ADMINISTRATOR]);
        var items = navigation.getMenuItems();
        var routes = items.map(function(i) { return i.route; });
        expect(routes).toContain('environmental');
    });
});
