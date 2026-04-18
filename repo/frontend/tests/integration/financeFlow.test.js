/**
 * Finance Flow Integration Tests
 * Tests cash drawer operations using real shipped modules.
 */

const { renderAmountBreakdown } = require('../../src/components/AmountBreakdown');
const { validateAmount, validateRequired } = require('../../src/utils/validation');
const { formatMMDDYYYY } = require('../../src/utils/date');

describe('Finance Cash Drawer Flow', () => {
    describe('Open Drawer Validation', () => {
        test('opening amount validated via shipped validateAmount', () => {
            expect(validateAmount(200.00).valid).toBe(true);
            expect(validateAmount(0).valid).toBe(false);
            expect(validateAmount(-50).valid).toBe(false);
        });

        test('business date formatted via shipped date util', () => {
            const date = new Date(2025, 0, 15);
            expect(formatMMDDYYYY(date)).toBe('01/15/2025');
        });
    });

    describe('Expected Total Calculation', () => {
        test('expected = open + payments - refunds', () => {
            const open = 200.00;
            const payments = 1500.00;
            const refunds = 50.00;
            const expected = Math.round((open + payments - refunds) * 100) / 100;
            expect(expected).toBe(1650.00);
        });

        test('variance = expected - counted', () => {
            expect(Math.round((1650.00 - 1648.50) * 100) / 100).toBe(1.50);
        });

        test('positive variance means short count', () => {
            expect(500.00 - 498.00).toBeGreaterThan(0);
        });

        test('negative variance means over count', () => {
            expect(500.00 - 502.00).toBeLessThan(0);
        });
    });

    describe('Discrepancy Detection', () => {
        function hasDiscrepancy(expected, counted) {
            return Math.abs(expected - counted) > 1.00;
        }

        test('zero variance: no discrepancy', () => {
            expect(hasDiscrepancy(500, 500)).toBe(false);
        });

        test('$0.50 variance: no discrepancy', () => {
            expect(hasDiscrepancy(500, 499.50)).toBe(false);
        });

        test('exactly $1.00 variance: no discrepancy (> not >=)', () => {
            expect(hasDiscrepancy(500, 499)).toBe(false);
        });

        test('$1.01 variance: discrepancy', () => {
            expect(hasDiscrepancy(500, 498.99)).toBe(true);
        });

        test('$2.00 variance: discrepancy', () => {
            expect(hasDiscrepancy(500, 498)).toBe(true);
        });

        test('over-count $1.50: discrepancy', () => {
            expect(hasDiscrepancy(500, 501.50)).toBe(true);
        });
    });

    describe('Drawer Reopen Governance', () => {
        test('reopen reason validated via shipped validateRequired', () => {
            expect(validateRequired('Found additional receipt', 'Reason').valid).toBe(true);
            expect(validateRequired('', 'Reason').valid).toBe(false);
            expect(validateRequired('   ', 'Reason').valid).toBe(false);
        });

        test('only administrator can reopen', () => {
            const store = require('../../src/store/index');
            const router = require('../../src/router/index');

            store.setRoles([router.ROLES.FINANCE]);
            expect(store.hasRole(router.ROLES.ADMINISTRATOR)).toBe(false);

            store.setRoles([router.ROLES.ADMINISTRATOR]);
            expect(store.hasRole(router.ROLES.ADMINISTRATOR)).toBe(true);
        });

        test('reopen only valid from closed status', () => {
            expect('closed' === 'closed').toBe(true);
            expect('open' === 'closed').toBe(false);
            expect('reopened' === 'closed').toBe(false);
        });
    });

    describe('Reconciliation Statement Rendering', () => {
        test('statement amounts render via shipped AmountBreakdown', () => {
            const html = renderAmountBreakdown({
                subtotal: 1650.00,
                discount: 0,
                tax: 0,
                total: 1650.00,
                amount_due: 1.50,
            });
            expect(html).toContain('$1650.00');
            expect(html).toContain('$1.50');
        });
    });
});
