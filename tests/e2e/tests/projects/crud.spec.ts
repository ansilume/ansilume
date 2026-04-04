import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, getTableRowCount, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Projects CRUD', () => {
  test('index page lists projects', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/project/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view project detail', async ({ page }) => {
    await page.goto('/project/index');
    await page.locator('table.table tbody tr a').first().click();
    await expect(page.locator('body')).toContainText(/project|scm|status/i);
  });

  test('create manual project', async ({ page }) => {
    await page.goto('/project/create');
    await fillForm(page, 'project', {
      name: 'e2e-crud-project',
      description: 'Created by E2E test',
    });
    // Select manual SCM type
    const scmSelect = page.locator('#project-scm_type');
    if (await scmSelect.isVisible()) {
      await scmSelect.selectOption('manual');
    }
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('validation rejects empty name', async ({ page }) => {
    await page.goto('/project/create');
    await fillForm(page, 'project', { name: '' });
    await page.locator('#page-content button[type="submit"]').click();
    await expect(page.locator('.invalid-feedback, .help-block-error, .has-error')).toBeVisible({
      timeout: 5_000,
    });
  });

  test('update project', async ({ page }) => {
    await page.goto('/project/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-project' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#project-description').fill('Updated by E2E test');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete project', async ({ page }) => {
    await deleteByRowText(page, '/project/index', 'e2e-crud-project');
    await expectFlash(page, 'success');
  });
});
