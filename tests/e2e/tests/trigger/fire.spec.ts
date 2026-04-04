import { test, expect } from '@playwright/test';

test.describe('Trigger (public API)', () => {
  test('invalid token returns 404', async ({ request }) => {
    const response = await request.post('/api/v1/trigger/fire', {
      headers: { 'Content-Type': 'application/json' },
      data: { token: 'invalid-token-e2e' },
    });
    // Should be 404 or 401 for invalid token
    expect([401, 403, 404]).toContain(response.status());
  });
});
