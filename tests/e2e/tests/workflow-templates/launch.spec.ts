import { test, expect } from '@playwright/test';

test.describe('Workflow Launch', () => {
  test('launch button exists on workflow detail', async ({ page }) => {
    await page.goto('/workflow-template/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-workflow' });
    if (await row.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await row.locator('a').first().click();
      const launchBtn = page.locator('button:has-text("Launch")').first();
      const exists = await launchBtn.isVisible({ timeout: 3_000 }).catch(() => false);
      expect(exists).toBeTruthy();
    }
  });

  test('launch from template detail page succeeds (regression #11)', async ({ page }) => {
    await page.goto('/workflow-template/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-workflow' });
    if (!(await row.isVisible({ timeout: 3_000 }).catch(() => false))) {
      test.skip(true, 'e2e-workflow template not seeded');
      return;
    }

    await row.locator('a').first().click();
    await expect(page).toHaveURL(/workflow-template\/view/);

    // Accept the confirmation dialog
    page.on('dialog', (dialog) => dialog.accept());

    // Click the Launch button (now a form submit button)
    const launchBtn = page.locator('button:has-text("Launch")').first();
    await expect(launchBtn).toBeVisible();
    await launchBtn.click();

    // Should NOT get a 400 Bad Request — should redirect to workflow job or
    // show success. Don't match bare "400" in the URL: a workflow-job URL
    // like /workflow-job/view?id=400 legitimately contains those digits.
    await expect(page).not.toHaveURL(/\/error.*400|400\/bad-request|errorHandler/i);
    const flash = page.locator('.alert-success, .alert-danger');
    if (await flash.isVisible({ timeout: 5_000 }).catch(() => false)) {
      // Success flash or a meaningful error (like "no steps") — not a 400
      const text = await flash.textContent();
      expect(text).not.toContain('Bad Request');
    }
  });
});
