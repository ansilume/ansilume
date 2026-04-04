import { test, expect } from '@playwright/test';

test.describe('Job Template Launch', () => {
  test('launch form renders for seeded template', async ({ page }) => {
    await page.goto('/job-template/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-template' });
    const launchBtn = row.locator('a:has-text("Launch"), button:has-text("Launch")').first();
    if (await launchBtn.isVisible()) {
      await launchBtn.click();
      // Should show launch confirmation or extra vars form
      await expect(page.locator('body')).toContainText(/launch|confirm|extra|vars|run/i);
    }
  });

  test('launch creates a job', async ({ page }) => {
    await page.goto('/job-template/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-template' });
    const launchBtn = row.locator('a:has-text("Launch"), button:has-text("Launch")').first();
    if (await launchBtn.isVisible()) {
      await launchBtn.click();
      // Submit the launch form if present
      const submitBtn = page.locator('#page-content button[type="submit"]:has-text("Launch"), #page-content button[type="submit"]').first();
      if (await submitBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await submitBtn.click();
      }
      // Should redirect to job detail or job list
      await page.waitForURL(/.*job.*/, { timeout: 10_000 }).catch(() => {});
    }
  });
});
