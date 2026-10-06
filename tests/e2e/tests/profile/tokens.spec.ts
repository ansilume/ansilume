import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';

test.describe('API Tokens', () => {
  test('lists tokens page', async ({ page }) => {
    await page.goto('/profile/tokens');
    await expect(page.locator('h1, h2, .page-title')).toContainText(/token/i);
  });

  test('creates a new token', async ({ page }) => {
    await page.goto('/profile/tokens');
    // The create form is inline on the index — just fill name and submit.
    await page.locator('input[name="name"]').fill('e2e-token');
    await page.locator('#page-content button[type="submit"]:has-text("Generate")').click();
    // Success flash + freshly generated token value is shown once, in a <code> block.
    await expect(page.locator('.alert-success code')).toBeVisible({ timeout: 5_000 });
  });

  // Regression: the revoke confirm() put the token name into a JavaScript
  // string with addslashes(Html::encode()). The browser decodes &#039; back
  // to an apostrophe before the script runs, so a name could execute code.
  test('regression: token names with quotes stay inside the revoke dialog', async ({ page }) => {
    const name = `e2e-quote-token'+(window.__e2eInjected=1)+'"`;
    await page.goto('/profile/tokens');
    await page.locator('input[name="name"]').fill(name);
    await page.locator('#page-content button[type="submit"]:has-text("Generate")').click();
    await expect(page.locator('.alert-success code')).toBeVisible({ timeout: 5_000 });

    const row = page.locator('table tbody tr', { hasText: 'e2e-quote-token' });
    const messages: string[] = [];
    page.once('dialog', async (dialog) => {
      messages.push(dialog.message());
      await dialog.dismiss();
    });
    await row.locator('button:has-text("Revoke")').click();
    await expect.poll(() => messages.length).toBe(1);
    expect(messages[0]).toBe(`Revoke token "${name}"?`);
    expect(await page.evaluate(() => (window as unknown as { __e2eInjected?: number }).__e2eInjected)).toBeUndefined();

    // Clean up: revoke it for real.
    page.once('dialog', (dialog) => dialog.accept());
    await row.locator('button:has-text("Revoke")').click();
    await expectFlash(page, 'success');
  });

  test('shows dev-mode API explorer links', async ({ page }) => {
    await page.goto('/profile/tokens');
    // E2E env runs with YII_DEBUG=1, so the dev banner is visible.
    const banner = page.locator('#dev-api-explorer');
    await expect(banner).toBeVisible();
    await expect(banner.getByRole('link', { name: /OpenAPI spec/i })).toHaveAttribute('href', '/openapi.yaml');
    await expect(banner.getByRole('link', { name: /Swagger UI/i })).toHaveAttribute('href', /:8088$/);
  });

  test('API reference is rendered dynamically from OpenAPI spec', async ({ page }) => {
    await page.goto('/profile/tokens');

    // The API Reference card must exist
    const card = page.locator('.card', { hasText: 'API Reference' });
    await expect(card).toBeVisible();

    // Must show the API version badge
    await expect(card.locator('.badge').first()).toBeVisible({ timeout: 10_000 });

    // Must contain endpoint tables generated from openapi.yaml —
    // check for known tags and endpoints
    await expect(card).toContainText('Projects');
    await expect(card).toContainText('Jobs');
    await expect(card).toContainText('Credentials');
    await expect(card).toContainText('/api/v1/jobs');
    await expect(card).toContainText('/api/v1/projects');

    // Must show HTTP method badges
    await expect(card.locator('.badge:has-text("GET")').first()).toBeVisible();
    await expect(card.locator('.badge:has-text("POST")').first()).toBeVisible();

    // Must have the OpenAPI spec link in the card header
    await expect(card.getByRole('link', { name: /OpenAPI spec/i })).toHaveAttribute('href', '/openapi.yaml');
  });
});
