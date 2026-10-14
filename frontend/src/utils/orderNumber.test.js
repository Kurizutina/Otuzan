import { orderNumber } from './orderNumber';

test('shows the server order number once the server has the order', () => {
  expect(orderNumber({ id: 'ORD-1014545', backendOrderId: 29 })).toBe('Order #29');
  expect(orderNumber({ id: 'BACKEND-29', backendOrderId: 29 })).toBe('Order #29');
});

test('falls back to the browser id before the server has the order', () => {
  expect(orderNumber({ id: 'ORD-1014545' })).toBe('ORD-1014545');
  expect(orderNumber(null)).toBeUndefined();
});
