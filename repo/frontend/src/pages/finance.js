var api = require('../services/api');
var store = require('../store/index');
var dateUtil = require('../utils/date');

/**
 * Finance reconciliation page.
 * Daily cash drawer display, open/close controls, discrepancy detection,
 * reconciliation statement, and CSV export.
 */

var VARIANCE_THRESHOLD = 1.00;
var _currentDrawer = null;

/**
 * Format a currency value as USD.
 */
function formatUSD(amount) {
  if (amount === null || amount === undefined) return '$0.00';
  return '$' + Number(amount).toFixed(2);
}

/**
 * Fetch and render the daily cash drawer data.
 */
function fetchDrawer(container) {
  container.innerHTML =
    '<div class="fieldops-empty">' +
      '<i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop" style="font-size:30px;"></i>' +
    '</div>';

  var params = {
    date: new Date().toISOString().slice(0, 10),
  };

  api.get('/finance/cash-drawer/daily', params)
    .then(function (res) {
      var drawer = res.data;
      _currentDrawer = drawer;
      renderDrawer(container, drawer);
    })
    .catch(function (err) {
      container.innerHTML =
        '<div class="fieldops-error">' +
          'Failed to load cash drawer: ' + (err.message || 'Unknown error') +
        '</div>';
    });
}

/**
 * Render cash drawer display with controls.
 */
