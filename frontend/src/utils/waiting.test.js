import { renderHook } from '@testing-library/react';
import { formatWaiting, minutesWaiting, useTabTitleCount, waitingLevel } from './waiting';

const now = Date.parse('2026-10-16T05:00:00.000Z');

test('minutes waiting counts whole minutes since the order was placed', () => {
  expect(minutesWaiting({ createdAt: '2026-10-16T04:56:30.000Z' }, now)).toBe(3);
  expect(minutesWaiting({ createdAt: '2026-10-16T05:00:00.000Z' }, now)).toBe(0);
  expect(minutesWaiting({ createdAt: '2026-10-16T05:05:00.000Z' }, now)).toBe(0);
  expect(minutesWaiting({}, now)).toBe(0);
});

test('an order turns amber after 3 minutes and red after 5', () => {
  expect([0, 2, 3, 4, 5, 30].map(waitingLevel)).toEqual(['normal', 'normal', 'amber', 'amber', 'red', 'red']);
});

test('waiting time reads naturally', () => {
  expect([0, 1, 59, 60, 135].map(formatWaiting)).toEqual(['Just now', '1 min', '59 min', '1 hr 0 min', '2 hr 15 min']);
});

test('the tab title shows how many orders need attention and restores itself', () => {
  document.title = 'Otu-Zan Delivery';
  const { rerender, unmount } = renderHook(({ count }) => useTabTitleCount(count), { initialProps: { count: 2 } });
  expect(document.title).toBe('(2) Otu-Zan Delivery');
  rerender({ count: 3 });
  expect(document.title).toBe('(3) Otu-Zan Delivery');
  rerender({ count: 0 });
  expect(document.title).toBe('Otu-Zan Delivery');
  rerender({ count: 1 });
  unmount();
  expect(document.title).toBe('Otu-Zan Delivery');
});
