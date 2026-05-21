var api = require('../services/api');
var store = require('../store/index');
var dateUtil = require('../utils/date');
var orderAdapter = require('../utils/orderAdapter');

/**
 * Order management page.
 * Displays a filterable, paginated table of orders with detail/cancel/receipt modals.
 */

var PAGE_SIZE = 15;
var _currentPage = 1;
var _filters = {
  status: '',
  order_no: '',
  date_from: '',
  date_to: '',
};

/**
 * Format a currency value as USD.
 */
function formatUSD(amount) {
  if (amount === null || amount === undefined) return '$0.00';
  return '$' + Number(amount).toFixed(2);
}

/**
 * Check if the current user can create orders.
 */
function canCreateOrder() {
  return store.hasRole('front_desk') || store.hasRole('customer') || store.hasRole('administrator');
}

/**
 * Build the filter bar HTML.
 */
function buildFilterBar() {
  // Uses the shared .fieldops-filter-bar layout (see styles/main.css)
  // so this row aligns with the toolbars on every other page. The
  // earlier inline styles overrode .layui-form-label width which made
  // the labels float at a different baseline than the inputs.
  return '' +
    '<form class="layui-form fieldops-filter-bar" lay-filter="orders-filter-form">' +
      '<div class="filter-field">' +
        '<label>Status</label>' +
        '<select name="status" lay-filter="orders-status-filter">' +
          '<option value="">All</option>' +
          '<option value="draft">Draft</option>' +
          '<option value="confirmed">Confirmed</option>' +
          '<option value="assigned">Assigned</option>' +
          '<option value="in_progress">In Progress</option>' +
          '<option value="completed">Completed</option>' +
          '<option value="cancelled">Cancelled</option>' +
        '</select>' +
      '</div>' +
      '<div class="filter-field">' +
        '<label>Order No.</label>' +
        '<input type="text" name="order_no" id="orders-filter-order-no" class="layui-input" placeholder="Order number">' +
      '</div>' +
      '<div class="filter-field">' +
        '<label>From</label>' +
        '<input type="text" name="date_from" id="orders-filter-date-from" class="layui-input" placeholder="MM/DD/YYYY">' +
      '</div>' +
      '<div class="filter-field">' +
        '<label>To</label>' +
        '<input type="text" name="date_to" id="orders-filter-date-to" class="layui-input" placeholder="MM/DD/YYYY">' +
      '</div>' +
      '<div class="filter-actions">' +
        '<button type="button" class="layui-btn layui-btn-sm" id="orders-filter-btn">Search</button>' +
        '<button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="orders-reset-btn">Reset</button>' +
      '</div>' +
    '</form>';
}

/**
 * Fetch and render the orders table.
 *
 * The container refs are optional — when callers (e.g. the edit/assign
 * handlers in the detail modal) invoke fetchOrders() with no arguments we
 * resolve them from the DOM by their stable IDs. Silently no-op if the
 * orders page is no longer mounted.
 */
function fetchOrders(tableContainer, paginationContainer) {
  if (!tableContainer) {
    tableContainer = document.getElementById('orders-table-body');
  }
  if (!paginationContainer) {
    paginationContainer = document.getElementById('orders-pagination-wrap');
  }
  if (!tableContainer || !paginationContainer) {
    return;
  }

  var params = {
    page: _currentPage,
    page_size: PAGE_SIZE,
  };
  if (_filters.status) params.status = _filters.status;
  if (_filters.order_no) params.order_no = _filters.order_no;
  if (_filters.date_from) params.from = _filters.date_from;
  if (_filters.date_to) params.to = _filters.date_to;

  tableContainer.innerHTML = '<div class="fieldops-empty"><i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop" style="font-size:30px;"></i></div>';

  api.get('/orders', params).then(function (res) {
    var data = res.data;
    var orders = orderAdapter.normalizeOrderList(data.items || data.orders || data || []);
    var total = data.total || orders.length;

    if (orders.length === 0) {
      tableContainer.innerHTML = '<div class="fieldops-empty">No orders found.</div>';
      paginationContainer.innerHTML = '';
      return;
    }

    var html = '<table class="layui-table">' +
      '<colgroup><col width="120"><col width="120"><col><col width="100"><col width="140"><col width="100"></colgroup>' +
      '<thead><tr>' +
        '<th>Order No.</th>' +
        '<th>Status</th>' +
        '<th>Customer</th>' +
        '<th>Total</th>' +
        '<th>Date</th>' +
        '<th>Actions</th>' +
      '</tr></thead><tbody>';

    for (var i = 0; i < orders.length; i++) {
      var o = orders[i];
      var created = o.created_at ? dateUtil.toLocalDisplay(o.created_at) : '';
      html += '<tr>' +
        '<td>' + (o.order_no || o.id || '') + '</td>' +
        '<td><span class="layui-badge ' + statusBadgeClass(o.status) + '">' + (o.status || '') + '</span></td>' +
        '<td>' + (o.customer_name || '') + '</td>' +
        '<td>' + formatUSD(o.total_amount) + '</td>' +
        '<td>' + created + '</td>' +
        '<td>' +
          '<button class="layui-btn layui-btn-xs" data-action="detail" data-id="' + o.id + '">Detail</button>' +
          '<button class="layui-btn layui-btn-xs layui-btn-normal" data-action="receipt" data-id="' + o.id + '">Receipt</button>' +
        '</td>' +
      '</tr>';
    }

    html += '</tbody></table>';
    tableContainer.innerHTML = html;

    // Render pagination
    renderPagination(paginationContainer, total, _currentPage);

    // Bind row action buttons
    bindRowActions(tableContainer);
  }).catch(function (err) {
    tableContainer.innerHTML = '<div class="fieldops-error">Failed to load orders: ' + (err.message || 'Unknown error') + '</div>';
  });
}

