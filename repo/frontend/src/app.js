require('./styles/main.css');

var router = require('./router/index');
var auth = require('./services/auth');
var Navigation = require('./components/Navigation');

// ---------------------------------------------------------------------------
// Page module registry - maps route page names to page modules
// ---------------------------------------------------------------------------
var PAGE_MODULES = {
  'login':            function () { return require('./pages/login'); },
  'dashboard':        function () { return require('./pages/dashboard'); },
  'orders':           function () { return require('./pages/orders'); },
  'order-detail':     function () { return require('./pages/orders'); },
  'technician-queue': function () { return require('./pages/technicianQueue'); },
  'finance':          function () { return require('./pages/finance'); },
  'admin':            function () { return require('./pages/admin'); },
  'environmental':    function () { return require('./pages/environmental'); },
  'cleansing':        function () { return require('./pages/cleansing'); },
  'audit-logs':       function () { return require('./pages/auditLogs'); },
  'kiosk':            function () { return require('./pages/kiosk'); },
  'forbidden':        function () { return require('./pages/forbidden'); },
};

/**
 * Render the full application shell (header, sidebar, content area).
 */
function renderShell() {
  var app = document.getElementById('app');
  if (!app) return;

  // Brand link points at the current user's role-appropriate landing
  // page rather than a fixed dashboard URL, so clicking the title from
  // e.g. a technician's queue doesn't 403 them through the
  // store_manager-only dashboard route.
  var landing = router.getLandingPage();
  app.innerHTML =
    '<div class="fieldops-header">' +
      '<a href="#/' + landing + '" class="brand">FieldOps Service Suite</a>' +
      '<div id="header-user"></div>' +
    '</div>' +
    '<div class="fieldops-body">' +
      '<div class="fieldops-sidebar" id="sidebar-nav"></div>' +
      '<div class="fieldops-content" id="page-content"></div>' +
    '</div>';
}

/**
 * Render the login page.
 */
function renderLoginPage() {
  var app = document.getElementById('app');
  if (!app) return;

  // Try the page module first
  var loginLoader = PAGE_MODULES['login'];
  if (loginLoader) {
    var loginModule = loginLoader();
    if (loginModule && typeof loginModule.render === 'function') {
      loginModule.render(app);
      return;
    }
  }

  // Fallback inline login form
  app.innerHTML =
    '<div style="max-width:400px;margin:80px auto;padding:30px;background:#fff;border-radius:4px;box-shadow:0 1px 6px rgba(0,0,0,0.1);">' +
      '<h2 style="text-align:center;margin-bottom:20px;">FieldOps Login</h2>' +
      '<form id="login-form" class="layui-form">' +
        '<div class="fieldops-form-group">' +
          '<label class="layui-form-label">Username</label>' +
          '<div class="layui-input-block">' +
            '<input type="text" name="username" class="layui-input" placeholder="Username" required autocomplete="username">' +
          '</div>' +
        '</div>' +
        '<div class="fieldops-form-group">' +
          '<label class="layui-form-label">Password</label>' +
          '<div class="layui-input-block">' +
            '<input type="password" name="password" class="layui-input" placeholder="Password" required autocomplete="current-password">' +
          '</div>' +
        '</div>' +
        '<div class="fieldops-form-group">' +
          '<label class="layui-form-label">Store ID</label>' +
          '<div class="layui-input-block">' +
            '<input type="text" name="storeId" class="layui-input" placeholder="Store ID" required>' +
          '</div>' +
        '</div>' +
        '<div class="fieldops-form-group">' +
          '<label class="layui-form-label">Workstation</label>' +
          '<div class="layui-input-block">' +
            '<input type="text" name="workstationId" class="layui-input" placeholder="Workstation ID" required>' +
          '</div>' +
        '</div>' +
        '<div class="fieldops-form-group" style="text-align:center;margin-top:20px;">' +
          '<button type="submit" class="layui-btn layui-btn-fluid">Sign In</button>' +
        '</div>' +
        '<div id="login-error" class="field-error" style="text-align:center;margin-top:10px;"></div>' +
      '</form>' +
    '</div>';

  var form = document.getElementById('login-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      var btn = form.querySelector('button[type="submit"]');
      var errorEl = document.getElementById('login-error');

      if (btn) btn.classList.add('is-submitting');
      if (errorEl) {
        errorEl.style.display = 'none';
        errorEl.textContent = '';
      }

      auth.login(
        fd.get('username'),
        fd.get('password'),
        fd.get('storeId'),
        fd.get('workstationId')
      )
      .then(function () {
        router.navigate(router.getLandingPage());
      })
      .catch(function (err) {
        if (errorEl) {
          errorEl.textContent = err.message || 'Login failed. Please try again.';
          errorEl.style.display = 'block';
        }
      })
      .finally(function () {
        if (btn) btn.classList.remove('is-submitting');
      });
    });
  }
}

