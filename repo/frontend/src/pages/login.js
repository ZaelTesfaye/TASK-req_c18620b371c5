var api = require('../services/api');
var auth = require('../services/auth');
var store = require('../store/index');
var router = require('../router/index');

/**
 * Predefined demo accounts shipped by backend/database/seeds/seed.sql.
 * Rendered as a dropdown because the app is a workstation console with
 * a closed user set (no self-registration), so free-form typing only
 * invites typos. The label shows role + bound store/workstation so the
 * operator picks a row that actually matches the Store/Workstation
 * dropdowns below — otherwise the backend returns INVALID_BINDING.
 *
 * Keep in sync with seed.sql user/binding rows. If a real user-mgmt
 * flow is added later, swap this list for a /auth/bootstrap/users
 * endpoint (currently intentionally not exposed for security).
 */
var DEMO_USERS = [
  { username: 'admin',      label: 'admin (Administrator) - Store 1 / WS 1' },
  { username: 'frontdesk1', label: 'frontdesk1 (Front Desk) - Store 1 / WS 1' },
  { username: 'tech1',      label: 'tech1 (Technician) - Store 1 / WS 2' },
  { username: 'manager1',   label: 'manager1 (Store Manager) - Store 1 / WS 1' },
  { username: 'finance1',   label: 'finance1 (Finance) - Store 1 / WS 1' },
  { username: 'customer1',  label: 'customer1 (Customer) - Store 1 / WS 3 (Kiosk)' },
  { username: 'tech2',      label: 'tech2 (Technician) - Store 2 / WS 5' },
  { username: 'frontdesk2', label: 'frontdesk2 (Front Desk) - Store 2 / WS 4' },
];

/**
 * Login page with workspace selector.
 * Provides username/password inputs, store/workstation dropdowns,
 * and submit button with loading/disabled/submitting states.
 */

var _stores = [];
var _workstations = [];

/**
 * Fetch available stores for the dropdown.
 */
function loadStores(selectEl) {
  api.get('/auth/bootstrap/stores').then(function (res) {
    _stores = res.data || [];
    var html = '<option value="">-- Select Store --</option>';
    for (var i = 0; i < _stores.length; i++) {
      var s = _stores[i];
      html += '<option value="' + s.id + '">' + (s.name || s.id) + '</option>';
    }
    selectEl.innerHTML = html;
    if (typeof layui !== 'undefined' && layui.form) {
      layui.form.render('select', 'login-form-filter');
    }
  }).catch(function () {
    selectEl.innerHTML = '<option value="">Failed to load stores</option>';
    if (typeof layui !== 'undefined' && layui.form) {
      layui.form.render('select', 'login-form-filter');
    }
  });
}

/**
 * Fetch workstations for the selected store.
 */
function loadWorkstations(storeId, selectEl) {
  if (!storeId) {
    selectEl.innerHTML = '<option value="">-- Select Workstation --</option>';
    _workstations = [];
    if (typeof layui !== 'undefined' && layui.form) {
      layui.form.render('select', 'login-form-filter');
    }
    return;
  }

  api.get('/auth/bootstrap/workstations', { store_id: storeId }).then(function (res) {
    _workstations = res.data || [];
    var html = '<option value="">-- Select Workstation --</option>';
    for (var i = 0; i < _workstations.length; i++) {
      var w = _workstations[i];
      html += '<option value="' + w.id + '">' + (w.name || w.id) + '</option>';
    }
    selectEl.innerHTML = html;
    if (typeof layui !== 'undefined' && layui.form) {
      layui.form.render('select', 'login-form-filter');
    }
  }).catch(function () {
    selectEl.innerHTML = '<option value="">Failed to load workstations</option>';
    if (typeof layui !== 'undefined' && layui.form) {
      layui.form.render('select', 'login-form-filter');
    }
  });
}

/**
 * Set the submit button state.
 */
function setButtonState(btn, state) {
  btn.classList.remove('is-loading', 'is-submitting', 'is-disabled');
  if (state) {
    btn.classList.add(state);
  }
  btn.disabled = state === 'is-disabled' || state === 'is-submitting' || state === 'is-loading';
}

/**
 * Render the login page into the given container.
 *
 * @param {HTMLElement} container
 */
