import { installSessionExpiryWatcher, logout, SESSION_EXPIRED_KEY, setSession } from './session';

const realLocation = window.location;

beforeEach(() => {
  sessionStorage.clear();
  localStorage.clear();
  delete window.otuzanFetchWatched;
  delete window.location;
  window.location = { pathname: '/admin/dashboard', assign: jest.fn() };
});

afterAll(() => {
  window.location = realLocation;
});

test('logout revokes the token on the server and forgets who the customer was', () => {
  window.fetch = jest.fn(() => Promise.resolve({ status: 200 }));
  setSession('tok-1', { id: 7, role: 'customer' });
  localStorage.setItem('otuzanCustomerProfile', '{"email":"a@b.co"}');
  localStorage.setItem('otuzanCustomerAddress:a@b.co', 'Villa Isidra');
  localStorage.setItem('otuzanDeliveryLocation', 'villa-isidra');
  localStorage.setItem('otuzanCustomerActivity', '{"orders":[]}');
  localStorage.setItem('otuzanRememberedEmail', 'a@b.co');

  logout();

  expect(window.fetch).toHaveBeenCalledWith(expect.stringMatching(/\/api\/auth\/logout$/), expect.objectContaining({
    method: 'POST',
    keepalive: true,
    headers: expect.objectContaining({ Authorization: 'Bearer tok-1' })
  }));
  expect(sessionStorage.getItem('otuzanAuthenticated')).toBeNull();
  expect(localStorage.getItem('otuzanCustomerProfile')).toBeNull();
  expect(localStorage.getItem('otuzanCustomerAddress:a@b.co')).toBeNull();
  expect(localStorage.getItem('otuzanDeliveryLocation')).toBeNull();
  // Shared per-browser activity and the opt-in remembered email stay.
  expect(localStorage.getItem('otuzanCustomerActivity')).not.toBeNull();
  expect(localStorage.getItem('otuzanRememberedEmail')).toBe('a@b.co');
});

test('logout without a session makes no request', () => {
  window.fetch = jest.fn();
  logout();
  expect(window.fetch).not.toHaveBeenCalled();
});

test('a 401 for the current token ends the session and sends the user to log in', async () => {
  window.fetch = jest.fn(() => Promise.resolve({ status: 401 }));
  installSessionExpiryWatcher();
  setSession('tok-1', { id: 1, role: 'admin' });

  await window.fetch('/api/admin/orders', { headers: { Authorization: 'Bearer tok-1' } });

  expect(sessionStorage.getItem('otuzanAuthenticated')).toBeNull();
  expect(sessionStorage.getItem(SESSION_EXPIRED_KEY)).toBe('1');
  expect(window.location.assign).toHaveBeenCalledWith('/login');
});

test('a 401 for an older token, an unauthenticated request, or a 200 is ignored', async () => {
  const responses = [{ status: 401 }, { status: 401 }, { status: 200 }];
  window.fetch = jest.fn(() => Promise.resolve(responses.shift()));
  installSessionExpiryWatcher();
  setSession('tok-new', { id: 1, role: 'admin' });

  await window.fetch('/api/orders', { headers: { Authorization: 'Bearer tok-old' } });
  await window.fetch('/api/auth/login', { method: 'POST' });
  await window.fetch('/api/orders', { headers: { Authorization: 'Bearer tok-new' } });

  expect(sessionStorage.getItem('otuzanAuthenticated')).toBe('tok-new');
  expect(window.location.assign).not.toHaveBeenCalled();
});

test('logging in again clears the expired-session notice', () => {
  sessionStorage.setItem(SESSION_EXPIRED_KEY, '1');
  setSession('tok-2', { id: 1, role: 'customer' });
  expect(sessionStorage.getItem(SESSION_EXPIRED_KEY)).toBeNull();
});
