import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, getTableRowCount, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Teams CRUD', () => {
  test('index page lists teams', async ({ page }) => {
    await page.goto('/team/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/team/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view team detail', async ({ page }) => {
    await page.goto('/team/index');
    await page.locator('table.table tbody tr a').first().click();
    await expect(page.locator('body')).toContainText(/team|member|project/i);
  });

  test('create team', async ({ page }) => {
    await page.goto('/team/create');
    await fillForm(page, 'team', {
      name: 'e2e-crud-team',
      description: 'Created by E2E test',
    });
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update team', async ({ page }) => {
    await page.goto('/team/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-team' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#team-description').fill('Updated by E2E test');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete team', async ({ page }) => {
    await deleteByRowText(page, '/team/index', 'e2e-crud-team');
    await expectFlash(page, 'success');
  });
});