function renderDrawer(container, drawer) {
  var isOpen = drawer && drawer.status === 'open';
  var expectedTotal = drawer ? Number(drawer.expected_total || 0) : 0;
  var openingBalance = drawer ? Number(drawer.open_amount || drawer.opening_balance || 0) : 0;

  var html =
    '<div class="layui-card">' +
      '<div class="layui-card-header">' +
        '<strong>Daily Cash Drawer</strong>' +
        ' <span class="layui-badge ' + (isOpen ? 'layui-bg-green' : 'layui-bg-gray') + '">' +
          (isOpen ? 'Open' : 'Closed') +
        '</span>' +
        '<span style="float:right;color:#999;font-size:12px;">' +
          (drawer && drawer.date ? dateUtil.toLocalDisplay(drawer.date) : dateUtil.formatMMDDYYYY(new Date())) +
        '</span>' +
      '</div>' +
      '<div class="layui-card-body">' +
        '<div class="layui-row layui-col-space16">' +
          '<div class="layui-col-md4">' +
            '<p><strong>Opening Balance:</strong> ' + formatUSD(openingBalance) + '</p>' +
          '</div>' +
          '<div class="layui-col-md4">' +
            '<p><strong>Expected Total:</strong> ' + formatUSD(expectedTotal) + '</p>' +
          '</div>' +
          '<div class="layui-col-md4">' +
            '<p><strong>Status:</strong> ' + (isOpen ? 'Open' : 'Closed') + '</p>' +
          '</div>' +
        '</div>' +

        '<div style="margin-top:20px;">' +
          (!isOpen ?
            '<div class="layui-form-item" style="display:flex;gap:8px;align-items:center;">' +
              '<label><strong>Opening Amount:</strong></label>' +
              '<input type="number" step="0.01" min="0" id="finance-opening-amount" class="layui-input" style="width:160px;" placeholder="0.00">' +
              '<button class="layui-btn layui-btn-normal" id="finance-open-drawer-btn">Open Drawer</button>' +
            '</div>'
          :
            '<div>' +
              '<div class="layui-form-item" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">' +
                '<label><strong>Counted Total:</strong></label>' +
                '<input type="number" step="0.01" min="0" id="finance-counted-total" class="layui-input" style="width:160px;" placeholder="0.00">' +
                '<button class="layui-btn layui-btn-warm" id="finance-close-drawer-btn">Close Drawer</button>' +
              '</div>' +
              '<div id="finance-discrepancy" style="margin-top:12px;"></div>' +
            '</div>'
          ) +
        '</div>' +
      '</div>' +
    '</div>';

  // Reconciliation statement
  html +=
    '<div class="layui-card" style="margin-top:16px;">' +
      '<div class="layui-card-header"><strong>Reconciliation Statement</strong></div>' +
      '<div class="layui-card-body" id="finance-reconciliation-statement">' +
        '<p style="color:#999;">Close the drawer to generate a reconciliation statement.</p>' +
      '</div>' +
    '</div>';

  container.innerHTML = html;

  // Bind open drawer
  var openBtn = document.getElementById('finance-open-drawer-btn');
  if (openBtn) {
    openBtn.addEventListener('click', function () {
      var amountInput = document.getElementById('finance-opening-amount');
      var amount = amountInput ? parseFloat(amountInput.value) : 0;

      if (isNaN(amount) || amount < 0) {
        if (typeof layui !== 'undefined' && layui.layer) {
          layui.layer.msg('Please enter a valid opening amount.', { icon: 0 });
        }
        return;
      }

      openBtn.disabled = true;
      openBtn.textContent = 'Opening...';

      api.post('/finance/cash-drawer', {
        business_date: new Date().toISOString().slice(0, 10),
        open_amount: amount,
      }).then(function () {
        if (typeof layui !== 'undefined' && layui.layer) {
          layui.layer.msg('Drawer opened.', { icon: 1 });
        }
        fetchDrawer(container);
      }).catch(function (err) {
        openBtn.disabled = false;
        openBtn.textContent = 'Open Drawer';
        if (typeof layui !== 'undefined' && layui.layer) {
          layui.layer.msg(err.message || 'Failed to open drawer.', { icon: 2 });
        }
      });
    });
  }

  // Bind close drawer
  var closeBtn = document.getElementById('finance-close-drawer-btn');
  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      var countedInput = document.getElementById('finance-counted-total');
      var counted = countedInput ? parseFloat(countedInput.value) : NaN;

      if (isNaN(counted) || counted < 0) {
        if (typeof layui !== 'undefined' && layui.layer) {
          layui.layer.msg('Please enter a valid counted total.', { icon: 0 });
        }
        return;
      }

      closeBtn.disabled = true;
      closeBtn.textContent = 'Closing...';

      api.post('/finance/cash-drawer/' + (_currentDrawer && _currentDrawer.id ? _currentDrawer.id : 0) + '/close', {
        store_id: store.getStoreId(),
        workstation_id: store.getWorkstationId(),
        counted_total: counted,
      }).then(function (res) {
        if (typeof layui !== 'undefined' && layui.layer) {
          layui.layer.msg('Drawer closed.', { icon: 1 });
        }
        // Show discrepancy
        var variance = counted - expectedTotal;
        showDiscrepancy(variance, counted, expectedTotal);
        // Show reconciliation statement
        showReconciliationStatement(res.data, counted, expectedTotal, variance);
      }).catch(function (err) {
        closeBtn.disabled = false;
        closeBtn.textContent = 'Close Drawer';
        if (typeof layui !== 'undefined' && layui.layer) {
          layui.layer.msg(err.message || 'Failed to close drawer.', { icon: 2 });
        }
      });
    });

    // Real-time discrepancy display when typing
    var countedInput = document.getElementById('finance-counted-total');
    if (countedInput) {
      countedInput.addEventListener('input', function () {
        var counted = parseFloat(countedInput.value);
        if (!isNaN(counted)) {
          var variance = counted - expectedTotal;
          showDiscrepancy(variance, counted, expectedTotal);
        }
      });
    }
  }
}

/**
 * Display discrepancy information.
 */
function showDiscrepancy(variance, counted, expected) {
  var el = document.getElementById('finance-discrepancy');
  if (!el) return;

  var absVariance = Math.abs(variance);
  var isFlagged = absVariance > VARIANCE_THRESHOLD;
  var sign = variance >= 0 ? '+' : '-';
  var color = isFlagged ? '#FF5722' : '#5FB878';

  el.innerHTML =
    '<div style="padding:12px;border-radius:4px;background:' + (isFlagged ? '#FFF3E0' : '#E8F5E9') + ';">' +
      '<p><strong>Expected:</strong> ' + formatUSD(expected) + '</p>' +
      '<p><strong>Counted:</strong> ' + formatUSD(counted) + '</p>' +
      '<p style="font-size:16px;color:' + color + ';"><strong>Variance:</strong> ' + sign + formatUSD(absVariance) +
        (isFlagged ? ' <span class="layui-badge">FLAGGED</span>' : ' <span class="layui-badge layui-bg-green">OK</span>') +
      '</p>' +
    '</div>';
}

