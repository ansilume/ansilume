import { test, expect, Locator, Page } from '@playwright/test';
import { expectFlash, fillForm, notAnInteger, postJsonWithPageCsrf, showAnswer, submitForm } from '../../lib/helpers';
import { PAGE_TITLE } from '../../lib/selectors';
import { forgePost, idWhere, indexRows } from '../../lib/scoping';
import {
  APPROVAL_RULE,
  BEYOND_INT_TEAM_RULE,
  BEYOND_INT_USERS_RULE,
  DELETE_BLOCKED,
  GATED_TEMPLATE,
  NOT_IN_USE,
  NO_TEAM_CONFIGURED,
  NO_USERS_CONFIGURED,
  approverCell,
  expectNoApproverSelected,
  flash,
} from '../../lib/approvals';
import { HOST_INVENTORY, HOST_RULE } from '../../lib/inventories';

/** The rule this file creates, changes and deletes. */
const CRUD_RULE = 'e2e-crud-approval-rule';

/**
 * The users of the Users select of the rule form who may decide
 * (data-eligible="1": active, and holding approval.decide or a superadmin,
 * ApproverCounts): only they count towards the required approvals.
 */
const ELIGIBLE_USERS = '#approver-users option[data-eligible="1"]';

/**
 * models/ApprovalRule.php ROLE_HINT, the hint under Role
 * (#approver-role-hint). A role rule counts the users assigned the role
 * directly (regression: it said "All users with this role can approve.").
 */
const ROLE_HINT = 'Approvers are the users assigned this role directly, not through another role, nor as the '
  + 'default role every signed-in user holds. Those of them who are active, hold approval.decide '
  + '(or are superadmins) and may see the job can approve, never the user who launched it.';

/**
 * models/ApprovalRule.php TEAM_HINT, the hint under Team
 * (#approver-team-hint). A team rule counts every member of the team, of
 * whom only those who may decide can approve (regression: it said "All
 * members of this team can approve.").
 */
const TEAM_HINT = 'Approvers are the members of this team. Those of them who are active, hold approval.decide '
  + '(or are superadmins) and may see the job can approve, never the user who launched it.';

/** models/ApprovalRule.php LAUNCHER_NOTE */
const LAUNCHER_NOTE = 'The user who launches a job never approves it: a launch is refused '
  + 'unless enough other approvers may decide.';

/**
 * Opens the page of the rule `name`; its ID. Column 0 of the rule list is the
 * name. The seeded rule is the only one of its name; of the rules this file
 * creates, a run that failed before its delete test may have left one behind,
 * so `newest` takes the newest of them.
 */
async function openRule(page: Page, name: string, newest = false): Promise<string> {
  const rows = await indexRows(page, '/approval-rule/index');
  const id = newest
    ? String(Math.max(...rows.filter((row) => row.cells[0] === name).map((row) => Number(row.id))))
    : idWhere(rows, 0, name);
  expect(id, `a rule named ${name}`).toMatch(/^\d+$/);
  await page.goto(`/approval-rule/view?id=${id}`);
  return id;
}

/**
 * The fields of the rule form that hold a whole number: its label, the id of
 * its input and the class of its field, which the input's id names
 * (required_approvals has an id of its own, #required-approvals).
 */
const WHOLE_NUMBER_FIELDS = {
  required_approvals: { label: 'Required Approvals', input: '#required-approvals', field: '.field-required-approvals' },
  timeout_minutes: { label: 'Timeout Minutes', input: '#approvalrule-timeout_minutes', field: '.field-approvalrule-timeout_minutes' },
} as const;

/** The error of the whole-number field `attribute` of the rule form on the open page. */
function ruleFieldError(page: Page, attribute: keyof typeof WHOLE_NUMBER_FIELDS): Locator {
  return page.locator(`${WHOLE_NUMBER_FIELDS[attribute].field} .help-block`);
}

