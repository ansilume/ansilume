import { test, expect, Page } from '@playwright/test';
import { templateId } from '../../lib/helpers';
import { optionLabels } from '../../lib/scoping';
import { flash, ruleLabel } from '../../lib/approvals';
import {
  HOST_CONFLICT_INVENTORY,
  HOST_CONFLICT_TEMPLATE,
  HOST_INVENTORY,
  HOST_IN_USE_TEMPLATE,
  HOST_OTHER_RULE,
  HOST_RULE,
  HOST_SOLO_INVENTORY,
  HOST_SOLO_RULE,
  HOST_SOLO_TEMPLATE,
  HOST_TEMPLATE,
} from '../../lib/inventories';
import {
  LAUNCH_NOTE,
  LAUNCH_NOTE_INVENTORY,
  LOCKED_INPUTS,
  gatedInventoryOption,
  inventoryRuleHint,
  launchApprovalNote,
  launchRefusal,
  lockedNotice,
  rulesDiffer,
  runsWhat,
  setApprovalRule,
} from '../../lib/job-templates';

/**
 * A job template on an inventory with an approval rule waits for that rule
 * (services/JobTemplateGate.php), as an admin sees and changes it: the
 * inventory select marks gated inventories, the template page names the
 * inventory's rule, a launch waits for one rule only (a rule of its own
 * that differs from its inventory's is refused), and the launch page says
 * what the job will wait for. Only admins hold approval-rule.update; the
 * operator's and the viewer's side is in rbac.spec.ts. Fixtures:
 * commands/E2eInventoryGateSeeder.php (lib/inventories.ts).
 */

/** The validation error of the rule select of the open template form. */
function ruleError(page: Page) {
  return page.locator('.field-jobtemplate-approval_rule_id .help-block');
}

/** The hint below the rule select of the open template form: the rule of the inventory the form names. */
function ruleHint(page: Page) {
  return page.locator('#job-template-inventory-rule-hint');
}

/**
 * Clicks Save Changes on the open template form: the status of the answer
 * to its POST (a redirect after a save, 200 when the form shows again with
 * its errors).
 */
async function saveTemplateForm(page: Page): Promise<number> {
  const [answer] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/job-template/update')),
    page.getByRole('button', { name: 'Save Changes' }).click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
  return answer.status();
}

