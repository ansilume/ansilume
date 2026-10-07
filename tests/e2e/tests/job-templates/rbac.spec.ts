import { test, expect, Page } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';

/** Opens a template's page, paging through the list sorted by name. */
async function openTemplate(page: Page, name: string) {
  for (let listPage = 1; listPage <= 10; listPage++) {
    await page.goto(`/job-template/index?sort=name&page=${listPage}`);
    const link = page.locator('#template-table tbody tr a', { hasText: new RegExp(`^${name}$`) }).first();
    if (await link.count() > 0) {
      await link.click();
      return;
    }
  }
  throw new Error(`Job template ${name} is not listed`);
}

test.describe('Job Templates RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer cannot create templates', async ({ page }) => {
    await page.goto('/job-template/create');
    await expectForbidden(page);
  });

  test('viewer can view template index', async ({ page }) => {
    await page.goto('/job-template/index');
    // Match only "Forbidden" as a word — the Yii debug toolbar's
    // "Memory 2.403 MB" readout gives "403" a word boundary on both
    // sides, so checking for the numeric code produces false positives
    // on any page. "Forbidden" is unambiguous: Yii's 403 error view has
    // both `<title>Forbidden (#403)` and a visible heading containing
    // "Forbidden", neither of which appear on normal pages.
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('viewer sees the neutral vault lint verdict', async ({ page }) => {
    await page.goto('/job-template/index');
    const link = page.locator('table.table tbody tr a', { hasText: /^e2e-vault-template$/ }).first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'e2e-vault-template is not seeded');
      return;
    }
    await link.click();
    await expect(page.getByTestId('lint-badge')).toHaveText('not lint-checked: vault-encrypted vars_files');
  });

  test('operator can create templates', async ({ page }) => {
    await page.goto('/job-template/create');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('viewer gets 403 on template launch URL', async ({ page }) => {
    // Regression: the check looked for e2e-template, which belongs to a team
    // the viewer is not in, and skipped itself, so it tested nothing.
    // e2e-beta-tmpl is in the viewer's own team project, where the team even
    // has the operator role: the viewer role alone must block the launch.
    await openTemplate(page, 'e2e-beta-tmpl');
    const match = page.url().match(/id=(\d+)/);
    expect(match, 'the template page has a numeric id').not.toBeNull();
    await page.goto(`/job-template/launch?id=${match?.[1]}`);
    await expectForbidden(page);
  });

  // Fixtures from commands/E2eTeamScopingSeeder.php: e2e-operator's team operates
  // e2e-alpha-proj and may only view e2e-alpha-viewed-proj.
  test('operator cannot clone a template of a project their team only views', async ({ page }) => {
    // Regression: clone needed only view access and created the copy in that project.
    await openTemplate(page, 'e2e-alpha-viewed-tmpl');
    await page.getByRole('button', { name: 'Clone' }).click();
    await expectForbidden(page);

    await page.goto('/job-template/index?sort=-id');
    await expect(page.locator('#template-table tbody')).not.toContainText('e2e-alpha-viewed-tmpl (copy)');
  });

  test('operator cannot move a template into a project their team only views', async ({ page }) => {
    // Regression: saving checked only the project the template came from.
    await openTemplate(page, 'e2e-alpha-tmpl');
    await page.getByRole('link', { name: 'Edit' }).first().click();
    await page.locator('#jobtemplate-project_id').selectOption({ label: 'e2e-alpha-viewed-proj' });
    await page.getByRole('button', { name: 'Save Changes' }).click();
    await expectForbidden(page);

    await openTemplate(page, 'e2e-alpha-tmpl');
    await expect(page.locator('body')).toContainText('e2e-alpha-proj');
    await expect(page.locator('body')).not.toContainText('e2e-alpha-viewed-proj');
  });

  // Fixtures from commands/E2eVaultAssignmentSeeder.php and E2eInventoryProjectSeeder.php (open projects)
  test('viewer sees the warnings of older templates', async ({ page }) => {
    for (const [name, code] of [['e2e-two-vaults-legacy', 'multiple_vault_credentials'], ['e2e-xproj-legacy', 'inventory_other_project']]) {
      await page.goto('/job-template/index?sort=-id');
      await page.locator('#template-table tbody tr', { hasText: name }).first()
        .locator('a', { hasText: new RegExp(`^${name}$`) }).click();
      await expect(page.locator(`[data-testid="template-warning"][data-code="${code}"]`)).toBeVisible();
    }
  });
});
