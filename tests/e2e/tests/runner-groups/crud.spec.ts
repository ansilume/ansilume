import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, getTableRowCount, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Runner Groups CRUD', () => {
  test('index page lists runner groups', async ({ page }) => {
    await page.goto('/runner-group/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/runner/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view runner group detail', async ({ page }) => {
    await page.goto('/runner-group/index');
    await page.locator('table.table tbody tr a').first().click();
    await expect(page.locator('body')).toContainText(/runner|group|token/i);
  });

  test('create runner group', async ({ page }) => {
    await page.goto('/runner-group/create');
    await fillForm(page, 'runnergroup', {
      name: 'e2e-crud-runner-group',
      description: 'Created by E2E test',
    });
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update runner group', async ({ page }) => {
    await page.goto('/runner-group/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-runner-group' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#runnergroup-description').fill('Updated by E2E test');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete runner group', async ({ page }) => {
    await deleteByRowText(page, '/runner-group/index', 'e2e-crud-runner-group');
    await expectFlash(page, 'success');
  });
});
