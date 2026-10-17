import { test, expect, Page } from '@playwright/test';
import { expectForbidden, templateId } from '../../lib/helpers';
import { asAdmin, forgePost, idWhere, indexRows, optionLabels } from '../../lib/scoping';
import { PLACEHOLDER, expectNotInPage } from '../../lib/survey-secrets';
import { generateTriggerToken, revokeTriggerTokenIfPresent } from '../../lib/triggers';
import { jobIdsOfTemplate } from '../../lib/schedules';
import { APPROVAL_RULE, GATED_TEMPLATE, SOLO_RULE, flash, ruleLabel } from '../../lib/approvals';
import {
  ASSIGN_REFUSED,
  HOST_ASSIGN_INVENTORY,
  HOST_ASSIGN_TEMPLATE,
  HOST_CONFLICT_INVENTORY,
  HOST_INVENTORY,
  HOST_PROJECT,
  HOST_RENAMED_TEMPLATE,
  HOST_RULE,
  HOST_SOLO_INVENTORY,
  HOST_SOLO_RULE,
  HOST_SOLO_TEMPLATE,
  HOST_TEMPLATE,
  inventoryId,
} from '../../lib/inventories';
import {
  GATED_CHANGE_REFUSED,
  GATED_INVENTORIES_HINT,
  GATED_INVENTORY_CHANGE_REFUSED,
  GATED_INVENTORY_LOCK_NOTICE,
  GATED_INVENTORY_REFUSED,
  GATED_LOCK_NOTICE,
  LAUNCH_NOTE_INVENTORY,
  LOCKED_INPUTS,
  LOCKED_PLAYBOOK,
  LOCKED_RENAMED,
  LOCKED_TEMPLATE,
  approverRefusal,
  deleteOpenTemplate,
  expectLockedForm,
  gatedInventoryOption,
  launchApprovalNote,
  launchFailed,
  launchRefusal,
  lockedNotice,
  runsWhat,
  surveyOf,
} from '../../lib/job-templates';

/** The ID of the approval rule `name`, from the rule list (column 0: Name). */
async function approvalRuleId(page: Page, name: string): Promise<string> {
  return idWhere(await indexRows(page, '/approval-rule/index'), 0, name);
}

/** The ID of the open template page. */
function openTemplateId(page: Page): string {
  const id = new URL(page.url()).searchParams.get('id') ?? '';
  expect(id, 'a template page').toMatch(/^\d+$/);
  return id;
}

/**
 * Sets the name and the description of the template whose edit form is
 * open, and saves the form; the template page opens.
 */
async function saveNameAndDescription(page: Page, name: string, description: string) {
  await page.locator('#jobtemplate-name').fill(name);
  await page.locator('#jobtemplate-description').fill(description);
  await Promise.all([
    page.waitForURL(/\/job-template\/view\?id=\d+/),
    page.getByRole('button', { name: 'Save Changes' }).click(),
  ]);
}

/**
 * What the open edit form would post, without the CSRF token: the values of
 * its enabled fields (FormData leaves out disabled ones).
 */
async function formFields(page: Page): Promise<Record<string, string>> {
  return page.locator('#jt-form').evaluate((form) => {
    const csrfParam = document.querySelector('meta[name="csrf-param"]')?.getAttribute('content') || '_csrf';
    const values: Record<string, string> = {};
    new FormData(form as HTMLFormElement).forEach((value, key) => {
      if (typeof value === 'string' && key !== csrfParam) {
        values[key] = value;
      }
    });
    return values;
  });
}

/** The names of the job templates the job list's template filter offers: every template the user may see. */
async function templateNames(page: Page): Promise<string[]> {
  await page.goto('/job/index');
  return (await page.locator('select[name="template_id"] option').allTextContents()).map((label) => label.trim());
}

