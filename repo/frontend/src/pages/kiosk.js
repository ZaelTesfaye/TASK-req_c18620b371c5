var api = require('../services/api');
var store = require('../store/index');
var validation = require('../utils/validation');
var orderAdapter = require('../utils/orderAdapter');
var experiments = require('../services/experiments');

/**
 * Kiosk / customer order intake page.
 * Service selection, coupon validation, invoice toggle, real-time pricing, receipt.
 */

var _services = [];
var _selectedServices = [];
var _couponData = null;
var _currentOrderId = null;
var _currentVariant = 'control';
var _currentIsHoldout = false;
var _pricingBreakdown = {
  subtotal: 0,
  discount: 0,
  tax: 0,
  total: 0,
  amount_due: 0,
};

/**
 * Discover which experiment id (if any) the kiosk page is subject to.
 * Read from localStorage first (runtime-configurable, handy for tests and
 * ad-hoc rollouts) and fall back to the window global the harness can set.
 * Returns null when no experiment is configured — in which case the page
 * renders the default/control experience without any backend calls.
 */
function getConfiguredExperimentId() {
  try {
    if (typeof localStorage !== 'undefined') {
      var stored = localStorage.getItem('kiosk_experiment_id');
      if (stored) {
        var parsed = parseInt(stored, 10);
        if (!isNaN(parsed) && parsed > 0) return parsed;
      }
    }
  } catch (e) { /* ignore */ }
  if (typeof window !== 'undefined' && window.__KIOSK_EXPERIMENT_ID__) {
    return window.__KIOSK_EXPERIMENT_ID__;
  }
  return null;
}

/**
 * Tag the rendered container with the assigned variant so CSS and
 * downstream logic can branch on it. The `treatment` variant gets a promo
 * banner; `control` and the holdout render the default experience.
 */
function applyVariantToContainer(container, assignment) {
  if (!container) return;
  var variant = assignment.variant || 'control';
  container.setAttribute('data-kiosk-variant', variant);
  container.setAttribute('data-kiosk-holdout', assignment.is_holdout ? 'true' : 'false');
  if (container.classList) {
    container.classList.add('kiosk-variant-' + String(variant).toLowerCase());
  }

  // Holdout always renders the default experience — do nothing further.
  if (assignment.is_holdout) return;

  if (variant === 'treatment') {
    var kioskContent = container.querySelector('#kiosk-content');
    if (kioskContent && !kioskContent.querySelector('[data-variant-banner]')) {
      var banner = document.createElement('div');
      banner.setAttribute('data-variant-banner', 'treatment');
      banner.className = 'kiosk-variant-banner';
      banner.textContent = 'Special pricing available on select services.';
      kioskContent.insertBefore(banner, kioskContent.firstChild);
    }
  }
}

/**
 * Format a currency value as USD.
 */
function formatUSD(amount) {
  if (amount === null || amount === undefined) return '$0.00';
  return '$' + Number(amount).toFixed(2);
}

/**
 * Fetch available services.
 */
function loadServices(callback) {
  // Services are loaded from the order items configuration.
  // In a production deployment, a /services endpoint would be added.
  // For now, use a static service catalogue.
  var defaultServices = [
    { id: 1, service_code: 'SVC-001', service_name: 'Oil Change', price: 49.99 },
    { id: 2, service_code: 'SVC-002', service_name: 'Tire Rotation', price: 29.99 },
    { id: 3, service_code: 'SVC-003', service_name: 'Brake Inspection', price: 39.99 },
    { id: 4, service_code: 'SVC-004', service_name: 'Filter Replacement', price: 15.00 },
    { id: 5, service_code: 'SVC-005', service_name: 'Full Service', price: 99.99 },
  ];
  _services = defaultServices;
  if (callback) callback(_services);
}

/**
 * Recalculate pricing breakdown from selected services and coupon.
 */
function recalculate() {
  var subtotal = 0;
  for (var i = 0; i < _selectedServices.length; i++) {
    subtotal += Number(_selectedServices[i].price || 0);
  }

  var discount = 0;
  if (_couponData && _couponData.discount) {
    if (_couponData.discount_type === 'percent') {
      discount = subtotal * (_couponData.discount / 100);
    } else {
      discount = Number(_couponData.discount);
    }
  }

  var afterDiscount = Math.max(0, subtotal - discount);
  var taxRate = 0.0825; // Default 8.25% tax
  var tax = afterDiscount * taxRate;
  var total = afterDiscount + tax;

  _pricingBreakdown = {
    subtotal: subtotal,
    discount: discount,
    tax: tax,
    total: total,
    amount_due: total,
  };

  return _pricingBreakdown;
}