/**
 * Lists and objects as a whole-number field of the rule form posted as JSON,
 * with what 2.13.1 and the move to PHP 8.5 up to its fixes did: Yii's rules
 * take an empty list for empty and skip it, so the column stored it as 0: a
 * rule that needs no approval, whose requests any vote approved, a rejection
 * too, or one whose requests time out at once. The form, shown again for a
 * list with values, made text of it and answered 500 ("Array to string
 * conversion"). ApprovalRuleWriteService refuses each now with the field's
 * integer error, the field emptied.
 */
const REFUSED_LISTS = [
  { attribute: 'required_approvals', value: '[]' },
  { attribute: 'required_approvals', value: '{}' },
  { attribute: 'timeout_minutes', value: '[]' },
  { attribute: 'required_approvals', value: '[5]' },
] as const;

/**
 * Creates the rule `name` through a real form post: approvers of the role
 * admin, 1 required approval, a timeout of 30 minutes that rejects; its ID.
 * Its page opens.
 */
async function createAdminRoleRule(page: Page, name: string): Promise<string> {
  await page.goto('/approval-rule/index');
  await forgePost(page, '/approval-rule/create', {
    'ApprovalRule[name]': name,
    'ApprovalRule[approver_type]': 'role',
    'ApprovalRule[approver_config]': '{"role":"admin"}',
    'ApprovalRule[required_approvals]': '1',
    'ApprovalRule[timeout_minutes]': '30',
    'ApprovalRule[timeout_action]': 'reject',
  }, /\/approval-rule\/view\?id=\d+$/);
  return new URL(page.url()).searchParams.get('id') ?? '';
}

/** What the open rule page says the rule is: the rows of its first table, by heading. */
async function ruleDetails(page: Page): Promise<Record<string, string>> {
  return page.locator('#page-content table').first().locator('tr').evaluateAll((rows) => Object.fromEntries(rows.map((row) => [
    (row.querySelector('th')?.textContent ?? '').trim(),
    (row.querySelector('td')?.textContent ?? '').replace(/\s+/g, ' ').trim(),
  ])));
}

/** Deletes the unused rule #`id` through a real form post; the rule list opens. */
async function deleteRule(page: Page, id: string): Promise<void> {
  await page.goto(`/approval-rule/view?id=${id}`);
  await forgePost(page, `/approval-rule/delete?id=${id}`, {}, /\/approval-rule\/index$/);
}

