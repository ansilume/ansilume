import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, getTableRowCount, deleteByRowText } from '../../lib/helpers';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Projects CRUD', () => {
  test('index page lists projects', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/project/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view project detail', async ({ page }) => {
    await page.goto('/project/index');
    await page.locator('table.table tbody tr a').first().click();
    await expect(page.locator('body')).toContainText(/project|scm|status/i);
  });

  test('create manual project', async ({ page }) => {
    await page.goto('/project/create');
    await fillForm(page, 'project', {
      name: 'e2e-crud-project',
      description: 'Created by E2E test',
    });
    // Select manual SCM type
    const scmSelect = page.locator('#project-scm_type');
    if (await scmSelect.isVisible()) {
      await scmSelect.selectOption('manual');
    }
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('validation rejects empty name', async ({ page }) => {
    await page.goto('/project/create');
    await fillForm(page, 'project', { name: '' });
    await page.locator('#page-content button[type="submit"]').click();
    await expect(page.locator('.invalid-feedback, .help-block-error, .has-error')).toBeVisible({
      timeout: 5_000,
    });
  });

  test('update project', async ({ page }) => {
    await page.goto('/project/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-project' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#project-description').fill('Updated by E2E test');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  // Regression: issue #15 — deleting a project that still has job templates
  // must show a danger flash instead of throwing an FK constraint exception.
  test('delete project with templates is refused (issue #15 regression)', async ({ page }) => {
    // The seeded e2e-project has e2e-template referencing it.
    page.on('dialog', (d) => d.accept());
    await page.goto('/project/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-project' }).first();
    await expect(row).toBeVisible({ timeout: 5_000 });
    await row.locator('a').first().click();
    await page.waitForLoadState('domcontentloaded');

    // Submit the delete form on the view page.
    const submitted = await page.evaluate(() => {
      const root = document.getElementById('page-content') || document.body;
      const btn = Array.from(root.querySelectorAll('form button[type="submit"], form input[type="submit"]'))
        .find((el) => /delete/i.test((el as HTMLElement).innerText || (el as HTMLInputElement).value || ''));
      if (btn) {
        const form = (btn as HTMLButtonElement).closest('form') as HTMLFormElement | null;
        if (form) { form.submit(); return true; }
      }
      return false;
    });
    expect(submitted).toBe(true);
    await page.waitForLoadState('networkidle', { timeout: 10_000 });

    // Must show danger flash with refusal, not an exception page.
    await expectFlash(page, 'danger', 'job template');
    // Project must still be listed.
    await page.goto('/project/index');
    await expect(page.locator('table.table tbody tr', { hasText: 'e2e-project' })).toBeVisible();
  });

  test('delete project', async ({ page }) => {
    await deleteByRowText(page, '/project/index', 'e2e-crud-project');
    await expectFlash(page, 'success');
  });
});
