/**
 * Admin Console Page
 * User management, store/workstation bindings, experiments, events, key rotation.
 */
var api = require('../services/api');
var store = require('../store/index');
var router = require('../router/index');

/**
 * Resolve a comma-separated user-typed role list against the canonical
 * router.ROLES enum. Returns either:
 *   { ok: true, codes: [...] }   - all entries match a known role
 *   { ok: false, unknown: [...] } - one or more typos
 *
 * Centralising this here keeps the admin UI from posting role codes
 * the backend will reject with INVALID_ROLE — both layers now agree
 * on the same closed set (customer, front_desk, technician,
 * store_manager, finance, administrator). Empty input is treated as
 * an error because role updates with an empty list are not what an
 * admin "Enter new roles" prompt is for.
 */
function parseRoleInput(raw) {
    var codes = (raw || '')
        .split(',')
        .map(function (r) { return r.trim(); })
        .filter(Boolean);
    if (codes.length === 0) {
        return { ok: false, unknown: [], empty: true };
    }
    var known = Object.keys(router.ROLES).map(function (k) { return router.ROLES[k]; });
    var unknown = codes.filter(function (c) { return known.indexOf(c) === -1; });
    if (unknown.length > 0) {
        return { ok: false, unknown: unknown };
    }
    return { ok: true, codes: codes };
}

function knownRolesHint() {
    return Object.keys(router.ROLES)
        .map(function (k) { return router.ROLES[k]; })
        .join(', ');
}

function render(container) {
    container.innerHTML =
        '<div class="admin-page">' +
            '<h2>Administrator Console</h2>' +
            '<div class="layui-tab">' +
                '<ul class="layui-tab-title">' +
                    '<li class="layui-this">Users</li>' +
                    '<li>Experiments</li>' +
                    '<li>Events</li>' +
                    '<li>Security</li>' +
                '</ul>' +
                '<div class="layui-tab-content">' +
                    '<div class="layui-tab-item layui-show" id="tab-users"></div>' +
                    '<div class="layui-tab-item" id="tab-experiments"></div>' +
                    '<div class="layui-tab-item" id="tab-events"></div>' +
                    '<div class="layui-tab-item" id="tab-security"></div>' +
                '</div>' +
            '</div>' +
        '</div>';

    loadUsers();
    loadExperiments();
    loadEvents();
    renderSecurityTab();

    // Register global action handlers for inline onclick attributes
    window.editUserRoles = editUserRoles;
    window.startExperiment = startExperiment;
    window.stopExperiment = stopExperiment;
}

function loadUsers() {
    var tab = document.getElementById('tab-users');
    tab.className = 'layui-tab-item layui-show is-loading';
    tab.innerHTML = 'Loading users...';

    api.get('admin/users').then(function(resp) {
        var users = resp.data;
        if (Array.isArray(users)) {
            var html = '<button class="layui-btn layui-btn-sm" id="btn-create-user">Create User</button>' +
                '<table class="layui-table"><thead><tr><th>ID</th><th>Username</th><th>Status</th><th>Roles</th><th>Actions</th></tr></thead><tbody>';
            users.forEach(function(u) {
                html += '<tr><td>' + u.id + '</td><td>' + (u.display_name || u.username) + '</td><td>' + u.status + '</td>' +
                    '<td>' + (u.roles || []).join(', ') + '</td>' +
                    '<td><button class="layui-btn layui-btn-xs" onclick="editUserRoles(' + u.id + ')">Edit Roles</button></td></tr>';
            });
            html += '</tbody></table>';
            tab.innerHTML = html;
            tab.className = 'layui-tab-item layui-show is-success';

            // Wire create user button
            var createBtn = document.getElementById('btn-create-user');
            if (createBtn) {
                createBtn.addEventListener('click', createUser);
            }
        }
    }).catch(function() {
        tab.className = 'layui-tab-item layui-show is-error';
        tab.innerHTML = 'Failed to load users';
    });
}

