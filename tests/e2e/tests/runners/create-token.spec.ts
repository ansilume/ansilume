import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { GROUP_WITH_TEMPLATE, NO_RUNNERS_ADD_ONE, groupId } from '../../lib/runner-groups';
import { forgePost } from '../../lib/scoping';
import { RUNNER_TOKEN_CARD, runnerRow } from '../../lib/tokens';

/** The runner a forged create names; e2e/seed removes e2e-ui-* runners. */
const FRACTION_GROUP_RUNNER = 'e2e-ui-fraction-group-runner';

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

  // Regression: create read group_id with an (int) cast, so a forged "<id>.5"
  // registered the runner in the group <id> and showed its token (and on PHP
  // 8.5 a JSON number beyond the int range answered 500). A group_id that is
  // no ID finds no group (SubmittedId): the runner group list with the
  // danger flash, and no runner. commands/E2eRunnerGroupGuardSeeder.php:
  // e2e-group-with-template has no runners.
  test('a forged create with a group ID that is no ID registers no runner', async ({ page }) => {
    const group = await groupId(page, GROUP_WITH_TEMPLATE);
    // A page without requests of its own: the dashboard's chart request can
    // store its copy of the session after the post set the flash.
    await page.goto('/team/index');

    await forgePost(page, '/runner/create', { group_id: `${group}.5`, name: FRACTION_GROUP_RUNNER }, /\/runner-group\//);

    await expect(page, 'the runner group list, not the page of the group').toHaveURL(/\/runner-group\/index$/);
    await expectFlash(page, 'danger', 'Runner group not found.');
    await expect(page.locator(RUNNER_TOKEN_CARD), 'no token').toHaveCount(0);
    await page.goto(`/runner-group/view?id=${group}`);
    await expect(runnerRow(page, FRACTION_GROUP_RUNNER)).toHaveCount(0);
    await expect(page.getByTestId('runner-group-empty')).toHaveText(NO_RUNNERS_ADD_ONE);
  });
});