/** The labels the inventory select of the open template form offers, without its prompt. */
async function inventoryOptions(page: Page): Promise<string[]> {
  return (await optionLabels(page, '#jobtemplate-inventory_id')).filter((label) => label !== '— Select inventory —');
}

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
    // Pages through the list: other seeded templates push this one off page 1.
    try {
      await openTemplate(page, 'e2e-vault-template');
    } catch {
      test.skip(true, 'e2e-vault-template is not seeded');
      return;
    }
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

  // commands/E2eSurveyEditorSeeder.php: Extra Vars with a value for the
  // template's password field, saved before such keys were refused.
  test('viewer sees a password field\'s key in a template\'s Extra Vars only as $encrypted$', async ({ page }) => {
    await openTemplate(page, 'e2e-survey-extra-vars-template');
    await expectNotInPage(page, 'e2e-legacy-extra-var-Pq90');
    const shown = (await page.getByTestId('template-extra-vars').textContent()) ?? '';
    expect(JSON.parse(shown)).toEqual({ region: 'eu-central', db_password: PLACEHOLDER });
  });

  // Approval rules on templates are assigned by admins (approval-rule.update).
  // commands/E2eApprovalSeeder.php: e2e-gated-template, gated by
  // e2e-approval-rule, is in e2e-project, which e2e-operator's team operates.
  test('operator sees a template\'s approval rule, read-only in its form', async ({ page }) => {
    await openTemplate(page, GATED_TEMPLATE);
    const shown = page.locator('#job-template-approval-rule');
    await expect(shown).toHaveText(ruleLabel(APPROVAL_RULE));
    // Operators may open rules (approval-rule.view).
    await expect(shown.locator('a')).toHaveAttribute('href', /\/approval-rule\/view\?id=\d+/);

    await page.goto(`/job-template/update?id=${openTemplateId(page)}`);
    await expect(page.locator('#jobtemplate-approval_rule_id')).toHaveCount(0);
    const readOnly = page.locator('#job-template-approval-rule-readonly');
    await expect(readOnly.locator('.form-control-plaintext')).toHaveText(ruleLabel(APPROVAL_RULE));
    await expect(readOnly.locator('.form-text')).toHaveText(ASSIGN_REFUSED);

    await page.goto('/job-template/create');
    await expect(page.locator('#jobtemplate-approval_rule_id')).toHaveCount(0);
    await expect(page.locator('#job-template-approval-rule-readonly .form-control-plaintext')).toHaveText('None');
  });

  // Regression: an operator could set or remove a template's approval rule through a crafted web POST.
  test('operator gets 403 on CSRF-valid updates that remove or change a template\'s approval rule, and nothing changes', async ({ page }) => {
    const otherRule = await approvalRuleId(page, SOLO_RULE);
    await openTemplate(page, GATED_TEMPLATE);
    const id = openTemplateId(page);
    const marker = `forged-${Date.now()}`;

    for (const ruleId of ['', otherRule]) {
      // Real form posts with a valid CSRF token: only the server's check may stop them.
      await page.goto(`/job-template/view?id=${id}`);
      await forgePost(page, `/job-template/update?id=${id}`, {
        'JobTemplate[description]': marker,
        'JobTemplate[approval_rule_id]': ruleId,
      }, /\/job-template\/update/);
      await expectForbidden(page);
      await expect(page.locator('#page-content')).toContainText(ASSIGN_REFUSED);
    }

    await page.goto(`/job-template/view?id=${id}`);
    await expect(page.locator('#job-template-approval-rule')).toHaveText(ruleLabel(APPROVAL_RULE));
    await page.goto(`/job-template/update?id=${id}`);
    await expect(page.locator('#jobtemplate-description')).not.toHaveValue(marker);
  });

  test('operator gets 403 on a CSRF-valid create of a template with an approval rule, and none is created', async ({ page }) => {
    const ruleId = await approvalRuleId(page, APPROVAL_RULE);
    const name = `e2e-forged-gated-${Date.now()}`;
    await page.goto('/job-template/create');
    const projectId = await page.locator('#jobtemplate-project_id option', { hasText: /^e2e-project$/ }).getAttribute('value');
    expect(projectId, 'the operator may create templates in e2e-project').toBeTruthy();

    await page.goto('/job-template/index');
    await forgePost(page, '/job-template/create', {
      'JobTemplate[name]': name,
      'JobTemplate[project_id]': projectId ?? '',
      'JobTemplate[playbook]': 'site.yml',
      'JobTemplate[approval_rule_id]': ruleId,
    }, /\/job-template\/create/);
    await expectForbidden(page);
    await expect(page.locator('#page-content')).toContainText(ASSIGN_REFUSED);

    const names = (await indexRows(page, '/job-template/index?sort=name')).map((row) => row.cells[1]);
    expect(names).not.toContain(name);
  });

  test('operator gets 403 on a CSRF-valid template delete, and the template stays', async ({ page }) => {
    // Deleting templates (and so canceling their waiting jobs) needs job-template.delete: admins only.
    await openTemplate(page, GATED_TEMPLATE);
    const id = openTemplateId(page);
    await expect(page.locator('#page-content form[action*="/job-template/delete"]')).toHaveCount(0);

    await forgePost(page, `/job-template/delete?id=${id}`, {}, /\/job-template\/delete/);
    await expectForbidden(page);

    await page.goto(`/job-template/view?id=${id}`);
    await expect(page.locator('h2').first()).toHaveText(GATED_TEMPLATE);
  });

  test('operator clone of a gated template keeps its approval rule and opens a locked form', async ({ page, browser }, testInfo) => {
    // Copying is not assigning: dropping the rule would make an ungated twin.
    // The clone is gated too, so only its name and description are open.
    await openTemplate(page, GATED_TEMPLATE);
    await Promise.all([
      page.waitForURL(/\/job-template\/update\?id=\d+/),
      page.getByRole('button', { name: 'Clone' }).click(),
    ]);
    await expect(page.locator('#jobtemplate-name')).toHaveValue(new RegExp(`^${GATED_TEMPLATE} \\(copy( \\d+)?\\)$`));
    await expect(page.locator('#job-template-approval-rule-readonly .form-control-plaintext')).toHaveText(ruleLabel(APPROVAL_RULE));
    await expectLockedForm(page);

    const cloneId = openTemplateId(page);
    await page.goto(`/job-template/view?id=${cloneId}`);
    await expect(page.locator('#job-template-approval-rule')).toHaveText(ruleLabel(APPROVAL_RULE));

    // Operators may not delete templates; the admin removes the clone again.
    await asAdmin(browser, testInfo, async (admin) => {
      await admin.goto(`/job-template/view?id=${cloneId}`);
      await deleteOpenTemplate(admin);
    });
  });

  // Only admins (approval-rule.update) change what a job template with an
  // approval rule runs: every field but its name and description.
  // commands/E2eLockedTemplateSeeder.php: e2e-admin's e2e-locked-template,
  // gated by e2e-approval-rule, in e2e-locked-project, which has no team, so
  // every role sees it.
  test('operator sees on a gated template\'s page and form that only its name and description are open', async ({ page }) => {
    const id = await templateId(page, LOCKED_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    await expect(page.locator('h2').first()).toHaveText(LOCKED_TEMPLATE);
    await expect(lockedNotice(page)).toHaveText(GATED_LOCK_NOTICE);
    await expect(lockedNotice(page)).toHaveClass(/(^|\s)alert-info(\s|$)/);
    // What stays open, as the notice says: Edit (for the name and
    // description), Launch, and the trigger token.
    await expect(page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true })).toBeVisible();
    await expect(page.locator('#page-content').getByRole('link', { name: 'Launch', exact: true })).toBeVisible();
    await expect(page.locator('#page-content .card-header', { hasText: 'Inbound Trigger' })).toBeVisible();

    await page.goto(`/job-template/update?id=${id}`);
    await expectLockedForm(page);
  });

  test('viewer sees a gated template without the locked notice, and may not open its form', async ({ page }) => {
    const id = await templateId(page, LOCKED_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    await expect(page.locator('h2').first()).toHaveText(LOCKED_TEMPLATE);
    await expect(page.locator('#job-template-approval-rule')).toHaveText(ruleLabel(APPROVAL_RULE));
    // The notice says what is left to change: for a viewer, nothing is.
    await expect(lockedNotice(page)).toHaveCount(0);
    await expect(page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);

    await page.goto(`/job-template/update?id=${id}`);
    await expectForbidden(page);
  });

  test('operator sees no locked notice on a template without an approval rule, whose form stays open', async ({ page }) => {
    const id = await templateId(page, 'e2e-template');
    await page.goto(`/job-template/view?id=${id}`);
    await expect(page.locator('h2').first()).toHaveText('e2e-template');
    await expect(page.locator('#job-template-approval-rule')).toHaveText('None');
    await expect(lockedNotice(page)).toHaveCount(0);

    await page.goto(`/job-template/update?id=${id}`);
    await expect(page.locator('#jobtemplate-name')).toHaveValue('e2e-template');
    await expect(lockedNotice(page)).toHaveCount(0);
    for (const selector of LOCKED_INPUTS) {
      await expect(page.locator(selector), selector).toBeEnabled();
    }
  });

  test('operator renames a gated template through its form, which keeps everything it runs', async ({ page }) => {
    const id = await templateId(page, LOCKED_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    const before = await runsWhat(page);
    expect(before.Playbook).toBe(LOCKED_PLAYBOOK);
    expect(before.credentialIds.split(','), 'a primary and an additional credential').toHaveLength(2);
    expect(before.extraVars).not.toBe('');
    await page.goto(`/job-template/update?id=${id}`);
    const survey = await surveyOf(page);
    expect(survey, 'the seeded survey').toHaveLength(2);
    const description = await page.locator('#jobtemplate-description').inputValue();
    const renamedDescription = `renamed by e2e-operator ${Date.now()}`;

    try {
      await saveNameAndDescription(page, LOCKED_RENAMED, renamedDescription);
      await expect(flash(page, 'success')).toHaveText(`Template "${LOCKED_RENAMED}" updated.`);
      await expect(page.locator('h2').first()).toHaveText(LOCKED_RENAMED);
      expect(await runsWhat(page)).toEqual(before);

      await page.goto(`/job-template/update?id=${id}`);
      await expect(page.locator('#jobtemplate-description')).toHaveValue(renamedDescription);
      expect(await surveyOf(page)).toEqual(survey);
      await expectLockedForm(page);
    } finally {
      await page.goto(`/job-template/update?id=${id}`);
      await saveNameAndDescription(page, LOCKED_TEMPLATE, description);
    }
    await expect(page.locator('h2').first()).toHaveText(LOCKED_TEMPLATE);
    expect(await runsWhat(page)).toEqual(before);
  });

  test('operator generates and revokes the trigger token of a gated template', async ({ page }) => {
    // Not a change of what it runs: the trigger runs as the user who generated the token.
    const id = await templateId(page, LOCKED_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    try {
      await generateTriggerToken(page, '');
      await expect(flash(page, 'success')).toHaveText('Trigger token generated. Copy it now — it will not be shown again.');
      await expect(page.getByTestId('trigger-runs-as')).toContainText('Runs as e2e-operator, who generated the token.');
    } finally {
      await page.goto(`/job-template/view?id=${id}`);
      await revokeTriggerTokenIfPresent(page);
    }
    await expect(flash(page, 'success')).toHaveText('Trigger token revoked. The /trigger endpoint is now disabled for this template.');
    await expect(page.getByTestId('trigger-not-configured')).toBeVisible();
  });

  // Regression: an operator could change a gated job template and approve its job.
  test('operator gets 403 on CSRF-valid updates that change what a gated template runs, and nothing changes', async ({ page }) => {
    const id = await templateId(page, LOCKED_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    const before = await runsWhat(page);
    // What the form posts (its name and description, the rest is disabled),
    // and an additional credential the template does not have, from its list.
    await page.goto(`/job-template/update?id=${id}`);
    const posted = await formFields(page);
    expect(posted['JobTemplate[name]']).toBe(LOCKED_TEMPLATE);
    const otherCredential = await page.locator('#jt-form input[name="credential_ids[]"]:not(:checked)').first().getAttribute('value');
    expect(otherCredential, 'another credential to attach').toBeTruthy();
    const marker = `forged-${Date.now()}`;
    // What the template runs: its playbook, its extra vars, its list of credentials.
    const changes: Record<string, string>[] = [
      { 'JobTemplate[playbook]': 'forged.yml' },
      { 'JobTemplate[extra_vars]': '{"env":"forged"}' },
      { 'credential_ids[]': otherCredential ?? '' },
    ];

    for (const change of changes) {
      // The form's own post with one forged field and a valid CSRF token:
      // only the server's check may stop it.
      await page.goto(`/job-template/view?id=${id}`);
      await forgePost(page, `/job-template/update?id=${id}`, {
        ...posted,
        'JobTemplate[description]': marker,
        ...change,
      }, /\/job-template\/update/);
      await expectForbidden(page);
      await expect(page.locator('#page-content')).toContainText(GATED_CHANGE_REFUSED);
    }

    await page.goto(`/job-template/view?id=${id}`);
    await expect(page.locator('h2').first()).toHaveText(LOCKED_TEMPLATE);
    expect(await runsWhat(page)).toEqual(before);
    await page.goto(`/job-template/update?id=${id}`);
    await expect(page.locator('#jobtemplate-description')).not.toHaveValue(marker);
  });

  // A job template on an inventory with an approval rule is gated by that
  // rule: only admins change what it runs, and only admins give a job
  // template such an inventory (services/GatedJobTemplateLock.php).
  // commands/E2eInventoryGateSeeder.php: e2e-host-gated-template, without a
  // rule of its own, on e2e-gated-inventory (gated by e2e-host-gate-rule), in
  // e2e-host-gate-project, which has no team, so every role sees it.
  test('operator is not offered inventories with an approval rule in the template form, and is told so', async ({ page }) => {
    await page.goto('/job-template/create');
    let labels = await inventoryOptions(page);
    expect(labels).toContain(HOST_CONFLICT_INVENTORY);
    expect(labels).not.toContain(HOST_INVENTORY);
    expect(labels).not.toContain(HOST_SOLO_INVENTORY);
    expect(labels.filter((label) => label.includes(' \u00b7 needs approval: ')), 'no gated inventory is offered').toEqual([]);
    await expect(page.locator('#job-template-gated-inventories-hint')).toHaveText(GATED_INVENTORIES_HINT);

    // The form of a template on an inventory without a rule is not locked: the same choice.
    await page.goto(`/job-template/update?id=${await templateId(page, HOST_ASSIGN_TEMPLATE)}`);
    await expect(lockedNotice(page)).toHaveCount(0);
    await expect(page.locator('#jobtemplate-inventory_id option:checked')).toHaveText(HOST_ASSIGN_INVENTORY);
    labels = await inventoryOptions(page);
    expect(labels).not.toContain(HOST_INVENTORY);
    expect(labels.filter((label) => label.includes(' \u00b7 needs approval: '))).toEqual([]);
    await expect(page.locator('#job-template-gated-inventories-hint')).toHaveText(GATED_INVENTORIES_HINT);
  });

  // Only admins give a job template an inventory with an approval rule. The browser twin of
  // JobTemplateControllerInventoryLockTest::testRegressionAnOperatorGaveANewTemplateAGatedInventory and
  // ::testRegressionAnOperatorRepointedAnUngatedTemplateToAGatedInventory.
  test('operator gets 403 on CSRF-valid creates and updates that give a template an inventory with an approval rule, and nothing changes', async ({ page }) => {
    // The inventory is not offered, but the operator may see it: its ID from the inventory list.
    const gatedInventory = await inventoryId(page, HOST_INVENTORY);
    const name = `e2e-forged-host-gate-${Date.now()}`;
    await page.goto('/job-template/create');
    const projectId = await page.locator('#jobtemplate-project_id option', { hasText: new RegExp(`^${HOST_PROJECT}$`) }).getAttribute('value');
    expect(projectId, `the operator may create templates in ${HOST_PROJECT}`).toBeTruthy();

    await page.goto('/job-template/index');
    await forgePost(page, '/job-template/create', {
      'JobTemplate[name]': name,
      'JobTemplate[project_id]': projectId ?? '',
      'JobTemplate[playbook]': 'site.yml',
      'JobTemplate[inventory_id]': gatedInventory,
    }, /\/job-template\/create/);
    await expectForbidden(page);
    await expect(page.locator('#page-content')).toContainText(GATED_INVENTORY_REFUSED);
    expect(await templateNames(page)).not.toContain(name);

    // An ungated template moved onto the gated inventory.
    const id = await templateId(page, HOST_ASSIGN_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    const before = await runsWhat(page);
    await page.goto(`/job-template/update?id=${id}`);
    const posted = await formFields(page);
    await page.goto(`/job-template/view?id=${id}`);
    await forgePost(page, `/job-template/update?id=${id}`, {
      ...posted,
      'JobTemplate[inventory_id]': gatedInventory,
    }, /\/job-template\/update/);
    await expectForbidden(page);
    await expect(page.locator('#page-content')).toContainText(GATED_INVENTORY_REFUSED);

    await page.goto(`/job-template/view?id=${id}`);
    expect(await runsWhat(page)).toEqual(before);
    expect(before.Inventory).toBe(HOST_ASSIGN_INVENTORY);
  });

  test('operator sees on a template gated through its inventory that only its name and description are open', async ({ page }) => {
    const id = await templateId(page, HOST_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    await expect(page.locator('h2').first()).toHaveText(HOST_TEMPLATE);
    await expect(lockedNotice(page)).toHaveText(GATED_INVENTORY_LOCK_NOTICE);
    await expect(page.locator('#job-template-approval-rule')).toHaveText('None');
    const inventoryRule = page.locator('#job-template-inventory-rule a');
    await expect(inventoryRule).toHaveText(ruleLabel(HOST_RULE));
    await expect(inventoryRule).toHaveAttribute('href', /\/approval-rule\/view\?id=\d+$/);
    await expect(page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true })).toBeVisible();
    await expect(page.locator('#page-content').getByRole('link', { name: 'Launch', exact: true })).toBeVisible();

    await page.goto(`/job-template/update?id=${id}`);
    await expectLockedForm(page, GATED_INVENTORY_LOCK_NOTICE);
    // The stored inventory stays selected, with its mark.
    await expect(page.locator('#jobtemplate-inventory_id option:checked')).toHaveText(gatedInventoryOption(HOST_INVENTORY, HOST_RULE));
    await expect(page.locator('#job-template-gated-inventories-hint')).toHaveCount(0);
    await expect(page.locator('#job-template-approval-rule-readonly .form-control-plaintext')).toHaveText('None');
  });

  test('operator renames a template gated through its inventory through its form, which keeps everything it runs', async ({ page }) => {
    const id = await templateId(page, HOST_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    const before = await runsWhat(page);
    expect(before.Inventory).toBe(HOST_INVENTORY);
    await page.goto(`/job-template/update?id=${id}`);
    const description = await page.locator('#jobtemplate-description').inputValue();
    const renamedDescription = `renamed by e2e-operator ${Date.now()}`;

    try {
      await saveNameAndDescription(page, HOST_RENAMED_TEMPLATE, renamedDescription);
      await expect(flash(page, 'success')).toHaveText(`Template "${HOST_RENAMED_TEMPLATE}" updated.`);
      await expect(page.locator('h2').first()).toHaveText(HOST_RENAMED_TEMPLATE);
      expect(await runsWhat(page)).toEqual(before);

      await page.goto(`/job-template/update?id=${id}`);
      await expect(page.locator('#jobtemplate-description')).toHaveValue(renamedDescription);
      await expectLockedForm(page, GATED_INVENTORY_LOCK_NOTICE);
    } finally {
      await page.goto(`/job-template/update?id=${id}`);
      await saveNameAndDescription(page, HOST_TEMPLATE, description);
    }
    await expect(page.locator('h2').first()).toHaveText(HOST_TEMPLATE);
    expect(await runsWhat(page)).toEqual(before);
  });

  // Only admins change what a template gated through its inventory runs. The browser twin of
  // JobTemplateControllerInventoryLockTest::testRegressionAnOperatorChangedWhatATemplateGatedThroughItsInventoryRuns and
  // ::testRegressionAnOperatorUngatedATemplateByRepointingIt.
  test('operator gets 403 on CSRF-valid updates that change what a template gated through its inventory runs, and nothing changes', async ({ page }) => {
    const ungatedInventory = await inventoryId(page, HOST_ASSIGN_INVENTORY);
    const id = await templateId(page, HOST_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    const before = await runsWhat(page);
    await page.goto(`/job-template/update?id=${id}`);
    const posted = await formFields(page);
    expect(posted['JobTemplate[name]']).toBe(HOST_TEMPLATE);
    const marker = `forged-${Date.now()}`;
    // Its playbook, and its inventory: moving it onto an inventory without a rule would ungate it.
    const changes: Record<string, string>[] = [
      { 'JobTemplate[playbook]': 'forged.yml' },
      { 'JobTemplate[inventory_id]': ungatedInventory },
    ];

    for (const change of changes) {
      await page.goto(`/job-template/view?id=${id}`);
      await forgePost(page, `/job-template/update?id=${id}`, {
        ...posted,
        'JobTemplate[description]': marker,
        ...change,
      }, /\/job-template\/update/);
      await expectForbidden(page);
      await expect(page.locator('#page-content'), JSON.stringify(change)).toContainText(GATED_INVENTORY_CHANGE_REFUSED);
    }

    await page.goto(`/job-template/view?id=${id}`);
    expect(await runsWhat(page)).toEqual(before);
    await page.goto(`/job-template/update?id=${id}`);
    await expect(page.locator('#jobtemplate-description')).not.toHaveValue(marker);
  });

  test('operator clone of a template gated through its inventory keeps the inventory and opens a locked form', async ({ page, browser }, testInfo) => {
    // A copy on the same inventory is gated by the same rule: only its name and description are open.
    const id = await templateId(page, HOST_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    await Promise.all([
      page.waitForURL(/\/job-template\/update\?id=\d+/),
      page.getByRole('button', { name: 'Clone' }).click(),
    ]);
    const cloneId = openTemplateId(page);
    try {
      expect(cloneId).not.toBe(id);
      await expect(page.locator('#jobtemplate-name')).toHaveValue(new RegExp(`^${HOST_TEMPLATE} \\(copy( \\d+)?\\)$`));
      await expectLockedForm(page, GATED_INVENTORY_LOCK_NOTICE);
      await expect(page.locator('#jobtemplate-inventory_id option:checked')).toHaveText(gatedInventoryOption(HOST_INVENTORY, HOST_RULE));

      await page.goto(`/job-template/view?id=${cloneId}`);
      await expect(page.locator('#job-template-approval-rule')).toHaveText('None');
      await expect(page.locator('#job-template-inventory-rule')).toHaveText(ruleLabel(HOST_RULE));
    } finally {
      // Operators may not delete templates; the admin removes the clone again.
      await asAdmin(browser, testInfo, async (admin) => {
        await admin.goto(`/job-template/view?id=${cloneId}`);
        await deleteOpenTemplate(admin);
      });
    }
  });

  test('operator sees before the click that their launch of a template gated through its inventory could never be approved', async ({ page }) => {
    // e2e-host-solo-rule, the rule of e2e-host-solo-inventory, has e2e-operator as its only approver.
    const id = await templateId(page, HOST_SOLO_TEMPLATE);
    const jobsBefore = await jobIdsOfTemplate(page, id);
    const refusal = approverRefusal(HOST_SOLO_RULE, 1, 0);
    await page.goto(`/job-template/launch?id=${id}`);
    await expect(launchRefusal(page)).toHaveText(refusal);
    await expect(launchRefusal(page)).toHaveAttribute('role', 'alert');
    await expect(launchApprovalNote(page)).toHaveText(LAUNCH_NOTE_INVENTORY(HOST_SOLO_RULE, HOST_SOLO_INVENTORY));
    const launch = page.locator('#launch-form button[type="submit"]');
    await expect(launch).toBeEnabled();

    await launch.click();
    await expect(flash(page, 'danger')).toHaveText(launchFailed(refusal));
    await expect(page).toHaveURL(/\/job-template\/launch/);
    await expect(launchRefusal(page)).toHaveCount(0);
    // Nothing was written: no job of the template.
    expect(await jobIdsOfTemplate(page, id)).toEqual(jobsBefore);
  });

  test('viewer sees the inventory\'s approval rule on a template gated through its inventory, without the locked notice, and may not open its form', async ({ page }) => {
    const id = await templateId(page, HOST_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    await expect(page.locator('h2').first()).toHaveText(HOST_TEMPLATE);
    await expect(page.locator('#job-template-approval-rule')).toHaveText('None');
    // Viewers hold approval-rule.view: the rule is a link.
    await expect(page.locator('#job-template-inventory-rule a')).toHaveText(ruleLabel(HOST_RULE));
    await expect(lockedNotice(page)).toHaveCount(0);
    await expect(page.locator('#page-content').getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);
    await expect(page.locator('#page-content form[action*="/job-template/delete"]')).toHaveCount(0);

    await page.goto(`/job-template/update?id=${id}`);
    await expectForbidden(page);
  });
});
