import { API_BASE_URL, apiAssetUrl } from './catalog';

test('signed bill-document links reach the API with their signature intact', () => {
  const signed = '/api/uploads/bill-documents/0b6a1f8e-0000-4000-8000-000000000000.pdf?expires=1760259600&signature=abc123';
  expect(apiAssetUrl(signed)).toBe(`${API_BASE_URL}${signed}`);
});

test('plain upload paths still resolve against the API host', () => {
  expect(apiAssetUrl('/uploads/products/Mc Café.png')).toBe(`${API_BASE_URL}/uploads/products/Mc%20Caf%C3%A9.png`);
});
