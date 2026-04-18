/**
 * Kiosk Receipt Rendering Tests
 *
 * Feeds the exact backend-shaped payload returned by GET /orders/:id/receipt
 * (see OrderService::getReceipt at backend/app/service/OrderService.php:538 and
 * the order_items row shape created at OrderService.php:81) into the kiosk
 * page's showReceipt() and asserts line-item values render. orderFlow.test.js
 * only exercises renderReceipt with a synthetic payload and would not catch a
 * mismatch between the kiosk page and backend field names.
 */

const localStorageMock = (function () {
    let s = {};
    return {
        getItem: function (k) { return s[k] || null; },
        setItem: function (k, v) { s[k] = String(v); },
        removeItem: function (k) { delete s[k]; },
        clear: function () { s = {}; },
    };
})();
Object.defineProperty(global, 'localStorage', { value: localStorageMock });
global.fetch = jest.fn();

const kiosk = require('../../src/pages/kiosk');

describe('kiosk showReceipt - backend payload contract', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="kiosk-content"></div>';
    });

    test('renders qty, unit_price, and line_subtotal from backend-shaped items', () => {
        // Exact shape from OrderService::getReceipt -> order_items rows.
        const backendReceipt = {
            receipt_no: 'RCP-001',
            order_no: 'ORD-20260418-ABC',
            store_id: 1,
            customer_name: 'Jane Doe',
            items: [
                {
                    service_code: 'SVC-001',
                    service_name: 'Oil Change',
                    qty: 2,
                    unit_price: 49.99,
                    line_subtotal: 99.98,
                },
                {
                    service_code: 'SVC-002',
                    service_name: 'Tire Rotation',
                    qty: 1,
                    unit_price: 29.99,
                    line_subtotal: 29.99,
                },
            ],
            subtotal: '129.97',
            discount: '0.00',
            tax: '10.72',
            total: '140.69',
            amount_due: '140.69',
            currency: 'USD',
        };

        kiosk.showReceipt(backendReceipt);
        const html = document.getElementById('kiosk-content').innerHTML;

        expect(html).toContain('ORD-20260418-ABC');
        expect(html).toContain('Oil Change');
        expect(html).toContain('Tire Rotation');
        // qty column
        expect(html).toMatch(/<td>2<\/td>/);
        expect(html).toMatch(/<td>1<\/td>/);
        // unit_price column
        expect(html).toContain('$49.99');
        expect(html).toContain('$29.99');
        // line_subtotal column
        expect(html).toContain('$99.98');
    });

    test('does not fall back to legacy price/amount fields when canonical fields are present', () => {
        const backendReceipt = {
            order_no: 'ORD-1',
            items: [
                {
                    service_code: 'SVC-001',
                    service_name: 'Service X',
                    qty: 3,
                    unit_price: 10,
                    line_subtotal: 30,
                    // If the page still read `price` or `amount`, these would slip through.
                    price: 999,
                    amount: 999,
                },
            ],
        };

        kiosk.showReceipt(backendReceipt);
        const html = document.getElementById('kiosk-content').innerHTML;

        expect(html).toContain('$10.00');
        expect(html).toContain('$30.00');
        expect(html).not.toContain('$999.00');
    });

    test('renders safely when items array is empty', () => {
        kiosk.showReceipt({ order_no: 'ORD-EMPTY', items: [] });
        const html = document.getElementById('kiosk-content').innerHTML;
        expect(html).toContain('ORD-EMPTY');
    });
});
