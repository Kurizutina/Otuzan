import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { AccountManagementTab } from './DeliveryAdminDashboard';

jest.mock('react-router-dom', () => ({ useNavigate: () => jest.fn(), useLocation: () => ({ pathname: '/admin/dashboard', search: '' }) }));

const json = (body) => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => body });
const page = (accounts) => json({ data: accounts, current_page: 1, last_page: 1, total: accounts.length });
const ana = { UserID: 5, UserName: 'Ana Prank', Email: 'ana@example.com', Contact: '09171234567', Address: 'PNR Main', BlockedAt: null };

beforeEach(() => { localStorage.setItem('otuzanAuthenticated', 'admin-token'); });
afterEach(() => { localStorage.clear(); delete global.fetch; jest.restoreAllMocks(); });

test('an admin blocks a customer after confirming, and the list then shows them as blocked', async () => {
  global.fetch = jest.fn()
    .mockResolvedValueOnce(page([ana]))
    .mockResolvedValueOnce(json({ ...ana, BlockedAt: '2026-10-16 08:00:00' }))
    .mockResolvedValueOnce(page([{ ...ana, BlockedAt: '2026-10-16 08:00:00' }]));
  jest.spyOn(window, 'confirm').mockReturnValue(true);
  render(<AccountManagementTab role="customer" />);

  fireEvent.click(await screen.findByRole('button', { name: 'Block' }));

  expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining("won't be able to log in or order"));
  const [url, options] = global.fetch.mock.calls[1];
  expect(url).toMatch(/\/api\/admin\/accounts\/customer\/5\/block$/);
  expect(options).toMatchObject({ method: 'PATCH', body: JSON.stringify({ blocked: true }) });
  expect(await screen.findByText('Blocked')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Unblock' })).toBeInTheDocument();
});

test('cancelling the confirmation blocks nobody', async () => {
  global.fetch = jest.fn().mockResolvedValueOnce(page([ana]));
  jest.spyOn(window, 'confirm').mockReturnValue(false);
  render(<AccountManagementTab role="customer" />);
  fireEvent.click(await screen.findByRole('button', { name: 'Block' }));
  await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
});

test('riders have no block button (a rider who should stop is removed instead)', async () => {
  global.fetch = jest.fn().mockResolvedValueOnce(page([{ ...ana, UserName: 'Ben Rider' }]));
  render(<AccountManagementTab role="driver" />);
  await screen.findByText('Ben Rider');
  expect(screen.queryByRole('button', { name: 'Block' })).not.toBeInTheDocument();
});