/**
 * Map order status to a Layui badge CSS class.
 */
function statusBadgeClass(status) {
  switch (status) {
    case 'draft': return 'layui-bg-orange';
    case 'confirmed': return 'layui-bg-cyan';
    case 'assigned': return 'layui-bg-blue';
    case 'in_progress': return 'layui-bg-blue';
    case 'completed': return 'layui-bg-green';
    case 'cancelled': return 'layui-bg-gray';
    default: return '';
  }
}

/**
 * Render a simple pagination bar.
 */
function renderPagination(container, total, current) {
  var totalPages = Math.ceil(total / PAGE_SIZE);
  if (totalPages <= 1) {
    container.innerHTML = '';
    return;
  }

  var html = '<div id="orders-pagination" style="text-align:center;margin-top:16px;"></div>';
  container.innerHTML = html;

  if (typeof layui !== 'undefined' && layui.laypage) {
    layui.laypage.render({
      elem: 'orders-pagination',
      count: total,
      limit: PAGE_SIZE,
      curr: current,
      jump: function (obj, first) {
        if (!first) {
          _currentPage = obj.curr;
          var tableEl = document.getElementById('orders-table-body');
          var pagEl = document.getElementById('orders-pagination-wrap');
          if (tableEl && pagEl) {
            fetchOrders(tableEl, pagEl);
          }
        }
      },
    });
  }
}

/**
 * Bind click handlers to action buttons in table rows.
 */
function bindRowActions(tableContainer) {
  tableContainer.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;

    var action = btn.getAttribute('data-action');
    var orderId = btn.getAttribute('data-id');

    if (action === 'detail') {
      showOrderDetail(orderId);
    } else if (action === 'receipt') {
      showReceipt(orderId);
    }
  });
}

/**
 * Show order detail in a Layui layer modal.
 */
