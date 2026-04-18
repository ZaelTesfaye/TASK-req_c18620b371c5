/**
 * Validation Rules Unit Tests
 * Tests form validation rules and realtime feedback for all critical inputs.
 */

const {
    validateRequired,
    validatePasswordPolicy,
    validateAmount,
    validateInvoiceFields,
    validateCouponCode,
} = require('../../src/utils/validation');

describe('validateRequired', () => {
    test('returns invalid for empty string', () => {
        const result = validateRequired('', 'Name');
        expect(result.valid).toBe(false);
        expect(result.message).toContain('Name');
    });

    test('returns invalid for null', () => {
        const result = validateRequired(null, 'Field');
        expect(result.valid).toBe(false);
    });

    test('returns valid for non-empty value', () => {
        const result = validateRequired('John', 'Name');
        expect(result.valid).toBe(true);
    });

    test('returns invalid for whitespace only', () => {
        const result = validateRequired('   ', 'Field');
        expect(result.valid).toBe(false);
    });
});

describe('validatePasswordPolicy', () => {
    test('valid password passes all checks', () => {
        const result = validatePasswordPolicy('Demo12345678!');
        expect(result.valid).toBe(true);
    });

    test('too short password fails', () => {
        const result = validatePasswordPolicy('Demo123!');
        expect(result.valid).toBe(false);
        expect(result.message).toContain('12');
    });

    test('no uppercase fails', () => {
        const result = validatePasswordPolicy('demo12345678!');
        expect(result.valid).toBe(false);
        expect(result.message).toContain('uppercase');
    });

    test('no lowercase fails', () => {
        const result = validatePasswordPolicy('DEMO12345678!');
        expect(result.valid).toBe(false);
        expect(result.message).toContain('lowercase');
    });

    test('no digit fails', () => {
        const result = validatePasswordPolicy('DemoPassword!!');
        expect(result.valid).toBe(false);
        expect(result.message).toContain('digit');
    });

    test('no special char fails', () => {
        const result = validatePasswordPolicy('Demo123456789');
        expect(result.valid).toBe(false);
        expect(result.message).toContain('special');
    });

    test('empty password fails', () => {
        const result = validatePasswordPolicy('');
        expect(result.valid).toBe(false);
    });
});

describe('validateAmount', () => {
    test('positive amount is valid', () => {
        const result = validateAmount(10.00);
        expect(result.valid).toBe(true);
    });

    test('zero is invalid', () => {
        const result = validateAmount(0);
        expect(result.valid).toBe(false);
    });

    test('negative is invalid', () => {
        const result = validateAmount(-5);
        expect(result.valid).toBe(false);
    });

    test('non-numeric is invalid', () => {
        const result = validateAmount('abc');
        expect(result.valid).toBe(false);
    });
});

describe('validateInvoiceFields', () => {
    test('returns valid when all required fields present', () => {
        const result = validateInvoiceFields({
            customer_name: 'John Doe',
            amount: 100,
            date: '01/15/2025',
        });
        expect(result.valid).toBe(true);
        expect(Object.keys(result.errors)).toHaveLength(0);
    });

    test('returns errors when required fields missing', () => {
        const result = validateInvoiceFields({
            customer_name: '',
            amount: '',
            date: '',
        });
        expect(result.valid).toBe(false);
        expect(Object.keys(result.errors).length).toBeGreaterThan(0);
    });

    test('credit card requires last four digits', () => {
        const result = validateInvoiceFields({
            customer_name: 'John',
            amount: 100,
            date: '01/15/2025',
            payment_method: 'credit_card',
            card_last_four: '',
        });
        expect(result.valid).toBe(false);
        expect(result.errors.card_last_four).toBeTruthy();
    });
});

describe('validateCouponCode', () => {
    test('valid code passes', () => {
        const result = validateCouponCode('WELCOME10');
        expect(result.valid).toBe(true);
    });

    test('empty code fails', () => {
        const result = validateCouponCode('');
        expect(result.valid).toBe(false);
    });
});