test.describe('Job Templates on gated inventories', () => {
  test('the inventory select marks the inventories with an approval rule, and lists them all for admins', async ({ page }) => {
    await page.goto('/job-template/create');
    const labels = await optionLabels(page, '#jobtemplate-inventory_id');
    expect(labels).toEqual(expect.arrayContaining([
      gatedInventoryOption(HOST_INVENTORY, HOST_RULE),
      gatedInventoryOption(HOST_SOLO_INVENTORY, HOST_SOLO_RULE),
      HOST_CONFLICT_INVENTORY,
    ]));
    expect(labels, 'a gated inventory is listed once, with its mark').not.toContain(HOST_INVENTORY);
    // Admins may give a template any inventory: nothing is left out.
    await expect(page.locator('#job-template-gated-inventories-hint')).toHaveCount(0);
    // A new template names no inventory yet.
    await expect(ruleHint(page)).toHaveCount(0);
  });

  test('the page of a template gated through its inventory names the inventory\'s rule, and its form says to leave its own rule empty', async ({ page }) => {
    const id = await templateId(page, HOST_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    await expect(page.locator('#job-template-approval-rule')).toHaveText('None');
    const inventoryRule = page.locator('#job-template-inventory-rule');
    await expect(inventoryRule).toHaveText(ruleLabel(HOST_RULE));
    await expect(inventoryRule.locator('a')).toHaveAttribute('href', /\/approval-rule\/view\?id=\d+$/);
    await expect(page.locator('#page-content dt', { hasText: /^Inventory's approval rule$/ })).toHaveCount(1);
    await expect(lockedNotice(page)).toHaveCount(0);

    // A template with a rule of its own and an inventory without one has no such row.
    await page.goto(`/job-template/view?id=${await templateId(page, HOST_CONFLICT_TEMPLATE)}`);
    await expect(page.locator('#job-template-approval-rule')).toHaveText(ruleLabel(HOST_OTHER_RULE));
    await expect(page.locator('#job-template-inventory-rule')).toHaveCount(0);

    await page.goto(`/job-template/update?id=${id}`);
    await expect(lockedNotice(page)).toHaveCount(0);
    await expect(page.locator('#jobtemplate-inventory_id option:checked')).toHaveText(gatedInventoryOption(HOST_INVENTORY, HOST_RULE));
    await expect(page.locator('#jobtemplate-approval_rule_id')).toHaveValue('');
    await expect(ruleHint(page)).toHaveText(inventoryRuleHint(HOST_INVENTORY, HOST_RULE));
  });

  test('a rule of its own that differs from its inventory\'s is refused, the same rule is kept, and both rows show', async ({ page }) => {
    const id = await templateId(page, HOST_TEMPLATE);
    await page.goto(`/job-template/update?id=${id}`);
    await page.locator('#jobtemplate-approval_rule_id').selectOption({ label: ruleLabel(HOST_OTHER_RULE) });
    expect(await saveTemplateForm(page), 'the form shows again').toBe(200);
    await expect(ruleError(page)).toHaveText(rulesDiffer(HOST_INVENTORY, HOST_RULE));
    await expect(ruleHint(page)).toHaveText(inventoryRuleHint(HOST_INVENTORY, HOST_RULE));
    await page.goto(`/job-template/view?id=${id}`);
    await expect(page.locator('#job-template-approval-rule')).toHaveText('None');

    try {
      // One rule per launch: the inventory's own rule is no second one.
      await setApprovalRule(page, id, ruleLabel(HOST_RULE));
      await expect(page.locator('#job-template-inventory-rule')).toHaveText(ruleLabel(HOST_RULE));
    } finally {
      await setApprovalRule(page, id, '');
    }
    await expect(page.locator('#job-template-inventory-rule')).toHaveText(ruleLabel(HOST_RULE));
  });

  test('a template with a rule of its own is not moved onto an inventory with another rule', async ({ page }) => {
    // e2e-conflict-template has e2e-host-other-rule as its own rule; e2e-gated-inventory has e2e-host-gate-rule.
    const id = await templateId(page, HOST_CONFLICT_TEMPLATE);
    await page.goto(`/job-template/update?id=${id}`);
    await expect(ruleHint(page)).toHaveCount(0);
    await page.locator('#jobtemplate-inventory_id').selectOption({ label: gatedInventoryOption(HOST_INVENTORY, HOST_RULE) });
    expect(await saveTemplateForm(page), 'the form shows again').toBe(200);
    await expect(ruleError(page)).toHaveText(rulesDiffer(HOST_INVENTORY, HOST_RULE));
    // The hint follows the inventory the form names.
    await expect(ruleHint(page)).toHaveText(inventoryRuleHint(HOST_INVENTORY, HOST_RULE));

    await page.goto(`/job-template/view?id=${id}`);
    const fields = await runsWhat(page);
    expect(fields.Inventory).toBe(HOST_CONFLICT_INVENTORY);
    expect(fields['Approval rule']).toBe(ruleLabel(HOST_OTHER_RULE));
  });

  test('an admin changes what a template gated through its inventory runs', async ({ page }) => {
    const id = await templateId(page, HOST_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    const before = await runsWhat(page);
    await page.goto(`/job-template/update?id=${id}`);
    for (const selector of LOCKED_INPUTS) {
      await expect(page.locator(selector), selector).toBeEnabled();
    }
    const playbook = `e2e-host-admin-${Date.now()}.yml`;
    try {
      await page.locator('#jobtemplate-playbook').fill(playbook);
      expect(await saveTemplateForm(page), 'saved').toBe(302);
      await page.waitForURL(/\/job-template\/view\?id=\d+/);
      await expect(flash(page, 'success')).toHaveText(`Template "${HOST_TEMPLATE}" updated.`);
      expect(await runsWhat(page)).toEqual({ ...before, Playbook: playbook });
    } finally {
      await page.goto(`/job-template/update?id=${id}`);
      await page.locator('#jobtemplate-playbook').fill(before.Playbook);
      expect(await saveTemplateForm(page), 'restored').toBe(302);
    }
    await page.waitForURL(/\/job-template\/view\?id=\d+/);
    expect(await runsWhat(page)).toEqual(before);
  });

  test('the launch page says which rule the job will wait for, and for whom it could not be approved', async ({ page }) => {
    const notes: [string, string | null][] = [
      [HOST_TEMPLATE, LAUNCH_NOTE_INVENTORY(HOST_RULE, HOST_INVENTORY)],
      // e2e-operator decides on e2e-admin's launch: no refusal for the admin.
      [HOST_SOLO_TEMPLATE, LAUNCH_NOTE_INVENTORY(HOST_SOLO_RULE, HOST_SOLO_INVENTORY)],
      [HOST_CONFLICT_TEMPLATE, LAUNCH_NOTE(HOST_OTHER_RULE)],
      [HOST_IN_USE_TEMPLATE, null],
    ];
    for (const [template, note] of notes) {
      await page.goto(`/job-template/launch?id=${await templateId(page, template)}`);
      if (note === null) {
        await expect(launchApprovalNote(page), template).toHaveCount(0);
      } else {
        await expect(launchApprovalNote(page), template).toHaveText(note);
        await expect(launchApprovalNote(page)).toHaveAttribute('role', 'status');
        await expect(launchApprovalNote(page)).toHaveClass(/(^|\s)alert-info(\s|$)/);
      }
      await expect(launchRefusal(page), template).toHaveCount(0);
      await expect(page.locator('#launch-form button[type="submit"]')).toBeEnabled();
    }
  });
});