function showOrderDetail(orderId) {
  if (typeof layui === 'undefined') return;

  layui.layer.open({
    type: 1,
    title: 'Order Detail',
    area: ['700px', '520px'],
    content: '<div id="order-detail-content" style="padding:20px;"><div style="text-align:center;"><i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop"></i> Loading...</div></div>',
    success: function () {
      api.get('/orders/' + orderId).then(function (res) {
        var order = orderAdapter.normalizeOrder(res.data);
        var contentEl = document.getElementById('order-detail-content');
        if (!contentEl) return;

        var items = orderAdapter.normalizeItemList(order.items || []);
        var history = order.status_history || [];

        var html = '<div class="layui-row layui-col-space16">' +
          '<div class="layui-col-md6">' +
            '<p><strong>Order No.:</strong> ' + (order.order_no || order.id) + '</p>' +
            '<p><strong>Customer:</strong> ' + (order.customer_name || '') + '</p>' +
            '<p><strong>Status:</strong> ' + (order.status || '') + '</p>' +
            '<p><strong>Created:</strong> ' + (order.created_at ? dateUtil.toLocalDisplay(order.created_at) : '') + '</p>' +
          '</div>' +
          '<div class="layui-col-md6">' +
            '<p><strong>Subtotal:</strong> ' + formatUSD(order.subtotal_amount) + '</p>' +
            '<p><strong>Discount:</strong> ' + formatUSD(order.discount_amount) + '</p>' +
            '<p><strong>Tax:</strong> ' + formatUSD(order.tax_amount) + '</p>' +
            '<p><strong>Total:</strong> ' + formatUSD(order.total_amount) + '</p>' +
            '<p><strong>Amount Due:</strong> ' + formatUSD(order.amount_due) + '</p>' +
          '</div>' +
        '</div>';

        // Items table
        if (items.length > 0) {
          html += '<h4 style="margin:16px 0 8px;">Items</h4>' +
            '<table class="layui-table"><thead><tr>' +
              '<th>Service</th><th>Qty</th><th>Unit Price</th><th>Amount</th>' +
            '</tr></thead><tbody>';
          for (var i = 0; i < items.length; i++) {
            var item = items[i];
            html += '<tr>' +
              '<td>' + item.service_name + '</td>' +
              '<td>' + item.qty + '</td>' +
              '<td>' + formatUSD(item.unit_price) + '</td>' +
              '<td>' + formatUSD(item.line_subtotal) + '</td>' +
            '</tr>';
          }
          html += '</tbody></table>';
        }

        // Status history
        if (history.length > 0) {
          html += '<h4 style="margin:16px 0 8px;">Status History</h4>' +
            '<table class="layui-table"><thead><tr>' +
              '<th>Status</th><th>Changed By</th><th>Date</th><th>Note</th>' +
            '</tr></thead><tbody>';
          for (var j = 0; j < history.length; j++) {
            var h = history[j];
            html += '<tr>' +
              '<td>' + (h.status || '') + '</td>' +
              '<td>' + (h.changed_by || '') + '</td>' +
              '<td>' + (h.changed_at ? dateUtil.toLocalDisplay(h.changed_at) : '') + '</td>' +
              '<td>' + (h.note || '') + '</td>' +
            '</tr>';
          }
          html += '</tbody></table>';
        }

        // Action buttons
        var actions = '<div style="margin-top:16px;text-align:right;">';

        // Assign technician (front_desk/admin, confirmed orders only)
        // Edit order (front_desk/admin, draft/confirmed only)
        if ((order.status === 'draft' || order.status === 'confirmed') && (store.hasRole('front_desk') || store.hasRole('administrator'))) {
          actions += '<button class="layui-btn" id="order-edit-btn" data-id="' + orderId + '" style="margin-right:8px;">Edit Order</button>';
        }

        // Assign technician (front_desk/admin, confirmed orders only)
        if (order.status === 'confirmed' && (store.hasRole('front_desk') || store.hasRole('administrator'))) {
          actions += '<button class="layui-btn layui-btn-normal" id="order-assign-btn" data-id="' + orderId + '" style="margin-right:8px;">Assign Technician</button>';
        }

        // Cancel button (if order is not already cancelled/completed)
        if (order.status !== 'cancelled' && order.status !== 'completed') {
          actions += '<button class="layui-btn layui-btn-danger" id="order-cancel-btn" data-id="' + orderId + '">Cancel Order</button>';
        }

        actions += '</div>';
        html += actions;

        contentEl.innerHTML = html;

        // Bind edit order button
        var editBtn = document.getElementById('order-edit-btn');
        if (editBtn) {
          editBtn.addEventListener('click', function () {
            var newName = prompt('Edit customer name:', order.customer_name || '');
            if (newName !== null) {
              api.patch('/orders/' + orderId, { customer_name: newName })
                .then(function () {
                  if (typeof layui !== 'undefined' && layui.layer) {
                    layui.layer.msg('Order updated.', { icon: 1 });
                    layui.layer.closeAll();
                  }
                  fetchOrders();
                })
                .catch(function (err) {
                  if (typeof layui !== 'undefined' && layui.layer) {
                    layui.layer.msg(err.message || 'Failed to update order.', { icon: 2 });
                  }
                });
            }
          });
        }

        // Bind assign technician button
        var assignBtn = document.getElementById('order-assign-btn');
        if (assignBtn) {
          assignBtn.addEventListener('click', function () {
            var techId = prompt('Enter technician user ID:');
            if (techId) {
              api.post('/orders/' + orderId + '/assign-technician', { technician_id: parseInt(techId, 10) })
                .then(function () {
                  if (typeof layui !== 'undefined' && layui.layer) {
                    layui.layer.msg('Technician assigned.', { icon: 1 });
                    layui.layer.closeAll();
                  }
                  fetchOrders();
                })
                .catch(function (err) {
                  if (typeof layui !== 'undefined' && layui.layer) {
                    layui.layer.msg(err.message || 'Failed to assign.', { icon: 2 });
                  }
                });
            }
          });
        }

        // Bind cancel button
        var cancelBtn = document.getElementById('order-cancel-btn');
        if (cancelBtn) {
          cancelBtn.addEventListener('click', function () {
            showCancelModal(orderId);
          });
        }
      }).catch(function (err) {
        var contentEl = document.getElementById('order-detail-content');
        if (contentEl) {
          contentEl.innerHTML = '<div style="color:#FF5722;">Failed to load order detail: ' + (err.message || 'Unknown error') + '</div>';
        }
      });
    },
  });
}

