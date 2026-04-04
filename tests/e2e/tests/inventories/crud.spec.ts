import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, getTableRowCount, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Inventories CRUD', () => {
  test('index page lists inventories', async ({ page }) => {
    await page.goto('/inventory/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/inventor/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view inventory detail', async ({ page }) => {
    await page.goto('/inventory/index');
    await page.locator('table.table tbody tr a').first().click();
    await expect(page.locator('body')).toContainText(/inventor|type|content/i);
  });

  test('create static inventory', async ({ page }) => {
    await page.goto('/inventory/create');
    await fillForm(page, 'inventory', {
      name: 'e2e-crud-inventory',
      description: 'Created by E2E test',
    });
    const typeSelect = page.locator('#inventory-inventory_type');
    if (await typeSelect.isVisible()) {
      await typeSelect.selectOption('static');
    }
    const contentArea = page.locator('#inventory-content');
    if (await contentArea.isVisible()) {
      await contentArea.fill("all:\n  hosts:\n    testhost:\n      ansible_connection: local\n");
    }
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update inventory', async ({ page }) => {
    await page.goto('/inventory/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-inventory' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#inventory-description').fill('Updated by E2E test');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete inventory', async ({ page }) => {
    await deleteByRowText(page, '/inventory/index', 'e2e-crud-inventory');
    await expectFlash(page, 'success');
  });
});
