var store = require('../store/index');

// Build-time base URL. webpack.DefinePlugin rewrites `process.env.API_BASE_URL`
// into a string literal during `npm run build`; the fallback `/api/v1` covers
// both the test env (jest runs the raw source) and the production container,
// where nginx reverse-proxies `/api/` to the backend so a relative URL is the
// right default. See README → "Configuration → Frontend Service".
var ENV_BASE_URL = typeof process !== 'undefined' && process.env && process.env.API_BASE_URL
  ? process.env.API_BASE_URL
  : null;
var BASE_URL = ENV_BASE_URL || '/api/v1';
var _inflightRequests = {};

/**
 * Build full URL from a relative path.
 */
function buildUrl(path) {
  return BASE_URL + (path.startsWith('/') ? path : '/' + path);
}

/**
 * Generate a key for in-flight request deduplication.
 */
function requestKey(method, path, body) {
  return method + ':' + path + ':' + (body ? JSON.stringify(body) : '');
}

/**
 * Normalize error responses into a consistent shape.
 * Always returns { status, message, errors }.
 */
function normalizeError(status, data) {
  var message = 'An unexpected error occurred';
  var errors = null;

  if (data && typeof data === 'object') {
    message = data.message || data.msg || data.error || message;
    errors = data.errors || null;
  } else if (typeof data === 'string') {
    message = data;
  }

  return {
    status: status,
    message: message,
    errors: errors,
  };
}

/**
 * Central fetch wrapper with auth header injection,
 * error normalization, and duplicate-submit prevention.
 *
 * @param {string} method - HTTP method
 * @param {string} path   - API path (relative to /api/v1)
 * @param {object} [options] - { body, headers, params, skipGuard }
 * @returns {Promise<object>}
 */
function request(method, path, options) {
  options = options || {};
  var body = options.body || null;
  var extraHeaders = options.headers || {};
  var params = options.params || null;
  var skipGuard = options.skipGuard || false;

  var key = requestKey(method, path, body);

  // Duplicate-submit prevention
  if (!skipGuard && _inflightRequests[key]) {
    return _inflightRequests[key];
  }

  var url = buildUrl(path);

  // Append query params
  if (params && typeof params === 'object') {
    var qs = Object.keys(params)
      .filter(function (k) { return params[k] !== undefined && params[k] !== null; })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
      .join('&');
    if (qs) {
      url += '?' + qs;
    }
  }

  var headers = Object.assign({}, { 'Content-Type': 'application/json' }, extraHeaders);

  // Auto-inject Authorization header
  var token = store.getToken();
  if (token) {
    headers['Authorization'] = 'Bearer ' + token;
  }

  var fetchOptions = {
    method: method.toUpperCase(),
    headers: headers,
  };

  if (body && method.toUpperCase() !== 'GET') {
    fetchOptions.body = JSON.stringify(body);
  }

  var promise = fetch(url, fetchOptions)
    .then(function (response) {
      // Remove from in-flight tracking
      delete _inflightRequests[key];

      if (response.status === 204) {
        return { data: null, status: 204 };
      }

      return response.json().then(function (data) {
        if (!response.ok) {
          var err = normalizeError(response.status, data);
          var error = new Error(err.message);
          error.status = err.status;
          error.errors = err.errors;
          throw error;
        }
        // Unwrap backend envelope: { success, data: payload, request_id }
        // Pages receive the inner payload directly via res.data
        var payload = (data && typeof data === 'object' && 'success' in data && 'data' in data)
          ? data.data
          : data;
        return { data: payload, success: data.success, status: response.status };
      });
    })
    .catch(function (err) {
      delete _inflightRequests[key];

      if (err.status) {
        throw err;
      }

      var error = new Error(err.message || 'Network error');
      error.status = 0;
      error.errors = null;
      throw error;
    });

  if (!skipGuard) {
    _inflightRequests[key] = promise;
  }

  return promise;
}

function get(path, params) {
  return request('GET', path, { params: params, skipGuard: true });
}

function post(path, body) {
  return request('POST', path, { body: body });
}

function put(path, body) {
  return request('PUT', path, { body: body });
}

function patch(path, body) {
  return request('PATCH', path, { body: body });
}

function del(path) {
  return request('DELETE', path);
}

/**
 * Clear all in-flight request references (useful for testing).
 */
function clearInflight() {
  Object.keys(_inflightRequests).forEach(function (k) {
    delete _inflightRequests[k];
  });
}

module.exports = {
  request: request,
  get: get,
  post: post,
  put: put,
  patch: patch,
  del: del,
  clearInflight: clearInflight,
  BASE_URL: BASE_URL,
};
