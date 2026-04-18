/**
 * Receipt Component - On-screen receipt display after order confirmation.
 * Shows: receipt_no, order_no, customer, items, subtotal, discount, tax, total, amount_due in USD.
 */

function renderReceipt(receiptData) {
    if (!receiptData) return '<div class="is-empty">No receipt data available</div>';

    var itemRows = '';
    if (receiptData.items && receiptData.items.length > 0) {
        receiptData.items.forEach(function(item) {
            itemRows += '<tr>' +
                '<td>' + (item.service_name || '') + '</td>' +
                '<td>' + (item.qty || 0) + '</td>' +
                '<td>$' + (parseFloat(item.unit_price) || 0).toFixed(2) + '</td>' +
                '<td>$' + (parseFloat(item.line_subtotal) || 0).toFixed(2) + '</td>' +
                '</tr>';
        });
    }

    return '<div class="receipt-container">' +
        '<div class="receipt-header">' +
            '<h2>Service Receipt</h2>' +
            '<div class="receipt-meta">' +
                '<div><strong>Receipt #:</strong> ' + (receiptData.receipt_no || 'N/A') + '</div>' +
                '<div><strong>Order #:</strong> ' + (receiptData.order_no || 'N/A') + '</div>' +
                '<div><strong>Date:</strong> ' + (receiptData.confirmed_at || new Date().toLocaleDateString('en-US')) + '</div>' +
            '</div>' +
        '</div>' +
        '<div class="receipt-customer">' +
            '<strong>Customer:</strong> ' + (receiptData.customer_name || 'N/A') +
        '</div>' +
        '<table class="layui-table receipt-items">' +
            '<thead><tr>' +
                '<th>Service</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th>' +
            '</tr></thead>' +
            '<tbody>' + itemRows + '</tbody>' +
        '</table>' +
        '<div class="receipt-totals">' +
            '<div class="receipt-line"><span>Subtotal:</span><span>$' + (receiptData.subtotal || '0.00') + '</span></div>' +
            '<div class="receipt-line"><span>Discount:</span><span>-$' + (receiptData.discount || '0.00') + '</span></div>' +
            '<div class="receipt-line"><span>Tax:</span><span>$' + (receiptData.tax || '0.00') + '</span></div>' +
            '<div class="receipt-line receipt-total"><span>Total (USD):</span><span>$' + (receiptData.total || '0.00') + '</span></div>' +
            '<div class="receipt-line receipt-due"><span>Amount Due:</span><span>$' + (receiptData.amount_due || '0.00') + '</span></div>' +
        '</div>' +
        (receiptData.invoice_requested ? '<div class="receipt-invoice-note">Invoice Requested</div>' : '') +
    '</div>';
}

module.exports = { renderReceipt: renderReceipt };
