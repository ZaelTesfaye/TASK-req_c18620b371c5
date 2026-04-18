/**
 * Receipt Component Tests
 * Tests real rendering logic of the Receipt component.
 */

const { renderReceipt } = require('../../src/components/Receipt');

describe('Receipt Component', () => {
    test('renders all receipt fields', () => {
        const receiptData = {
            receipt_no: 'RCP-20250115-ABCD',
            order_no: 'ORD-20250115-1234',
            customer_name: 'John Doe',
            confirmed_at: '01/15/2025',
            items: [
                { service_name: 'Oil Change', qty: 1, unit_price: 49.99, line_subtotal: 49.99 },
            ],
            subtotal: '49.99',
            discount: '0.00',
            tax: '4.00',
            total: '53.99',
            amount_due: '53.99',
            invoice_requested: false,
        };

        const html = renderReceipt(receiptData);
        expect(html).toContain('RCP-20250115-ABCD');
        expect(html).toContain('ORD-20250115-1234');
        expect(html).toContain('John Doe');
        expect(html).toContain('Oil Change');
        expect(html).toContain('$49.99');
        expect(html).toContain('$53.99');
    });

    test('renders empty state for null data', () => {
        const html = renderReceipt(null);
        expect(html).toContain('is-empty');
        expect(html).toContain('No receipt data available');
    });

    test('renders empty state for undefined data', () => {
        const html = renderReceipt(undefined);
        expect(html).toContain('is-empty');
    });

    test('renders multiple items', () => {
        const receiptData = {
            receipt_no: 'RCP-001',
            order_no: 'ORD-001',
            customer_name: 'Jane',
            items: [
                { service_name: 'Oil Change', qty: 1, unit_price: 49.99, line_subtotal: 49.99 },
                { service_name: 'Filter Replace', qty: 2, unit_price: 15.00, line_subtotal: 30.00 },
            ],
            subtotal: '79.99',
            discount: '0.00',
            tax: '6.40',
            total: '86.39',
            amount_due: '86.39',
        };

        const html = renderReceipt(receiptData);
        expect(html).toContain('Oil Change');
        expect(html).toContain('Filter Replace');
    });

    test('renders invoice requested note', () => {
        const receiptData = {
            receipt_no: 'RCP-001',
            order_no: 'ORD-001',
            customer_name: 'Corp User',
            items: [],
            subtotal: '100.00',
            discount: '0.00',
            tax: '8.00',
            total: '108.00',
            amount_due: '108.00',
            invoice_requested: true,
        };

        const html = renderReceipt(receiptData);
        expect(html).toContain('Invoice Requested');
    });

    test('does not render invoice note when not requested', () => {
        const receiptData = {
            receipt_no: 'RCP-001',
            order_no: 'ORD-001',
            customer_name: 'User',
            items: [],
            subtotal: '50.00',
            discount: '0.00',
            tax: '4.00',
            total: '54.00',
            amount_due: '54.00',
            invoice_requested: false,
        };

        const html = renderReceipt(receiptData);
        expect(html).not.toContain('Invoice Requested');
    });

    test('contains correct CSS classes', () => {
        const receiptData = {
            receipt_no: 'RCP-001',
            order_no: 'ORD-001',
            customer_name: 'User',
            items: [{ service_name: 'Test', qty: 1, unit_price: 10, line_subtotal: 10 }],
            subtotal: '10.00',
            discount: '0.00',
            tax: '0.80',
            total: '10.80',
            amount_due: '10.80',
        };
        const html = renderReceipt(receiptData);
        expect(html).toContain('receipt-container');
        expect(html).toContain('receipt-header');
        expect(html).toContain('receipt-meta');
        expect(html).toContain('receipt-customer');
        expect(html).toContain('receipt-items');
        expect(html).toContain('receipt-totals');
        expect(html).toContain('receipt-total');
        expect(html).toContain('receipt-due');
    });

    test('displays N/A for missing fields', () => {
        const receiptData = {
            items: [],
        };
        const html = renderReceipt(receiptData);
        expect(html).toContain('N/A');
    });

    test('handles items with missing unit_price', () => {
        const receiptData = {
            receipt_no: 'RCP-001',
            order_no: 'ORD-001',
            customer_name: 'User',
            items: [{ service_name: 'Test', qty: 1 }],
            subtotal: '0.00',
            discount: '0.00',
            tax: '0.00',
            total: '0.00',
            amount_due: '0.00',
        };
        const html = renderReceipt(receiptData);
        expect(html).toContain('$0.00');
    });

    test('renders Service Receipt heading', () => {
        const receiptData = {
            receipt_no: 'RCP-001',
            order_no: 'ORD-001',
            customer_name: 'User',
            items: [],
            subtotal: '0.00',
            discount: '0.00',
            tax: '0.00',
            total: '0.00',
        };
        const html = renderReceipt(receiptData);
        expect(html).toContain('Service Receipt');
    });
});