function loadExperiments() {
    var tab = document.getElementById('tab-experiments');
    api.get('experiments').then(function(resp) {
        var exps = resp.data;
        if (exps && exps.items) { exps = exps.items; }
        if (!Array.isArray(exps)) { exps = []; }
        var html = '<button class="layui-btn layui-btn-sm" id="btn-create-exp">Create Experiment</button>' +
            '<table class="layui-table"><thead><tr><th>Key</th><th>Name</th><th>Status</th><th>Holdout %</th><th>Actions</th></tr></thead><tbody>';
        exps.forEach(function(e) {
            html += '<tr><td>' + e.key + '</td><td>' + e.name + '</td><td>' + e.status + '</td>' +
                '<td>' + e.holdout_percent + '%</td>' +
                '<td>' +
                (e.status === 'draft' ? '<button class="layui-btn layui-btn-xs layui-btn-normal" onclick="startExperiment(' + e.id + ')">Start</button>' : '') +
                (e.status === 'running' ? '<button class="layui-btn layui-btn-xs layui-btn-danger" onclick="stopExperiment(' + e.id + ')">Stop</button>' : '') +
                '</td></tr>';
        });
        html += '</tbody></table>';
        tab.innerHTML = html;

        var createExpBtn = document.getElementById('btn-create-exp');
        if (createExpBtn) { createExpBtn.addEventListener('click', createExperiment); }
    });
}

function loadEvents() {
    var tab = document.getElementById('tab-events');
    api.get('events').then(function(resp) {
        var events = resp.data;
        if (events && events.items) { events = events.items; }
        if (!Array.isArray(events)) { events = []; }
        var html = '<button class="layui-btn layui-btn-sm" id="btn-create-event">Define Event</button>' +
            '<table class="layui-table"><thead><tr><th>Key</th><th>Name</th><th>Active</th></tr></thead><tbody>';
        events.forEach(function(e) {
            html += '<tr><td>' + (e.event_key || e.key) + '</td><td>' + e.name + '</td><td>' + (e.active ? 'Yes' : 'No') + '</td></tr>';
        });
        html += '</tbody></table>';
        tab.innerHTML = html;

        var createEvtBtn = document.getElementById('btn-create-event');
        if (createEvtBtn) { createEvtBtn.addEventListener('click', createEvent); }
    });
}

function renderSecurityTab() {
    var tab = document.getElementById('tab-security');
    tab.innerHTML =
        '<div class="security-actions">' +
            '<h3>Encryption Key Management</h3>' +
            '<p>Current active key version: <span id="key-version">1</span></p>' +
            '<div class="layui-form-item">' +
                '<label>New version:</label> ' +
                '<input type="number" id="new-key-version" class="layui-input" style="width:100px;display:inline-block" value="2" min="1">' +
            '</div>' +
            '<button class="layui-btn layui-btn-danger" id="btn-rotate-key">Rotate Encryption Key</button>' +
            '<h3>Store/Workstation Bindings</h3>' +
            '<button class="layui-btn layui-btn-sm" id="btn-reassign">Reassign User Binding</button>' +
        '</div>';

    var rotateBtn = document.getElementById('btn-rotate-key');
    if (rotateBtn) {
        rotateBtn.onclick = function() {
            var versionInput = document.getElementById('new-key-version');
            var newVersion = versionInput ? parseInt(versionInput.value, 10) : 0;
            if (!newVersion || newVersion <= 0) {
                if (typeof layui !== 'undefined' && layui.layer) {
                    layui.layer.msg('Please enter a valid key version number.', { icon: 0 });
                }
                return;
            }
            rotateBtn.className = 'layui-btn layui-btn-danger is-submitting';
            rotateBtn.disabled = true;
            api.post('admin/encryption/keys/rotate', { new_version: newVersion }).then(function() {
                rotateBtn.className = 'layui-btn layui-btn-danger is-success';
                rotateBtn.disabled = false;
                rotateBtn.textContent = 'Key Rotated Successfully';
            }).catch(function(err) {
                rotateBtn.className = 'layui-btn layui-btn-danger';
                rotateBtn.disabled = false;
                if (typeof layui !== 'undefined' && layui.layer) {
                    layui.layer.msg(err.message || 'Rotation failed.', { icon: 2 });
                }
            });
        };
    }
}

// --- Action handlers wired via onclick attributes ---

