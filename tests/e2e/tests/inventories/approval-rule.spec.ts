import { test, expect, Page } from '@playwright/test';
import { postWithPageCsrf, templateId } from '../../lib/helpers';
import { regexLiteral } from '../../lib/runs';
import { forgePost, indexRows, optionLabels } from '../../lib/scoping';
import { flash, ruleLabel } from '../../lib/approvals';
import {
  APPROVAL_RULE_NOT_FOUND,
  GATED_BADGE,
  HOST_ASSIGN_INVENTORY,
  HOST_ASSIGN_TEMPLATE,
  HOST_CONFLICT_INVENTORY,
  HOST_CONFLICT_TEMPLATE,
  HOST_HIDDEN_PROJECT,
  HOST_HIDDEN_TEMPLATE,
  HOST_INVENTORY,
  HOST_OTHER_RULE,
  HOST_PROJECT,
  HOST_RULE,
  HOST_SOLO_RULE,
  HOST_TEMPLATE,
  INVENTORY_NAME_COLUMN,
  INVENTORY_RULE_FORM_HINT,
  LOCKED_INVENTORY_INPUTS,
  OPEN_INVENTORY_INPUTS,
  REPOSITORY_HOSTS_NOTE,
  SOURCE_PATH_REQUIRED_WHEN_GATED,
  deleteOpenInventory,
  gatedBadge,
  gatedTitle,
  inventoryCreated,
  inventoryDeleted,
  inventoryDetails,
  inventoryId,
  inventoryListRow,
  inventoryLockedNotice,
  inventoryRulesDiffer,
  inventoryTemplates,
  inventoryUpdated,
  openInventory,
} from '../../lib/inventories';

/**
 * The approval rule of an inventory, as an admin assigns it
 * (views/inventory/form.php, view.php, index.php, _job-templates.php), and
 * what an admin may change on an inventory that has one. Only admins hold
 * approval-rule.update; the operator's and the viewer's side is in
 * rbac.spec.ts. Fixtures: commands/E2eInventoryGateSeeder.php (lib/inventories.ts).
 */

/** The ID of the rule labelled `label` (ruleLabel()) in the rule select of the open inventory form. */
async function ruleOption(page: Page, label: string): Promise<string> {
  const value = await page.locator('#inventory-approval_rule_id option', { hasText: new RegExp(`^${regexLiteral(label)}$`) })
    .getAttribute('value');
  expect(value, `the rule select offers ${label}`).toMatch(/^\d+$/);
  return value ?? '';
}

/**
 * Sets the approval rule of inventory #`id` through its edit form (`label`
 * is the rule's option, ruleLabel(); '' for none) and saves it; the
 * inventory's page opens with the success flash.
 */
async function saveInventoryRule(page: Page, id: string, name: string, label: string): Promise<void> {
  await page.goto(`/inventory/update?id=${id}`);
  const select = page.locator('#inventory-approval_rule_id');
  if (label === '') {
    await select.selectOption('');
  } else {
    await select.selectOption({ label });
  }
  await clickSave(page, 'Save Changes', '/inventory/update');
  await page.waitForURL(/\/inventory\/view\?id=\d+/);
  await expect(flash(page, 'success')).toHaveText(inventoryUpdated(name));
}

/** The names in the inventory list, every page read. */
async function inventoryNames(page: Page): Promise<string[]> {
  return (await indexRows(page, '/inventory/index')).map((row) => row.cells[INVENTORY_NAME_COLUMN]);
}

/**
 * Clicks the submit button `button` of the open inventory form: the status
 * of the answer to its POST to `path` (a redirect after a save, 200 when the
 * form shows again with its errors).
 */
async function clickSave(page: Page, button: string, path: string): Promise<number> {
  const [answer] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes(path)),
    page.getByRole('button', { name: button }).click(),
  ]);
  const status = answer.status();
  expect([200, 302], `the answer to the POST to ${path}`).toContain(status);
  await page.waitForLoadState('domcontentloaded');
  return status;
}

