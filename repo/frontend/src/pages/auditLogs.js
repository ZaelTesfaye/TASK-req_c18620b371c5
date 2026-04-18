/**
 * Audit Log Search Page
 * Filters: user, role, store, workstation, action, entity type, entity id, date range.
 * Paginated results table showing immutable log entries.
 */
var api = require('../services/api');
var dateUtils = require('../utils/date');

function render(container) {
    container.innerHTML =
        '<div class="audit-logs-page">' +
            '<h2>Audit Log Search</h2>' +
            '<div class="layui-form audit-filters">' +
                '<div class="layui-form-item">' +
                    '<div class="layui-inline"><label class="layui-form-label">User ID:</label><div class="layui-input-inline"><input type="number" id="af-user" class="layui-input"></div></div>' +
                    '<div class="layui-inline"><label class="layui-form-label">Role:</label><div class="layui-input-inline"><select id="af-role" class="layui-input"><option value="">All</option><option value="customer">Customer</option><option value="front_desk">Front Desk</option><option value="technician">Technician</option><option value="store_manager">Store Manager</option><option value="finance">Finance</option><option value="administrator">Administrator</option></select></div></div>' +
                '</div>' +
                '<div class="layui-form-item">' +
                    '<div class="layui-inline"><label class="layui-form-label">Store ID:</label><div class="layui-input-inline"><input type="number" id="af-store" class="layui-input"></div></div>' +
                    '<div class="layui-inline"><label class="layui-form-label">Workstation:</label><div class="layui-input-inline"><input type="number" id="af-ws" class="layui-input"></div></div>' +
                '</div>' +
                '<div class="layui-form-item">' +
                    '<div class="layui-inline"><label class="layui-form-label">Action:</label><div class="layui-input-inline"><input type="text" id="af-action" class="layui-input"></div></div>' +
                    '<div class="layui-inline"><label class="layui-form-label">Entity Type:</label><div class="layui-input-inline"><input type="text" id="af-entity-type" class="layui-input"></div></div>' +
                    '<div class="layui-inline"><label class="layui-form-label">Entity ID:</label><div class="layui-input-inline"><input type="text" id="af-entity-id" class="layui-input"></div></div>' +
                '</div>' +
                '<div class="layui-form-item">' +
                    '<div class="layui-inline"><label class="layui-form-label">From:</label><div class="layui-input-inline"><input type="text" id="af-from" class="layui-input" placeholder="MM/DD/YYYY"></div></div>' +
                    '<div class="layui-inline"><label class="layui-form-label">To:</label><div class="layui-input-inline"><input type="text" id="af-to" class="layui-input" placeholder="MM/DD/YYYY"></div></div>' +
                    '<button class="layui-btn" id="btn-search-audit">Search</button>' +
                '</div>' +
            '</div>' +
            '<div id="audit-results" class="is-empty">Enter search criteria and click Search</div>' +
            '<div id="audit-pagination"></div>' +
        '</div>';

    document.getElementById('btn-search-audit').onclick = function() { searchAuditLogs(1); };
}

function searchAuditLogs(page) {
    var results = document.getElementById('audit-results');
    results.className = 'is-loading';
    results.innerHTML = 'Searching...';

    var params = {};
    var userId = document.getElementById('af-user').value;
    var role = document.getElementById('af-role').value;
    var storeId = document.getElementById('af-store').value;
    var ws = document.getElementById('af-ws').value;
    var action = document.getElementById('af-action').value;
    var entityType = document.getElementById('af-entity-type').value;
    var entityId = document.getElementById('af-entity-id').value;
    var from = document.getElementById('af-from').value;
    var to = document.getElementById('af-to').value;

    if (userId) params.user_id = userId;
    if (role) params.role_code = role;
    if (storeId) params.store_id = storeId;
    if (ws) params.workstation_id = ws;
    if (action) params.action = action;
    if (entityType) params.entity_type = entityType;
    if (entityId) params.entity_id = entityId;
    if (from) params.from = from;
    if (to) params.to = to;
    params.page = page;
    params.page_size = 20;

    var queryStr = Object.keys(params).map(function(k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');

    api.get('audit/logs?' + queryStr).then(function(resp) {
        if (resp.success && resp.data) {
            var items = resp.data.items || [];
            if (items.length === 0) {
                results.className = 'is-empty';
                results.innerHTML = 'No audit log entries found';
                return;
            }

            var html = '<p>Total: ' + resp.data.total + ' | Page ' + resp.data.page + '</p>' +
                '<table class="layui-table"><thead><tr>' +
                '<th>Time</th><th>User</th><th>Role</th><th>Store</th><th>WS</th><th>Action</th><th>Entity</th><th>Entity ID</th>' +
                '</tr></thead><tbody>';

            items.forEach(function(log) {
                html += '<tr>' +
                    '<td>' + log.created_at + '</td>' +
                    '<td>' + (log.actor_user_id || 'N/A') + '</td>' +
                    '<td>' + log.actor_role_code + '</td>' +
                    '<td>' + log.store_id + '</td>' +
                    '<td>' + log.workstation_id + '</td>' +
                    '<td>' + log.action + '</td>' +
                    '<td>' + log.entity_type + '</td>' +
                    '<td>' + (log.entity_id || 'N/A') + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
            results.className = 'is-success';
            results.innerHTML = html;

            // Pagination
            var pagination = document.getElementById('audit-pagination');
            var totalPages = Math.ceil(resp.data.total / resp.data.page_size);
            var paginationHtml = '';
            for (var i = 1; i <= Math.min(totalPages, 10); i++) {
                paginationHtml += '<button class="layui-btn layui-btn-sm' + (i === page ? ' layui-btn-normal' : '') + '" onclick="searchAuditLogs(' + i + ')">' + i + '</button> ';
            }
            pagination.innerHTML = paginationHtml;
        }
    }).catch(function() {
        results.className = 'is-error';
        results.innerHTML = 'Failed to search audit logs';
    });
}

// Expose for pagination buttons
if (typeof window !== 'undefined') {
    window.searchAuditLogs = searchAuditLogs;
}

module.exports = { render: render };