/**
 * Show cancel order modal with reason input.
 */
function showCancelModal(orderId) {
  if (typeof layui === 'undefined') return;

  layui.layer.open({
    type: 1,
    title: 'Cancel Order',
    area: ['450px', '250px'],
    content:
      '<div style="padding:20px;">' +
        '<form class="layui-form">' +
          '<div class="layui-form-item">' +
            '<label class="layui-form-label">Reason</label>' +
            '<div class="layui-input-block">' +
              '<textarea name="reason" id="cancel-reason-input" class="layui-textarea" placeholder="Enter cancellation reason"></textarea>' +
            '</div>' +
          '</div>' +
          '<div class="layui-form-item" style="text-align:right;">' +
            '<button type="button" class="layui-btn layui-btn-danger" id="cancel-confirm-btn">Confirm Cancellation</button>' +
          '</div>' +
          '<div id="cancel-error-msg" style="color:#FF5722;margin-top:8px;display:none;"></div>' +
        '</form>' +
      '</div>',
    success: function (layero, index) {
      var confirmBtn = document.getElementById('cancel-confirm-btn');
      if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
          var reason = document.getElementById('cancel-reason-input').value.trim();
          var errorMsg = document.getElementById('cancel-error-msg');

          if (!reason) {
            if (errorMsg) {
              errorMsg.textContent = 'Cancellation reason is required.';
              errorMsg.style.display = 'block';
            }
            return;
          }

          confirmBtn.disabled = true;
          confirmBtn.textContent = 'Cancelling...';

          api.post('/orders/' + orderId + '/cancel', { reason: reason })
            .then(function () {
              layui.layer.close(index);
              layui.layer.closeAll();
              layui.layer.msg('Order cancelled successfully.', { icon: 1 });
              // Refresh orders table
              var tableEl = document.getElementById('orders-table-body');
              var pagEl = document.getElementById('orders-pagination-wrap');
              if (tableEl && pagEl) {
                fetchOrders(tableEl, pagEl);
              }
            })
            .catch(function (err) {
              confirmBtn.disabled = false;
              confirmBtn.textContent = 'Confirm Cancellation';
              if (errorMsg) {
                errorMsg.textContent = err.message || 'Failed to cancel order.';
                errorMsg.style.display = 'block';
              }
            });
        });
      }
    },
  });
}

/**
 * Show receipt in a Layui layer modal.
 */
