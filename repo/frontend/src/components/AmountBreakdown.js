/**
 * AmountBreakdown Component - Real-time pricing display.
 * Shows: subtotal, discount, tax, total, amount_due in USD with 2-decimal formatting.
 */

function renderAmountBreakdown(pricing) {
    if (!pricing) return '<div class="is-empty">No pricing data</div>';

    var formatUSD = function(amount) {
        return '$' + (parseFloat(amount) || 0).toFixed(2);
    };

    return '<div class="amount-breakdown">' +
        '<div class="breakdown-row">' +
            '<span class="breakdown-label">Subtotal:</span>' +
            '<span class="breakdown-value">' + formatUSD(pricing.subtotal) + '</span>' +
        '</div>' +
        '<div class="breakdown-row">' +
            '<span class="breakdown-label">Discount:</span>' +
            '<span class="breakdown-value discount">-' + formatUSD(pricing.discount) + '</span>' +
        '</div>' +
        '<div class="breakdown-row">' +
            '<span class="breakdown-label">Tax:</span>' +
            '<span class="breakdown-value">' + formatUSD(pricing.tax) + '</span>' +
        '</div>' +
        '<div class="breakdown-row total-row">' +
            '<span class="breakdown-label"><strong>Total (USD):</strong></span>' +
            '<span class="breakdown-value"><strong>' + formatUSD(pricing.total) + '</strong></span>' +
        '</div>' +
        '<div class="breakdown-row due-row">' +
            '<span class="breakdown-label"><strong>Amount Due:</strong></span>' +
            '<span class="breakdown-value amount-due"><strong>' + formatUSD(pricing.amount_due) + '</strong></span>' +
        '</div>' +
    '</div>';
}

module.exports = { renderAmountBreakdown: renderAmountBreakdown };
