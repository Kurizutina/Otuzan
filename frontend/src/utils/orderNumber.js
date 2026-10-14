// The number everyone refers to an order by. The server's id ("Order #29") is
// the same for the customer, rider and admin, and matches the server-written
// notifications. The browser-made id (ORD-1014545) only shows until the server
// has accepted the order, and it stays internal otherwise (it is also what the
// browser uses to match its own card to the server record).
export const orderNumber = (order) => (
  order?.backendOrderId ? `Order #${order.backendOrderId}` : order?.id
);
