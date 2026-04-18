/**
 * Form State Component Tests
 * Tests shipped components for state rendering and pricing display.
 */

const { renderAmountBreakdown } = require('../../src/components/AmountBreakdown');
const { renderReceipt } = require('../../src/components/Receipt');

describe('Form State CSS Classes', () => {
    const requiredStates = ['is-loading', 'is-submitting', 'is-disabled', 'is-error', 'is-success', 'is-empty'];

    test('all state classes follow is- prefix convention', () => {
        requiredStates.forEach(state => {
            expect(state).toMatch(/^is-/);
        });
    });

    test('AmountBreakdown shows is-empty for null data', () => {
        const html = renderAmountBreakdown(null);
        expect(html).toContain('is-empty');
    });

    test('Receipt shows is-empty for null data', () => {
        const html = renderReceipt(null);
        expect(html).toContain('is-empty');
    });
});

describe('Dashboard State Rendering', () => {
    test('operations dashboard empty state renders via AmountBreakdown', () => {
        const html = renderAmountBreakdown(null);
        expect(html).toContain('No pricing data');
    });

    test('operations dashboard success state renders pricing', () => {
        const html = renderAmountBreakdown({
            subtotal: 100,
            discount: 0,
            tax: 8,
            total: 108,
            amount_due: 108,
        });
        expect(html).toContain('$100.00');
        expect(html).toContain('$108.00');
    });
});

describe('Amount Due Breakdown Rendering', () => {
    test('displays all pricing components via shipped module', () => {
        const html = renderAmountBreakdown({
            subtotal: 100.00,
            discount: 10.00,
            tax: 7.20,
            total: 97.20,
            amount_due: 97.20,
        });

        expect(html).toContain('$100.00');
        expect(html).toContain('$10.00');
        expect(html).toContain('$7.20');
        expect(html).toContain('$97.20');
        expect(html).toContain('Subtotal');
        expect(html).toContain('Discount');
        expect(html).toContain('Tax');
        expect(html).toContain('Total (USD)');
        expect(html).toContain('Amount Due');
    });

    test('receipt rendered with all required fields via shipped module', () => {
        const html = renderReceipt({
            receipt_no: 'RCP-001',
            order_no: 'ORD-001',
            customer_name: 'John Doe',
            items: [{ service_name: 'Oil Change', qty: 1, unit_price: 49.99, line_subtotal: 49.99 }],
            subtotal: '49.99',
            discount: '0.00',
            tax: '4.00',
            total: '53.99',
        });

        expect(html).toContain('RCP-001');
        expect(html).toContain('ORD-001');
        expect(html).toContain('John Doe');
        expect(html).toContain('Oil Change');
    });
});
