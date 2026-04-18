var auth = require('../services/auth');
var store = require('../store/index');

// ---------------------------------------------------------------------------
// Role constants
// ---------------------------------------------------------------------------
// Role codes match backend snake_case values from roles.code column
var ROLES = {
  CUSTOMER: 'customer',
  FRONT_DESK: 'front_desk',
  TECHNICIAN: 'technician',
  STORE_MANAGER: 'store_manager',
  FINANCE: 'finance',
  ADMINISTRATOR: 'administrator',
};

// Display labels for UI rendering only
var ROLE_LABELS = {
  customer: 'Customer',
  front_desk: 'Front Desk',
  technician: 'Technician',
  store_manager: 'Store Manager',
  finance: 'Finance',
  administrator: 'Administrator',
};

var ALL_ROLES = [
  ROLES.CUSTOMER,
  ROLES.FRONT_DESK,
  ROLES.TECHNICIAN,
  ROLES.STORE_MANAGER,
  ROLES.FINANCE,
  ROLES.ADMINISTRATOR,
];

// ---------------------------------------------------------------------------
// Route definitions
// ---------------------------------------------------------------------------
var routes = [
  { path: 'login',            page: 'login',            auth: false, roles: null },
  { path: 'dashboard',        page: 'dashboard',        auth: true,  roles: [ROLES.STORE_MANAGER, ROLES.ADMINISTRATOR] },
  { path: 'orders',           page: 'orders',           auth: true,  roles: [ROLES.CUSTOMER, ROLES.FRONT_DESK, ROLES.TECHNICIAN, ROLES.STORE_MANAGER, ROLES.FINANCE, ROLES.ADMINISTRATOR] },
  { path: 'order-detail',     page: 'order-detail',     auth: true,  roles: [ROLES.CUSTOMER, ROLES.FRONT_DESK, ROLES.TECHNICIAN, ROLES.STORE_MANAGER, ROLES.FINANCE, ROLES.ADMINISTRATOR] },
  { path: 'technician-queue', page: 'technician-queue', auth: true,  roles: [ROLES.TECHNICIAN, ROLES.STORE_MANAGER, ROLES.ADMINISTRATOR] },
  { path: 'finance',          page: 'finance',          auth: true,  roles: [ROLES.FINANCE, ROLES.STORE_MANAGER, ROLES.ADMINISTRATOR] },
  { path: 'admin',            page: 'admin',            auth: true,  roles: [ROLES.ADMINISTRATOR] },
  { path: 'environmental',    page: 'environmental',    auth: true,  roles: [ROLES.STORE_MANAGER, ROLES.ADMINISTRATOR] },
  { path: 'cleansing',        page: 'cleansing',        auth: true,  roles: [ROLES.STORE_MANAGER, ROLES.ADMINISTRATOR] },
  { path: 'audit-logs',       page: 'audit-logs',       auth: true,  roles: [ROLES.STORE_MANAGER, ROLES.ADMINISTRATOR] },
  { path: 'kiosk',            page: 'kiosk',            auth: true,  roles: [ROLES.CUSTOMER, ROLES.FRONT_DESK, ROLES.ADMINISTRATOR] },
];

var _currentRoute = null;
var _onNavigate = null;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Parse the current hash into a route path and optional query params.
 */
function parseHash() {
  var hash = window.location.hash.replace(/^#\/?/, '');
  var parts = hash.split('?');
  var path = parts[0] || '';
  var queryStr = parts[1] || '';
  var params = {};

  if (queryStr) {
    queryStr.split('&').forEach(function (pair) {
      var kv = pair.split('=');
      if (kv[0]) {
        params[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || '');
      }
    });
  }

  return { path: path, params: params };
}

/**
 * Find a route definition by path.
 */
function findRoute(path) {
  for (var i = 0; i < routes.length; i++) {
    if (routes[i].path === path) {
      return routes[i];
    }
  }
  return null;
}

/**
 * Check whether the current user has at least one of the required roles.
 */
function hasAccess(route) {
  if (!route.roles) {
    return true;
  }
  var userRoles = store.getRoles();
  for (var i = 0; i < route.roles.length; i++) {
    if (userRoles.indexOf(route.roles[i]) !== -1) {
      return true;
    }
  }
  return false;
}

// ---------------------------------------------------------------------------
// Render helpers
// ---------------------------------------------------------------------------

function render403() {
  var app = document.getElementById('app');
  if (app) {
    app.innerHTML =
      '<div style="text-align:center;padding:80px 20px;">' +
      '<h1>403 - Access Denied</h1>' +
      '<p>You do not have permission to view this page.</p>' +
      '<a href="#/dashboard">Return to Dashboard</a>' +
      '</div>';
  }
}

function render404() {
  var app = document.getElementById('app');
  if (app) {
    app.innerHTML =
      '<div style="text-align:center;padding:80px 20px;">' +
      '<h1>404 - Page Not Found</h1>' +
      '<p>The page you requested could not be found.</p>' +
      '<a href="#/dashboard">Return to Dashboard</a>' +
      '</div>';
  }
}

// ---------------------------------------------------------------------------
// Core navigation
// ---------------------------------------------------------------------------

function navigate(path) {
  if (window.location.hash !== '#/' + path) {
    window.location.hash = '#/' + path;
  } else {
    handleRouteChange();
  }
}

function handleRouteChange() {
  var parsed = parseHash();
  var path = parsed.path;
  var params = parsed.params;

  // Default redirect
  if (!path) {
    navigate(auth.isAuthenticated() ? 'dashboard' : 'login');
    return;
  }

  var route = findRoute(path);

  // 404 - unknown route
  if (!route) {
    render404();
    return;
  }

  // Auth guard: unauthenticated users trying to access protected routes
  if (route.auth && !auth.isAuthenticated()) {
    navigate('login');
    return;
  }

  // Already logged in users visiting login page -> redirect to dashboard
  if (path === 'login' && auth.isAuthenticated()) {
    navigate('dashboard');
    return;
  }

  // Role guard
  if (route.auth && route.roles && !hasAccess(route)) {
    render403();
    return;
  }

  _currentRoute = { route: route, params: params };

  // Notify the navigation callback (app.js wires this up)
  if (typeof _onNavigate === 'function') {
    _onNavigate(_currentRoute);
  }
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Initialize the router. Call once on app startup.
 *
 * @param {function} onNavigate - callback invoked with { route, params } on each navigation
 */
function init(onNavigate) {
  _onNavigate = onNavigate || null;
  window.addEventListener('hashchange', handleRouteChange);
  handleRouteChange();
}

/**
 * Tear down the router (useful for tests).
 */
function destroy() {
  window.removeEventListener('hashchange', handleRouteChange);
  _onNavigate = null;
  _currentRoute = null;
}

function getCurrentRoute() {
  return _currentRoute;
}

function getRoutes() {
  return routes.slice();
}

module.exports = {
  init: init,
  destroy: destroy,
  navigate: navigate,
  getCurrentRoute: getCurrentRoute,
  getRoutes: getRoutes,
  findRoute: findRoute,
  hasAccess: hasAccess,
  ROLES: ROLES,
  ROLE_LABELS: ROLE_LABELS,
  ALL_ROLES: ALL_ROLES,
};
