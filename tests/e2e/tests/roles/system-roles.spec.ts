import { test, expect } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';

test.describe('System Roles', () => {
  test('viewer role name field is readonly on update form', async ({ page }) => {
    await page.goto('/role/update/viewer');
    const nameField = page.locator('#roleform-name');
    await expect(nameField).toHaveValue('viewer');
    await expect(nameField).toHaveAttribute('readonly', /.*/);
  });

  test('system role view page has no delete button', async ({ page }) => {
    await page.goto('/role/view/viewer');
    // Edit button is present.
    await expect(page.locator('#page-content a:has-text("Edit")')).toBeVisible();
    // Delete button must be absent for system roles.
    await expect(page.locator('#page-content a:has-text("Delete")')).toHaveCount(0);
  });

  test('system role update form shows warning banner', async ({ page }) => {
    await page.goto('/role/update/viewer');
    await expect(page.locator('.alert-info')).toContainText(/built-in system role/i);
  });

  test('can toggle a permission on a system role and save', async ({ page }) => {
    // Use the `operator` role — adding/removing a permission here cannot
    // affect other E2E specs because operator already has broad permissions.
    await page.goto('/role/update/operator');
    // Make sure the form is rendered before clicking.
    await expect(page.locator('#role-form')).toBeVisible();
    // Toggle analytics.export on (idempotent — the test only asserts the
    // save succeeds, not the starting state).
    const checkbox = page.locator('input[name="RoleForm[permissions][]"][value="analytics.export"]');
    const wasChecked = await checkbox.isChecked();
    if (!wasChecked) {
      await checkbox.check();
    }
    await submitForm(page);
    await expectFlash(page, 'success');
    // Restore original state so later specs see the same fixture.
    if (!wasChecked) {
      await page.goto('/role/update/operator');
      await page.locator('input[name="RoleForm[permissions][]"][value="analytics.export"]').uncheck();
      await submitForm(page);
      await expectFlash(page, 'success');
    }
  });

  test('attempting to POST delete for system role is blocked', async ({ page }) => {
    // Drive the delete endpoint directly via a POST form — the controller
    // must refuse and redirect back to the view page with an error flash.
    await page.goto('/role/view/viewer');
    await page.evaluate(() => {
      const csrfMeta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
      const csrfParam = (document.querySelector('meta[name="csrf-param"]') as HTMLMetaElement | null)?.content || '_csrf';
      const form = document.createElement('form');
      form.method = 'post';
      form.action = '/role/delete/viewer';
      if (csrfMeta) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = csrfParam;
        input.value = csrfMeta.content;
        form.appendChild(input);
      }
      document.body.appendChild(form);
      form.submit();
    });
    await page.waitForLoadState('networkidle', { timeout: 10_000 });
    // Still exists.
    await page.goto('/role/index');
    await expect(page.locator('table.table')).toContainText('viewer');
  });
});
