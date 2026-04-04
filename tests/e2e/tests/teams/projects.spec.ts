import { test, expect } from '@playwright/test';

test.describe('Team Projects', () => {
  test('team detail shows projects section', async ({ page }) => {
    await page.goto('/team/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-team' });
    await row.locator('a').first().click();
    await expect(page.locator('body')).toContainText(/project/i);
  });

  test('add project to team', async ({ page }) => {
    await page.goto('/team/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-team' });
    await row.locator('a').first().click();
    const addBtn = page.locator('a:has-text("Add Project"), button:has-text("Add Project")').first();
    if (await addBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await addBtn.click();
      const projectSelect = page.locator('select[name*="project_id"]').first();
      if (await projectSelect.isVisible()) {
        const options = await projectSelect.locator('option:not([value=""])').all();
        if (options.length > 0) {
          const value = await options[0].getAttribute('value');
          if (value) await projectSelect.selectOption(value);
        }
        await page.locator('#page-content button[type="submit"]').click();
      }
    }
  });
});