function editUserRoles(userId) {
    var newRoles = prompt(
        'Enter new role codes (comma-separated).\nValid: ' + knownRolesHint(),
        'front_desk'
    );
    if (newRoles === null) return;
    var parsed = parseRoleInput(newRoles);
    if (!parsed.ok) {
        var msg = parsed.empty
            ? 'At least one role code is required.'
            : 'Unknown role code(s): ' + parsed.unknown.join(', ') +
              '\nValid roles: ' + knownRolesHint();
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg(msg, { icon: 2 });
        } else {
            alert(msg);
        }
        return;
    }
    api.patch('admin/users/' + userId + '/roles', { role_codes: parsed.codes }).then(function() {
        loadUsers();
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg('Roles updated.', { icon: 1 });
        }
    }).catch(function(err) {
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg(err.message || 'Failed to update roles.', { icon: 2 });
        }
    });
}

function startExperiment(expId) {
    api.post('experiments/' + expId + '/start', {}).then(function() {
        loadExperiments();
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg('Experiment started.', { icon: 1 });
        }
    }).catch(function(err) {
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg(err.message || 'Failed to start experiment.', { icon: 2 });
        }
    });
}

function stopExperiment(expId) {
    api.post('experiments/' + expId + '/stop', {}).then(function() {
        loadExperiments();
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg('Experiment stopped.', { icon: 1 });
        }
    }).catch(function(err) {
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg(err.message || 'Failed to stop experiment.', { icon: 2 });
        }
    });
}

// --- Create handlers ---

function createUser() {
    var username = prompt('Enter username:');
    if (!username) return;
    var password = prompt('Enter password (min 12 chars, mixed case, digit, special):');
    if (!password) return;
    var roles = prompt(
        'Enter role codes (comma-separated).\nValid: ' + knownRolesHint(),
        'front_desk'
    );
    // roles is optional on create; only validate if the admin typed
    // something. An empty value -> the user is created with no roles.
    var roleCodes = [];
    if (roles && roles.trim()) {
        var parsed = parseRoleInput(roles);
        if (!parsed.ok) {
            var msg = 'Unknown role code(s): ' + parsed.unknown.join(', ') +
                      '\nValid roles: ' + knownRolesHint();
            if (typeof layui !== 'undefined' && layui.layer) {
                layui.layer.msg(msg, { icon: 2 });
            } else {
                alert(msg);
            }
            return;
        }
        roleCodes = parsed.codes;
    }

    api.post('admin/users', {
        username: username,
        password: password,
        role_codes: roleCodes,
        bindings: [{ store_id: parseInt(store.getStoreId(), 10) || 1, workstation_id: 1 }],
    }).then(function() {
        loadUsers();
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg('User created.', { icon: 1 });
        }
    }).catch(function(err) {
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg(err.message || 'Failed to create user.', { icon: 2 });
        }
    });
}

function createExperiment() {
    var key = prompt('Enter experiment key (e.g. promo_banner_v2):');
    if (!key) return;
    var name = prompt('Enter experiment name:');
    if (!name) return;
    var holdout = prompt('Holdout percentage (e.g. 10):', '10');

    api.post('experiments', {
        key: key,
        name: name,
        holdout_percent: parseFloat(holdout) || 10,
        variants: [
            { variant_key: 'control', traffic_percent: 45 },
            { variant_key: 'treatment', traffic_percent: 45 },
        ],
    }).then(function() {
        loadExperiments();
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg('Experiment created.', { icon: 1 });
        }
    }).catch(function(err) {
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg(err.message || 'Failed to create experiment.', { icon: 2 });
        }
    });
}

function createEvent() {
    var eventKey = prompt('Enter event key (e.g. page_view):');
    if (!eventKey) return;
    var name = prompt('Enter event name:');
    if (!name) return;

    api.post('events', {
        event_key: eventKey,
        name: name,
        description: '',
    }).then(function() {
        loadEvents();
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg('Event created.', { icon: 1 });
        }
    }).catch(function(err) {
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg(err.message || 'Failed to create event.', { icon: 2 });
        }
    });
}

module.exports = {
    render: render,
    editUserRoles: editUserRoles,
    startExperiment: startExperiment,
    stopExperiment: stopExperiment,
    createUser: createUser,
    createExperiment: createExperiment,
    createEvent: createEvent,
};
