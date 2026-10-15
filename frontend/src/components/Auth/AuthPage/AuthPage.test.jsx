import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import AuthPage from './AuthPage';
import { ORDER_LOGIN_MESSAGE } from '../../../utils/loginRedirect';

const mockNavigate = jest.fn();
let mockLocation = { pathname: '/login', state: undefined };
jest.mock('react-router-dom', () => ({ useNavigate: () => mockNavigate, useLocation: () => mockLocation }));

const signIn = async (role) => {
  global.fetch = jest.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ token: 'tok', user: { id: 4, role, email: 'a@example.com', username: 'Ana', contact: '09171234567' } })
  });
  fireEvent.change(screen.getByPlaceholderText('Email address'), { target: { value: 'a@example.com' } });
  fireEvent.change(screen.getByPlaceholderText('Password'), { target: { value: 'Zebra2026x' } });
  fireEvent.click(screen.getByRole('button', { name: 'Log in' }));
  await waitFor(() => expect(mockNavigate).toHaveBeenCalled());
};

beforeEach(() => {
  localStorage.clear(); sessionStorage.clear(); mockNavigate.mockClear();
  mockLocation = { pathname: '/login', state: undefined };
});
afterEach(() => { delete global.fetch; });

test('a guest sent here to place an order is told why and returns to the cart afterwards', async () => {
  mockLocation = { pathname: '/login', state: { from: '/catalog/brands/5', reason: 'order' } };
  render(<AuthPage mode="login" />);
  expect(screen.getByText(ORDER_LOGIN_MESSAGE)).toBeInTheDocument();
  await signIn('customer');
  expect(mockNavigate).toHaveBeenCalledWith('/catalog/brands/5', { replace: true });
});

test('the reason and the way back survive switching between log in and create account', () => {
  const state = { from: '/catalog/brands/5', reason: 'order' };
  mockLocation = { pathname: '/login', state };
  render(<AuthPage mode="login" />);
  fireEvent.click(screen.getByText('Create account'));
  expect(mockNavigate).toHaveBeenCalledWith('/register', { state });
});

test('without a saved place there is no message and login goes to the usual page', async () => {
  render(<AuthPage mode="login" />);
  expect(screen.queryByText(ORDER_LOGIN_MESSAGE)).not.toBeInTheDocument();
  await signIn('customer');
  expect(mockNavigate).toHaveBeenCalledWith('/home', { replace: true });
});

test('a rider is never sent to a customer page', async () => {
  mockLocation = { pathname: '/login', state: { from: '/catalog/brands/5', reason: 'order' } };
  render(<AuthPage mode="login" />);
  await signIn('driver');
  expect(mockNavigate).toHaveBeenCalledWith('/rider/orders', { replace: true });
});

test('a link to another site is never followed after login', async () => {
  mockLocation = { pathname: '/login', state: { from: '//evil.example/steal' } };
  render(<AuthPage mode="login" />);
  await signIn('customer');
  expect(mockNavigate).toHaveBeenCalledWith('/home', { replace: true });
});

test('sign-up asks for the password once, with a show-password toggle, and explains the user type', () => {
  render(<AuthPage mode="register" />);
  expect(screen.queryByPlaceholderText('Confirm password')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Show password' })).toBeInTheDocument();
  expect(screen.getByText(/after 8 PM for students and after 6 PM for everyone else/)).toBeInTheDocument();
  expect(screen.getByPlaceholderText('Email address')).toHaveAttribute('autocomplete', 'email');
  expect(screen.getByPlaceholderText('Password')).toHaveAttribute('autocomplete', 'new-password');
  expect(screen.getByLabelText('Contact number')).toHaveAttribute('autocomplete', 'tel');
});
