import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, getTableRowCount, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Schedules CRUD', () => {
  test('index page lists schedules', async ({ page }) => {
    await page.goto('/schedule/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/schedule/i);
  });

  test('view schedule detail', async ({ page }) => {
    await page.goto('/schedule/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/schedule|cron|template/i);
    }
  });

  test('create schedule', async ({ page }) => {
    await page.goto('/schedule/create');
    await page.locator('#schedule-name').fill('e2e-crud-schedule');
    await page.locator('#cron-input').fill('30 2 * * *');
    const templateSelect = page.locator('#schedule-job_template_id');
    if (await templateSelect.isVisible()) {
      const options = await templateSelect.locator('option:not([value=""])').all();
      if (options.length > 0) {
        const value = await options[0].getAttribute('value');
        if (value) await templateSelect.selectOption(value);
      }
    }
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update schedule', async ({ page }) => {
    await page.goto('/schedule/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-schedule' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#cron-input').fill('0 3 * * *');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete schedule', async ({ page }) => {
    await deleteByRowText(page, '/schedule/index', 'e2e-crud-schedule');
    await expectFlash(page, 'success');
  });
});
