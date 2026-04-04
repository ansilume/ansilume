import { test, expect } from '@playwright/test';

test.describe('Workflow Launch', () => {
  test('launch button exists on workflow detail', async ({ page }) => {
    await page.goto('/workflow-template/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-workflow' });
    if (await row.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await row.locator('a').first().click();
      const launchBtn = page.locator('a:has-text("Launch"), button:has-text("Launch")').first();
      const exists = await launchBtn.isVisible({ timeout: 3_000 }).catch(() => false);
      expect(exists).toBeTruthy();
    }
  });
});
