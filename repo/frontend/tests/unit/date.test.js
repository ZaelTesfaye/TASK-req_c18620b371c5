/**
 * Date Utilities Unit Tests
 * Tests MM/DD/YYYY formatting and parsing.
 */

const { formatMMDDYYYY, parseMMDDYYYY, formatISO } = require('../../src/utils/date');

describe('formatMMDDYYYY', () => {
    test('formats date correctly', () => {
        const date = new Date(2025, 0, 15); // Jan 15, 2025
        expect(formatMMDDYYYY(date)).toBe('01/15/2025');
    });

    test('pads single digit month', () => {
        const date = new Date(2025, 2, 5); // Mar 5, 2025
        expect(formatMMDDYYYY(date)).toBe('03/05/2025');
    });

    test('handles December correctly', () => {
        const date = new Date(2025, 11, 31); // Dec 31, 2025
        expect(formatMMDDYYYY(date)).toBe('12/31/2025');
    });

    test('returns empty string for invalid date', () => {
        expect(formatMMDDYYYY(null)).toBe('');
        expect(formatMMDDYYYY(undefined)).toBe('');
    });
});

describe('parseMMDDYYYY', () => {
    test('parses valid date string', () => {
        const result = parseMMDDYYYY('01/15/2025');
        expect(result).toBeInstanceOf(Date);
        expect(result.getFullYear()).toBe(2025);
        expect(result.getMonth()).toBe(0);
        expect(result.getDate()).toBe(15);
    });

    test('returns null for invalid format', () => {
        expect(parseMMDDYYYY('2025-01-15')).toBeNull();
    });

    test('returns null for empty string', () => {
        expect(parseMMDDYYYY('')).toBeNull();
    });

    test('handles leap year date', () => {
        const result = parseMMDDYYYY('02/29/2024');
        expect(result).toBeInstanceOf(Date);
    });
});

describe('formatISO', () => {
    test('formats to ISO string', () => {
        const date = new Date(2025, 0, 15, 10, 30, 0);
        const result = formatISO(date);
        expect(result).toContain('2025');
    });
});
