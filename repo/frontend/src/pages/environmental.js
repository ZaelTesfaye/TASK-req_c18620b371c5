/**
 * Environmental Analytics Page
 * CSV/sensor import, aligned buckets, derived metrics, lineage explorer, formula management.
 */
var api = require('../services/api');
var store = require('../store/index');

function render(container) {
    container.innerHTML =
        '<div class="environmental-page">' +
            '<h2>Environmental Analytics</h2>' +
            '<div class="layui-tab">' +
                '<ul class="layui-tab-title">' +
                    '<li class="layui-this">Import Data</li>' +
                    '<li>Aligned Buckets</li>' +
                    '<li>Derived Metrics</li>' +
                    '<li>Lineage Explorer</li>' +
                    '<li>Formulas</li>' +
                '</ul>' +
                '<div class="layui-tab-content">' +
                    '<div class="layui-tab-item layui-show" id="tab-import"></div>' +
                    '<div class="layui-tab-item" id="tab-buckets"></div>' +
                    '<div class="layui-tab-item" id="tab-derived"></div>' +
                    '<div class="layui-tab-item" id="tab-lineage"></div>' +
                    '<div class="layui-tab-item" id="tab-formulas"></div>' +
                '</div>' +
            '</div>' +
        '</div>';

    renderImportTab();
    loadAlignedBuckets();
    loadDerivedMetrics();
    loadFormulas();
}

function renderImportTab() {
    var tab = document.getElementById('tab-import');
    tab.innerHTML =
        '<div class="import-section">' +
            '<h3>CSV Import</h3>' +
            '<div class="layui-form-item">' +
                '<label class="layui-form-label">Source ID:</label>' +
                '<div class="layui-input-inline"><input type="number" id="csv-source-id" class="layui-input" value="1"></div>' +
            '</div>' +
            '<textarea id="csv-data" class="layui-textarea" placeholder="Paste CSV data (metric_type,metric_value,observed_at,zone_id)" rows="6"></textarea>' +
            '<button class="layui-btn" id="btn-import-csv">Import CSV</button>' +
            '<div id="import-result" class="is-empty"></div>' +
        '</div>';

    var importBtn = document.getElementById('btn-import-csv');
    if (importBtn) {
        importBtn.onclick = function() {
            importBtn.className = 'layui-btn is-submitting';
            importBtn.disabled = true;
            var sourceId = document.getElementById('csv-source-id').value;
            var csvText = document.getElementById('csv-data').value;
            var records = parseCsvRecords(csvText);

            api.post('environment/import/csv', {
                source_id: parseInt(sourceId),
                records: records,
            }).then(function(resp) {
                importBtn.className = 'layui-btn';
                importBtn.disabled = false;
                var result = document.getElementById('import-result');
                if (resp.success) {
                    result.className = 'is-success';
                    result.innerHTML = 'Imported ' + resp.data.imported + ' records (Batch: ' + resp.data.batch_id + ')';
                } else {
                    result.className = 'is-error';
                    result.innerHTML = resp.message || 'Import failed';
                }
            });
        };
    }
}

function parseCsvRecords(text) {
    var lines = text.trim().split('\n');
    var records = [];
    lines.forEach(function(line) {
        var parts = line.split(',');
        if (parts.length >= 3) {
            records.push({
                metric_type: parts[0].trim(),
                metric_value: parseFloat(parts[1].trim()),
                observed_at: parts[2].trim(),
                zone_id: parts[3] ? parseInt(parts[3].trim()) : null,
            });
        }
    });
    return records;
}

function loadAlignedBuckets() {
    var tab = document.getElementById('tab-buckets');
    tab.className = 'layui-tab-item is-loading';
    api.get('environment/aligned-buckets?store_id=' + store.getStoreId()).then(function(resp) {
        if (resp.success && resp.data && resp.data.items) {
            var html = '<table class="layui-table"><thead><tr><th>Bucket Start</th><th>Zone</th><th>Completeness</th><th>Confidence</th><th>Label</th></tr></thead><tbody>';
            resp.data.items.forEach(function(b) {
                html += '<tr><td>' + b.bucket_start + '</td><td>' + (b.zone_id || 'All') + '</td>' +
                    '<td>' + (b.completeness_ratio * 100).toFixed(1) + '%</td>' +
                    '<td>' + (b.confidence_score * 100).toFixed(1) + '%</td>' +
                    '<td><span class="confidence-' + b.confidence_label.toLowerCase() + '">' + b.confidence_label + '</span></td></tr>';
            });
            html += '</tbody></table>';
            tab.innerHTML = html;
            tab.className = 'layui-tab-item is-success';
        } else {
            tab.className = 'layui-tab-item is-empty';
            tab.innerHTML = 'No aligned buckets found';
        }
    });
}

function loadDerivedMetrics() {
    var tab = document.getElementById('tab-derived');
    api.get('environment/derived-metrics?store_id=' + store.getStoreId()).then(function(resp) {
        if (resp.success && resp.data && resp.data.items) {
            var html = '<table class="layui-table"><thead><tr><th>Bucket</th><th>Metric</th><th>Value</th><th>Formula Ver.</th><th>Lineage</th></tr></thead><tbody>';
            resp.data.items.forEach(function(m) {
                html += '<tr><td>' + m.bucket_start + '</td><td>' + m.metric_key + '</td>' +
                    '<td>' + parseFloat(m.metric_value).toFixed(4) + '</td>' +
                    '<td>v' + m.formula_version_id + '</td>' +
                    '<td><a href="#" onclick="viewLineage(' + m.id + ')">View</a></td></tr>';
            });
            html += '</tbody></table>';
            tab.innerHTML = html;
        }
    });
}

function loadFormulas() {
    var tab = document.getElementById('tab-formulas');
    api.get('environment/formulas').then(function(resp) {
        if (resp.success && resp.data) {
            var formulas = resp.data.items || resp.data;
            var html = '<table class="layui-table"><thead><tr><th>Key</th><th>Version</th><th>Expression</th><th>Effective From</th></tr></thead><tbody>';
            (Array.isArray(formulas) ? formulas : []).forEach(function(f) {
                html += '<tr><td>' + f.formula_key + '</td><td>v' + f.version_no + '</td>' +
                    '<td>' + f.formula_expression + '</td><td>' + f.effective_from + '</td></tr>';
            });
            html += '</tbody></table>';
            tab.innerHTML = html;
        }
    });
}

function viewLineage(derivedMetricId) {
    var tab = document.getElementById('tab-lineage');
    if (!tab) return;
    tab.innerHTML = 'Loading lineage for metric ' + derivedMetricId + '...';

    api.get('environment/lineage/' + derivedMetricId).then(function(resp) {
        var lineage = resp.data;
        if (lineage) {
            tab.innerHTML =
                '<h4>Lineage for Derived Metric #' + derivedMetricId + '</h4>' +
                '<pre>' + JSON.stringify(lineage, null, 2) + '</pre>';
        } else {
            tab.innerHTML = '<p>No lineage found for this metric.</p>';
        }
    }).catch(function(err) {
        tab.innerHTML = '<p style="color:#FF5722;">Failed to load lineage: ' + (err.message || 'Error') + '</p>';
    });
}

// Register global handlers for inline onclick
if (typeof window !== 'undefined') {
    window.viewLineage = viewLineage;
}

module.exports = { render: render, viewLineage: viewLineage };
