import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, getTableRowCount, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Job Templates CRUD', () => {
  test('index page lists templates', async ({ page }) => {
    await page.goto('/job-template/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/template/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view template detail', async ({ page }) => {
    await page.goto('/job-template/index');
    await page.locator('table.table tbody tr a').first().click();
    await expect(page.locator('body')).toContainText(/template|playbook|project/i);
  });

  test('create job template', async ({ page }) => {
    await page.goto('/job-template/create');
    await fillForm(page, 'jobtemplate', {
      name: 'e2e-crud-template',
      playbook: 'test.yml',
      description: 'Created by E2E test',
    });
    // Select project, inventory, runner group from dropdowns
    const projectSelect = page.locator('#jobtemplate-project_id');
    if (await projectSelect.isVisible()) {
      // Select the first available option that's not empty
      const options = await projectSelect.locator('option:not([value=""])').all();
      if (options.length > 0) {
        const value = await options[0].getAttribute('value');
        if (value) await projectSelect.selectOption(value);
      }
    }
    const inventorySelect = page.locator('#jobtemplate-inventory_id');
    if (await inventorySelect.isVisible()) {
      const options = await inventorySelect.locator('option:not([value=""])').all();
      if (options.length > 0) {
        const value = await options[0].getAttribute('value');
        if (value) await inventorySelect.selectOption(value);
      }
    }
    const runnerGroupSelect = page.locator('#jobtemplate-runner_group_id');
    if (await runnerGroupSelect.isVisible()) {
      const options = await runnerGroupSelect.locator('option:not([value=""])').all();
      if (options.length > 0) {
        const value = await options[0].getAttribute('value');
        if (value) await runnerGroupSelect.selectOption(value);
      }
    }
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update job template', async ({ page }) => {
    // The index defaults to name-sort, which pushes the freshly-created
    // e2e-crud-template past page 1 when many Demo/e2e templates share
    // a common prefix. Force id-desc so the newest row stays on page 1.
    await page.goto('/job-template/index?sort=-id');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-template' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#jobtemplate-description').fill('Updated by E2E test');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete job template', async ({ page }) => {
    await deleteByRowText(page, '/job-template/index?sort=-id', 'e2e-crud-template');
    await expectFlash(page, 'success');
  });
});
