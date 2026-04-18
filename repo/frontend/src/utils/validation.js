/**
 * Validate that a value is present (not empty/null/undefined).
 *
 * @param {*} value
 * @param {string} fieldName - Human-readable field name for the error message
 * @returns {{ valid: boolean, message: string }}
 */
function validateRequired(value, fieldName) {
  var name = fieldName || 'This field';
  if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) {
    return { valid: false, message: name + ' is required.' };
  }
  return { valid: true, message: '' };
}

/**
 * Validate a password against policy requirements:
 * - Minimum 12 characters
 * - At least one uppercase letter
 * - At least one lowercase letter
 * - At least one digit
 * - At least one special character
 *
 * @param {string} password
 * @returns {{ valid: boolean, message: string }}
 */
function validatePasswordPolicy(password) {
  if (!password || typeof password !== 'string') {
    return { valid: false, message: 'Password is required.' };
  }

  var errors = [];

  if (password.length < 12) {
    errors.push('at least 12 characters');
  }
  if (!/[A-Z]/.test(password)) {
    errors.push('one uppercase letter');
  }
  if (!/[a-z]/.test(password)) {
    errors.push('one lowercase letter');
  }
  if (!/[0-9]/.test(password)) {
    errors.push('one digit');
  }
  if (!/[^A-Za-z0-9]/.test(password)) {
    errors.push('one special character');
  }

  if (errors.length > 0) {
    return {
      valid: false,
      message: 'Password must contain ' + errors.join(', ') + '.',
    };
  }

  return { valid: true, message: '' };
}

/**
 * Validate a coupon code format.
 * Accepts alphanumeric codes with optional hyphens, 4-20 characters.
 *
 * @param {string} code
 * @returns {{ valid: boolean, message: string }}
 */
function validateCouponCode(code) {
  if (!code || typeof code !== 'string' || code.trim() === '') {
    return { valid: false, message: 'Coupon code is required.' };
  }

  var trimmed = code.trim();

  if (!/^[A-Za-z0-9-]+$/.test(trimmed)) {
    return { valid: false, message: 'Coupon code may only contain letters, numbers, and hyphens.' };
  }

  if (trimmed.length < 4 || trimmed.length > 20) {
    return { valid: false, message: 'Coupon code must be between 4 and 20 characters.' };
  }

  return { valid: true, message: '' };
}

/**
 * Validate that an amount is a positive number.
 *
 * @param {*} amount
 * @returns {{ valid: boolean, message: string }}
 */
function validateAmount(amount) {
  if (amount === null || amount === undefined || amount === '') {
    return { valid: false, message: 'Amount is required.' };
  }

  var num = Number(amount);

  if (isNaN(num)) {
    return { valid: false, message: 'Amount must be a valid number.' };
  }

  if (num <= 0) {
    return { valid: false, message: 'Amount must be a positive number.' };
  }

  return { valid: true, message: '' };
}

/**
 * Validate invoice fields with conditional required logic.
 *
 * Required fields: customer_name, amount, date.
 * If payment_method is "credit_card", card_last_four is also required.
 * If tax_exempt is true, tax_exempt_id is required.
 *
 * @param {object} data
 * @returns {{ valid: boolean, errors: object }}
 */
function validateInvoiceFields(data) {
  var errors = {};

  if (!data || typeof data !== 'object') {
    return { valid: false, errors: { _form: 'Invoice data is required.' } };
  }

  // Required fields
  var reqCheck = validateRequired(data.customer_name, 'Customer name');
  if (!reqCheck.valid) {
    errors.customer_name = reqCheck.message;
  }

  var amtCheck = validateAmount(data.amount);
  if (!amtCheck.valid) {
    errors.amount = amtCheck.message;
  }

  var dateCheck = validateRequired(data.date, 'Date');
  if (!dateCheck.valid) {
    errors.date = dateCheck.message;
  }

  // Conditional: credit card last four
  if (data.payment_method === 'credit_card') {
    if (!data.card_last_four || !/^\d{4}$/.test(String(data.card_last_four))) {
      errors.card_last_four = 'Last four digits of the card are required for credit card payments.';
    }
  }

  // Conditional: tax exempt ID
  if (data.tax_exempt === true) {
    var taxCheck = validateRequired(data.tax_exempt_id, 'Tax exempt ID');
    if (!taxCheck.valid) {
      errors.tax_exempt_id = taxCheck.message;
    }
  }

  var valid = Object.keys(errors).length === 0;
  return { valid: valid, errors: errors };
}

module.exports = {
  validateRequired: validateRequired,
  validatePasswordPolicy: validatePasswordPolicy,
  validateCouponCode: validateCouponCode,
  validateAmount: validateAmount,
  validateInvoiceFields: validateInvoiceFields,
};
