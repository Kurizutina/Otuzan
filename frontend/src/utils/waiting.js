import { useEffect, useState } from 'react';

// Quiet cues for an order nobody has picked up yet: how long it has waited,
// and a colour that turns amber and then red. Deliberately silent - no sound,
// no pop-up, no push notification (client decision) - so the screen itself
// has to make a waiting order impossible to miss at a glance.
export const WAITING_AMBER_MINUTES = 3;
export const WAITING_RED_MINUTES = 5;

export const minutesWaiting = (order, now = Date.now()) => {
  const placedAt = Date.parse(order?.createdAt);
  return Number.isFinite(placedAt) ? Math.max(0, Math.floor((now - placedAt) / 60000)) : 0;
};

export const waitingLevel = (minutes) => {
  if (minutes >= WAITING_RED_MINUTES) return 'red';
  if (minutes >= WAITING_AMBER_MINUTES) return 'amber';
  return 'normal';
};

export const formatWaiting = (minutes) => {
  if (minutes < 1) return 'Just now';
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  return `${hours} hr ${minutes % 60} min`;
};

// Re-renders every `intervalMs` so a waiting time keeps counting up.
export const useNow = (intervalMs = 15000) => {
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), intervalMs);
    return () => window.clearInterval(timer);
  }, [intervalMs]);
  return now;
};

// Puts the number of orders needing attention in the browser tab title -
// "(2) Otu-Zan" - so a minimized or background tab still shows it. Restores
// the original title when the count is 0 or the screen closes.
export const useTabTitleCount = (count) => {
  useEffect(() => {
    const baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
    document.title = count > 0 ? `(${count}) ${baseTitle}` : baseTitle;
    return () => { document.title = baseTitle; };
  }, [count]);
};
