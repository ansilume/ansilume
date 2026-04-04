import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, getTableRowCount, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Credentials CRUD', () => {
  test('index page lists credentials', async ({ page }) => {
    await page.goto('/credential/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/credential/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view credential detail (secrets masked)', async ({ page }) => {
    await page.goto('/credential/index');
    await page.locator('table.table tbody tr a').first().click();
    // Secrets should never appear in plain text
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).not.toContain('e2e-dummy-token-value');
  });

  test('create token credential', async ({ page }) => {
    await page.goto('/credential/create');
    await fillForm(page, 'credential', {
      name: 'e2e-crud-credential',
      description: 'Created by E2E test',
    });
    await page.locator('#credential-type').selectOption('token');
    await page.locator('input[name="secrets[token]"]').fill('e2e-test-token');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update credential', async ({ page }) => {
    await page.goto('/credential/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-credential' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#credential-description').fill('Updated by E2E test');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete credential', async ({ page }) => {
    await deleteByRowText(page, '/credential/index', 'e2e-crud-credential');
    await expectFlash(page, 'success');
  });
});
