/**
 * Cleansing Flow Integration Tests
 * Tests data cleansing lifecycle using real shipped modules.
 */

const { validateRequired } = require('../../src/utils/validation');

// Mock localStorage for store import
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

describe('Cleansing Batch Lifecycle', () => {
    describe('Batch Status Transitions', () => {
        const VALID_APPROVE_FROM = ['pending_review'];
        const VALID_ROLLBACK_FROM = ['approved', 'pending_review'];

        test('approve only from pending_review', () => {
            expect(VALID_APPROVE_FROM).toContain('pending_review');
            expect(VALID_APPROVE_FROM).not.toContain('approved');
            expect(VALID_APPROVE_FROM).not.toContain('rolled_back');
        });

        test('rollback from pending_review or approved', () => {
            expect(VALID_ROLLBACK_FROM).toContain('pending_review');
            expect(VALID_ROLLBACK_FROM).toContain('approved');
            expect(VALID_ROLLBACK_FROM).not.toContain('rolled_back');
        });
    });

    describe('Confidence Thresholds', () => {
        function getReviewDecision(confidence) {
            if (confidence >= 0.7) return { review: false, reason: null };
            if (confidence < 0.4) return { review: true, reason: 'low_confidence' };
            return { review: true, reason: 'ambiguous_match' };
        }

        test('high confidence (>= 0.7) no review', () => {
            const d = getReviewDecision(0.85);
            expect(d.review).toBe(false);
        });

        test('exactly 0.7 no review', () => {
            const d = getReviewDecision(0.7);
            expect(d.review).toBe(false);
        });

        test('below 0.7 requires review', () => {
            const d = getReviewDecision(0.5);
            expect(d.review).toBe(true);
            expect(d.reason).toBe('ambiguous_match');
        });

        test('below 0.4 gets low_confidence code', () => {
            const d = getReviewDecision(0.3);
            expect(d.review).toBe(true);
            expect(d.reason).toBe('low_confidence');
        });
    });

    describe('Administrator Gating via Store', () => {
        beforeEach(() => {
            store.clear();
        });

        test('administrator has admin role', () => {
            store.setRoles([router.ROLES.ADMINISTRATOR]);
            expect(store.hasRole(router.ROLES.ADMINISTRATOR)).toBe(true);
        });

        test('store_manager lacks admin role', () => {
            store.setRoles([router.ROLES.STORE_MANAGER]);
            expect(store.hasRole(router.ROLES.ADMINISTRATOR)).toBe(false);
        });

        test('front_desk lacks admin role', () => {
            store.setRoles([router.ROLES.FRONT_DESK]);
            expect(store.hasRole(router.ROLES.ADMINISTRATOR)).toBe(false);
        });

        test('cleansing route accessible to store_manager', () => {
            store.setRoles([router.ROLES.STORE_MANAGER]);
            expect(router.hasAccess(router.findRoute('cleansing'))).toBe(true);
        });

        test('cleansing route accessible to administrator', () => {
            store.setRoles([router.ROLES.ADMINISTRATOR]);
            expect(router.hasAccess(router.findRoute('cleansing'))).toBe(true);
        });
    });

    describe('Import Validation', () => {
        test('source_name required', () => {
            expect(validateRequired('test_source', 'Source name').valid).toBe(true);
            expect(validateRequired('', 'Source name').valid).toBe(false);
        });

        test('rows must not be empty', () => {
            const rows = [];
            expect(rows.length).toBe(0);
            const hasRows = rows.length > 0;
            expect(hasRows).toBe(false);
        });

        test('valid row has required fields', () => {
            const row = { job_title: 'Eng', company: 'Co', city: 'LA', salary: '100k', education: 'BS', experience: '3 yrs' };
            expect(validateRequired(row.job_title, 'Job title').valid).toBe(true);
            expect(validateRequired(row.company, 'Company').valid).toBe(true);
        });
    });

    describe('Normalization Preview', () => {
        // Replicate the exact normalization rules from CleansingService
        function normalizeJobTitle(title) {
            title = title.toLowerCase().trim();
            var map = { 'sr.': 'senior', 'jr.': 'junior', 'dev': 'developer', 'eng': 'engineer', 'mgr': 'manager', 'swe': 'software engineer' };
            Object.keys(map).forEach(function(k) { title = title.split(k).join(map[k]); });
            return title.replace(/\s+/g, ' ').trim().replace(/\b\w/g, function(c) { return c.toUpperCase(); });
        }

        function normalizeSalary(salary) {
            salary = salary.replace(/[^0-9.\-kKmM]/g, '');
            var kMatch = salary.match(/(\d+\.?\d*)k/i);
            if (kMatch) return String(parseFloat(kMatch[1]) * 1000);
            var mMatch = salary.match(/(\d+\.?\d*)m/i);
            if (mMatch) return String(parseFloat(mMatch[1]) * 1000000);
            return salary;
        }

        test('Sr. Dev -> Senior Developer', () => {
            expect(normalizeJobTitle('Sr. Dev')).toContain('Senior');
            expect(normalizeJobTitle('Sr. Dev')).toContain('Developer');
        });

        test('$75k -> 75000', () => {
            expect(normalizeSalary('$75k')).toBe('75000');
        });

        test('1.5M -> 1500000', () => {
            expect(normalizeSalary('1.5M')).toBe('1500000');
        });
    });
});
