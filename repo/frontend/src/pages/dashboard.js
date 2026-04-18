var api = require('../services/api');
var store = require('../store/index');
var dateUtil = require('../utils/date');

/**
 * Operations and Analytics dashboard.
 * Date range picker, metric cards, analytics section, CSV export.
 * Supports .is-loading, .is-empty, .is-error states.
 */

var _dateFrom = '';
var _dateTo = '';

/**
 * Format a metric value for display.
 */
function formatMetricValue(key, value) {
  if (value === null || value === undefined) return '--';
  if (key === 'transaction_volume') return Number(value).toLocaleString();
  if (key === 'avg_fulfillment_time') return value + ' min';
  if (key === 'cancellation_rate' || key === 'complaint_rate' ||
      key === 'conversion' || key === 'retention' || key === 'zero_result_search_rate') {
    return (Number(value) * 100).toFixed(1) + '%';
  }
  return String(value);
}

/**
 * Human-readable labels for metric keys.
 */
function metricLabel(key) {
  var labels = {
    transaction_volume: 'Transaction Volume',
    avg_fulfillment_time: 'Avg Fulfillment Time',
    cancellation_rate: 'Cancellation Rate',
    complaint_rate: 'Complaint Rate',
    activity: 'Activity',
    conversion: 'Conversion',
    retention: 'Retention',
    content_quality: 'Content Quality',
    zero_result_search_rate: 'Zero-Result Search Rate',
  };
  return labels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
}

/**
 * Icon class for metrics.
 */
function metricIcon(key) {
  var icons = {
    transaction_volume: 'layui-icon-chart',
    avg_fulfillment_time: 'layui-icon-time',
    cancellation_rate: 'layui-icon-close',
    complaint_rate: 'layui-icon-flag',
    activity: 'layui-icon-chart-screen',
    conversion: 'layui-icon-transfer',
    retention: 'layui-icon-group',
    content_quality: 'layui-icon-star',
    zero_result_search_rate: 'layui-icon-search',
  };
  return icons[key] || 'layui-icon-chart';
}

/**
 * Set the dashboard content state.
 */
function setState(container, state, message) {
  var contentEl = document.getElementById('dashboard-content');
  if (!contentEl) return;

  contentEl.classList.remove('is-loading', 'is-empty', 'is-error');

  if (state === 'loading') {
    contentEl.classList.add('is-loading');
    contentEl.innerHTML =
      '<div style="text-align:center;padding:60px;">' +
        '<i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop" style="font-size:40px;"></i>' +
        '<p style="margin-top:12px;">Loading dashboard data...</p>' +
      '</div>';
  } else if (state === 'empty') {
    contentEl.classList.add('is-empty');
    contentEl.innerHTML =
      '<div style="text-align:center;padding:60px;color:#999;">' +
        '<i class="layui-icon layui-icon-face-surprised" style="font-size:40px;"></i>' +
        '<p style="margin-top:12px;">' + (message || 'No data available for the selected period.') + '</p>' +
      '</div>';
  } else if (state === 'error') {
    contentEl.classList.add('is-error');
    contentEl.innerHTML =
      '<div style="text-align:center;padding:60px;color:#FF5722;">' +
        '<i class="layui-icon layui-icon-face-cry" style="font-size:40px;"></i>' +
        '<p style="margin-top:12px;">' + (message || 'Failed to load dashboard data.') + '</p>' +
      '</div>';
  }
}

/**
 * Build metric cards HTML.
 */
function buildMetricCards(metrics) {
  var keys = ['transaction_volume', 'avg_fulfillment_time', 'cancellation_rate', 'complaint_rate'];
  var html = '<div class="layui-row layui-col-space16">';
  for (var i = 0; i < keys.length; i++) {
    var key = keys[i];
    var value = metrics[key];
    html +=
      '<div class="layui-col-md3 layui-col-sm6">' +
        '<div class="layui-card">' +
          '<div class="layui-card-header">' +
            '<i class="layui-icon ' + metricIcon(key) + '"></i> ' + metricLabel(key) +
          '</div>' +
          '<div class="layui-card-body" style="font-size:28px;text-align:center;padding:20px 0;">' +
            formatMetricValue(key, value) +
          '</div>' +
        '</div>' +
      '</div>';
  }
  html += '</div>';
  return html;
}

/**
 * Build analytics section HTML.
 */
