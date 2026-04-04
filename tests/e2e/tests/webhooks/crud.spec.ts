import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Webhooks CRUD', () => {
  test('index page lists webhooks', async ({ page }) => {
    await page.goto('/webhook/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/webhook/i);
  });

  test('create webhook', async ({ page }) => {
    await page.goto('/webhook/create');
    await fillForm(page, 'webhook', {
      name: 'e2e-crud-webhook',
      url: 'https://example.com/e2e-crud-hook',
    });
    // Events are checkboxList on eventsArray — tick at least one to satisfy validation.
    await page.locator('input[type="checkbox"][name*="eventsArray"]').first().check();
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update webhook', async ({ page }) => {
    await page.goto('/webhook/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-webhook' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#webhook-url').fill('https://example.com/e2e-updated');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete webhook', async ({ page }) => {
    await deleteByRowText(page, '/webhook/index', 'e2e-crud-webhook');
    await expectFlash(page, 'success');
  });
});
