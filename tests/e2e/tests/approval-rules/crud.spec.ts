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
      // Should show human-readable approver config instead of raw JSON
      await expect(page.locator('body')).toContainText('Users:');
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

  test('warns when required approvals exceeds eligible users', async ({ page }) => {
    await page.goto('/approval-rule/create');

    // Select "users" type and pick only 1 user
    await page.locator('#approver-type').selectOption('users');
    const firstOption = page.locator('#approver-users option').first();
    const firstValue = await firstOption.getAttribute('value');
    if (firstValue) {
      await page.locator('#approver-users').selectOption([firstValue]);
    }

    // Set required approvals to 5 — should trigger warning
    await page.locator('#required-approvals').fill('5');
    await page.locator('#required-approvals').dispatchEvent('input');
    const warning = page.locator('#approver-count-warning');
    await expect(warning).toBeVisible();
    await expect(warning).toContainText(/exceeds/i);

    // Reduce to 1 — warning should disappear
    await page.locator('#required-approvals').fill('1');
    await page.locator('#required-approvals').dispatchEvent('input');
    await expect(warning).toBeHidden();
  });

  test('server rejects rule when required approvals exceeds eligible users', async ({ page }) => {
    await page.goto('/approval-rule/create');
    await page.locator('#approvalrule-name').fill('e2e-excess-approvers');
    await page.locator('#approver-type').selectOption('users');
    await expect(page.locator('#config-users')).toBeVisible();
    const firstOption = page.locator('#approver-users option').first();
    const firstValue = await firstOption.getAttribute('value');
    if (firstValue) {
      await page.locator('#approver-users').selectOption([firstValue]);
    }
    await page.locator('#required-approvals').fill('10');
    await page.locator('#approvalrule-timeout_action').selectOption('reject');
    await submitForm(page);

    // Server-side validation should reject — page stays on form with error
    await expect(page.locator('body')).toContainText(/exceeds/i, { timeout: 5_000 });
  });

  test('delete approval rule', async ({ page }) => {
    await deleteByRowText(page, '/approval-rule/index', 'e2e-crud-approval-rule');
    await expectFlash(page, 'success');
  });
});
