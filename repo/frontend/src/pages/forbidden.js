/**
 * 403 Forbidden Page
 * Displayed when user does not have permission to access a route.
 */

function render(container) {
    container.innerHTML =
        '<div class="forbidden-page">' +
            '<div class="forbidden-content">' +
                '<h1>403</h1>' +
                '<h2>Access Denied</h2>' +
                '<p>You do not have permission to access this page.</p>' +
                '<p>Please contact your administrator if you believe this is an error.</p>' +
                '<a href="#/dashboard" class="layui-btn">Return to Dashboard</a>' +
            '</div>' +
        '</div>';
}

module.exports = { render: render };
