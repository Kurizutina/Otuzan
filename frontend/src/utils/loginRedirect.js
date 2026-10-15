// A guest who is sent to log in should come back to where they were (their
// cart, the store they tapped) and be told why they were sent. The login page
// reads this from the router state.
export const loginState = (location, reason) => ({
  from: `${location.pathname}${location.search || ''}`,
  ...(reason ? { reason } : {})
});

export const ORDER_LOGIN_MESSAGE = 'Log in or create an account to place your order. Your cart is saved.';

const NEVER_RETURN_TO = ['/login', '/register', '/forgot-password', '/reset-password', '/change-password'];

// Only an in-app path is a valid place to return to: never another site
// ("//evil.example", "https://...") and never an auth page, which would loop.
// ProtectedRoute passes a whole location object as `from`, so accept both.
export const returnPathFrom = (state) => {
  const from = state?.from;
  const path = typeof from === 'string' ? from : from?.pathname;
  if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//') || path.includes('\\')) return null;
  return NEVER_RETURN_TO.some((blocked) => path === blocked || path.startsWith(`${blocked}?`)) ? null : path;
};
