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
    const typeSelect = page.locator('#approvalrule-approver_type');
    if (await typeSelect.isVisible()) {
      await typeSelect.selectOption('role');
    }
    await submitForm(page);
    await expectFlash(page, 'success');
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
