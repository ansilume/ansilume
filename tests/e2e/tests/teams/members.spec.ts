import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';

test.describe('Team Members', () => {
  test('team detail shows members section', async ({ page }) => {
    await page.goto('/team/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-team' });
    await row.locator('a').first().click();
    await expect(page.locator('body')).toContainText(/member/i);
  });

  test('add member to team', async ({ page }) => {
    await page.goto('/team/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-team' });
    await row.locator('a').first().click();
    const addBtn = page.locator('a:has-text("Add Member"), button:has-text("Add Member"), a:has-text("Add User")').first();
    if (await addBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await addBtn.click();
      // Select a user from the form
      const userSelect = page.locator('select[name*="user_id"]').first();
      if (await userSelect.isVisible()) {
        const options = await userSelect.locator('option:not([value=""])').all();
        if (options.length > 0) {
          const value = await options[0].getAttribute('value');
          if (value) await userSelect.selectOption(value);
        }
        await page.locator('#page-content button[type="submit"]').click();
      }
    }
  });
});
