import { loginState, returnPathFrom } from './loginRedirect';

test('the login state remembers the page, its query string and why the guest was sent', () => {
  expect(loginState({ pathname: '/catalog/brands/5', search: '?x=1' }, 'order')).toEqual({ from: '/catalog/brands/5?x=1', reason: 'order' });
  expect(loginState({ pathname: '/home' })).toEqual({ from: '/home' });
});

test('only an in-app page is a valid place to return to', () => {
  expect(returnPathFrom({ from: '/catalog/brands/5' })).toBe('/catalog/brands/5');
  expect(returnPathFrom({ from: { pathname: '/faq' } })).toBe('/faq');
  ['//evil.example/x', 'https://evil.example', '/\\evil', '/login', '/register', '/change-password', '/login?x=1', undefined, 42].forEach((from) => {
    expect(returnPathFrom({ from })).toBeNull();
  });
  expect(returnPathFrom(undefined)).toBeNull();
});
