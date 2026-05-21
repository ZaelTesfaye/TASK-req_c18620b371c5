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
// Labels mirror the human-readable names rendered in the Store /
// Workstation dropdowns so the operator can match them by sight
// instead of translating opaque "Store 1 / WS 1" IDs. Kept in sync
// with seed.sql -> stores.name + workstations.name columns.
var DEMO_USERS = [
  { username: 'admin',      label: 'admin (Administrator) - Downtown Service Center / Front Desk Terminal 1' },
  { username: 'frontdesk1', label: 'frontdesk1 (Front Desk) - Downtown Service Center / Front Desk Terminal 1' },
  { username: 'tech1',      label: 'tech1 (Technician) - Downtown Service Center / Front Desk Terminal 2' },
  { username: 'manager1',   label: 'manager1 (Store Manager) - Downtown Service Center / Front Desk Terminal 1' },
  { username: 'finance1',   label: 'finance1 (Finance) - Downtown Service Center / Front Desk Terminal 1' },
  { username: 'customer1',  label: 'customer1 (Customer) - Downtown Service Center / Kiosk Station 1' },
  { username: 'tech2',      label: 'tech2 (Technician) - Midtown Service Hub / Technician Station 1' },
  { username: 'frontdesk2', label: 'frontdesk2 (Front Desk) - Midtown Service Hub / Front Desk Terminal 1' },
];

/**
 * Login page with workspace selector.
 * Provides username/password inputs, store/workstation dropdowns,
 * and submit button with loading/disabled/submitting states.
 */

var _stores = [];
var _workstations = [];

/**
 * Re-render Layui's custom dropdown wrapper after we mutate the
 * underlying native <select>. Layui creates a sibling DOM tree to
 * display selects; without this call, the wrapper keeps showing the
 * old options even though `selectEl.innerHTML` has been updated, so
 * the user sees a stale "-- Select Store --" / stale failure label.
 */
function rerenderLayuiSelect() {
  if (typeof layui !== 'undefined' && layui.form) {
    layui.form.render('select', 'login-form-filter');
  }
}

/**
 * Common loader for the two bootstrap dropdowns. Fetches with bounded
 * retries so a transient backend-still-booting blip recovers on its
 * own (the previous implementation showed "Failed to load …" on the
 * very first 502/network hiccup and never retried, which made the
 * login page look permanently broken even when the backend came up
 * two seconds later). On terminal failure logs the actual cause to
 * the console — the previous `.catch(function () { … })` swallowed
 * the error entirely so there was no way to tell whether it was a
 * 503, a JSON parse error, a CORS rejection, or a DOM exception
 * inside the success handler.
 *
 * @param {string}       label      Human label for the option text + log
 * @param {string}       path       API path (relative to /api/v1)
 * @param {object|null}  params     Query params or null
 * @param {HTMLElement}  selectEl   Target <select> to repopulate
 * @param {string}       placeholder First option ("-- Select … --")
 * @param {function}     applyData  (data) => void, populates options from response
 */
function loadDropdownWithRetry(label, path, params, selectEl, placeholder, applyData) {
  if (!selectEl) return;
  selectEl.innerHTML = '<option value="">Loading ' + label + '…</option>';
  rerenderLayuiSelect();

  var attempt = 0;
  var maxAttempts = 4; // ~0 + 600 + 1500 + 3000 = ≈5s total window

  function tryOnce() {
    attempt++;
    api.get(path, params).then(function (res) {
      // Defensive: api.js unwraps the envelope so res.data should be
      // the payload array. If something upstream returned a non-array
      // (e.g. a stringified HTML page that slipped past JSON parsing),
      // treat it as an empty result rather than rendering "[object Object]"
      // characters into the options.
      var items = Array.isArray(res && res.data) ? res.data : [];
      try {
        applyData(items, selectEl, placeholder);
      } catch (renderErr) {
        // A throw inside applyData would otherwise bubble into Promise's
        // implicit catch and look like a network failure. Surface it.
        console.error('[login] ' + label + ' render failed:', renderErr);
        showFinalFailure();
        return;
      }
      rerenderLayuiSelect();
    }).catch(function (err) {
      console.error('[login] ' + label + ' fetch failed (attempt ' + attempt + '/' + maxAttempts + '):', err);
      if (attempt < maxAttempts) {
        // Backoff: 600ms, 1500ms, 3000ms. Backend's bootstrap-db.php
        // typically finishes within ~3s on a warm volume; this window
        // covers the cold-start path.
        var delay = attempt === 1 ? 600 : attempt === 2 ? 1500 : 3000;
        setTimeout(tryOnce, delay);
      } else {
        showFinalFailure(err);
      }
    });
  }

  function showFinalFailure(err) {
    var hint = err && err.message ? ' (' + err.message + ')' : '';
    selectEl.innerHTML =
      '<option value="">Failed to load ' + label + hint + '</option>';
    rerenderLayuiSelect();
  }

  tryOnce();
}

/**
 * Fetch available stores for the dropdown.
 */
function loadStores(selectEl) {
  loadDropdownWithRetry(
    'stores',
    '/auth/bootstrap/stores',
    null,
    selectEl,
    '-- Select Store --',
    function (items, el, placeholder) {
      _stores = items;
      var html = '<option value="">' + placeholder + '</option>';
      for (var i = 0; i < items.length; i++) {
        var s = items[i];
        html += '<option value="' + s.id + '">' + (s.name || s.id) + '</option>';
      }
      el.innerHTML = html;
    }
  );
}

/**
 * Fetch workstations for the selected store.
 */
function loadWorkstations(storeId, selectEl) {
  if (!storeId) {
    selectEl.innerHTML = '<option value="">-- Select Workstation --</option>';
    _workstations = [];
    rerenderLayuiSelect();
    return;
  }

  loadDropdownWithRetry(
    'workstations',
    '/auth/bootstrap/workstations',
    { store_id: storeId },
    selectEl,
    '-- Select Workstation --',
    function (items, el, placeholder) {
      _workstations = items;
      var html = '<option value="">' + placeholder + '</option>';
      for (var i = 0; i < items.length; i++) {
        var w = items[i];
        html += '<option value="' + w.id + '">' + (w.name || w.id) + '</option>';
      }
      el.innerHTML = html;
    }
  );
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
