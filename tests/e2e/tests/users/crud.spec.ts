import { test, expect } from '@playwright/test';
import { expectFlash, fillForm, submitForm, navigateTo, getTableRowCount, deleteByRowText } from '../../lib/helpers';
import { TABLE_ROW, BTN_CREATE, BTN_DELETE, CONFIRM_OK, PAGE_TITLE } from '../../lib/selectors';

test.describe('Users CRUD', () => {
  test('index page lists users', async ({ page }) => {
    await page.goto('/user/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/user/i);
    const rows = await getTableRowCount(page);
    expect(rows).toBeGreaterThanOrEqual(1);
  });

  test('view user detail', async ({ page }) => {
    await page.goto('/user/index');
    await page.locator('table.table tbody tr a').first().click();
    await expect(page.locator('body')).toContainText(/username|email/i);
  });

  test('create new user', async ({ page }) => {
    await page.goto('/user/create');
    await fillForm(page, 'userform', {
      username: 'e2e-newuser',
      email: 'e2e-newuser@example.com',
      password: 'E2eNewUser1!',
    });
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('role dropdown includes seeded custom role', async ({ page }) => {
    // Proves UserForm::roleOptions() is dynamic — the e2e-custom-role
    // seeded by E2eController must appear alongside the built-in roles.
    await page.goto('/user/create');
    const values = await page.locator('#userform-role option').evaluateAll(
      (els) => els.map((el) => (el as HTMLOptionElement).value),
    );
    expect(values).toContain('viewer');
    expect(values).toContain('operator');
    expect(values).toContain('admin');
    expect(values).toContain('e2e-custom-role');
  });

  test('duplicate username fails validation', async ({ page }) => {
    await page.goto('/user/create');
    await fillForm(page, 'userform', {
      username: 'e2e-admin',
      email: 'e2e-dup@example.com',
      password: 'E2eDupPass1!',
    });
    await page.locator('#page-content button[type="submit"]').click();
    await expect(page.locator('.invalid-feedback, .help-block-error, .has-error')).toBeVisible({
      timeout: 5_000,
    });
  });

  test('update user', async ({ page }) => {
    await page.goto('/user/index');
    // Find e2e-newuser row and click update
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-newuser' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#userform-email').fill('e2e-newuser-updated@example.com');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete user', async ({ page }) => {
    await deleteByRowText(page, '/user/index', 'e2e-newuser');
    await expectFlash(page, 'success');
  });
});