function render(container) {
  if (!container) return;

  var html =
    '<div style="max-width:420px;margin:80px auto;padding:30px;background:#fff;border-radius:4px;box-shadow:0 1px 6px rgba(0,0,0,0.12);">' +
      '<h2 style="text-align:center;margin-bottom:24px;">FieldOps Login</h2>' +
      '<form class="layui-form" lay-filter="login-form-filter">' +
        '<div class="layui-form-item">' +
          '<label class="layui-form-label">Username</label>' +
          '<div class="layui-input-block">' +
            (function () {
              var opts = '<option value="">-- Select User --</option>';
              for (var i = 0; i < DEMO_USERS.length; i++) {
                opts += '<option value="' + DEMO_USERS[i].username + '">' + DEMO_USERS[i].label + '</option>';
              }
              return '<select name="username" id="login-username" lay-verify="required">' + opts + '</select>';
            })() +
          '</div>' +
        '</div>' +
        '<div class="layui-form-item">' +
          '<label class="layui-form-label">Password</label>' +
          '<div class="layui-input-block">' +
            '<input type="password" name="password" id="login-password" class="layui-input" placeholder="Enter password" required autocomplete="current-password">' +
          '</div>' +
        '</div>' +
        '<div class="layui-form-item">' +
          '<label class="layui-form-label">Store</label>' +
          '<div class="layui-input-block">' +
            '<select name="store_id" id="login-store" lay-filter="login-store-select" lay-verify="required">' +
              '<option value="">-- Select Store --</option>' +
            '</select>' +
          '</div>' +
        '</div>' +
        '<div class="layui-form-item">' +
          '<label class="layui-form-label">Workstation</label>' +
          '<div class="layui-input-block">' +
            '<select name="workstation_id" id="login-workstation" lay-verify="required">' +
              '<option value="">-- Select Workstation --</option>' +
            '</select>' +
          '</div>' +
        '</div>' +
        '<div class="layui-form-item" style="text-align:center;margin-top:24px;">' +
          '<button type="submit" id="login-submit-btn" class="layui-btn layui-btn-fluid" lay-submit lay-filter="login-submit">Sign In</button>' +
        '</div>' +
        '<div id="login-error-msg" class="field-error" style="text-align:center;margin-top:12px;color:#FF5722;display:none;"></div>' +
      '</form>' +
    '</div>';

  container.innerHTML = html;

  var storeSelect = document.getElementById('login-store');
  var workstationSelect = document.getElementById('login-workstation');
  var submitBtn = document.getElementById('login-submit-btn');
  var errorEl = document.getElementById('login-error-msg');

  // Load stores on init
  loadStores(storeSelect);

  // Render Layui form
  if (typeof layui !== 'undefined' && layui.form) {
    layui.form.render(null, 'login-form-filter');

    // Listen for store dropdown change to load workstations
    layui.form.on('select(login-store-select)', function (data) {
      loadWorkstations(data.value, workstationSelect);
    });
  }

  // Handle form submission
  var form = container.querySelector('form');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();

      var username = document.getElementById('login-username').value.trim();
      var password = document.getElementById('login-password').value;
      var storeId = storeSelect.value;
      var workstationId = workstationSelect.value;

      // Client-side validation
      if (!username || !password || !storeId || !workstationId) {
        errorEl.textContent = 'All fields are required.';
        errorEl.style.display = 'block';
        return;
      }

      // Clear previous error and set submitting state
      errorEl.style.display = 'none';
      errorEl.textContent = '';
      setButtonState(submitBtn, 'is-submitting');
      submitBtn.textContent = 'Signing In...';

      auth.login(username, password, storeId, workstationId)
        .then(function () {
          setButtonState(submitBtn, null);
          submitBtn.textContent = 'Sign In';
          // Send the user to whichever page their role is actually
          // allowed to view. Hard-coding dashboard sent every
          // non-manager/admin role straight into a 403 because the
          // dashboard route is restricted to store_manager +
          // administrator.
          var landing = '#/' + router.getLandingPage();
          if (window.location.hash !== landing) {
            window.location.hash = landing;
          }
        })
        .catch(function (err) {
          setButtonState(submitBtn, null);
          submitBtn.textContent = 'Sign In';

          var message = err.message || 'Login failed. Please try again.';

          // Handle lockout
          if (err.status === 423 || /locked|lockout/i.test(message)) {
            errorEl.textContent = 'Account is locked. Please contact your administrator.';
            setButtonState(submitBtn, 'is-disabled');
          } else if (err.status === 401) {
            errorEl.textContent = 'Invalid username or password.';
          } else {
            errorEl.textContent = message;
          }

          errorEl.style.display = 'block';
        });
    });
  }
}

module.exports = {
  render: render,
};
