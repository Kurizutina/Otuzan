// Mirrors the backend rule (AuthController::passwordRule) for every form that
// sets a password, so the customer sees the requirement before submitting.
export const PASSWORD_HINT = 'Use at least 8 characters with letters and numbers.';

export const passwordProblem = (password = '') => (
  password.length < 8 || !/[A-Za-z]/.test(password) || !/\d/.test(password) ? PASSWORD_HINT : ''
);
