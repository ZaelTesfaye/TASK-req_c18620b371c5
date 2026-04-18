/**
 * Cleansing Batch Review Page
 * Batch list, preview, approve/rollback (admin only), manual review queue.
 */
var api = require('../services/api');
var store = require('../store/index');

function render(container) {
    container.innerHTML =
        '<div class="cleansing-page">' +
            '<h2>Data Cleansing & Standardization</h2>' +
            '<div class="layui-tab">' +
                '<ul class="layui-tab-title">' +
                    '<li class="layui-this">Batches</li>' +
                    '<li>Manual Review Queue</li>' +
                    '<li>Import</li>' +
                '</ul>' +
                '<div class="layui-tab-content">' +
                    '<div class="layui-tab-item layui-show" id="tab-batches"></div>' +
                    '<div class="layui-tab-item" id="tab-review-queue"></div>' +
                    '<div class="layui-tab-item" id="tab-import"></div>' +
                '</div>' +
            '</div>' +
            '<div id="preview-modal" style="display:none"></div>' +
        '</div>';

    loadBatches();
    loadReviewQueue();
    renderImportTab();
}

function loadBatches() {
    var tab = document.getElementById('tab-batches');
    tab.className = 'layui-tab-item layui-show is-loading';
    tab.innerHTML = 'Loading batches...';

    api.get('cleansing/batches').then(function(resp) {
        if (resp.success && resp.data && resp.data.items) {
            var html = '<table class="layui-table"><thead><tr><th>Batch #</th><th>Source</th><th>Profile</th><th>Status</th><th>Submitted By</th><th>Actions</th></tr></thead><tbody>';
            resp.data.items.forEach(function(b) {
                var actions = '<button class="layui-btn layui-btn-xs" onclick="previewBatch(' + b.id + ')">Preview</button>';
                if (b.status === 'pending_review' && store.hasRole('administrator')) {
                    actions += ' <button class="layui-btn layui-btn-xs layui-btn-normal" onclick="approveBatch(' + b.id + ')">Approve</button>';
                    actions += ' <button class="layui-btn layui-btn-xs layui-btn-danger" onclick="rollbackBatch(' + b.id + ')">Rollback</button>';
                }
                if (b.status === 'approved' && store.hasRole('administrator')) {
                    actions += ' <button class="layui-btn layui-btn-xs layui-btn-danger" onclick="rollbackBatch(' + b.id + ')">Rollback</button>';
                }
                html += '<tr><td>' + b.batch_no + '</td><td>' + b.source_name + '</td>' +
                    '<td>' + b.dataset_profile + '</td><td>' + b.status + '</td>' +
                    '<td>' + b.submitted_by + '</td><td>' + actions + '</td></tr>';
            });
            html += '</tbody></table>';
            tab.innerHTML = html;
            tab.className = 'layui-tab-item layui-show is-success';
        } else {
            tab.className = 'layui-tab-item layui-show is-empty';
            tab.innerHTML = 'No cleansing batches found';
        }
    }).catch(function() {
        tab.className = 'layui-tab-item layui-show is-error';
        tab.innerHTML = 'Failed to load batches';
    });
}

function loadReviewQueue() {
    var tab = document.getElementById('tab-review-queue');
    api.get('cleansing/manual-review-queue').then(function(resp) {
        if (resp.success && resp.data && resp.data.items && resp.data.items.length > 0) {
            var html = '<table class="layui-table"><thead><tr><th>Batch</th><th>Row</th><th>Reason</th><th>Queued At</th><th>Status</th></tr></thead><tbody>';
            resp.data.items.forEach(function(r) {
                html += '<tr><td>' + r.batch_id + '</td><td>' + r.row_id + '</td>' +
                    '<td>' + r.reason_code + '</td><td>' + r.queued_at + '</td>' +
                    '<td>' + (r.resolved_at ? 'Resolved' : 'Pending') + '</td></tr>';
            });
            html += '</tbody></table>';
            tab.innerHTML = html;
        } else {
            tab.innerHTML = '<div class="is-empty">No items in review queue</div>';
        }
    });
}

