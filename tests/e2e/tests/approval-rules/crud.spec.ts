import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Approval Rules CRUD', () => {
  test('index page lists approval rules', async ({ page }) => {
    await page.goto('/approval-rule/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/approval/i);
  });

  test('view approval rule detail', async ({ page }) => {
    await page.goto('/approval-rule/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/approval|approver|rule/i);
    }
  });

  test('create approval rule', async ({ page }) => {
    await page.goto('/approval-rule/create');
    await fillForm(page, 'approvalrule', {
      name: 'e2e-crud-approval-rule',
      description: 'Created by E2E test',
    });
    // Select approver type and configure via the UI selector
    await page.locator('#approver-type').selectOption('role');
    await expect(page.locator('#config-role')).toBeVisible();
    await page.locator('#approver-role').selectOption('admin');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('approver config panels toggle by type', async ({ page }) => {
    await page.goto('/approval-rule/create');

    // Default is "role" — role panel visible, others hidden
    await expect(page.locator('#config-role')).toBeVisible();
    await expect(page.locator('#config-team')).toBeHidden();
    await expect(page.locator('#config-users')).toBeHidden();

    // Switch to "team"
    await page.locator('#approver-type').selectOption('team');
    await expect(page.locator('#config-role')).toBeHidden();
    await expect(page.locator('#config-team')).toBeVisible();
    await expect(page.locator('#config-users')).toBeHidden();

    // Switch to "users"
    await page.locator('#approver-type').selectOption('users');
    await expect(page.locator('#config-role')).toBeHidden();
    await expect(page.locator('#config-team')).toBeHidden();
    await expect(page.locator('#config-users')).toBeVisible();
  });

  test('view shows human-readable approver config', async ({ page }) => {
    await page.goto('/approval-rule/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-approval-rule' });
    if (await row.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await row.locator('a').first().click();
      // Should show "Role: admin" instead of raw JSON
      await expect(page.locator('body')).toContainText('Role:');
    }
  });

  test('update approval rule', async ({ page }) => {
    await page.goto('/approval-rule/index');
    // Approval rules expose Edit only from the view page, not the index row.
    await page.locator('table.table tbody tr', { hasText: 'e2e-crud-approval-rule' }).locator('a').first().click();
    await page.locator('#page-content a:has-text("Update"), #page-content a:has-text("Edit")').first().click();
    await page.locator('#approvalrule-description').fill('Updated by E2E');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete approval rule', async ({ page }) => {
    await deleteByRowText(page, '/approval-rule/index', 'e2e-crud-approval-rule');
    await expectFlash(page, 'success');
  });
});
