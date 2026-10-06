import { test, expect, Page } from '@playwright/test';

// Fixtures from commands/E2eCredentialUsageSeeder.php
const TEMPLATE = 'e2e-cred-usage-template';
const PRIMARY = 'e2e-cred-usage-primary';
const TOKEN = 'e2e-cred-usage-token';

async function openTemplate(page: Page) {
  await page.goto('/job-template/index?sort=-id');
  await page.locator('#template-table tbody tr', { hasText: TEMPLATE }).first().locator('a', { hasText: TEMPLATE }).click();
  await expect(page.locator('h2').first()).toHaveText(TEMPLATE);
}

test.describe('Job template credentials', () => {
  test('the template page lists its credentials in precedence order', async ({ page }) => {
    await openTemplate(page);

    const items = page.locator('#template-credentials li');
    await expect(items).toHaveCount(2);
    await expect(items.nth(0)).toHaveAttribute('data-role', 'primary');
    await expect(items.nth(0)).toContainText(PRIMARY);
    await expect(items.nth(1)).toHaveAttribute('data-role', 'additional');
    await expect(items.nth(1)).toContainText(TOKEN);
  });

  test('the launch page shows which credentials the job will use', async ({ page }) => {
    await openTemplate(page);
    await page.locator('#page-content a', { hasText: /^Launch$/ }).first().click();

    const items = page.locator('#launch-credentials li');
    await expect(items).toHaveCount(2);
    await expect(items.nth(0)).toContainText(PRIMARY);
    await expect(items.nth(1)).toContainText(TOKEN);
  });
});
