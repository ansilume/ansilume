import { test, expect, Page } from '@playwright/test';
import { expectForbidden, fieldError, notAnInteger, userId } from '../../lib/helpers';
import { BTN_CREATE } from '../../lib/selectors';
import { asAdmin, forgePost, idWhere, indexRows, optionValue, rowsWhere } from '../../lib/scoping';
import { flash, ruleLabel } from '../../lib/approvals';
import {
  ASSIGN_REFUSED,
  GATED_BADGE,
  HOST_INVENTORY,
  HOST_OTHER_RULE,
  HOST_PROJECT,
  HOST_RENAMED_INVENTORY,
  HOST_RULE,
  HOST_TEMPLATE,
  INVENTORY_CHANGE_REFUSED,
  INVENTORY_LOCK_NOTICE,
  INVENTORY_NAME_COLUMN,
  cardHidden,
  expectLockedInventoryForm,
  gatedBadge,
  gatedTitle,
  inventoryDetails,
  inventoryListRow,
  inventoryLockedNotice,
  inventoryTemplates,
  inventoryUpdated,
  openInventory,
  postedInventoryFields,
} from '../../lib/inventories';

/** The ID of the approval rule `name`, from the rule list (column 0: Name); every role holds approval-rule.view. */
async function approvalRuleId(page: Page, name: string): Promise<string> {
  return idWhere(await indexRows(page, '/approval-rule/index'), 0, name);
}

/**
 * Renames the inventory whose edit form is open to `name` through the form,
 * as it is, and saves it; the inventory's page opens with the success flash.
 */
async function saveInventoryName(page: Page, name: string): Promise<void> {
  await page.locator('#inventory-name').fill(name);
  const [answer] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/inventory/update')),
    page.getByRole('button', { name: 'Save Changes' }).click(),
  ]);
  expect(answer.status(), `the save of the name ${name} is answered with a redirect`).toBe(302);
  await page.waitForURL(/\/inventory\/view\?id=\d+/);
  await expect(flash(page, 'success')).toHaveText(inventoryUpdated(name));
}

