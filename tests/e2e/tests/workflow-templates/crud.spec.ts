import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Workflow Templates CRUD', () => {
  test('index page lists workflow templates', async ({ page }) => {
    await page.goto('/workflow-template/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/workflow/i);
  });

  test('view workflow template detail', async ({ page }) => {
    await page.goto('/workflow-template/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/workflow|step|template/i);
    }
  });

  test('create workflow template', async ({ page }) => {
    await page.goto('/workflow-template/create');
    await fillForm(page, 'workflowtemplate', {
      name: 'e2e-crud-workflow',
      description: 'Created by E2E test',
    });
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update workflow template', async ({ page }) => {
    await page.goto('/workflow-template/index');
    await page.locator('table.table tbody tr', { hasText: 'e2e-crud-workflow' }).locator('a').first().click();
    await page.locator('#page-content a:has-text("Update"), #page-content a:has-text("Edit")').first().click();
    await page.locator('#workflowtemplate-description').fill('Updated by E2E');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete workflow template', async ({ page }) => {
    await deleteByRowText(page, '/workflow-template/index', 'e2e-crud-workflow');
    await expectFlash(page, 'success');
  });
});
