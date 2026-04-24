import { test, expect } from '@playwright/test';

// Closes the loop on the API-token flow: generate a token in the profile UI,
// then actually authenticate against the REST API with it. The existing
// `tokens.spec.ts` only verifies the UI side; a broken BaseApiController
// auth check or a malformed token format would slip past both that spec and
// the PHPUnit layer (which mints tokens in-process, skipping the UI entirely).

test.describe('API token UI → REST roundtrip', () => {
  test('a freshly generated token authenticates GET /api/v1/projects', async ({ page, request, baseURL }) => {
    await page.goto('/profile/tokens');
    await page.locator('input[name="name"]').fill('e2e-token-api-roundtrip');
    await page.locator('#page-content button[type="submit"]:has-text("Generate")').click();

    const tokenCode = page.locator('.alert-success code').first();
    await expect(tokenCode).toBeVisible({ timeout: 5_000 });
    const token = (await tokenCode.textContent())?.trim();
    expect(token, 'token text was empty').toBeTruthy();

    // Bearer-authenticated request must succeed and return the documented
    // pagination envelope (data + meta). A regression in BaseApiController::auth
    // would drop us at 401 here.
    const apiResponse = await request.get(`${baseURL}/api/v1/projects`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    expect(apiResponse.status(), `unexpected ${apiResponse.status()}`).toBe(200);
    const body = (await apiResponse.json()) as { data: unknown[]; meta: { total: number; per_page: number } };
    expect(Array.isArray(body.data)).toBe(true);
    expect(body.meta).toMatchObject({ per_page: 25 });
    expect(typeof body.meta.total).toBe('number');

    // Same call without the header must be rejected — proves the 200 above
    // was earned via Bearer auth and not by a session cookie side-channel.
    const unauth = await request.get(`${baseURL}/api/v1/projects`, {
      headers: { Authorization: '' },
    });
    expect([401, 403]).toContain(unauth.status());
  });
});
