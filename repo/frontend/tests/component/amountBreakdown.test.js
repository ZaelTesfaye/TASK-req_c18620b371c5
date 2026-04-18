/**
 * AmountBreakdown Component Tests
 * Tests real rendering logic of the AmountBreakdown component.
 */

const { renderAmountBreakdown } = require('../../src/components/AmountBreakdown');

describe('AmountBreakdown Component', () => {
    test('renders all pricing fields', () => {
        const pricing = {
            subtotal: 100.00,
            discount: 10.00,
            tax: 7.20,
            total: 97.20,
            amount_due: 97.20,
        };

        const html = renderAmountBreakdown(pricing);
        expect(html).toContain('$100.00');
        expect(html).toContain('$10.00');
        expect(html).toContain('$7.20');
        expect(html).toContain('$97.20');
    });

    test('renders empty state for null pricing', () => {
        const html = renderAmountBreakdown(null);
        expect(html).toContain('is-empty');
        expect(html).toContain('No pricing data');
    });

    test('renders empty state for undefined pricing', () => {
        const html = renderAmountBreakdown(undefined);
        expect(html).toContain('is-empty');
    });

    test('formats zero amounts correctly', () => {
        const pricing = {
            subtotal: 0,
            discount: 0,
            tax: 0,
            total: 0,
            amount_due: 0,
        };
        const html = renderAmountBreakdown(pricing);
        expect(html).toContain('$0.00');
    });

    test('contains correct CSS class names', () => {
        const pricing = { subtotal: 50, discount: 5, tax: 3.60, total: 48.60, amount_due: 48.60 };
        const html = renderAmountBreakdown(pricing);
        expect(html).toContain('amount-breakdown');
        expect(html).toContain('breakdown-row');
        expect(html).toContain('breakdown-label');
        expect(html).toContain('breakdown-value');
        expect(html).toContain('total-row');
        expect(html).toContain('due-row');
        expect(html).toContain('amount-due');
    });

    test('contains label text', () => {
        const pricing = { subtotal: 100, discount: 0, tax: 8, total: 108, amount_due: 108 };
        const html = renderAmountBreakdown(pricing);
        expect(html).toContain('Subtotal:');
        expect(html).toContain('Discount:');
        expect(html).toContain('Tax:');
        expect(html).toContain('Total (USD):');
        expect(html).toContain('Amount Due:');
    });

    test('handles string amounts', () => {
        const pricing = { subtotal: '49.99', discount: '0', tax: '4.00', total: '53.99', amount_due: '53.99' };
        const html = renderAmountBreakdown(pricing);
        expect(html).toContain('$49.99');
        expect(html).toContain('$53.99');
    });

    test('handles NaN gracefully by showing $0.00', () => {
        const pricing = { subtotal: 'abc', discount: null, tax: undefined, total: NaN, amount_due: '' };
        const html = renderAmountBreakdown(pricing);
        expect(html).toContain('$0.00');
    });

    test('renders discount with negative sign', () => {
        const pricing = { subtotal: 100, discount: 15, tax: 6.80, total: 91.80, amount_due: 91.80 };
        const html = renderAmountBreakdown(pricing);
        expect(html).toContain('-$15.00');
    });

    test('formats large amounts correctly', () => {
        const pricing = { subtotal: 99999.99, discount: 0, tax: 7999.99, total: 107999.98, amount_due: 107999.98 };
        const html = renderAmountBreakdown(pricing);
        expect(html).toContain('$99999.99');
    });
});
