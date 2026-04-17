import { test, expect } from '@playwright/test';

test.describe('Error Pages', () => {
  test('nonexistent project id shows 404 rendering', async ({ page }) => {
    const response = await page.goto('/project/view?id=999999');
    expect(response?.status()).toBe(404);
    await expect(page.locator('body')).toContainText(/not found|404/i);
  });

  test('nonexistent job template id shows 404', async ({ page }) => {
    const response = await page.goto('/job-template/view?id=999999');
    expect(response?.status()).toBe(404);
    await expect(page.locator('body')).toContainText(/not found|404/i);
  });

  test('direct navigation to unknown URL shows a not-found page', async ({ page }) => {
    const response = await page.goto('/this-does-not-exist');
    expect([200, 404]).toContain(response?.status() ?? 0);
    await expect(page.locator('body')).not.toContainText(/SQLSTATE|Exception/);
  });
});