function buildAnalyticsSection(analytics) {
  var keys = ['activity', 'conversion', 'retention', 'content_quality', 'zero_result_search_rate'];
  var html =
    '<h4 style="margin:24px 0 12px;">Analytics</h4>' +
    '<table class="layui-table">' +
      '<colgroup><col width="250"><col></colgroup>' +
      '<thead><tr><th>Metric</th><th>Value</th></tr></thead>' +
      '<tbody>';

  for (var i = 0; i < keys.length; i++) {
    var key = keys[i];
    var value = analytics[key];
    html += '<tr>' +
      '<td><i class="layui-icon ' + metricIcon(key) + '"></i> ' + metricLabel(key) + '</td>' +
      '<td>' + formatMetricValue(key, value) + '</td>' +
    '</tr>';
  }

  html += '</tbody></table>';
  return html;
}

/**
 * Fetch dashboard data and render.
 */
function fetchDashboard() {
  setState(null, 'loading');

  var params = {};
  if (_dateFrom) params.from = _dateFrom;
  if (_dateTo) params.to = _dateTo;
  params.store_id = store.getStoreId();

  // Fetch both operations and analytics endpoints
  Promise.all([
    api.get('/dashboards/operations', params),
    api.get('/dashboards/analytics', params),
  ]).then(function (results) {
      var metrics = results[0].data || {};
      var analytics = results[1].data || {};

      if (Object.keys(metrics).length === 0 && Object.keys(analytics).length === 0) {
        setState(null, 'empty');
        return;
      }

      var contentEl = document.getElementById('dashboard-content');
      if (!contentEl) return;
      contentEl.classList.remove('is-loading', 'is-empty', 'is-error');

      var html = buildMetricCards(metrics);
      html += buildAnalyticsSection(analytics);
      contentEl.innerHTML = html;
    })
    .catch(function (err) {
      setState(null, 'error', 'Failed to load dashboard data: ' + (err.message || 'Unknown error'));
    });
}

/**
 * Export dashboard data as CSV.
 */
function exportCSV() {
  var params = {};
  if (_dateFrom) params.from = _dateFrom;
  if (_dateTo) params.to = _dateTo;
  params.store_id = store.getStoreId();

  api.get('/dashboards/operations/export.csv', params)
    .then(function (res) {
      var csvContent = res.data.csv || res.data;
      if (typeof csvContent === 'string') {
        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'dashboard_export.csv';
        link.click();
        URL.revokeObjectURL(url);
      }
    })
    .catch(function (err) {
      if (typeof layui !== 'undefined' && layui.layer) {
        layui.layer.msg('Export failed: ' + (err.message || 'Unknown error'), { icon: 2 });
      }
    });
}

/**
 * Render the dashboard page into the given container.
 *
 * @param {HTMLElement} container
 */
function render(container) {
  if (!container) return;

  // Set default date range (last 30 days)
  var now = new Date();
  var thirtyDaysAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
  _dateFrom = dateUtil.formatMMDDYYYY(thirtyDaysAgo);
  _dateTo = dateUtil.formatMMDDYYYY(now);

  var html =
    '<div class="layui-form" lay-filter="dashboard-form">' +
      '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:20px;">' +
        '<div class="layui-inline">' +
          '<label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">From</label>' +
          '<div class="layui-input-inline" style="width:140px;">' +
            '<input type="text" name="date_from" id="dashboard-date-from" class="layui-input" placeholder="MM/DD/YYYY" value="' + _dateFrom + '">' +
          '</div>' +
        '</div>' +
        '<div class="layui-inline">' +
          '<label class="layui-form-label" style="width:auto;padding:0 10px 0 0;">To</label>' +
          '<div class="layui-input-inline" style="width:140px;">' +
            '<input type="text" name="date_to" id="dashboard-date-to" class="layui-input" placeholder="MM/DD/YYYY" value="' + _dateTo + '">' +
          '</div>' +
        '</div>' +
        '<button type="button" class="layui-btn layui-btn-sm" id="dashboard-apply-btn">Apply</button>' +
        '<button type="button" class="layui-btn layui-btn-sm layui-btn-warm" id="dashboard-export-btn">' +
          '<i class="layui-icon layui-icon-export"></i> Export CSV' +
        '</button>' +
      '</div>' +
    '</div>' +
    '<div id="dashboard-content"></div>';

  container.innerHTML = html;

  // Apply date range
  var applyBtn = document.getElementById('dashboard-apply-btn');
  if (applyBtn) {
    applyBtn.addEventListener('click', function () {
      _dateFrom = (document.getElementById('dashboard-date-from').value || '').trim();
      _dateTo = (document.getElementById('dashboard-date-to').value || '').trim();
      fetchDashboard();
    });
  }

  // Export CSV
  var exportBtn = document.getElementById('dashboard-export-btn');
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      exportCSV();
    });
  }

  // Initial load
  fetchDashboard();
}

module.exports = {
  render: render,
};
