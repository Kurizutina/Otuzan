import { sanitizeContactNumber, validateContactNumber } from './validation';

const run = (validator, value) => {
  const setMessage = jest.fn();
  return { ok: validator(value, setMessage), setMessage };
};

test('contact numbers must be exactly 11 digits starting with 09', () => {
  expect(run(validateContactNumber, '09123456789').ok).toBe(true);
  ['0912345678', '091234567890', '19123456789', '+639123456789', '0912-345-6789'].forEach((value) => {
    const { ok, setMessage } = run(validateContactNumber, value);
    expect(ok).toBe(false);
    expect(setMessage).toHaveBeenCalledWith(expect.objectContaining({ type: 'error' }));
  });
});

test('contact input keeps only digits and stops at 11 characters', () => {
  expect(sanitizeContactNumber('09a1-2 3456789012')).toBe('09123456789');
});
