import { applyBackendTruth } from './useBackendOrders';

// Bill documents are private: the upload answers with the bare stored path,
// which the server refuses, and only the API's copy of PaymentNote carries
// the signed link. The browser that placed the bill keeps the bare path in
// its own localStorage, so the overlay has to take the signed links.
const signedReceipt = '/api/uploads/bill-documents/11111111-1111-1111-1111-111111111111.png?expires=1&signature=a';
const signedProof = '/api/uploads/bill-documents/22222222-2222-2222-2222-222222222222.pdf?expires=1&signature=b';

const storedBill = {
  id: 'local-bill', backendOrderId: 9, source: 'Electric bill', section: 'bills', status: 'pending_rider',
  details: {
    establishment: 'Electric bill', paymentStatus: 'pending', amount: 500,
    billReceiptUrl: '/uploads/bill-documents/11111111-1111-1111-1111-111111111111.png', billReceiptName: 'bill.png', billReceiptType: 'image/png',
    transferProofUrl: '/uploads/bill-documents/22222222-2222-2222-2222-222222222222.pdf', transferProofName: 'proof.pdf', transferProofType: 'application/pdf'
  }
};

const backendBill = {
  OrderID: 9, UserID: 1, TotalPrice: 575, ServiceFee: 75, OrderDate: '2026-10-13 01:00:00', DeliveryStatus: 'pending_rider',
  items: [], rider: null, queuePosition: 1,
  payments: [{
    PaymentID: 4, PaymentName: 'Electric bill', PaymentAmount: 500, PaymentStatus: 'verified',
    PaymentNote: JSON.stringify({ billReceiptUrl: signedReceipt, billReceiptName: 'bill.png', transferProofUrl: signedProof, transferProofName: 'proof.pdf' })
  }]
};

test('a stored bill shows the signed document links from the server, not its own bare paths', () => {
  const [overlaid] = applyBackendTruth([storedBill], { 9: backendBill });
  expect(overlaid.details).toMatchObject({
    paymentStatus: 'verified',
    billReceiptUrl: signedReceipt,
    transferProofUrl: signedProof,
    billReceiptType: 'image/png',
    amount: 500
  });
});
