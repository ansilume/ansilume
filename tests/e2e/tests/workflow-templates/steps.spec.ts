import { test, expect } from '@playwright/test';

test.describe('Workflow Steps', () => {
  test('workflow detail shows steps', async ({ page }) => {
    await page.goto('/workflow-template/index');
    await page.locator('table.table tbody tr', { hasText: 'e2e-workflow' }).first().locator('a').first().click();
    await expect(page.locator('body')).toContainText(/step/i);
  });

  test('add step form exists', async ({ page }) => {
    await page.goto('/workflow-template/index');
    await page.locator('table.table tbody tr', { hasText: 'e2e-workflow' }).first().locator('a').first().click();
    await expect(page.locator('#add-step-form')).toBeVisible();
  });
});