/**
 * Display reconciliation statement.
 */
function showReconciliationStatement(data, counted, expected, variance) {
  var el = document.getElementById('finance-reconciliation-statement');
  if (!el) return;

  var statement = data || {};
  var transactions = statement.transactions || [];

  var html =
    '<table class="layui-table">' +
      '<tbody>' +
        '<tr><td><strong>Opening Amount</strong></td><td>' + formatUSD(statement.open_amount || statement.opening_balance) + '</td></tr>' +
        '<tr><td><strong>Total Sales</strong></td><td>' + formatUSD(statement.total_sales) + '</td></tr>' +
        '<tr><td><strong>Total Refunds</strong></td><td>' + formatUSD(statement.total_refunds) + '</td></tr>' +
        '<tr><td><strong>Expected Total</strong></td><td>' + formatUSD(expected) + '</td></tr>' +
        '<tr><td><strong>Counted Total</strong></td><td>' + formatUSD(counted) + '</td></tr>' +
        '<tr><td><strong>Variance</strong></td><td>' + formatUSD(variance) + '</td></tr>' +
      '</tbody>' +
    '</table>';

  if (transactions.length > 0) {
    html += '<h4 style="margin:12px 0 8px;">Transactions</h4>' +
      '<table class="layui-table"><thead><tr>' +
        '<th>Time</th><th>Type</th><th>Amount</th><th>Reference</th>' +
      '</tr></thead><tbody>';
    for (var i = 0; i < transactions.length; i++) {
      var t = transactions[i];
      html += '<tr>' +
        '<td>' + (t.time ? dateUtil.toLocalDisplay(t.time) : '') + '</td>' +
        '<td>' + (t.type || '') + '</td>' +
        '<td>' + formatUSD(t.amount) + '</td>' +
        '<td>' + (t.reference || '') + '</td>' +
      '</tr>';
    }
    html += '</tbody></table>';
  }

  el.innerHTML = html;
}

/**
 * Export finance data as CSV.
 */
function exportCSV() {
  var params = {
    store_id: store.getStoreId(),
    workstation_id: store.getWorkstationId(),
  };

  api.get('/finance/reconciliation/' + (_currentDrawer && _currentDrawer.id ? _currentDrawer.id : 0) + '/statement.csv', params)
    .then(function (res) {
      var csvContent = res.data.csv || res.data;
      if (typeof csvContent === 'string') {
        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'finance_reconciliation.csv';
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
 * Render the finance page into the given container.
 *
 * @param {HTMLElement} container
 */
function render(container) {
  if (!container) return;

  var html =
    '<div style="margin-bottom:16px;display:flex;gap:8px;">' +
      '<button class="layui-btn layui-btn-sm" id="finance-refresh-btn">' +
        '<i class="layui-icon layui-icon-refresh-3"></i> Refresh' +
      '</button>' +
      '<button class="layui-btn layui-btn-sm layui-btn-warm" id="finance-export-btn">' +
        '<i class="layui-icon layui-icon-export"></i> Export CSV' +
      '</button>' +
    '</div>' +
    '<div id="finance-drawer-container"></div>';

  container.innerHTML = html;

  var drawerContainer = document.getElementById('finance-drawer-container');

  // Refresh
  var refreshBtn = document.getElementById('finance-refresh-btn');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () {
      fetchDrawer(drawerContainer);
    });
  }

  // Export
  var exportBtn = document.getElementById('finance-export-btn');
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      exportCSV();
    });
  }

  // Initial load
  fetchDrawer(drawerContainer);
}

module.exports = {
  render: render,
};
