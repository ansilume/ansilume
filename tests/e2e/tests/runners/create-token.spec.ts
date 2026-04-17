import { test, expect } from '@playwright/test';

test.describe('Runner Create + Token Regenerate', () => {
  async function gotoFirstRunnerGroup(page: import('@playwright/test').Page): Promise<boolean> {
    await page.goto('/runner-group/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No runner group seeded');
      return false;
    }
    const href = await link.getAttribute('href');
    if (!href) {
      test.skip(true, 'Group row has no link');
      return false;
    }
    await page.goto(href);
    return true;
  }

  test('admin creates a runner via UI and sees its token', async ({ page }) => {
    if (!(await gotoFirstRunnerGroup(page))) return;

    // The runner-group view hosts an embedded "Add Runner" form posting to
    // /runner/create. Fill name + submit.
    const nameField = page.locator('form[action*="/runner/create"] input[name="name"]');
    await expect(nameField).toBeVisible({ timeout: 5_000 });
    await nameField.fill('e2e-ui-created-runner');

    const submit = page.locator(
      'form[action*="/runner/create"] button[type="submit"], form[action*="/runner/create"] input[type="submit"]'
    ).first();
    await submit.click();

    // Response redirects back to /runner-group/view with a flashed token.
    await expect(page).toHaveURL(/\/runner-group\/view/, { timeout: 5_000 });
    const tokenInput = page.locator('#token-display');
    await expect(tokenInput).toBeVisible({ timeout: 5_000 });
    const tokenValue = await tokenInput.inputValue();
    expect(tokenValue.length).toBeGreaterThan(10);
  });

  test('admin regenerates a runner token', async ({ page }) => {
    if (!(await gotoFirstRunnerGroup(page))) return;

    // Find any existing runner in this group with a regenerate-token form.
    const regenForm = page.locator('form[action*="regenerate-token"]').first();
    if (!(await regenForm.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No runner with regenerate-token form on this group');
      return;
    }

    // The form uses onsubmit="return confirm(...)" — accept the dialog.
    page.once('dialog', (d) => d.accept());

    const submit = regenForm.locator('button[type="submit"], input[type="submit"]').first();
    await submit.click();

    await expect(page).toHaveURL(/\/runner-group\/view/, { timeout: 5_000 });
    const tokenInput = page.locator('#token-display');
    await expect(tokenInput).toBeVisible({ timeout: 5_000 });
    const newToken = await tokenInput.inputValue();
    expect(newToken.length).toBeGreaterThan(10);
  });
});
