import { test, expect, Page } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { forgePost, idWhere, indexRows } from '../../lib/scoping';
import { APPROVAL_RULE, GATED_TEMPLATE } from '../../lib/approvals';
import { HOST_INVENTORY, HOST_RULE } from '../../lib/inventories';

/**
 * Approval rules are managed by admins: creating, changing and deleting a
 * rule needs approval-rule.create, .update or .delete, which operators no
 * longer hold (m000082). Operators and viewers still see the rules and what
 * uses them. Fixture: e2e-approval-rule (commands/E2eApprovalSeeder.php).
 */

/** The ID of e2e-approval-rule, from the rule list (column 0: Name). */
async function ruleId(page: Page): Promise<string> {
  return idWhere(await indexRows(page, '/approval-rule/index'), 0, APPROVAL_RULE);
}

test.describe('Approval Rules RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/approval-rule/create');
    await expectForbidden(page);
  });

  test('viewer gets 403 on update', async ({ page }) => {
    const id = await ruleId(page);
    await page.goto(`/approval-rule/update?id=${id}`);
    await expectForbidden(page);
  });

  test('viewer can view index', async ({ page }) => {
    await page.goto('/approval-rule/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
    await expect(page.locator('#page-content a', { hasText: 'New Approval Rule' })).toHaveCount(0);
  });

  // Regression: an operator could change any rule's approvers.
  test('operator gets 403 on approval rule create and update', async ({ page }) => {
    await page.goto('/approval-rule/create');
    await expectForbidden(page);

    const id = await ruleId(page);
    await page.goto(`/approval-rule/update?id=${id}`);
    await expectForbidden(page);
  });

  test('operator sees a rule and what uses it, without New, Edit or Delete', async ({ page }) => {
    await page.goto('/approval-rule/index');
    await expect(page.locator('#page-content a', { hasText: 'New Approval Rule' })).toHaveCount(0);

    await page.goto(`/approval-rule/view?id=${await ruleId(page)}`);
    await expect(page.locator('#approval-rule-usage-templates')).toContainText(GATED_TEMPLATE);
    for (const control of ['#approval-rule-edit', '#approval-rule-delete-form', '#approval-rule-delete-blocked', '#approval-rule-delete-blocked-reason']) {
      await expect(page.locator(control), control).toHaveCount(0);
    }
  });

  test('operator gets 403 on CSRF-valid posts that change or delete a rule, and the rule stays as it was', async ({ page }) => {
    const id = await ruleId(page);
    // Real form posts with a valid CSRF token: only the permission check may stop them.
    await page.goto(`/approval-rule/view?id=${id}`);
    await forgePost(page, `/approval-rule/update?id=${id}`, {
      'ApprovalRule[name]': APPROVAL_RULE,
      'ApprovalRule[approver_type]': 'role',
      'ApprovalRule[approver_config]': '{"role":"operator"}',
      'ApprovalRule[required_approvals]': '1',
    }, /\/approval-rule\/update/);
    await expectForbidden(page);

    await page.goto(`/approval-rule/view?id=${id}`);
    await forgePost(page, `/approval-rule/delete?id=${id}`, {}, /\/approval-rule\/delete/);
    await expectForbidden(page);

    await page.goto(`/approval-rule/view?id=${id}`);
    await expect(page.locator('h2').first()).toHaveText(APPROVAL_RULE);
    await expect(page.locator('#page-content table').first()).toContainText('Users: e2e-admin, e2e-operator');
  });

  // commands/E2eInventoryGateSeeder.php: e2e-host-gate-rule gates
  // e2e-gated-inventory, which has no project, so every role sees it; only
  // admins change or delete the rule.
  for (const role of ['viewer', 'operator']) {
    test(`${role} sees the inventory a rule gates under Used by, without Edit or Delete`, async ({ page }) => {
      const id = idWhere(await indexRows(page, '/approval-rule/index'), 0, HOST_RULE);
      await page.goto(`/approval-rule/view?id=${id}`);
      const inventory = page.locator('#approval-rule-usage-inventories li')
        .filter({ has: page.getByRole('link', { name: HOST_INVENTORY, exact: true }) });
      await expect(inventory).toHaveCount(1);
      await expect(inventory.getByRole('link')).toHaveAttribute('href', /\/inventory\/view\?id=\d+$/);
      // Nothing is hidden from them: the inventory has no project.
      await expect(page.locator('#approval-rule-usage-inventories li.text-muted')).toHaveCount(0);
      for (const control of ['#approval-rule-edit', '#approval-rule-delete-form', '#approval-rule-delete-blocked', '#approval-rule-delete-blocked-reason']) {
        await expect(page.locator(control), control).toHaveCount(0);
      }

      // The link opens the inventory, which they may view.
      await inventory.getByRole('link').click();
      await expect(page).toHaveURL(/\/inventory\/view\?id=\d+$/);
      await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
      await expect(page.locator('#inventory-approval-rule')).toContainText(HOST_RULE);
    });
  }
});