/**
 * Render the pricing breakdown section.
 */
function renderBreakdown() {
  var el = document.getElementById('kiosk-pricing-breakdown');
  if (!el) return;

  var p = _pricingBreakdown;
  el.innerHTML =
    '<table class="layui-table" style="max-width:360px;">' +
      '<tbody>' +
        '<tr><td><strong>Subtotal</strong></td><td style="text-align:right;">' + formatUSD(p.subtotal) + '</td></tr>' +
        '<tr><td><strong>Discount</strong></td><td style="text-align:right;color:#5FB878;">-' + formatUSD(p.discount) + '</td></tr>' +
        '<tr><td><strong>Tax</strong></td><td style="text-align:right;">' + formatUSD(p.tax) + '</td></tr>' +
        '<tr style="font-size:16px;"><td><strong>Total</strong></td><td style="text-align:right;">' + formatUSD(p.total) + '</td></tr>' +
        '<tr style="font-size:16px;"><td><strong>Amount Due</strong></td><td style="text-align:right;color:#FF5722;">' + formatUSD(p.amount_due) + '</td></tr>' +
      '</tbody>' +
    '</table>';
}

/**
 * Render the service selection checkboxes.
 */
function renderServiceCheckboxes(containerEl, services) {
  var html = '';
  for (var i = 0; i < services.length; i++) {
    var svc = services[i];
    html +=
      '<div class="layui-form-item" style="margin-bottom:6px;">' +
        '<input type="checkbox" name="service" value="' + svc.id + '" ' +
          'data-price="' + (svc.price || 0) + '" ' +
          'data-name="' + (svc.service_name || svc.name || '') + '" ' +
          'data-service-code="' + (svc.service_code || '') + '" ' +
          'data-service-name="' + (svc.service_name || svc.name || '') + '" ' +
          'title="' + (svc.service_name || svc.name || '') + ' (' + formatUSD(svc.price) + ')" ' +
          'lay-filter="kiosk-service-check" lay-skin="primary">' +
      '</div>';
  }
  containerEl.innerHTML = html || '<p style="color:#999;">No services available.</p>';
  if (typeof layui !== 'undefined' && layui.form) {
    layui.form.render('checkbox', 'kiosk-form-filter');
  }
}

/**
 * Show the on-screen receipt after successful order confirmation.
 */
function showReceipt(orderData) {
  var container = document.getElementById('kiosk-content');
  if (!container) return;

  var items = orderAdapter.normalizeItemList(orderData.items || _selectedServices);
  var html =
    '<div style="max-width:500px;margin:0 auto;background:#fff;padding:24px;border-radius:4px;box-shadow:0 1px 6px rgba(0,0,0,0.1);">' +
      '<div style="text-align:center;border-bottom:1px dashed #ccc;padding-bottom:12px;margin-bottom:12px;">' +
        '<h3>Order Confirmed</h3>' +
        '<p>Order #' + (orderData.order_no || orderData.id || '') + '</p>' +
      '</div>' +
      '<table class="layui-table"><thead><tr>' +
        '<th>Service</th><th>Qty</th><th>Unit Price</th><th>Line Total</th>' +
      '</tr></thead><tbody>';

  for (var i = 0; i < items.length; i++) {
    html += '<tr>' +
      '<td>' + items[i].service_name + '</td>' +
      '<td>' + items[i].qty + '</td>' +
      '<td>' + formatUSD(items[i].unit_price) + '</td>' +
      '<td>' + formatUSD(items[i].line_subtotal) + '</td>' +
    '</tr>';
  }

  html += '</tbody></table>' +
    '<div style="border-top:1px dashed #ccc;padding-top:12px;">' +
      '<p><strong>Subtotal:</strong> ' + formatUSD(orderData.subtotal || _pricingBreakdown.subtotal) + '</p>' +
      '<p><strong>Discount:</strong> -' + formatUSD(orderData.discount || _pricingBreakdown.discount) + '</p>' +
      '<p><strong>Tax:</strong> ' + formatUSD(orderData.tax || _pricingBreakdown.tax) + '</p>' +
      '<p style="font-size:16px;"><strong>Total:</strong> ' + formatUSD(orderData.total || _pricingBreakdown.total) + '</p>' +
      '<p style="font-size:16px;"><strong>Amount Due:</strong> ' + formatUSD(orderData.amount_due || _pricingBreakdown.amount_due) + '</p>' +
    '</div>' +
    '<div style="text-align:center;margin-top:20px;">' +
      '<button class="layui-btn" id="kiosk-new-order-btn">New Order</button>' +
    '</div>' +
  '</div>';

  container.innerHTML = html;

  var newOrderBtn = document.getElementById('kiosk-new-order-btn');
  if (newOrderBtn) {
    newOrderBtn.addEventListener('click', function () {
      render(document.getElementById('page-inner') || container.parentElement);
    });
  }
}

