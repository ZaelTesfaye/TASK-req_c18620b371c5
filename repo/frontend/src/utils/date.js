/**
 * Pad a number to two digits.
 */
function pad(n) {
  return n < 10 ? '0' + n : '' + n;
}

/**
 * Format a Date object to MM/DD/YYYY.
 *
 * @param {Date} date
 * @returns {string}
 */
function formatMMDDYYYY(date) {
  if (!(date instanceof Date) || isNaN(date.getTime())) {
    return '';
  }
  var mm = pad(date.getMonth() + 1);
  var dd = pad(date.getDate());
  var yyyy = date.getFullYear();
  return mm + '/' + dd + '/' + yyyy;
}

/**
 * Parse an MM/DD/YYYY string into a Date object.
 *
 * @param {string} str
 * @returns {Date|null}
 */
function parseMMDDYYYY(str) {
  if (!str || typeof str !== 'string') {
    return null;
  }
  var parts = str.split('/');
  if (parts.length !== 3) {
    return null;
  }
  var month = parseInt(parts[0], 10);
  var day = parseInt(parts[1], 10);
  var year = parseInt(parts[2], 10);

  if (isNaN(month) || isNaN(day) || isNaN(year)) {
    return null;
  }
  if (month < 1 || month > 12 || day < 1 || day > 31) {
    return null;
  }

  var date = new Date(year, month - 1, day);
  // Validate the date components round-trip correctly
  if (
    date.getFullYear() !== year ||
    date.getMonth() !== month - 1 ||
    date.getDate() !== day
  ) {
    return null;
  }
  return date;
}

/**
 * Format a Date object to ISO 8601 string (YYYY-MM-DDTHH:mm:ss.sssZ).
 *
 * @param {Date} date
 * @returns {string}
 */
function formatISO(date) {
  if (!(date instanceof Date) || isNaN(date.getTime())) {
    return '';
  }
  return date.toISOString();
}

/**
 * Convert an ISO date string to a locale-aware display string.
 *
 * @param {string} isoDate - ISO 8601 date string
 * @param {string} [timezone] - IANA timezone (e.g. "America/New_York"). Defaults to local.
 * @returns {string}
 */
function toLocalDisplay(isoDate, timezone) {
  if (!isoDate) {
    return '';
  }
  var date = new Date(isoDate);
  if (isNaN(date.getTime())) {
    return '';
  }

  var options = {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true,
  };

  if (timezone) {
    options.timeZone = timezone;
  }

  try {
    return new Intl.DateTimeFormat('en-US', options).format(date);
  } catch (e) {
    // Fallback if timezone is invalid
    return date.toLocaleString('en-US');
  }
}

module.exports = {
  formatMMDDYYYY: formatMMDDYYYY,
  parseMMDDYYYY: parseMMDDYYYY,
  formatISO: formatISO,
  toLocalDisplay: toLocalDisplay,
};
