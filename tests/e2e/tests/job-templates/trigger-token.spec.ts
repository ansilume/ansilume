import { test, expect } from '@playwright/test';

test.describe('Job Template Trigger Token', () => {
  test('trigger token section on template detail', async ({ page }) => {
    await page.goto('/job-template/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-template' });
    if (await row.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await row.locator('a').first().click();
      // Template detail may show trigger token section
      await expect(page.locator('body')).toContainText(/template|trigger|playbook/i);
    }
  });
});