function showReceipt(orderId) {
  if (typeof layui === 'undefined') return;

  layui.layer.open({
    type: 1,
    title: 'Receipt',
    area: ['500px', '480px'],
    content: '<div id="receipt-content" style="padding:20px;"><div style="text-align:center;"><i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop"></i> Loading...</div></div>',
    success: function () {
      api.get('/orders/' + orderId + '/receipt').then(function (res) {
        var receipt = res.data;
        var contentEl = document.getElementById('receipt-content');
        if (!contentEl) return;

        var items = orderAdapter.normalizeItemList(receipt.items || []);
        var html =
          '<div style="text-align:center;border-bottom:1px dashed #ccc;padding-bottom:12px;margin-bottom:12px;">' +
            '<h3>FieldOps Service Suite</h3>' +
            '<p>Receipt #' + (receipt.receipt_no || receipt.order_no || orderId) + '</p>' +
            '<p>' + (receipt.date ? dateUtil.toLocalDisplay(receipt.date) : '') + '</p>' +
          '</div>';

        if (items.length > 0) {
          html += '<table class="layui-table" style="margin-bottom:12px;"><thead><tr>' +
            '<th>Item</th><th>Qty</th><th>Line Total</th>' +
          '</tr></thead><tbody>';
          for (var i = 0; i < items.length; i++) {
            html += '<tr>' +
              '<td>' + items[i].service_name + '</td>' +
              '<td>' + items[i].qty + '</td>' +
              '<td>' + formatUSD(items[i].line_subtotal) + '</td>' +
            '</tr>';
          }
          html += '</tbody></table>';
        }

        html +=
          '<div style="border-top:1px dashed #ccc;padding-top:12px;">' +
            '<p><strong>Subtotal:</strong> ' + formatUSD(receipt.subtotal) + '</p>' +
            '<p><strong>Discount:</strong> ' + formatUSD(receipt.discount) + '</p>' +
            '<p><strong>Tax:</strong> ' + formatUSD(receipt.tax) + '</p>' +
            '<p style="font-size:16px;"><strong>Total:</strong> ' + formatUSD(receipt.total) + '</p>' +
          '</div>';

        contentEl.innerHTML = html;
      }).catch(function (err) {
        var contentEl = document.getElementById('receipt-content');
        if (contentEl) {
          contentEl.innerHTML = '<div style="color:#FF5722;">Failed to load receipt: ' + (err.message || 'Unknown error') + '</div>';
        }
      });
    },
  });
}

/**
 * Render the orders page into the given container.
 *
 * @param {HTMLElement} container
 */
function render(container) {
  if (!container) return;

  // Reset state
  _currentPage = 1;
  _filters = { status: '', order_no: '', date_from: '', date_to: '' };

  var html = '';

  // Page header band: title + (optional) Create Order action on the
  // right, aligned with the page-header pattern used elsewhere.
  html += '<div class="fieldops-page-header">' +
            '<h2>Orders</h2>' +
            '<div class="fieldops-page-actions">' +
              (canCreateOrder()
                ? '<button class="layui-btn" id="orders-create-btn"><i class="layui-icon layui-icon-add-1"></i> Create Order</button>'
                : '') +
            '</div>' +
          '</div>';

  html += buildFilterBar();
  // Wrap the table + pagination in a single card so the table sits on
  // a framed white surface instead of floating on the page background.
  html += '<div class="fieldops-table-card">' +
            '<div id="orders-table-body"></div>' +
            '<div id="orders-pagination-wrap" class="fieldops-pagination-wrap"></div>' +
          '</div>';

  container.innerHTML = html;

  // Render Layui form elements
  if (typeof layui !== 'undefined' && layui.form) {
    layui.form.render(null, 'orders-filter-form');
  }

  var tableEl = document.getElementById('orders-table-body');
  var pagEl = document.getElementById('orders-pagination-wrap');

  // Filter button
  var filterBtn = document.getElementById('orders-filter-btn');
  if (filterBtn) {
    filterBtn.addEventListener('click', function () {
      _filters.order_no = (document.getElementById('orders-filter-order-no').value || '').trim();
      _filters.date_from = (document.getElementById('orders-filter-date-from').value || '').trim();
      _filters.date_to = (document.getElementById('orders-filter-date-to').value || '').trim();
      // Status is set via Layui form select
      var statusSelect = container.querySelector('select[name="status"]');
      _filters.status = statusSelect ? statusSelect.value : '';
      _currentPage = 1;
      fetchOrders(tableEl, pagEl);
    });
  }

  // Reset button
  var resetBtn = document.getElementById('orders-reset-btn');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      _filters = { status: '', order_no: '', date_from: '', date_to: '' };
      _currentPage = 1;
      var orderNoInput = document.getElementById('orders-filter-order-no');
      var dateFromInput = document.getElementById('orders-filter-date-from');
      var dateToInput = document.getElementById('orders-filter-date-to');
      var statusSelect = container.querySelector('select[name="status"]');
      if (orderNoInput) orderNoInput.value = '';
      if (dateFromInput) dateFromInput.value = '';
      if (dateToInput) dateToInput.value = '';
      if (statusSelect) statusSelect.value = '';
      if (typeof layui !== 'undefined' && layui.form) {
        layui.form.render('select', 'orders-filter-form');
      }
      fetchOrders(tableEl, pagEl);
    });
  }

  // Create order button
  var createBtn = document.getElementById('orders-create-btn');
  if (createBtn) {
    createBtn.addEventListener('click', function () {
      window.location.hash = '#/kiosk';
    });
  }

  // Initial load
  fetchOrders(tableEl, pagEl);
}

module.exports = {
  render: render,
};
