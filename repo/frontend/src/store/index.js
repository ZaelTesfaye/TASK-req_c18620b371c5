var TOKEN_KEY = 'fieldops_token';

var _state = {
  user: null,
  token: null,
  roles: [],
  storeId: null,
  workstationId: null,
};

// ---------------------------------------------------------------------------
// Token
// ---------------------------------------------------------------------------

function getToken() {
  if (!_state.token) {
    try {
      _state.token = localStorage.getItem(TOKEN_KEY) || null;
    } catch (e) {
      _state.token = null;
    }
  }
  return _state.token;
}

function setToken(token) {
  _state.token = token;
  try {
    if (token) {
      localStorage.setItem(TOKEN_KEY, token);
    } else {
      localStorage.removeItem(TOKEN_KEY);
    }
  } catch (e) {
    // localStorage unavailable
  }
}

function clearToken() {
  _state.token = null;
  try {
    localStorage.removeItem(TOKEN_KEY);
  } catch (e) {
    // localStorage unavailable
  }
}

// ---------------------------------------------------------------------------
// User
// ---------------------------------------------------------------------------

function getUser() {
  return _state.user;
}

function setUser(user) {
  _state.user = user;
}

// ---------------------------------------------------------------------------
// Roles
// ---------------------------------------------------------------------------

function getRoles() {
  return _state.roles;
}

function setRoles(roles) {
  _state.roles = Array.isArray(roles) ? roles : [];
}

function hasRole(role) {
  return _state.roles.indexOf(role) !== -1;
}

// ---------------------------------------------------------------------------
// Store ID
// ---------------------------------------------------------------------------

function getStoreId() {
  return _state.storeId;
}

function setStoreId(storeId) {
  _state.storeId = storeId;
}

// ---------------------------------------------------------------------------
// Workstation ID
// ---------------------------------------------------------------------------

function getWorkstationId() {
  return _state.workstationId;
}

function setWorkstationId(workstationId) {
  _state.workstationId = workstationId;
}

// ---------------------------------------------------------------------------
// Bulk operations
// ---------------------------------------------------------------------------

function clear() {
  _state.user = null;
  _state.roles = [];
  _state.storeId = null;
  _state.workstationId = null;
  clearToken();
}

function isAuthenticated() {
  return !!getToken();
}

function getState() {
  return Object.assign({}, _state);
}

module.exports = {
  isAuthenticated: isAuthenticated,
  getToken: getToken,
  setToken: setToken,
  clearToken: clearToken,
  getUser: getUser,
  setUser: setUser,
  getRoles: getRoles,
  setRoles: setRoles,
  hasRole: hasRole,
  getStoreId: getStoreId,
  setStoreId: setStoreId,
  getWorkstationId: getWorkstationId,
  setWorkstationId: setWorkstationId,
  clear: clear,
  getState: getState,
};