/**
 * Load and render a page module into the content area.
 */
function renderPageModule(page, params) {
  var container = document.getElementById('page-inner');
  if (!container) return;

  var loader = PAGE_MODULES[page];
  if (loader) {
    try {
      var pageModule = loader();
      if (pageModule && typeof pageModule.render === 'function') {
        pageModule.render(container, params);
        return;
      }
    } catch (e) {
      // Module not found or error loading - fall through to placeholder
    }
  }

  // Fallback: render a placeholder
  container.innerHTML = '<p>Page content loading...</p>';
}

/**
 * Handle a route change and render the appropriate page.
 */
function onNavigate(routeInfo) {
  if (!routeInfo || !routeInfo.route) return;

  var page = routeInfo.route.page;
  var params = routeInfo.params || {};

  // Login gets its own full-page layout
  if (page === 'login') {
    renderLoginPage();
    return;
  }

  // Ensure the shell is rendered
  if (!document.getElementById('sidebar-nav')) {
    renderShell();
  }

  // Update navigation
  var sidebar = document.getElementById('sidebar-nav');
  Navigation.render(sidebar, page);

  // Render page content container
  var content = document.getElementById('page-content');
  if (content) {
    content.innerHTML =
      '<div class="p-20">' +
        '<h2>' + formatPageTitle(page) + '</h2>' +
        '<div id="page-inner" class="mt-20"></div>' +
      '</div>';
  }

  // Load and render the page module
  renderPageModule(page, params);

  // Update header user info
  var headerUser = document.getElementById('header-user');
  if (headerUser) {
    var store = require('./store/index');
    var user = store.getUser();
    var displayName = user ? (user.name || user.username || 'User') : 'User';
    headerUser.innerHTML =
      '<span style="color:#fff;margin-right:15px;">' + displayName + '</span>' +
      '<a href="javascript:;" id="btn-logout" style="color:#c2c2c2;">Logout</a>';

    var logoutBtn = document.getElementById('btn-logout');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function () {
        auth.logout().then(function () {
          router.navigate('login');
        });
      });
    }
  }
}

/**
 * Convert a route path to a human-readable page title.
 */
function formatPageTitle(page) {
  return page.split('-').map(function (word) {
    return word.charAt(0).toUpperCase() + word.slice(1);
  }).join(' ');
}

/**
 * Application initialization.
 */
function init() {
  // If authenticated, try to refresh user info
  if (auth.isAuthenticated()) {
    auth.getMe().catch(function () {
      // Token is invalid or expired - clear and redirect to login
      auth.clearToken();
    }).finally(function () {
      router.init(onNavigate);
    });
  } else {
    router.init(onNavigate);
  }
}

// Boot the application
init();

module.exports = {
  init: init,
  onNavigate: onNavigate,
  PAGE_MODULES: PAGE_MODULES,
  renderPageModule: renderPageModule,
};