function renderImportTab() {
    var tab = document.getElementById('tab-import');
    tab.innerHTML =
        '<div class="import-form layui-form">' +
            '<div class="layui-form-item">' +
                '<label class="layui-form-label">Source Name:</label>' +
                '<div class="layui-input-inline"><input type="text" id="cln-source" class="layui-input"></div>' +
            '</div>' +
            '<div class="layui-form-item">' +
                '<label class="layui-form-label">Profile:</label>' +
                '<div class="layui-input-inline">' +
                    '<select id="cln-profile" class="layui-input">' +
                        '<option value="customer_entered">Customer Entered</option>' +
                        '<option value="partner_provided">Partner Provided</option>' +
                    '</select>' +
                '</div>' +
            '</div>' +
            '<textarea id="cln-data" class="layui-textarea" rows="6" placeholder="JSON array of rows: [{job_title, company, city, salary, education, experience}]"></textarea>' +
            '<button class="layui-btn" id="btn-import-cleansing">Import Batch</button>' +
            '<div id="cln-import-result"></div>' +
        '</div>';

    var btn = document.getElementById('btn-import-cleansing');
    if (btn) {
        btn.onclick = function() {
            btn.className = 'layui-btn is-submitting';
            btn.disabled = true;
            var rows;
            try {
                rows = JSON.parse(document.getElementById('cln-data').value);
            } catch (e) {
                document.getElementById('cln-import-result').innerHTML = '<span class="is-error">Invalid JSON</span>';
                btn.className = 'layui-btn';
                btn.disabled = false;
                return;
            }

            api.post('cleansing/import', {
                source_name: document.getElementById('cln-source').value,
                dataset_profile: document.getElementById('cln-profile').value,
                rows: rows,
            }).then(function(resp) {
                btn.className = 'layui-btn';
                btn.disabled = false;
                var result = document.getElementById('cln-import-result');
                if (resp.success) {
                    result.innerHTML = '<span class="is-success">Batch ' + resp.data.batch_no + ' imported (' + resp.data.rows + ' rows)</span>';
                    loadBatches();
                } else {
                    result.innerHTML = '<span class="is-error">' + (resp.message || 'Import failed') + '</span>';
                }
            });
        };
    }
}

function previewBatch(batchId) {
    api.get('cleansing/batches/' + batchId + '/preview').then(function(resp) {
        var data = resp.data;
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.open({
                type: 1,
                title: 'Batch Preview #' + batchId,
                area: ['600px', '400px'],
                content: '<pre style="padding:15px;">' + JSON.stringify(data, null, 2) + '</pre>',
            });
        }
    }).catch(function(err) {
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg(err.message || 'Failed to load preview.', { icon: 2 });
        }
    });
}

function approveBatch(batchId) {
    api.post('cleansing/batches/' + batchId + '/approve', {}).then(function() {
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg('Batch approved.', { icon: 1 });
        }
        // Refresh the batches tab
        var tab = document.getElementById('tab-batches');
        if (tab) { loadBatches(); }
    }).catch(function(err) {
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg(err.message || 'Failed to approve batch.', { icon: 2 });
        }
    });
}

function rollbackBatch(batchId) {
    api.post('cleansing/batches/' + batchId + '/rollback', {}).then(function() {
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg('Batch rolled back.', { icon: 1 });
        }
        var tab = document.getElementById('tab-batches');
        if (tab) { loadBatches(); }
    }).catch(function(err) {
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg(err.message || 'Failed to rollback batch.', { icon: 2 });
        }
    });
}

// Register global handlers for inline onclick attributes
if (typeof window !== 'undefined') {
    window.previewBatch = previewBatch;
    window.approveBatch = approveBatch;
    window.rollbackBatch = rollbackBatch;
}

module.exports = {
    render: render,
    previewBatch: previewBatch,
    approveBatch: approveBatch,
    rollbackBatch: rollbackBatch,
};