/**
 * Render the kiosk page into the given container.
 *
 * @param {HTMLElement} container
 */
function render(container) {
  if (!container) return;

  // Reset state
  _selectedServices = [];
  _couponData = null;
  _pricingBreakdown = { subtotal: 0, discount: 0, tax: 0, total: 0, amount_due: 0 };

  var html =
    '<div id="kiosk-content">' +
      '<div style="max-width:640px;margin:0 auto;">' +
        '<form class="layui-form" lay-filter="kiosk-form-filter">' +
          '<div class="layui-form-item">' +
            '<label class="layui-form-label">Customer Name</label>' +
            '<div class="layui-input-block">' +
              '<input type="text" name="customer_name" id="kiosk-customer-name" class="layui-input" placeholder="Enter customer name" lay-verify="required">' +
            '</div>' +
          '</div>' +

          '<div class="layui-form-item">' +
            '<label class="layui-form-label">Services</label>' +
            '<div class="layui-input-block" id="kiosk-services-list">' +
              '<p style="color:#999;"><i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop"></i> Loading services...</p>' +
            '</div>' +
          '</div>' +

          '<div class="layui-form-item">' +
            '<label class="layui-form-label">Coupon Code</label>' +
            '<div class="layui-input-block" style="display:flex;gap:8px;">' +
              '<input type="text" name="coupon_code" id="kiosk-coupon-code" class="layui-input" placeholder="Enter coupon code" style="flex:1;">' +
              '<button type="button" class="layui-btn layui-btn-normal" id="kiosk-validate-coupon-btn">Validate</button>' +
            '</div>' +
            '<div id="kiosk-coupon-msg" style="margin-top:4px;padding-left:110px;"></div>' +
          '</div>' +

          '<div class="layui-form-item">' +
            '<label class="layui-form-label">Invoice</label>' +
            '<div class="layui-input-block">' +
              '<input type="checkbox" name="needs_invoice" lay-filter="kiosk-invoice-toggle" lay-skin="switch" title="Request Invoice">' +
            '</div>' +
          '</div>' +

          '<div id="kiosk-invoice-fields" style="display:none;">' +
            '<div class="layui-form-item">' +
              '<label class="layui-form-label">Taxpayer ID</label>' +
              '<div class="layui-input-block">' +
                '<input type="text" name="taxpayer_id" id="kiosk-taxpayer-id" class="layui-input" placeholder="Taxpayer ID">' +
              '</div>' +
            '</div>' +
            '<div class="layui-form-item">' +
              '<label class="layui-form-label">Entity Name</label>' +
              '<div class="layui-input-block">' +
                '<input type="text" name="entity_name" id="kiosk-entity-name" class="layui-input" placeholder="Legal entity name">' +
              '</div>' +
            '</div>' +
            '<div class="layui-form-item">' +
              '<label class="layui-form-label">Identifier</label>' +
              '<div class="layui-input-block">' +
                '<input type="text" name="identifier" id="kiosk-identifier" class="layui-input" placeholder="Additional identifier">' +
              '</div>' +
            '</div>' +
          '</div>' +

          '<div class="layui-form-item">' +
            '<label class="layui-form-label">Amount</label>' +
            '<div class="layui-input-block" id="kiosk-pricing-breakdown">' +
              '<p style="color:#999;">Select services to see pricing.</p>' +
            '</div>' +
          '</div>' +

          '<div class="layui-form-item" style="text-align:center;margin-top:24px;">' +
            '<button type="button" class="layui-btn layui-btn-lg" id="kiosk-submit-btn">Confirm Order</button>' +
          '</div>' +
          '<div id="kiosk-error-msg" style="text-align:center;color:#FF5722;margin-top:8px;display:none;"></div>' +
        '</form>' +
      '</div>' +
    '</div>';

  container.innerHTML = html;

  // Fetch the user's sticky variant assignment and tag the page with it.
  // Holdout and failure paths fall back to the default experience because
  // the experiments service returns a control payload on any error.
  var experimentId = getConfiguredExperimentId();
  experiments.getAssignment(experimentId).then(function (assignment) {
    _currentVariant = assignment.variant;
    _currentIsHoldout = assignment.is_holdout;
    applyVariantToContainer(container, assignment);
  });

  // Render Layui form
  if (typeof layui !== 'undefined' && layui.form) {
    layui.form.render(null, 'kiosk-form-filter');
  }

  var servicesListEl = document.getElementById('kiosk-services-list');

  // Load services
  loadServices(function (services) {
    renderServiceCheckboxes(servicesListEl, services);
  });

  // Service checkbox change handler
  if (typeof layui !== 'undefined' && layui.form) {
    layui.form.on('checkbox(kiosk-service-check)', function (data) {
      var id = data.value;
      var price = Number(data.elem.getAttribute('data-price') || 0);
      var serviceCode = data.elem.getAttribute('data-service-code') || '';
      var serviceName = data.elem.getAttribute('data-service-name') || data.elem.getAttribute('data-name') || '';

      if (data.elem.checked) {
        _selectedServices.push({ id: id, service_code: serviceCode, service_name: serviceName, price: price });
      } else {
        _selectedServices = _selectedServices.filter(function (s) { return s.id !== id; });
      }

      recalculate();
      renderBreakdown();
    });

    // Invoice toggle
    layui.form.on('switch(kiosk-invoice-toggle)', function (data) {
      var fields = document.getElementById('kiosk-invoice-fields');
      if (fields) {
        fields.style.display = data.elem.checked ? 'block' : 'none';
      }
    });
  }

  // ---------------------------------------------------------------------
  // Checkout flow is now split into two distinct steps so the user can see
  // the discounted amount before they commit:
  //
  //   1. "Apply coupon" (or auto-triggered on the first Confirm attempt with
  //       a code in the input) creates the order as `draft` on the server,
  //       applies the coupon, and re-renders the pricing breakdown from the
  //       server's authoritative response.
  //   2. "Confirm Order" transitions the existing draft to `confirmed` and
  //       loads the receipt.
  // ---------------------------------------------------------------------

  /**
   * Read the current item/invoice form state into the payload the backend
   * expects for POST /orders. Returns null if the user input is incomplete;
   * the caller is responsible for surfacing a validation message.
   */
  function collectOrderPayload() {
    var customerName = (document.getElementById('kiosk-customer-name').value || '').trim();
    if (!customerName || _selectedServices.length === 0) { return null; }

    var body = {
      customer_name: customerName,
      channel: 'kiosk',
      items: _selectedServices.map(function (s) {
        return { service_code: s.service_code, service_name: s.service_name, qty: 1, unit_price: s.price };
      }),
    };

    var invoiceToggle = container.querySelector('input[name="needs_invoice"]');
    if (invoiceToggle && invoiceToggle.checked) {
      body.invoice_requested = true;
      body.invoice_taxpayer_id = (document.getElementById('kiosk-taxpayer-id').value || '').trim();
      body.invoice_entity_name = (document.getElementById('kiosk-entity-name').value || '').trim();
      body.invoice_identifier = (document.getElementById('kiosk-identifier').value || '').trim();
    }
    return body;
  }

  /**
   * Ensure a draft order exists on the server, creating one if needed from
   * the current form state. Returns a promise resolving to the order id.
   */
  function ensureDraftOrder(errorTarget) {
    if (_currentOrderId) { return Promise.resolve(_currentOrderId); }
    var body = collectOrderPayload();
    if (!body) {
      if (errorTarget) {
        errorTarget.textContent = 'Enter a customer name and at least one service before applying a coupon.';
        errorTarget.style.display = 'block';
      }
      return Promise.reject(new Error('Incomplete order form'));
    }
    return api.post('/orders', body).then(function (res) {
      var orderData = res.data;
      _currentOrderId = orderData.id || orderData.order_id || null;
      return _currentOrderId;
    });
  }

  // Validate/apply coupon button — enabled from the start. Clicking it will
  // create a draft order on demand if none exists yet, so the customer can
  // see the discounted amount due before they hit Confirm.
  var validateCouponBtn = document.getElementById('kiosk-validate-coupon-btn');
  if (validateCouponBtn) {
    validateCouponBtn.disabled = false;
    validateCouponBtn.title = '';

    validateCouponBtn.addEventListener('click', function () {
      var codeInput = document.getElementById('kiosk-coupon-code');
      var couponMsg = document.getElementById('kiosk-coupon-msg');
      var errorMsg = document.getElementById('kiosk-error-msg');
      var code = (codeInput.value || '').trim();

      var check = validation.validateCouponCode(code);
      if (!check.valid) {
        couponMsg.innerHTML = '<span style="color:#FF5722;">' + check.message + '</span>';
        _couponData = null;
        recalculate();
        renderBreakdown();
        return;
      }

      validateCouponBtn.disabled = true;
      validateCouponBtn.textContent = 'Validating...';

      ensureDraftOrder(errorMsg)
        .then(function (orderId) {
          return api.get('/coupons/validate', { code: code, order_id: orderId })
            .then(function (res) {
              _couponData = res.data;
              couponMsg.innerHTML = '<span style="color:#5FB878;">Coupon valid: ' + (_couponData.description || code) + '</span>';
              return api.post('/orders/' + orderId + '/apply-coupon', { code: code });
            });
        })
        .then(function (applyRes) {
          if (applyRes && applyRes.data) {
            // Use the canonical backend fields so the displayed breakdown
            // matches what will be charged at confirmation time.
            _pricingBreakdown.discount = Number(applyRes.data.discount_amount || 0);
            _pricingBreakdown.tax = Number(applyRes.data.tax_amount || 0);
            _pricingBreakdown.total = Number(applyRes.data.total_amount || 0);
            _pricingBreakdown.amount_due = Number(applyRes.data.amount_due || 0);
          }
          renderBreakdown();
        })
        .catch(function (err) {
          _couponData = null;
          couponMsg.innerHTML = '<span style="color:#FF5722;">' + (err.message || 'Invalid coupon code.') + '</span>';
          recalculate();
          renderBreakdown();
        })
        .finally(function () {
          validateCouponBtn.disabled = false;
          validateCouponBtn.textContent = 'Validate';
        });
    });
  }

  // Confirm order button — creates a draft if none exists, applies a
  // pending coupon, and then confirms. A button label of "Confirm Order"
  // makes the two-step flow explicit.
  var submitBtn = document.getElementById('kiosk-submit-btn');
  if (submitBtn) {
    submitBtn.addEventListener('click', function () {
      var errorMsg = document.getElementById('kiosk-error-msg');
      var customerName = (document.getElementById('kiosk-customer-name').value || '').trim();

      if (!customerName) {
        errorMsg.textContent = 'Customer name is required.';
        errorMsg.style.display = 'block';
        return;
      }
      if (_selectedServices.length === 0) {
        errorMsg.textContent = 'Please select at least one service.';
        errorMsg.style.display = 'block';
        return;
      }

      errorMsg.style.display = 'none';
      submitBtn.classList.add('is-submitting');
      submitBtn.disabled = true;
      submitBtn.textContent = 'Confirming...';

      ensureDraftOrder(errorMsg)
        .then(function (orderId) {
          // If a coupon code was entered but not yet applied (user skipped
          // the Validate button), attempt to apply it before confirming.
          var couponCode = (document.getElementById('kiosk-coupon-code').value || '').trim();
          if (couponCode && !_couponData) {
            return api.post('/orders/' + orderId + '/apply-coupon', { code: couponCode })
              .then(function () { return orderId; })
              .catch(function () { return orderId; });
          }
          return orderId;
        })
        .then(function (orderId) {
          return api.post('/orders/' + orderId + '/confirm', {}).then(function () {
            return api.get('/orders/' + orderId + '/receipt');
          });
        })
        .then(function (receiptRes) {
          showReceipt(receiptRes.data);
        })
        .catch(function (err) {
          errorMsg.textContent = err.message || 'Failed to confirm order.';
          errorMsg.style.display = 'block';
        })
        .finally(function () {
          submitBtn.classList.remove('is-submitting');
          submitBtn.disabled = false;
          submitBtn.textContent = 'Confirm Order';
        });
    });
  }
}

module.exports = {
  render: render,
  showReceipt: showReceipt,
};