test.describe('Inventory Approval Rule', () => {
  test('the form offers every rule with its approvals, none by default, says who may assign one and where file and dynamic hosts come from', async ({ page }) => {
    await page.goto('/inventory/create');
    await expect(page.locator('#inventory-form h5', { hasText: /^Approval$/ })).toBeVisible();
    await expect(page.locator('label[for="inventory-approval_rule_id"]')).toHaveText('Approval rule');
    const select = page.locator('#inventory-approval_rule_id');
    await expect(select).toHaveValue('');
    const labels = await optionLabels(page, '#inventory-approval_rule_id');
    expect(labels[0]).toBe('— None —');
    expect(labels).toEqual(expect.arrayContaining([ruleLabel(HOST_RULE), ruleLabel(HOST_OTHER_RULE), ruleLabel(HOST_SOLO_RULE)]));
    await expect(page.locator('.field-inventory-approval_rule_id .hint-block')).toHaveText(INVENTORY_RULE_FORM_HINT);
    await expect(page.locator('#inventory-approval-rule-readonly')).toHaveCount(0);
    await expect(inventoryLockedNotice(page)).toHaveCount(0);

    // The note on where the hosts of a file or dynamic inventory come from
    // sits with the project and the source path, which show for those types only.
    const note = page.locator('#field-project #inventory-repository-hosts-note');
    await expect(note).toHaveText(REPOSITORY_HOSTS_NOTE);
    await expect(note).toBeHidden();
    for (const type of ['file', 'dynamic']) {
      await page.locator('#inventory-type').selectOption(type);
      await expect(note, type).toBeVisible();
    }
    await page.locator('#inventory-type').selectOption('static');
    await expect(note).toBeHidden();
  });

  test('an admin gives an inventory a rule, which its page, the inventory list and its job templates show, and takes it away', async ({ page }) => {
    const id = await inventoryId(page, HOST_ASSIGN_INVENTORY);
    const template = await templateId(page, HOST_ASSIGN_TEMPLATE);
    await page.goto(`/inventory/update?id=${id}`);
    const ruleId = await ruleOption(page, ruleLabel(HOST_RULE));
    const content = await page.locator('#inventory-content').inputValue();

    try {
      await saveInventoryRule(page, id, HOST_ASSIGN_INVENTORY, ruleLabel(HOST_RULE));
      const link = page.locator('#inventory-approval-rule a');
      await expect(link).toHaveText(ruleLabel(HOST_RULE));
      await expect(link).toHaveAttribute('href', `/approval-rule/view?id=${ruleId}`);
      // Admins are not locked out of the hosts: no notice.
      await expect(inventoryLockedNotice(page)).toHaveCount(0);
      // The job templates that use it, each with its own rule (none).
      expect(await inventoryTemplates(page)).toEqual([[HOST_ASSIGN_TEMPLATE, HOST_PROJECT, '—']]);
      await expect(page.locator(`#inventory-job-templates-list tr[data-job-template-id="${template}"] a`))
        .toHaveAttribute('href', `/job-template/view?id=${template}`);
      await expect(page.locator('#inventory-job-templates-hidden')).toHaveCount(0);
      await expect(page.locator('#inventory-job-templates-empty')).toHaveCount(0);

      // The inventory list marks it in its Type cell, never in its Name cell.
      const row = await inventoryListRow(page, HOST_ASSIGN_INVENTORY);
      const badge = gatedBadge(row);
      await expect(badge).toHaveText(GATED_BADGE);
      await expect(badge).toHaveAttribute('title', gatedTitle(HOST_RULE));
      await expect(badge).toHaveClass('badge text-bg-warning ms-1');
      await expect(row.locator('td').nth(INVENTORY_NAME_COLUMN).locator('[data-inventory-gated-badge]')).toHaveCount(0);

      // Its job template is gated now, by the inventory's rule: it has none of its own.
      await page.goto(`/job-template/view?id=${template}`);
      await expect(page.locator('#job-template-approval-rule')).toHaveText('None');
      await expect(page.locator('#job-template-inventory-rule')).toHaveText(ruleLabel(HOST_RULE));
    } finally {
      await saveInventoryRule(page, id, HOST_ASSIGN_INVENTORY, '');
      // The stored content back, byte for byte: the form posted it with CRLF line breaks.
      const restored = await postWithPageCsrf(page, `/inventory/update?id=${id}`, { 'Inventory[content]': content });
      expect(restored.status(), 'the content is restored').toBe(302);
    }
    await expect(page.locator('#inventory-approval-rule')).toHaveText('None');
    await expect(gatedBadge(await inventoryListRow(page, HOST_ASSIGN_INVENTORY))).toHaveCount(0);
    await page.goto(`/job-template/view?id=${template}`);
    await expect(page.locator('#job-template-inventory-rule')).toHaveCount(0);
  });

  test('an inventory gets no rule while a job template on it has another rule of its own, and nothing is saved', async ({ page }) => {
    // e2e-conflict-template, on e2e-conflict-inventory, has e2e-host-other-rule as its own rule.
    const id = await inventoryId(page, HOST_CONFLICT_INVENTORY);
    await page.goto(`/inventory/update?id=${id}`);
    await page.locator('#inventory-approval_rule_id').selectOption({ label: ruleLabel(HOST_RULE) });
    expect(await clickSave(page, 'Save Changes', '/inventory/update'), 'the form shows again').toBe(200);
    await expect(page.locator('.field-inventory-approval_rule_id .help-block')).toHaveText(inventoryRulesDiffer([HOST_CONFLICT_TEMPLATE]));
    await expect(page).toHaveURL(new RegExp(`/inventory/update\\?id=${id}$`));

    // A rule that does not exist is an error of the same field.
    await page.goto(`/inventory/view?id=${id}`);
    await forgePost(page, `/inventory/update?id=${id}`, {
      'Inventory[name]': HOST_CONFLICT_INVENTORY,
      'Inventory[approval_rule_id]': '999999999',
    }, /\/inventory\/update/);
    await expect(page.locator('.field-inventory-approval_rule_id .help-block')).toHaveText(APPROVAL_RULE_NOT_FOUND);

    await page.goto(`/inventory/view?id=${id}`);
    await expect(page.locator('#inventory-approval-rule')).toHaveText('None');
    expect(await inventoryTemplates(page)).toEqual([[HOST_CONFLICT_TEMPLATE, HOST_PROJECT, HOST_OTHER_RULE]]);
  });

  test('a dynamic inventory with a rule needs a source path; with one, its page says where its hosts come from', async ({ page }) => {
    const name = `e2e-host-dynamic-${Date.now()}`;
    await page.goto('/inventory/create');
    await page.locator('#inventory-name').fill(name);
    await page.locator('#inventory-type').selectOption('dynamic');
    await page.locator('#inventory-project_id').selectOption({ label: HOST_PROJECT });
    await page.locator('#inventory-approval_rule_id').selectOption({ label: ruleLabel(HOST_RULE) });
    const ruleId = await ruleOption(page, ruleLabel(HOST_RULE));
    const project = await page.locator('#inventory-project_id').inputValue();

    // The browser refuses it before it is sent ...
    let posted = false;
    const watchPosts = (request: { method(): string; url(): string }) => {
      posted = posted || (request.method() === 'POST' && request.url().includes('/inventory/create'));
    };
    page.on('request', watchPosts);
    await page.getByRole('button', { name: 'Create Inventory' }).click();
    const pathError = page.locator('.field-inventory-source_path .help-block');
    await expect(pathError).toHaveText(SOURCE_PATH_REQUIRED_WHEN_GATED);
    page.off('request', watchPosts);
    expect(posted, 'the form was not sent').toBe(false);
    await expect(page).toHaveURL(/\/inventory\/create$/);

    // ... and so does the server, for a request the browser did not check.
    await page.goto('/inventory/index');
    await forgePost(page, '/inventory/create', {
      'Inventory[name]': name,
      'Inventory[inventory_type]': 'dynamic',
      'Inventory[project_id]': project,
      'Inventory[source_path]': '',
      'Inventory[approval_rule_id]': ruleId,
    }, /\/inventory\/create/);
    await expect(pathError).toHaveText(SOURCE_PATH_REQUIRED_WHEN_GATED);
    expect(await inventoryNames(page), `${name} was not created`).not.toContain(name);

    await page.goto('/inventory/create');
    await page.locator('#inventory-name').fill(name);
    await page.locator('#inventory-type').selectOption('dynamic');
    await page.locator('#inventory-project_id').selectOption({ label: HOST_PROJECT });
    await page.locator('#inventory-source_path').fill('inventory/hosts.py');
    await page.locator('#inventory-approval_rule_id').selectOption({ label: ruleLabel(HOST_RULE) });
    expect(await clickSave(page, 'Create Inventory', '/inventory/create'), 'created').toBe(302);
    await page.waitForURL(/\/inventory\/view\?id=\d+/);
    try {
      await expect(flash(page, 'success')).toHaveText(inventoryCreated(name));
      await expect(page.locator('#inventory-approval-rule a')).toHaveText(ruleLabel(HOST_RULE));
      // A file or dynamic inventory takes its hosts from the repository when the job runs.
      await expect(page.locator('#inventory-repository-hosts-note')).toHaveText(REPOSITORY_HOSTS_NOTE);
      await expect(page.locator('#inventory-job-templates-empty')).toBeVisible();
    } finally {
      // An admin deletes a gated inventory that nothing uses.
      const deleted = await deleteOpenInventory(page);
      expect(deleted.status, 'the delete is answered with a redirect').toBe(302);
    }
    await expect(page).toHaveURL(/\/inventory\/index/);
    await expect(flash(page, 'success')).toHaveText(inventoryDeleted(name));
  });

  test('an admin changes the hosts of a gated inventory through its form, which is not locked', async ({ page }) => {
    const id = await openInventory(page, HOST_INVENTORY);
    const before = await inventoryDetails(page);
    // Admins get Edit and Delete, and no notice.
    await expect(page.locator(`#page-content a[href="/inventory/update?id=${id}"]`)).toHaveText('Edit');
    await expect(page.locator('#page-content form[action*="/inventory/delete"] button')).toHaveText('Delete');
    await expect(inventoryLockedNotice(page)).toHaveCount(0);
    // e2e-admin sees every job template on it, the hidden one too.
    expect(await inventoryTemplates(page)).toEqual([[HOST_TEMPLATE, HOST_PROJECT, '—'], [HOST_HIDDEN_TEMPLATE, HOST_HIDDEN_PROJECT, '—']]);
    await expect(page.locator('#inventory-job-templates-hidden')).toHaveCount(0);

    await page.goto(`/inventory/update?id=${id}`);
    await expect(inventoryLockedNotice(page)).toHaveCount(0);
    await expect(page.locator('#inventory-host-fields')).not.toHaveAttribute('disabled', /.*/);
    for (const selector of [...OPEN_INVENTORY_INPUTS, ...LOCKED_INVENTORY_INPUTS]) {
      await expect(page.locator(selector), selector).toBeEnabled();
    }
    await expect(page.locator('.yaml-editor .CodeMirror').first()).toBeVisible();
    await expect(page.locator('#inventory-approval_rule_id option:checked')).toHaveText(ruleLabel(HOST_RULE));

    const content = await page.locator('#inventory-content').inputValue();
    const added = 'e2e-host-gate-admin-host';
    try {
      // The YAML editor syncs the textarea on its own edits only: set what the form posts.
      await page.locator('#inventory-content').evaluate((el, value) => {
        (el as HTMLTextAreaElement).value = value;
      }, `${content}    ${added}:\n      ansible_host: 192.0.2.46\n`);
      expect(await clickSave(page, 'Save Changes', '/inventory/update'), 'saved').toBe(302);
      await page.waitForURL(/\/inventory\/view\?id=\d+/);
      await expect(flash(page, 'success')).toHaveText(inventoryUpdated(HOST_INVENTORY));
      const after = await inventoryDetails(page);
      expect(after.content).toContain(added);
      expect({ ...after, content: '' }).toEqual({ ...before, content: '' });
    } finally {
      // The stored content back, byte for byte: a browser form would post it with CRLF line breaks.
      const restored = await postWithPageCsrf(page, `/inventory/update?id=${id}`, { 'Inventory[content]': content });
      expect(restored.status(), 'the content is restored').toBe(302);
    }
    await page.goto(`/inventory/view?id=${id}`);
    expect(await inventoryDetails(page)).toEqual(before);
  });
});
