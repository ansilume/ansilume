import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE } from '../../lib/selectors';
import { openWorkflowTemplate } from '../../lib/triggers';

test.describe('Workflow Templates CRUD', () => {
  test('index page lists workflow templates', async ({ page }) => {
    await page.goto('/workflow-template/index');
    await expect(page.locator(PAGE_TITLE).first()).toContainText(/workflow/i);
  });

  test('view workflow template detail', async ({ page }) => {
    await openWorkflowTemplate(page, 'e2e-workflow');
    await expect(page.locator('#page-content h2').first()).toHaveText('e2e-workflow');
    await expect(page.locator('#workflow-steps-table')).toContainText('e2e-step-run');
  });

  test('create workflow template refuses an empty name', async ({ page }) => {
    await page.goto('/workflow-template/create');
    await expect(page.locator('.breadcrumb-item.active')).toHaveText('New');
    await page.locator('#workflow-template-form button[type="submit"]').click();
    await expect(page.locator('#workflow-template-form')).toContainText('Name cannot be blank.');
    await expect(page).toHaveURL(/\/workflow-template\/create$/);
  });

  test('create workflow template', async ({ page }) => {
    await page.goto('/workflow-template/create');
    await fillForm(page, 'workflowtemplate', {
      name: 'e2e-crud-workflow',
      description: 'Created by E2E test',
    });
    await submitForm(page);
    await expectFlash(page, 'success', 'Workflow template "e2e-crud-workflow" created.');
  });

  test('update workflow template', async ({ page }) => {
    await page.goto('/workflow-template/index');
    await page.locator('table.table tbody tr', { hasText: 'e2e-crud-workflow' }).locator('a').first().click();
    await page.locator('#page-content a:has-text("Update"), #page-content a:has-text("Edit")').first().click();
    await expect(page.locator('.breadcrumb-item.active')).toHaveText('Edit');
    await expect(page.locator('.breadcrumb-item a', { hasText: 'e2e-crud-workflow' })).toBeVisible();
    await page.locator('#workflowtemplate-description').fill('Updated by E2E');
    await submitForm(page);
    await expectFlash(page, 'success', 'Workflow template "e2e-crud-workflow" updated.');
  });

  test('delete workflow template', async ({ page }) => {
    await deleteByRowText(page, '/workflow-template/index', 'e2e-crud-workflow');
    // Without running runs, nothing is canceled.
    await expectFlash(page, 'success', 'Workflow template "e2e-crud-workflow" deleted.');
    await expect(page.locator('.alert-success').first()).not.toContainText('run(s)');
  });
});
