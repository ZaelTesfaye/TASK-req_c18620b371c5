/**
 * Order API adapter.
 *
 * Normalizes raw order records coming from the backend so that UI code can
 * consume a stable shape. Backend payloads use snake_case fields with an
 * `_amount` suffix (subtotal_amount, tax_amount, total_amount, amount_due).
 * Legacy shortcuts (total, subtotal, tax, discount) are left on the object
 * purely so any lingering callers keep working, but new UI code should read
 * the canonical fields.
 */

function num(value) {
  if (value === null || value === undefined || value === '') return 0;
  var n = Number(value);
  return isNaN(n) ? 0 : n;
}

/**
 * Normalize a single order record. Accepts the raw backend shape and returns
 * the same object enriched with canonical monetary fields.
 */
function normalizeOrder(raw) {
  if (!raw || typeof raw !== 'object') return raw;

  var subtotal = raw.subtotal_amount;
  var discount = raw.discount_amount;
  var tax = raw.tax_amount;
  var total = raw.total_amount;
  var amountDue = raw.amount_due;

  if (subtotal === undefined) subtotal = raw.subtotal;
  if (discount === undefined) discount = raw.discount;
  if (tax === undefined) tax = raw.tax;
  if (total === undefined) total = raw.total;
  if (amountDue === undefined) amountDue = raw.amount_due;

  raw.subtotal_amount = num(subtotal);
  raw.discount_amount = num(discount);
  raw.tax_amount = num(tax);
  raw.total_amount = num(total);
  raw.amount_due = num(amountDue);

  return raw;
}

/**
 * Normalize a list of orders returned by /orders.
 */
function normalizeOrderList(items) {
  if (!Array.isArray(items)) return [];
  for (var i = 0; i < items.length; i++) {
    normalizeOrder(items[i]);
  }
  return items;
}

/**
 * Normalize a single order_item. Backend shape is { qty, unit_price,
 * line_subtotal, service_name }. Local kiosk selection uses { price,
 * service_name } with implied qty=1. Legacy shapes used { quantity, amount }.
 * Returns a new object with canonical fields plus a display-friendly name.
 */
function normalizeItem(raw) {
  if (!raw || typeof raw !== 'object') return raw;

  var qty = raw.qty;
  if (qty === undefined || qty === null) qty = raw.quantity;
  if (qty === undefined || qty === null) qty = 1;

  var unitPrice = raw.unit_price;
  if (unitPrice === undefined || unitPrice === null) unitPrice = raw.price;

  var lineSubtotal = raw.line_subtotal;
  if (lineSubtotal === undefined || lineSubtotal === null) lineSubtotal = raw.amount;
  if (lineSubtotal === undefined || lineSubtotal === null) {
    lineSubtotal = num(qty) * num(unitPrice);
  }

  return {
    service_code: raw.service_code || '',
    service_name: raw.service_name || raw.name || '',
    qty: num(qty),
    unit_price: num(unitPrice),
    line_subtotal: num(lineSubtotal),
  };
}

function normalizeItemList(items) {
  if (!Array.isArray(items)) return [];
  var out = [];
  for (var i = 0; i < items.length; i++) {
    out.push(normalizeItem(items[i]));
  }
  return out;
}

module.exports = {
  normalizeOrder: normalizeOrder,
  normalizeOrderList: normalizeOrderList,
  normalizeItem: normalizeItem,
  normalizeItemList: normalizeItemList,
};
