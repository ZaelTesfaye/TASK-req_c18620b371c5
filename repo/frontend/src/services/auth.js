var api = require('./api');
var store = require('../store/index');

/**
 * Log in with credentials and workstation context.
 *
 * @param {string} username
 * @param {string} password
 * @param {string|number} storeId
 * @param {string|number} workstationId
 * @returns {Promise<object>} user data
 */
function login(username, password, storeId, workstationId) {
  return api.post('/auth/login', {
    username: username,
    password: password,
    store_id: storeId,
    workstation_id: workstationId,
  }).then(function (res) {
    var data = res.data;
    store.setToken(data.token);
    store.setUser(data.user);
    store.setRoles(data.user.roles || []);
    store.setStoreId(storeId);
    store.setWorkstationId(workstationId);
    return data.user;
  });
}

/**
 * Log out the current user.
 *
 * @returns {Promise<void>}
 */
function logout() {
  return api.post('/auth/logout', {})
    .catch(function () {
      // Swallow errors - we clear local state regardless
    })
    .then(function () {
      store.clear();
    });
}

/**
 * Fetch the current authenticated user profile.
 *
 * @returns {Promise<object>} user data
 */
function getMe() {
  return api.get('/auth/me').then(function (res) {
    var user = res.data.user || res.data;
    store.setUser(user);
    store.setRoles(user.roles || []);
    return user;
  });
}

/**
 * Check whether the user is currently authenticated.
 *
 * @returns {boolean}
 */
function isAuthenticated() {
  return !!store.getToken();
}

/**
 * Get the current bearer token.
 *
 * @returns {string|null}
 */
function getToken() {
  return store.getToken();
}

/**
 * Persist a new token.
 *
 * @param {string} token
 */
function setToken(token) {
  store.setToken(token);
}

/**
 * Remove the stored token and clear session.
 */
function clearToken() {
  store.clearToken();
}

/**
 * Get the current user's roles.
 *
 * @returns {string[]}
 */
function getRoles() {
  return store.getRoles();
}

module.exports = {
  login: login,
  logout: logout,
  getMe: getMe,
  isAuthenticated: isAuthenticated,
  getToken: getToken,
  setToken: setToken,
  clearToken: clearToken,
  getRoles: getRoles,
};
