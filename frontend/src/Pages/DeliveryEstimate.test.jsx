import { render, screen } from '@testing-library/react';
import { CustomerActivityProvider, ESTIMATE_NOTE, formatEstimateRange } from '../context/CustomerActivityContext';
import RiderDashboard from './rider/RiderDashboard';
import { applyBackendTruth, toLocalOrderShape } from '../hooks/useBackendOrders';

jest.mock('react-router-dom', () => ({ useNavigate: () => jest.fn() }));

const backendOrder = {
  OrderID: 5, UserID: 42, DeliveryStatus: 'pending_rider', OrderDate: '2026-10-12 02:00:00',
  items: [], payments: [], OrderSnapshot: { source: 'Jollibee', clientOrderId: 'ORD-5' }, queuePosition: 3,
  estimatedMinutes: { min: 30, max: 40, basis: 'recent_orders', ordersAhead: 2 },
  estimatedCompletionAt: '2026-10-12 02:40:00'
};

const showOnRiderDashboard = (localOrder) => {
  sessionStorage.setItem('otuzanAuthenticated', 'token');
  sessionStorage.setItem('otuzanUser', JSON.stringify({ id: 7, role: 'driver' }));
  localStorage.setItem('otuzanCustomerActivity', JSON.stringify({ orders: [localOrder], cart: [], notifications: [] }));
  render(<CustomerActivityProvider><RiderDashboard /></CustomerActivityProvider>);
};

beforeEach(() => {
  global.fetch = jest.fn().mockResolvedValue({ ok: true, json: async () => ({ data: [] }) });
});
afterEach(() => {
  localStorage.clear();
  sessionStorage.clear();
  delete global.fetch;
});

test('shows the server range, or the same +/-5 range around the formula before the server answers', () => {
  expect(formatEstimateRange({ estimatedWaitRange: { min: 30, max: 40 }, items: [] })).toBe('Estimated 30-40 min');
  // No server data: 3 items -> 40 + 2 * 2 = 44 minutes.
  expect(formatEstimateRange({ items: [{ quantity: 3 }] })).toBe('Estimated 39-49 min');
  expect(ESTIMATE_NOTE).toMatch(/not guaranteed/);
});

test('the server estimate reaches new and already-stored orders alike', () => {
  expect(toLocalOrderShape(backendOrder)).toMatchObject({
    estimatedWaitRange: { min: 30, max: 40 }, estimatedWaitMinutes: 40,
    estimatedCompletionAt: '2026-10-12T02:40:00Z', queuePosition: 3
  });

  const stored = { id: 'ORD-5', status: 'pending_rider', items: [], estimatedWaitMinutes: 44 };
  const [overlaid] = applyBackendTruth([stored], { 5: backendOrder });
  expect(overlaid).toMatchObject({ estimatedWaitRange: { min: 30, max: 40 }, estimatedWaitMinutes: 40, queuePosition: 3 });
});

test('a waiting order still shows its place in the queue next to the estimate', () => {
  showOnRiderDashboard({
    id: 'ORD-9', label: 'Lunch', source: 'Jollibee', section: 'food', status: 'pending_rider',
    createdAt: new Date().toISOString(), items: [], assignedRider: { id: 7, name: 'Rider Seven' },
    queuePosition: 3, estimatedWaitRange: { min: 30, max: 40 }
  });
  expect(screen.getByText('2 ahead in queue • Estimated 30-40 min')).toBeInTheDocument();
});

test('an order on its way still counts down to the estimated completion', () => {
  showOnRiderDashboard({
    id: 'ORD-10', label: 'Lunch', source: 'Jollibee', section: 'food', status: 'out_for_delivery',
    createdAt: new Date().toISOString(), items: [], assignedRider: { id: 7, name: 'Rider Seven' },
    estimatedCompletionAt: new Date(Date.now() + (10 * 60 * 1000)).toISOString()
  });
  expect(screen.getByText(/^(9|10)m \d{2}s$/)).toBeInTheDocument();
});
