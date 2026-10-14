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

export const setSession = (token, user) => {
  // Replace both values together so a previous account can never be mixed
  // with the account that has just signed in.
  sessionStorage.setItem('otuzanAuthenticated', token);
  sessionStorage.setItem('otuzanUser', JSON.stringify(user));
  sessionStorage.removeItem(SESSION_EXPIRED_KEY);
  notifySessionChanged();
};

export const clearSession = () => {
  sessionStorage.removeItem('otuzanAuthenticated');
  sessionStorage.removeItem('otuzanUser');
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

// Clearing sessionStorage alone left the token valid on the server for the
// rest of its 2-hour life, so revoke it there too. keepalive lets the request
// finish even though the caller navigates away right after.
export const logout = () => {
  const token = sessionStorage.getItem('otuzanAuthenticated');
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

// Tokens expire after 2 hours (or are revoked by a password change). Without
// this, every poll quietly got 401s and actions failed with no message while
// the dashboard kept showing stale data. Any 401 to a request that carried the
// current token ends the session and sends the user to log in again. A
// different (older) token's 401 is ignored so it can't end a newer session.
export const installSessionExpiryWatcher = () => {
  if (window.otuzanFetchWatched) return;
  window.otuzanFetchWatched = true;
  const originalFetch = window.fetch.bind(window);
  window.fetch = async (input, init) => {
    const response = await originalFetch(input, init);
    if (response.status === 401) {
      const token = sessionStorage.getItem('otuzanAuthenticated');
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
    if (!sessionStorage.getItem('otuzanAuthenticated')) return null;
    return JSON.parse(sessionStorage.getItem('otuzanUser'));
  } catch {
    return null;
  }
};

export const isAssignedTo = (order, user) => Boolean(
  user?.id != null && order.assignedRider?.id != null
  && String(order.assignedRider.id) === String(user.id)
);
