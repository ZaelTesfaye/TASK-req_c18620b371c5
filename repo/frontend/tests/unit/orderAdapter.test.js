/**
 * orderAdapter Unit Tests
 *
 * Pin the backend payload → UI field mapping so renaming or dropping
 * canonical fields on the backend is caught without touching every page.
 */

const adapter = require('../../src/utils/orderAdapter');

describe('orderAdapter.normalizeOrder', () => {
    test('preserves canonical backend fields verbatim', () => {
        const raw = {
            id: 42,
            order_no: 'ORD-20260101-ABC',
            subtotal_amount: 100.5,
            discount_amount: 5,
            tax_amount: 7.64,
            total_amount: 103.14,
            amount_due: 103.14,
        };
        const out = adapter.normalizeOrder(raw);
        expect(out.subtotal_amount).toBe(100.5);
        expect(out.discount_amount).toBe(5);
        expect(out.tax_amount).toBe(7.64);
        expect(out.total_amount).toBe(103.14);
        expect(out.amount_due).toBe(103.14);
    });

    test('coerces string amounts to numbers', () => {
        const out = adapter.normalizeOrder({
            subtotal_amount: '49.99',
            discount_amount: '0',
            tax_amount: '4.00',
            total_amount: '53.99',
            amount_due: '53.99',
        });
        expect(out.total_amount).toBe(53.99);
        expect(out.subtotal_amount).toBe(49.99);
    });

    test('falls back to legacy short field names when canonical missing', () => {
        const out = adapter.normalizeOrder({ subtotal: 10, tax: 0.8, total: 10.8 });
        expect(out.subtotal_amount).toBe(10);
        expect(out.tax_amount).toBe(0.8);
        expect(out.total_amount).toBe(10.8);
    });

    test('defaults missing amounts to 0 rather than undefined', () => {
        const out = adapter.normalizeOrder({ id: 1 });
        expect(out.total_amount).toBe(0);
        expect(out.amount_due).toBe(0);
    });
});

describe('orderAdapter.normalizeOrderList', () => {
    test('normalizes every row in the list', () => {
        const list = [
            { total_amount: 10 },
            { total_amount: 20 },
        ];
        const out = adapter.normalizeOrderList(list);
        expect(out).toHaveLength(2);
        expect(out[0].total_amount).toBe(10);
        expect(out[1].total_amount).toBe(20);
    });

    test('returns empty array when input is not an array', () => {
        expect(adapter.normalizeOrderList(null)).toEqual([]);
        expect(adapter.normalizeOrderList(undefined)).toEqual([]);
        expect(adapter.normalizeOrderList({})).toEqual([]);
    });
});

describe('orderAdapter.normalizeItem', () => {
    test('preserves backend item shape (qty, unit_price, line_subtotal)', () => {
        const out = adapter.normalizeItem({
            service_code: 'SVC-001',
            service_name: 'Oil Change',
            qty: 2,
            unit_price: 49.99,
            line_subtotal: 99.98,
        });
        expect(out.qty).toBe(2);
        expect(out.unit_price).toBe(49.99);
        expect(out.line_subtotal).toBe(99.98);
        expect(out.service_name).toBe('Oil Change');
        expect(out.service_code).toBe('SVC-001');
    });

    test('falls back to legacy quantity/amount fields', () => {
        const out = adapter.normalizeItem({
            name: 'Tire Rotation',
            quantity: 3,
            unit_price: 10,
            amount: 30,
        });
        expect(out.qty).toBe(3);
        expect(out.unit_price).toBe(10);
        expect(out.line_subtotal).toBe(30);
        expect(out.service_name).toBe('Tire Rotation');
    });

    test('derives line_subtotal from qty * unit_price when missing', () => {
        const out = adapter.normalizeItem({ service_name: 'X', qty: 4, unit_price: 2.5 });
        expect(out.line_subtotal).toBe(10);
    });

    test('accepts local kiosk selection shape { price, service_name }', () => {
        const out = adapter.normalizeItem({ service_name: 'Full Service', price: 99.99 });
        expect(out.qty).toBe(1);
        expect(out.unit_price).toBe(99.99);
        expect(out.line_subtotal).toBe(99.99);
    });

    test('coerces string amounts to numbers', () => {
        const out = adapter.normalizeItem({
            service_name: 'S',
            qty: '2',
            unit_price: '5.50',
            line_subtotal: '11.00',
        });
        expect(out.qty).toBe(2);
        expect(out.unit_price).toBe(5.5);
        expect(out.line_subtotal).toBe(11);
    });
});

describe('orderAdapter.normalizeItemList', () => {
    test('normalizes every row in the list', () => {
        const out = adapter.normalizeItemList([
            { service_name: 'A', qty: 1, unit_price: 10, line_subtotal: 10 },
            { service_name: 'B', qty: 2, unit_price: 5, line_subtotal: 10 },
        ]);
        expect(out).toHaveLength(2);
        expect(out[1].qty).toBe(2);
    });

    test('returns empty array when input is not an array', () => {
        expect(adapter.normalizeItemList(null)).toEqual([]);
        expect(adapter.normalizeItemList(undefined)).toEqual([]);
    });
});

describe('orderAdapter - backend payload contract', () => {
    // This simulates the exact shape returned by GET /orders on the backend.
    // If the backend ever renames total_amount / tax_amount / etc., this test
    // should be updated deliberately, not silently.
    test('accepts the documented backend payload shape', () => {
        const backendRow = {
            id: 1,
            order_no: 'ORD-1',
            store_id: 1,
            status: 'confirmed',
            customer_name: 'Jane Doe',
            subtotal_amount: '49.99',
            discount_amount: '0.00',
            tax_amount: '4.00',
            total_amount: '53.99',
            amount_due: '53.99',
            created_at: '2026-01-01 10:00:00',
        };
        const out = adapter.normalizeOrder(backendRow);
        expect(out.total_amount).toBe(53.99);
        expect(out.amount_due).toBe(53.99);
        expect(out.subtotal_amount).toBe(49.99);
        expect(out.tax_amount).toBe(4);
    });
});
