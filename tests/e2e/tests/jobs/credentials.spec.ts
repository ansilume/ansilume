import { test, expect } from '@playwright/test';

// Fixtures from commands/E2eCredentialUsageSeeder.php
const TEMPLATE = 'e2e-cred-usage-template';
const PRIMARY = 'e2e-cred-usage-primary';
const TOKEN = 'e2e-cred-usage-token';
const REMOVED = 'e2e-cred-removed-key';

test.describe('Job credentials', () => {
  test('the job page lists the credentials as launched, including deleted ones', async ({ page }) => {
    await page.goto('/job-template/index?sort=-id');
    await page.locator('#template-table tbody tr', { hasText: TEMPLATE }).first().locator('a', { hasText: TEMPLATE }).click();
    await page.locator('a[href*="/job/view"]').first().click();

    const items = page.locator('#job-credentials li');
    await expect(items).toHaveCount(3);
    await expect(items.nth(0)).toContainText(PRIMARY);
    await expect(items.nth(0)).toHaveAttribute('data-role', 'primary');
    await expect(items.nth(1)).toContainText(TOKEN);
    await expect(items.nth(2)).toContainText(REMOVED);
    await expect(items.nth(2)).toContainText('(deleted)');
    await expect(items.nth(2).locator('a')).toHaveCount(0);
  });
});
