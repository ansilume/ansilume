import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm } from '../../lib/helpers';
import { PAGE_TITLE } from '../../lib/selectors';

test.describe('Roles CRUD', () => {
  const roleName = 'e2e-crud-role';

  test('index page lists roles', async ({ page }) => {
    await page.goto('/role/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/role/i);
    // Built-in roles are always present.
    await expect(page.locator('table.table')).toContainText('viewer');
    await expect(page.locator('table.table')).toContainText('operator');
    await expect(page.locator('table.table')).toContainText('admin');
  });

  test('create custom role', async ({ page }) => {
    await page.goto('/role/create');
    await fillForm(page, 'roleform', {
      name: roleName,
      description: 'Created by E2E test',
    });
    // Check two permissions across two domains.
    await page.locator('input[name="RoleForm[permissions][]"][value="project.view"]').check();
    await page.locator('input[name="RoleForm[permissions][]"][value="job.view"]').check();
    await submitForm(page);
    await expectFlash(page, 'success');
    // Landed on the view page.
    await expect(page.locator('body')).toContainText(roleName);
    await expect(page.locator('body')).toContainText('project.view');
    await expect(page.locator('body')).toContainText('job.view');
  });

  test('update custom role adds a permission', async ({ page }) => {
    await page.goto('/role/update/' + roleName);
    await expect(page.locator('#roleform-name')).toHaveValue(roleName);
    // Add a third permission.
    await page.locator('input[name="RoleForm[permissions][]"][value="analytics.view"]').check();
    await submitForm(page);
    await expectFlash(page, 'success');
    await page.goto('/role/view/' + roleName);
    await expect(page.locator('body')).toContainText('analytics.view');
  });

  test('delete custom role', async ({ page }) => {
    page.on('dialog', (d) => d.accept());
    await page.goto('/role/view/' + roleName);
    // Delete button is inside an explicit <form> with CSRF token.
    const deleteBtn = page.locator('button:has-text("Delete")');
    await expect(deleteBtn).toBeVisible();
    await deleteBtn.click();
    await page.waitForLoadState('networkidle', { timeout: 10_000 });
    await expectFlash(page, 'success');
    // Role no longer listed.
    await page.goto('/role/index');
    await expect(page.locator('table.table')).not.toContainText(roleName);
  });

  test('name validation rejects invalid characters', async ({ page }) => {
    await page.goto('/role/create');
    await fillForm(page, 'roleform', {
      name: 'Invalid Name!',
      description: 'bad',
    });
    await page.locator('#page-content button[type="submit"]').click();
    await expect(page.locator('.invalid-feedback, .help-block-error, .has-error')).toBeVisible({
      timeout: 5_000,
    });
  });
});
