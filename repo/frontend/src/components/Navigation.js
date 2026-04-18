var store = require('../store/index');
var router = require('../router/index');

var ROLES = router.ROLES;

/**
 * Menu items per role. Each entry: { label, route, icon }.
 * The icon values reference Layui's built-in icon classes.
 */
var MENU_CONFIG = {};

MENU_CONFIG[ROLES.CUSTOMER] = [
  { label: 'Dashboard',   route: 'dashboard',    icon: 'layui-icon-home' },
  { label: 'My Orders',   route: 'orders',       icon: 'layui-icon-form' },
  { label: 'Kiosk',       route: 'kiosk',        icon: 'layui-icon-screen-full' },
];

MENU_CONFIG[ROLES.FRONT_DESK] = [
  { label: 'Orders',      route: 'orders',       icon: 'layui-icon-form' },
  { label: 'Kiosk',       route: 'kiosk',        icon: 'layui-icon-screen-full' },
  { label: 'Cleansing',   route: 'cleansing',    icon: 'layui-icon-refresh-3' },
];

MENU_CONFIG[ROLES.TECHNICIAN] = [
  { label: 'Technician Queue', route: 'technician-queue', icon: 'layui-icon-list' },
];

MENU_CONFIG[ROLES.STORE_MANAGER] = [
  { label: 'Dashboard',       route: 'dashboard',        icon: 'layui-icon-home' },
  { label: 'Orders',          route: 'orders',           icon: 'layui-icon-form' },
  { label: 'Technician Queue', route: 'technician-queue', icon: 'layui-icon-list' },
  { label: 'Finance',         route: 'finance',          icon: 'layui-icon-dollar' },
  { label: 'Environmental',   route: 'environmental',    icon: 'layui-icon-tree' },
  { label: 'Cleansing',       route: 'cleansing',        icon: 'layui-icon-refresh-3' },
  { label: 'Audit Logs',      route: 'audit-logs',       icon: 'layui-icon-log' },
];

MENU_CONFIG[ROLES.FINANCE] = [
  { label: 'Finance',     route: 'finance',      icon: 'layui-icon-dollar' },
  { label: 'Audit Logs',  route: 'audit-logs',   icon: 'layui-icon-log' },
];

MENU_CONFIG[ROLES.ADMINISTRATOR] = [
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
 * Compute a merged, de-duplicated menu for the current user based on all
 * assigned roles. Higher-privilege roles contribute more items.
 */
function getMenuItems() {
  var roles = store.getRoles();
  var seen = {};
  var items = [];

  for (var i = 0; i < roles.length; i++) {
    var roleItems = MENU_CONFIG[roles[i]];
    if (!roleItems) continue;

    for (var j = 0; j < roleItems.length; j++) {
      var item = roleItems[j];
      if (!seen[item.route]) {
        seen[item.route] = true;
        items.push(item);
      }
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
  MENU_CONFIG: MENU_CONFIG,
};