test.describe('Approval Rules CRUD', () => {
  test('index page lists approval rules', async ({ page }) => {
    await page.goto('/approval-rule/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/approval/i);
    await expect(page.locator('#page-content a', { hasText: 'New Approval Rule' })).toBeVisible();
  });

  test('view approval rule detail', async ({ page }) => {
    await openRule(page, APPROVAL_RULE);
    await expect(page.locator('h2').first()).toHaveText(APPROVAL_RULE);
    await expect(page.locator('#page-content table').first()).toContainText('Required Approvals');
  });

  test('create approval rule', async ({ page }) => {
    await page.goto('/approval-rule/create');
    await fillForm(page, 'approvalrule', {
      name: CRUD_RULE,
      description: 'Created by E2E test',
    });
    // Select approver type and configure via the UI selector
    await page.locator('#approver-type').selectOption('role');
    await expect(page.locator('#config-role')).toBeVisible();
    await page.locator('#approver-role').selectOption('admin');
    await submitForm(page);
    await expectFlash(page, 'success', `Approval rule "${CRUD_RULE}" created.`);
  });

  test('approver config panels toggle by type', async ({ page }) => {
    await page.goto('/approval-rule/create');

    // Default is "role" — role panel visible, others hidden
    await expect(page.locator('#config-role')).toBeVisible();
    await expect(page.locator('#approver-role-hint')).toHaveText(ROLE_HINT);
    await expect(page.locator('#config-team')).toBeHidden();
    await expect(page.locator('#config-users')).toBeHidden();

    // Switch to "team"
    await page.locator('#approver-type').selectOption('team');
    await expect(page.locator('#config-role')).toBeHidden();
    await expect(page.locator('#config-team')).toBeVisible();
    await expect(page.locator('#approver-team-hint')).toHaveText(TEAM_HINT);
    await expect(page.locator('#config-users')).toBeHidden();

    // Switch to "users"
    await page.locator('#approver-type').selectOption('users');
    await expect(page.locator('#config-role')).toBeHidden();
    await expect(page.locator('#config-team')).toBeHidden();
    await expect(page.locator('#config-users')).toBeVisible();
  });

  test('view shows human-readable approver config', async ({ page }) => {
    await openRule(page, APPROVAL_RULE);
    // The seeded approvers by name instead of raw JSON (commands/E2eApprovalSeeder.php).
    await expect(page.locator('#page-content table').first()).toContainText('Users: e2e-admin, e2e-operator');
  });

  test('update approval rule', async ({ page }) => {
    await openRule(page, CRUD_RULE, true);
    await page.locator('#approval-rule-edit').click();
    await page.locator('#approvalrule-description').fill('Updated by E2E');
    await submitForm(page);
    await expectFlash(page, 'success', `Approval rule "${CRUD_RULE}" updated.`);
  });

  test('warns when required approvals exceeds eligible users', async ({ page }) => {
    await page.goto('/approval-rule/create');

    // Select "users" type and pick only 1 user who may decide: one who may
    // not would get the warning that nobody can approve instead.
    await page.locator('#approver-type').selectOption('users');
    const firstValue = await page.locator(ELIGIBLE_USERS).first().getAttribute('value');
    expect(firstValue, 'a user who may decide').toMatch(/^\d+$/);
    await page.locator('#approver-users').selectOption([firstValue ?? '']);

    // Set required approvals to 5 — should trigger warning
    await page.locator('#required-approvals').fill('5');
    await page.locator('#required-approvals').dispatchEvent('input');
    const warning = page.locator('#approver-count-warning');
    await expect(warning).toBeVisible();
    await expect(warning).toHaveText('Warning: Required approvals (5) exceeds eligible approvers (1).');

    // Reduce to 1 — warning should disappear
    await page.locator('#required-approvals').fill('1');
    await page.locator('#required-approvals').dispatchEvent('input');
    await expect(warning).toBeHidden();
  });

  test('the form says the launcher never approves when every approver is needed', async ({ page }) => {
    await page.goto('/approval-rule/create');
    await page.locator('#approver-type').selectOption('users');
    // Users who may decide: only they count as approvers.
    const values = await page.locator(ELIGIBLE_USERS).evaluateAll(
      (options) => options.slice(0, 2).map((option) => (option as HTMLOptionElement).value),
    );
    expect(values, 'two users who may decide to choose').toHaveLength(2);
    const note = page.locator('#approver-launcher-note');
    const required = page.locator('#required-approvals');

    // One approver, one approval: a launch by that approver would be refused.
    await page.locator('#approver-users').selectOption([values[0]]);
    await required.fill('1');
    await required.dispatchEvent('input');
    await expect(note).toBeVisible();
    await expect(note).toHaveText(LAUNCHER_NOTE);

    // Two approvers, one approval: the other one decides.
    await page.locator('#approver-users').selectOption(values);
    await required.dispatchEvent('input');
    await expect(note).toBeHidden();

    // Two approvers, two approvals: again every approver is needed.
    await required.fill('2');
    await required.dispatchEvent('input');
    await expect(note).toBeVisible();
  });

  test('server rejects rule when required approvals exceeds eligible users', async ({ page }) => {
    await page.goto('/approval-rule/create');
    await page.locator('#approvalrule-name').fill('e2e-excess-approvers');
    await page.locator('#approver-type').selectOption('users');
    await expect(page.locator('#config-users')).toBeVisible();
    const firstOption = page.locator('#approver-users option').first();
    const firstValue = await firstOption.getAttribute('value');
    if (firstValue) {
      await page.locator('#approver-users').selectOption([firstValue]);
    }
    await page.locator('#required-approvals').fill('10');
    await page.locator('#approvalrule-timeout_action').selectOption('reject');
    await submitForm(page);

    // Server-side validation should reject — page stays on form with error
    await expect(page.locator('body')).toContainText(/exceeds/i, { timeout: 5_000 });
  });

  test('a rule in use lists what uses it and offers no Delete', async ({ page }) => {
    await openRule(page, APPROVAL_RULE);
    await expect(page.locator('#approval-rule-edit')).toBeVisible();
    // Clones of the template keep its rule, so they are listed too.
    const templates = page.locator('#approval-rule-usage-templates');
    await expect(templates.getByRole('link', { name: GATED_TEMPLATE, exact: true })).toHaveAttribute('href', /\/job-template\/view\?id=\d+/);
    const steps = page.locator('#approval-rule-usage-steps li', { hasText: 'e2e-approval-workflow' });
    await expect(steps).toContainText('step e2e-step-approve');

    await expect(page.locator('#approval-rule-delete-form')).toHaveCount(0);
    const blocked = page.locator('#approval-rule-delete-blocked');
    await expect(blocked).toBeDisabled();
    await expect(blocked).toHaveAttribute('title', DELETE_BLOCKED);
    await expect(page.locator('#approval-rule-delete-blocked-reason')).toHaveText(DELETE_BLOCKED);
    // No inventory has this rule.
    await expect(page.locator('#approval-rule-usage-inventories')).toHaveCount(0);
  });

  // commands/E2eInventoryGateSeeder.php: e2e-host-gate-rule gates
  // e2e-gated-inventory, which has no project; no job template has the rule
  // as its own, so the inventory is what uses it.
  test('a rule that gates an inventory lists the inventory first under Used by and offers no Delete', async ({ page }) => {
    await openRule(page, HOST_RULE);
    const usage = page.locator('#approval-rule-usage');
    const inventories = page.locator('#approval-rule-usage-inventories');
    const inventory = inventories.locator('li').filter({ has: page.getByRole('link', { name: HOST_INVENTORY, exact: true }) });
    await expect(inventory).toHaveCount(1);
    await expect(inventory.getByRole('link')).toHaveAttribute('href', /\/inventory\/view\?id=\d+$/);
    // Without a project, nothing follows the name.
    await expect(inventory).toHaveText(HOST_INVENTORY);
    // Inventories come first, before what else uses the rule.
    await expect(usage.locator('h6').first()).toHaveText('Inventories');
    await expect(page.locator('#approval-rule-usage-templates')).toHaveCount(0);
    await expect(usage).not.toContainText(NOT_IN_USE);

    await expect(page.locator('#approval-rule-delete-form')).toHaveCount(0);
    const blocked = page.locator('#approval-rule-delete-blocked');
    await expect(blocked).toBeDisabled();
    await expect(blocked).toHaveAttribute('title', DELETE_BLOCKED);
    await expect(page.locator('#approval-rule-delete-blocked-reason')).toHaveText(DELETE_BLOCKED);

    // The link leads to the inventory, which names the rule.
    await inventory.getByRole('link').click();
    await expect(page).toHaveURL(/\/inventory\/view\?id=\d+$/);
    await expect(page.locator('#inventory-approval-rule')).toContainText(HOST_RULE);
  });

  test('a rule with a pending request counts it among its uses', async ({ page }) => {
    // commands/E2eWorkflowScopingSeeder.php: the run of e2e-beta-approval-wf
    // waits at its approval step on e2e-beta-approval-rule, which no template uses.
    await openRule(page, 'e2e-beta-approval-rule');
    await expect(page.locator('#approval-rule-usage-pending')).toHaveText('1 approval request(s) are waiting for a decision under this rule.');
    await expect(page.locator('#approval-rule-usage-steps li', { hasText: 'e2e-beta-approval-wf' })).toHaveCount(1);
    await expect(page.locator('#approval-rule-usage-templates')).toHaveCount(0);
    await expect(page.locator('#approval-rule-delete-blocked')).toBeDisabled();
  });

  // Regression: deleting a rule silently ungated its templates.
  test('deleting a rule in use is refused with what uses it', async ({ page }) => {
    const id = await openRule(page, APPROVAL_RULE);
    // The page offers no Delete: a real form post with a valid CSRF token.
    await page.goto('/approval-rule/index');
    await forgePost(page, `/approval-rule/delete?id=${id}`, {}, /\/approval-rule\/view\?id=\d+/);

    // What uses the rule (ApprovalRuleUsage::summary()): templates and steps,
    // and pending requests when there are any. A flash carries the whitespace
    // of its markup around the message.
    await expect(flash(page, 'danger')).toHaveText(new RegExp(
      `^\\s*Approval rule "${APPROVAL_RULE}" is in use by \\d+ job template\\(s\\)`
      + '(, \\d+ workflow step\\(s\\) and \\d+ pending approval request\\(s\\)| and \\d+ workflow step\\(s\\))'
      + '\\. Remove it from them, and let pending requests be decided or their jobs canceled, before deleting it\\.\\s*$',
    ));
    await expect(page.locator('h2').first()).toHaveText(APPROVAL_RULE);
    await expect(page.locator('#approval-rule-delete-blocked')).toBeDisabled();
    expect(idWhere(await indexRows(page, '/approval-rule/index'), 0, APPROVAL_RULE)).toBe(id);
  });

  // A rule an inventory has cannot go either: its job templates would launch
  // without approval (the foreign key of inventory.approval_rule_id is
  // RESTRICT as well).
  test('deleting a rule that gates an inventory is refused, and the flash counts the inventory first', async ({ page }) => {
    const id = await openRule(page, HOST_RULE);
    // The page offers no Delete: a real form post with a valid CSRF token.
    await page.goto('/approval-rule/index');
    await forgePost(page, `/approval-rule/delete?id=${id}`, {}, /\/approval-rule\/view\?id=\d+/);

    // ApprovalRuleUsage::summary(): the inventories first; pending requests
    // of jobs on the inventory, when a spec left one waiting.
    await expect(flash(page, 'danger')).toHaveText(new RegExp(
      `^\\s*Approval rule "${HOST_RULE}" is in use by \\d+ inventory\\(ies\\)( and \\d+ pending approval request\\(s\\))?`
      + '\\. Remove it from them, and let pending requests be decided or their jobs canceled, before deleting it\\.\\s*$',
    ));
    await expect(page.locator('h2').first()).toHaveText(HOST_RULE);
    await expect(page.locator('#approval-rule-usage-inventories')).toContainText(HOST_INVENTORY);
    expect(idWhere(await indexRows(page, '/approval-rule/index'), 0, HOST_RULE)).toBe(id);
  });

  test('an unused rule says nothing uses it, and its Delete deletes it', async ({ page }) => {
    const id = await openRule(page, CRUD_RULE, true);
    await expect(page.locator('#approval-rule-usage')).toContainText(NOT_IN_USE);
    await expect(page.locator('#approval-rule-usage-inventories')).toHaveCount(0);
    await expect(page.locator('#approval-rule-delete-blocked')).toHaveCount(0);

    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      page.waitForURL(/\/approval-rule\/index/),
      page.locator('#approval-rule-delete-form button[type="submit"]').click(),
    ]);
    await expectFlash(page, 'success', `Approval rule "${CRUD_RULE}" deleted.`);
    const ids = (await indexRows(page, '/approval-rule/index')).map((row) => row.id);
    expect(ids).not.toContain(id);
  });

  // Regression: the rule form posted as JSON (the request's JSON parser
  // serves the web forms too) with an empty list as the required approvals
  // created the rule with 0 of them: a rule that needs no approval, whose
  // requests any vote approved, a rejection too. It is refused now with the
  // field's integer error, and nothing is created.
  test('a create posted as JSON with required_approvals [] shows the field\'s error, and creates nothing', async ({ page }) => {
    const name = 'e2e-json-list-rule';
    await page.goto('/approval-rule/create');

    const answer = await postJsonWithPageCsrf(page, '/approval-rule/create', `{"ApprovalRule": {"name": "${name}", "approver_type": "role", `
      + '"approver_config": "{\\"role\\":\\"admin\\"}", "required_approvals": [], "timeout_action": "reject"}}');

    expect(answer.status(), 'the form again, not a server error').toBe(200);
    await showAnswer(page, answer);
    await expect(ruleFieldError(page, 'required_approvals')).toHaveText(notAnInteger('Required Approvals'));
    await expect(page.locator('#required-approvals'), 'the field empty').toHaveValue('');
    expect((await indexRows(page, '/approval-rule/index')).filter((row) => row.cells[0] === name), 'no rule was created').toHaveLength(0);
  });

  // Regression: the rule form posted as JSON stored a list or an object in a
  // whole-number field, also an empty one, as 0 (REFUSED_LISTS), or answered
  // 500 for one with values ([5]). It is refused now with the field's
  // integer error, the field empty, and the rule stays as it was.
  for (const { attribute, value } of REFUSED_LISTS) {
    test(`an update posted as JSON with ${attribute} ${value} shows the field's error with the field empty, and changes nothing`, async ({ page }) => {
      const id = await createAdminRoleRule(page, `e2e-json-${attribute.replace('_', '-')}-rule`);
      try {
        const before = await ruleDetails(page);
        expect(before['Required Approvals'], 'the rule as made').toBe('1');
        expect(before.Timeout, 'the rule as made').toBe('30 minutes');
        await page.goto(`/approval-rule/update?id=${id}`);

        const answer = await postJsonWithPageCsrf(page, `/approval-rule/update?id=${id}`, `{"ApprovalRule": {"description": "e2e-json-changed", "${attribute}": ${value}}}`);

        expect(answer.status(), 'the form again, not a server error').toBe(200);
        await showAnswer(page, answer);
        await expect(ruleFieldError(page, attribute)).toHaveText(notAnInteger(WHOLE_NUMBER_FIELDS[attribute].label));
        await expect(page.locator(WHOLE_NUMBER_FIELDS[attribute].input), 'the field empty').toHaveValue('');
        await page.goto(`/approval-rule/view?id=${id}`);
        expect(await ruleDetails(page), 'not even the description was saved').toEqual(before);
      } finally {
        await deleteRule(page, id);
      }
    });
  }

  // Regression: on PHP 8.5 the page and the edit form of a rule whose
  // approver config names its approvers by a JSON number beyond the int
  // range answered 500 ("The float 1.0E+30 is not representable as an int,
  // cast occurred"): views/approval-rule/view.php and form.php cast the
  // number with (int). The rule form and the API store such a config, for a
  // users rule whose only user is such a number only up to 2.13.1 (it
  // requires more approvals than its users). The number names nobody now
  // (app\components\ApproverConfigIds): the page says
  // that no approver is configured (for such a users rule it said "Users: "
  // and nothing, for a team rule without a team ID "Team: #"), and the form
  // selects none. Fixtures: commands/E2eApproverConfigSeeder.php.
  const BEYOND_INT_RULES = [
    { name: BEYOND_INT_TEAM_RULE, title: 'a team rule whose team', type: 'team', panel: '#config-team', approver: NO_TEAM_CONFIGURED },
    { name: BEYOND_INT_USERS_RULE, title: 'a users rule whose user', type: 'users', panel: '#config-users', approver: NO_USERS_CONFIGURED },
  ];
  for (const rule of BEYOND_INT_RULES) {
    test(`the page and the edit form of ${rule.title} is a number beyond the int range name no approver and select none`, async ({ page }) => {
      const id = idWhere(await indexRows(page, '/approval-rule/index'), 0, rule.name);

      const shown = await page.goto(`/approval-rule/view?id=${id}`);
      expect(shown?.status(), 'the rule page').toBe(200);
      await expect(page.locator('h2').first()).toHaveText(rule.name);
      await expect(approverCell(page)).toHaveText(rule.approver);

      const form = await page.goto(`/approval-rule/update?id=${id}`);
      expect(form?.status(), 'the edit form').toBe(200);
      await expect(page.locator('#approver-type')).toHaveValue(rule.type);
      await expect(page.locator(rule.panel)).toBeVisible();
      await expectNoApproverSelected(page);
    });
  }
});
