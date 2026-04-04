import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, getTableRowCount, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Notification Templates CRUD', () => {
  test('index page lists notification templates', async ({ page }) => {
    await page.goto('/notification-template/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/notification/i);
  });

  test('view notification template detail', async ({ page }) => {
    await page.goto('/notification-template/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/notification|channel|event/i);
    }
  });

  test('create notification template', async ({ page }) => {
    await page.goto('/notification-template/create');
    await fillForm(page, 'notificationtemplate', {
      name: 'e2e-crud-notification',
      subject_template: 'E2E Test Subject',
      body_template: 'E2E Test Body',
    });
    // Channel selector uses custom id #nt-channel in this view.
    await page.locator('#nt-channel').selectOption('email');
    await page.locator('#email-recipients').fill('["ops@example.com"]');
    // Tick at least one event checkbox so validation passes.
    await page.locator('.event-checkbox').first().check();
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update notification template', async ({ page }) => {
    await page.goto('/notification-template/index');
    // Notification templates expose Update only from the view page, not the index row.
    await page.locator('table.table tbody tr', { hasText: 'e2e-crud-notification' }).locator('a').first().click();
    await page.locator('#page-content a:has-text("Update"), #page-content a:has-text("Edit")').first().click();
    await page.locator('#notificationtemplate-description').fill('Updated by E2E');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete notification template', async ({ page }) => {
    await deleteByRowText(page, '/notification-template/index', 'e2e-crud-notification');
    await expectFlash(page, 'success');
  });
});
