var store = require('../store/index');
var router = require('../router/index');

var ROLES = router.ROLES;

/**
 * Flat catalog of every navigable section. Each entry is presentation
 * only (label + icon); the role allowlist for the matching route lives
 * in router/index.js and is consulted at render time via
 * router.hasAccess(). This removes the prior split where MENU_CONFIG
 * defined its own role -> menu-items mapping that drifted out of sync
 * with the route table (e.g., FRONT_DESK got a "Cleansing" menu entry
 * even though the cleansing route is restricted to store_manager +
 * administrator, so the click immediately 403'd; CUSTOMER got
 * "Dashboard" the same way). One source of truth = no drift.
 */
var MENU_ITEMS = [
  { label: 'Dashboard',        route: 'dashboard',        icon: 'layui-icon-home' },
  { label: 'Orders',           route: 'orders',           icon: 'layui-icon-form' },
  { label: 'Technician Queue', route: 'technician-queue', icon: 'layui-icon-list' },
  { label: 'Finance',          route: 'finance',          icon: 'layui-icon-dollar' },
  { label: 'Admin',            route: 'admin',            icon: 'layui-icon-set' },
  { label: 'Environmental',    route: 'environmental',    icon: 'layui-icon-tree' },
  { label: 'Cleansing',        route: 'cleansing',        icon: 'layui-icon-refresh-3' },
  { label: 'Audit Logs',       route: 'audit-logs',       icon: 'layui-icon-log' },
  { label: 'Kiosk',            route: 'kiosk',            icon: 'layui-icon-screen-full' },
];

/**
 * Map a role name to a CSS-safe class suffix for theming.
 */
function roleToCssClass(role) {
  var map = {};
  map[ROLES.CUSTOMER] = 'menu-role-customer';
  map[ROLES.FRONT_DESK] = 'menu-role-frontdesk';
  map[ROLES.TECHNICIAN] = 'menu-role-technician';
  map[ROLES.STORE_MANAGER] = 'menu-role-storemanager';
  map[ROLES.FINANCE] = 'menu-role-finance';
  map[ROLES.ADMINISTRATOR] = 'menu-role-administrator';
  return map[role] || '';
}

/**
 * Compute the menu visible to the current user. Filters MENU_ITEMS by
 * delegating the access decision to the router's hasAccess(), so any
 * change to a route's role allowlist automatically updates the menu
 * with no cross-file edit needed.
 */
function getMenuItems() {
  var items = [];
  for (var i = 0; i < MENU_ITEMS.length; i++) {
    var item = MENU_ITEMS[i];
    var route = router.findRoute(item.route);
    if (route && router.hasAccess(route)) {
      items.push(item);
    }
  }
  return items;
}

/**
 * Get the primary role CSS class (uses the highest-privilege role).
 */
function getPrimaryRoleClass() {
  var roles = store.getRoles();
  // Priority order (highest last, so the last match wins)
  var priority = [
    ROLES.CUSTOMER,
    ROLES.FRONT_DESK,
    ROLES.TECHNICIAN,
    ROLES.FINANCE,
    ROLES.STORE_MANAGER,
    ROLES.ADMINISTRATOR,
  ];

  var best = '';
  for (var i = 0; i < priority.length; i++) {
    if (roles.indexOf(priority[i]) !== -1) {
      best = roleToCssClass(priority[i]);
    }
  }
  return best;
}

/**
 * Render the sidebar navigation into the given container element.
 *
 * @param {HTMLElement} container - The sidebar DOM element
 * @param {string} [activePage] - The currently active route path
 */
function render(container, activePage) {
  if (!container) return;

  var items = getMenuItems();
  var roleClass = getPrimaryRoleClass();

  if (items.length === 0) {
    container.innerHTML = '';
    return;
  }

  var html = '<div class="' + roleClass + '">';
  html += '<ul class="layui-nav layui-nav-tree" lay-filter="fieldops-nav">';

  for (var i = 0; i < items.length; i++) {
    var item = items[i];
    var isActive = activePage === item.route ? ' layui-this' : '';
    html += '<li class="layui-nav-item' + isActive + '">';
    html += '<a href="#/' + item.route + '">';
    html += '<i class="layui-icon ' + item.icon + '"></i> ';
    html += item.label;
    html += '</a></li>';
  }

  html += '</ul></div>';
  container.innerHTML = html;

  // Re-render Layui nav element if layui is available
  if (typeof layui !== 'undefined' && layui.element) {
    layui.element.render('nav', 'fieldops-nav');
  }
}

module.exports = {
  render: render,
  getMenuItems: getMenuItems,
  getPrimaryRoleClass: getPrimaryRoleClass,
  roleToCssClass: roleToCssClass,
  MENU_ITEMS: MENU_ITEMS,
};