test.describe('Inventories RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer cannot see create button', async ({ page }) => {
    await page.goto('/inventory/index');
    await expect(page.locator(BTN_CREATE)).not.toBeVisible();
  });

  test('viewer parsing the vault inventory never sees decrypted values', async ({ page }) => {
    await page.goto('/inventory/index');
    const link = page.locator('table.table tbody tr a', { hasText: /^e2e-vault-inventory$/ }).first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'e2e-vault-inventory is not seeded');
      return;
    }
    await link.click();
    await Promise.all([
      page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/inventory/parse-hosts')),
      page.locator('#btn-parse-inventory').click(),
    ]);
    await expect(page.locator('#inventory-result').getByTestId('inventory-notice').first()).toBeVisible();
    await expect(page.locator('body')).not.toContainText('E2E-VAULT-PLAINTEXT-MARKER');
  });

  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/inventory/create');
    await expectForbidden(page);
  });

  // Fixtures from commands/E2eTeamScopingSeeder.php: e2e-operator's team operates
  // e2e-alpha-proj and may only view e2e-alpha-viewed-proj.
  test('operator cannot move an inventory into a project their team only views', async ({ page }) => {
    // Regression: saving checked only the project the inventory came from.
    await openInventory(page, 'e2e-alpha-inv');
    await page.getByRole('link', { name: 'Edit' }).first().click();
    // The project field shows for file and dynamic inventories.
    await page.locator('#inventory-type').selectOption('dynamic');
    await page.locator('#inventory-project_id').selectOption({ label: 'e2e-alpha-viewed-proj' });
    await page.locator('#inventory-source_path').fill('inventory.yml');
    await page.getByRole('button', { name: 'Save Changes' }).click();
    await expectForbidden(page);

    await openInventory(page, 'e2e-alpha-inv');
    await expect(page.locator('body')).toContainText('e2e-alpha-proj');
    await expect(page.locator('body')).not.toContainText('e2e-alpha-viewed-proj');
  });

  // Regression: the access check read a forged project that is no project
  // ID, such as a fraction of the ID of a project the operator's team only
  // views, as that project: a 403. Its error comes first now, before the
  // access check (TeamScopingTrait::refusesSubmittedProject()), and nothing
  // is created.
  test('operator gets the form error, not a 403, for a project that is no project ID, and no inventory is created', async ({ page }) => {
    const name = 'e2e-fraction-project-inventory';
    await page.goto('/inventory/create');
    const viewed = await optionValue(page, '#inventory-project_id', 'e2e-alpha-viewed-proj');
    await page.goto('/inventory/index');

    await forgePost(page, '/inventory/create', {
      'Inventory[name]': name,
      'Inventory[inventory_type]': 'dynamic',
      'Inventory[source_path]': 'inventory.yml',
      'Inventory[project_id]': `${viewed}.5`,
    }, /\/inventory\/create$/);

    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
    await expect(fieldError(page, 'inventory', 'project_id')).toHaveText(notAnInteger('Project Id'));
    expect(rowsWhere(await indexRows(page, '/inventory/index'), INVENTORY_NAME_COLUMN, name), 'no inventory was created').toHaveLength(0);
  });

  test('operator can access index', async ({ page }) => {
    await page.goto('/inventory/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  // Only admins (approval-rule.update) change the hosts of an inventory with
  // an approval rule, delete it or assign its rule (services/GatedInventoryLock.php).
  // commands/E2eInventoryGateSeeder.php: e2e-gated-inventory, static, without a
  // project, gated by e2e-host-gate-rule. Its job templates: e2e-host-gated-template
  // in e2e-host-gate-project (no team: every role sees it), and
  // e2e-host-hidden-template in a project only a team without members has.
  test('operator sees a gated inventory\'s notice and rule, no Delete, and the job templates they may see', async ({ page }) => {
    const ruleId = await approvalRuleId(page, HOST_RULE);
    const id = await openInventory(page, HOST_INVENTORY);
    await expect(inventoryLockedNotice(page)).toHaveText(INVENTORY_LOCK_NOTICE);
    await expect(page.locator(`#page-content a[href="/inventory/update?id=${id}"]`)).toHaveText('Edit');
    // Operators hold no inventory.delete, and the lock keeps them from deleting it anyway.
    await expect(page.locator('#page-content form[action*="/inventory/delete"]')).toHaveCount(0);

    const rule = page.locator('#inventory-approval-rule a');
    await expect(rule).toHaveText(ruleLabel(HOST_RULE));
    await expect(rule).toHaveAttribute('href', `/approval-rule/view?id=${ruleId}`);

    // The card lists the job templates of projects the operator may see, and counts the others.
    expect(await inventoryTemplates(page)).toEqual([[HOST_TEMPLATE, HOST_PROJECT, '—']]);
    await expect(page.locator('#inventory-job-templates-hidden')).toHaveText(cardHidden(1));
    // A static inventory takes its hosts from its content, not from a repository.
    await expect(page.locator('#inventory-repository-hosts-note')).toHaveCount(0);
  });

  test('operator renames a gated inventory through its locked form and back, which posts only its name and description', async ({ page }) => {
    const id = await openInventory(page, HOST_INVENTORY);
    const before = await inventoryDetails(page);
    expect(before.content, 'the seeded hosts').toContain('192.0.2.40');

    await page.goto(`/inventory/update?id=${id}`);
    await expectLockedInventoryForm(page);
    // The rule as text: only admins get the select.
    await expect(page.locator('#inventory-approval_rule_id')).toHaveCount(0);
    const readOnly = page.locator('#inventory-approval-rule-readonly');
    await expect(readOnly.locator('.form-control-plaintext')).toHaveText(ruleLabel(HOST_RULE));
    await expect(readOnly.locator('.form-text')).toHaveText(ASSIGN_REFUSED);
    // Disabled fields are not posted: not even the content, which a browser
    // would send with CRLF line breaks, a change of the stored hosts.
    expect(await postedInventoryFields(page)).toEqual(['Inventory[name]', 'Inventory[description]']);

    try {
      await saveInventoryName(page, HOST_RENAMED_INVENTORY);
      await expect(page.locator('#page-content h2').first()).toHaveText(HOST_RENAMED_INVENTORY);
      expect(await inventoryDetails(page)).toEqual(before);
    } finally {
      await page.goto(`/inventory/update?id=${id}`);
      await saveInventoryName(page, HOST_INVENTORY);
    }
    await expect(page.locator('#page-content h2').first()).toHaveText(HOST_INVENTORY);
    expect(await inventoryDetails(page)).toEqual(before);
  });

  // Only admins change the hosts and the rule of a gated inventory; InventoryWritePathsTest holds every write path to it in PHPUnit.
  test('operator gets 403 on CSRF-valid changes of a gated inventory\'s hosts or rule, and nothing changes', async ({ page }) => {
    const otherRule = await approvalRuleId(page, HOST_OTHER_RULE);
    const id = await openInventory(page, HOST_INVENTORY);
    const before = await inventoryDetails(page);
    // A project the operator may change inventories in: e2e-host-gate-project has no team.
    await page.goto(`/inventory/update?id=${id}`);
    const project = await page.locator('#inventory-project_id option', { hasText: new RegExp(`^${HOST_PROJECT}$`) }).getAttribute('value');
    expect(project, `${HOST_PROJECT} is offered`).toBeTruthy();

    const changes: [Record<string, string>, string][] = [
      [{ 'Inventory[content]': `${before.content}    e2e-forged-host:\n      ansible_host: 192.0.2.99\n` }, INVENTORY_CHANGE_REFUSED],
      [{ 'Inventory[inventory_type]': 'file', 'Inventory[source_path]': 'forged.yml' }, INVENTORY_CHANGE_REFUSED],
      [{ 'Inventory[source_path]': 'forged.yml' }, INVENTORY_CHANGE_REFUSED],
      [{ 'Inventory[project_id]': project ?? '' }, INVENTORY_CHANGE_REFUSED],
      [{ 'Inventory[approval_rule_id]': '' }, ASSIGN_REFUSED],
      [{ 'Inventory[approval_rule_id]': otherRule }, ASSIGN_REFUSED],
    ];
    for (const [change, refusal] of changes) {
      // Real form posts with a valid CSRF token: only the server's checks may stop them.
      await page.goto(`/inventory/view?id=${id}`);
      await forgePost(page, `/inventory/update?id=${id}`, {
        'Inventory[name]': HOST_INVENTORY,
        ...change,
      }, /\/inventory\/update/);
      await expectForbidden(page);
      await expect(page.locator('#page-content'), JSON.stringify(change)).toContainText(refusal);
    }

    await page.goto(`/inventory/view?id=${id}`);
    expect(await inventoryDetails(page)).toEqual(before);
  });

  test('operator cannot change who created a gated inventory: a forged created_by is ignored', async ({ page, browser }, testInfo) => {
    // The browser twin of InventoryControllerGateTest::testRegressionTheEditFormMassAssignedCreatedBy:
    // the operator names themselves the creator.
    const operatorId = await asAdmin(browser, testInfo, async (admin) => userId(admin, 'e2e-operator'));
    const id = await openInventory(page, HOST_INVENTORY);
    const before = await inventoryDetails(page);
    expect(before['Created by']).toBe('e2e-admin');

    await page.goto('/inventory/index');
    // Not a change of its hosts: the save goes through, without the creator.
    await forgePost(page, `/inventory/update?id=${id}`, {
      'Inventory[name]': HOST_INVENTORY,
      'Inventory[created_by]': operatorId,
    }, /\/inventory\/view\?id=\d+/);
    await expect(flash(page, 'success')).toHaveText(inventoryUpdated(HOST_INVENTORY));
    expect(await inventoryDetails(page)).toEqual(before);
  });

  test('operator gets 403 on a CSRF-valid delete of a gated inventory, which stays', async ({ page }) => {
    const id = await openInventory(page, HOST_INVENTORY);
    await expect(page.locator('#page-content form[action*="/inventory/delete"]')).toHaveCount(0);

    await forgePost(page, `/inventory/delete?id=${id}`, {}, /\/inventory\/delete/);
    await expectForbidden(page);

    await page.goto(`/inventory/view?id=${id}`);
    await expect(page.locator('#page-content h2').first()).toHaveText(HOST_INVENTORY);
  });

  test('viewer sees a gated inventory\'s rule and badge, without the notice, Edit or Delete, and may not open its form', async ({ page }) => {
    const row = await inventoryListRow(page, HOST_INVENTORY);
    const badge = gatedBadge(row);
    await expect(badge).toHaveText(GATED_BADGE);
    await expect(badge).toHaveAttribute('title', gatedTitle(HOST_RULE));
    // Viewers hold no inventory.update: no Edit in the list.
    await expect(row.locator('a', { hasText: /^Edit$/ })).toHaveCount(0);

    const id = await openInventory(page, HOST_INVENTORY);
    await expect(page.locator('#inventory-approval-rule a')).toHaveText(ruleLabel(HOST_RULE));
    // The notice says what is left to change: for a viewer, nothing is.
    await expect(inventoryLockedNotice(page)).toHaveCount(0);
    await expect(page.locator('#page-content a[href*="/inventory/update"]')).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/inventory/delete"]')).toHaveCount(0);
    expect(await inventoryTemplates(page)).toEqual([[HOST_TEMPLATE, HOST_PROJECT, '—']]);
    await expect(page.locator('#inventory-job-templates-hidden')).toHaveText(cardHidden(1));

    await page.goto(`/inventory/update?id=${id}`);
    await expectForbidden(page);
  });
});
