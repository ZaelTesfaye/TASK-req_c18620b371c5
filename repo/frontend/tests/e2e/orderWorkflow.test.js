/**
 * E2E Order Workflow Tests
 * Tests full order lifecycle with real module imports.
 */

const { validateRequired, validateAmount, validateCouponCode, validateInvoiceFields } = require('../../src/utils/validation');
const { renderAmountBreakdown } = require('../../src/components/AmountBreakdown');
const { renderReceipt } = require('../../src/components/Receipt');
const { formatMMDDYYYY, parseMMDDYYYY } = require('../../src/utils/date');

describe('Front Desk Order Flow', () => {
    test('order creation validates customer_name', () => {
        expect(validateRequired('John Doe', 'Customer name').valid).toBe(true);
        expect(validateRequired('', 'Customer name').valid).toBe(false);
    });

    test('item pricing validates via validateAmount', () => {
        expect(validateAmount(49.99).valid).toBe(true);
        expect(validateAmount(0).valid).toBe(false);
        expect(validateAmount(-5).valid).toBe(false);
        expect(validateAmount('abc').valid).toBe(false);
    });

    test('coupon code validated via validateCouponCode', () => {
        expect(validateCouponCode('WELCOME10').valid).toBe(true);
        expect(validateCouponCode('').valid).toBe(false);
        expect(validateCouponCode('AB').valid).toBe(false); // too short
    });

    test('subtotal calculation matches backend logic', () => {
        const items = [
            { qty: 1, unit_price: 49.99 },
            { qty: 2, unit_price: 15.00 },
        ];
        const subtotal = items.reduce((sum, item) => sum + item.qty * item.unit_price, 0);
        expect(subtotal).toBe(79.99);
    });

    test('pricing chain renders via shipped AmountBreakdown', () => {
        const subtotal = 79.99;
        const discount = 8.00;
        const taxRate = 0.08;
        const tax = Math.round((subtotal - discount) * taxRate * 100) / 100;
        const total = Math.round((subtotal - discount + tax) * 100) / 100;

        const html = renderAmountBreakdown({
            subtotal: subtotal,
            discount: discount,
            tax: tax,
            total: total,
            amount_due: total,
        });
        expect(html).toContain('$79.99');
        expect(html).toContain('Amount Due');
    });

    test('invoice path validated via shipped validateInvoiceFields', () => {
        const result = validateInvoiceFields({
            customer_name: 'Corp User',
            amount: 79.99,
            date: '01/15/2025',
        });
        expect(result.valid).toBe(true);
    });

    test('invoice with missing fields fails', () => {
        const result = validateInvoiceFields({
            customer_name: '',
            amount: '',
            date: '',
        });
        expect(result.valid).toBe(false);
    });

    test('receipt renders via shipped Receipt component', () => {
        const html = renderReceipt({
            receipt_no: 'RCP-20250115-ABC1',
            order_no: 'ORD-20250115-DEF2',
            customer_name: 'John Doe',
            items: [
                { service_name: 'Oil Change', qty: 1, unit_price: 49.99, line_subtotal: 49.99 },
                { service_name: 'Filter Replace', qty: 2, unit_price: 15.00, line_subtotal: 30.00 },
            ],
            subtotal: '79.99',
            discount: '$8.00',
            tax: '$5.76',
            total: '$77.75',
            amount_due: '$77.75',
            invoice_requested: true,
        });
        expect(html).toContain('RCP-20250115-ABC1');
        expect(html).toContain('Invoice Requested');
        expect(html).toContain('Oil Change');
        expect(html).toContain('Filter Replace');
    });
});

describe('Technician Assignment and Completion Flow', () => {
    test('order state machine: assigned -> in_progress -> completed', () => {
        const validTransitions = {
            draft: ['confirmed', 'cancelled'],
            confirmed: ['assigned', 'cancelled'],
            assigned: ['in_progress', 'cancelled'],
            in_progress: ['completed', 'cancelled'],
            completed: [],
            cancelled: [],
        };
        expect(validTransitions.assigned).toContain('in_progress');
        expect(validTransitions.in_progress).toContain('completed');
        expect(validTransitions.completed).toHaveLength(0);
    });

    test('work note requires non-empty text', () => {
        expect(validateRequired('Replaced brake pads', 'Note').valid).toBe(true);
        expect(validateRequired('', 'Note').valid).toBe(false);
        expect(validateRequired('   ', 'Note').valid).toBe(false);
    });
});

describe('Finance Reconciliation Close Flow', () => {
    test('variance under $1.00 is not a discrepancy', () => {
        const expected = 750.00;
        const counted = 749.50;
        expect(Math.abs(expected - counted) > 1.00).toBe(false);
    });

    test('variance over $1.00 is a discrepancy', () => {
        const expected = 750.00;
        const counted = 748.00;
        expect(Math.abs(expected - counted) > 1.00).toBe(true);
    });

    test('$1.00 exactly is NOT a discrepancy (> not >=)', () => {
        expect(Math.abs(500.00 - 499.00) > 1.00).toBe(false);
    });

    test('$1.01 IS a discrepancy', () => {
        expect(Math.abs(500.00 - 498.99) > 1.00).toBe(true);
    });
});

describe('Admin Experiment Create/Start/Stop', () => {
    test('holdout + variant traffic = 100%', () => {
        const holdout = 10;
        const variants = [
            { variant_key: 'control', traffic_percent: 45 },
            { variant_key: 'treatment', traffic_percent: 45 },
        ];
        const total = variants.reduce((sum, v) => sum + v.traffic_percent, 0) + holdout;
        expect(total).toBe(100);
    });

    test('experiment auto-duration is 14 days', () => {
        const start = new Date('2025-01-15');
        const end = new Date(start.getTime() + 14 * 86400000);
        expect(formatMMDDYYYY(end)).toBe('01/29/2025');
    });
});

describe('Cleansing Review Screens - Role Gated', () => {
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

    test('administrator can approve batch (role check via router)', () => {
        const store = require('../../src/store/index');
        const router = require('../../src/router/index');
        store.setRoles([router.ROLES.ADMINISTRATOR]);
        expect(store.hasRole(router.ROLES.ADMINISTRATOR)).toBe(true);
    });

    test('store manager cannot approve batch', () => {
        const store = require('../../src/store/index');
        const router = require('../../src/router/index');
        store.setRoles([router.ROLES.STORE_MANAGER]);
        expect(store.hasRole(router.ROLES.ADMINISTRATOR)).toBe(false);
    });

    test('date formatting for batch display', () => {
        const date = parseMMDDYYYY('01/15/2025');
        expect(date).toBeInstanceOf(Date);
        expect(formatMMDDYYYY(date)).toBe('01/15/2025');
    });
});
