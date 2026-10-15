export const getDashboardPath = (role) => ({
  customer: '/home',
  driver: '/rider/orders',
  admin: '/admin/dashboard'
}[role] || '/login');

export const SESSION_CHANGED_EVENT = 'otuzan:session-changed';
const API_BASE_URL = process.env.REACT_APP_API_URL || 'http://localhost:5000';
export const SESSION_EXPIRED_KEY = 'otuzanSessionExpired';

const notifySessionChanged = () => {
  window.dispatchEvent(new Event(SESSION_CHANGED_EVENT));
};

const TOKEN_KEY = 'otuzanAuthenticated';
const USER_KEY = 'otuzanUser';

// The login stays in localStorage so it survives closing the browser or the
// tab - on a phone that happens all the time (switching apps, Facebook's
// in-app browser) and used to log riders, admins and customers out
// mid-task. How long it really lasts is the token's server-side expiry
// (AuthController::authenticated: customer 30 days, rider 14, admin 1).
// A sessionStorage copy is still honoured (and wins) for a browser where
// localStorage is blocked. Reads try both; every write goes where the token
// lives so the two can never disagree.
const readKey = (key) => {
  try {
    const session = sessionStorage.getItem(key);
    if (session !== null) return session;
  } catch {
    // Storage blocked - fall through.
  }
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
};

export const getToken = () => readKey(TOKEN_KEY);

const removeEverywhere = (key) => {
  try { sessionStorage.removeItem(key); } catch { /* storage blocked */ }
  try { localStorage.removeItem(key); } catch { /* storage blocked */ }
};

export const setSession = (token, user) => {
  // Replace both values together so a previous account can never be mixed
  // with the account that has just signed in.
  removeEverywhere(TOKEN_KEY);
  removeEverywhere(USER_KEY);
  let store = localStorage;
  try {
    localStorage.setItem(TOKEN_KEY, token);
  } catch {
    store = sessionStorage;
    store.setItem(TOKEN_KEY, token);
  }
  store.setItem(USER_KEY, JSON.stringify(user));
  sessionStorage.removeItem(SESSION_EXPIRED_KEY);
  notifySessionChanged();
};

// Refreshes the stored user (e.g. after a profile edit) without touching the token.
export const updateSessionUser = (user) => {
  let store = sessionStorage;
  try {
    if (sessionStorage.getItem(TOKEN_KEY) === null && localStorage.getItem(TOKEN_KEY) !== null) store = localStorage;
  } catch {
    // Keep sessionStorage.
  }
  store.setItem(USER_KEY, JSON.stringify(user));
};

export const clearSession = () => {
  removeEverywhere(TOKEN_KEY);
  removeEverywhere(USER_KEY);
  notifySessionChanged();
};

// Who the customer is (name, email, contact, address, chosen area) must not
// outlive the session on a shared device. The order-activity blob is left
// alone on purpose: it's shared per browser and filtered by role at read time.
const forgetIdentity = () => {
  try {
    localStorage.removeItem('otuzanCustomerProfile');
    localStorage.removeItem('otuzanDeliveryLocation');
    Object.keys(localStorage)
      .filter((key) => key.startsWith('otuzanCustomerAddress:'))
      .forEach((key) => localStorage.removeItem(key));
  } catch {
    // Storage unavailable - nothing to forget.
  }
};

// Clearing the browser copy alone would leave the token valid on the server
// for the rest of its life, so revoke it there too. keepalive lets the request
// finish even though the caller navigates away right after.
export const logout = () => {
  const token = getToken();
  if (token) {
    fetch(`${API_BASE_URL}/api/auth/logout`, {
      method: 'POST',
      keepalive: true,
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }
    }).catch(() => {});
  }
  forgetIdentity();
  clearSession();
};

// Tokens expire (see readKey above) or are revoked by a password change or
// logout elsewhere. Without this, every poll quietly got 401s and actions
// failed with no message while the dashboard kept showing stale data. Any 401
// to a request that carried the current token ends the session and sends the
// user to log in again. A different (older) token's 401 is ignored so it
// can't end a newer session.
export const installSessionExpiryWatcher = () => {
  if (window.otuzanFetchWatched) return;
  window.otuzanFetchWatched = true;
  const originalFetch = window.fetch.bind(window);
  window.fetch = async (input, init) => {
    const response = await originalFetch(input, init);
    if (response.status === 401) {
      const token = getToken();
      const sent = new Headers(init?.headers || (input instanceof Request ? input.headers : undefined)).get('Authorization');
      if (token && sent === `Bearer ${token}`) {
        forgetIdentity();
        clearSession();
        sessionStorage.setItem(SESSION_EXPIRED_KEY, '1');
        if (window.location.pathname !== '/login') window.location.assign('/login');
      }
    }
    return response;
  };
};

export const getSessionUser = () => {
  try {
    if (!getToken()) return null;
    return JSON.parse(readKey(USER_KEY));
  } catch {
    return null;
  }
};

export const isAssignedTo = (order, user) => Boolean(
  user?.id != null && order.assignedRider?.id != null
  && String(order.assignedRider.id) === String(user.id)
);
