import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import PayBillsForm from './PayBillsForm';

jest.mock('../../../context/CustomerActivityContext', () => ({
  useCustomerActivity: () => ({ deliveryLocation: 'clsu-main-campus' })
}));
jest.mock('../Header/LocationPicker/LocationPicker', () => () => null);

afterEach(() => { delete global.fetch; });

const openForm = (onSubmit) => {
  const view = render(<PayBillsForm establishmentName="Water" onSubmit={onSubmit} onCancel={jest.fn()} />);
  const document = new File(['bill'], 'bill.pdf', { type: 'application/pdf' });
  view.container.querySelectorAll('input[type="file"]').forEach((input) => {
    fireEvent.change(input, { target: { files: [document] } });
  });
  return view.container.querySelector('form');
};

test('upload failure is shown and cannot create a local-only bill', async () => {
  global.fetch = jest.fn().mockResolvedValue({ ok: false, json: async () => ({ error: 'Upload unavailable' }) });
  const onSubmit = jest.fn();
  fireEvent.submit(openForm(onSubmit));
  expect(await screen.findByRole('alert')).toHaveTextContent('Upload unavailable');
  expect(onSubmit).not.toHaveBeenCalled();
  expect(screen.getByRole('button', { name: 'Place' })).toBeEnabled();
});

test('an HTML upload response displays a clear error instead of a JSON parser error', async () => {
  global.fetch = jest.fn().mockResolvedValue({ ok: true, json: async () => { throw new SyntaxError("Unexpected token '<'"); } });
  const onSubmit = jest.fn();
  fireEvent.submit(openForm(onSubmit));
  expect(await screen.findByRole('alert')).toHaveTextContent('The upload server returned an invalid response. Please try again.');
  expect(onSubmit).not.toHaveBeenCalled();
});

test('submission waits for persistence, blocks duplicate clicks and displays save failures', async () => {
  global.fetch = jest.fn().mockResolvedValue({ ok: true, json: async () => ({ url: '/uploads/bill-documents/bill.pdf', name: 'bill.pdf' }) });
  let rejectSave;
  const onSubmit = jest.fn(() => new Promise((resolve, reject) => { rejectSave = reject; }));
  const form = openForm(onSubmit);
  fireEvent.submit(form);
  fireEvent.submit(form);
  await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
  expect(global.fetch).toHaveBeenCalledTimes(2);
  expect(screen.getByRole('button', { name: 'Submitting...' })).toBeDisabled();
  rejectSave(new Error('Payment unavailable'));
  expect(await screen.findByRole('alert')).toHaveTextContent('Payment unavailable');
  expect(screen.getByRole('button', { name: 'Place' })).toBeEnabled();
});
