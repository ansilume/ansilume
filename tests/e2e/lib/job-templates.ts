import { Locator, Page, expect } from '@playwright/test';
import { quoted } from './inventories';
import { optionValue } from './scoping';

/** Helpers for job template pages (views/job-template/view.php). */

/**
 * Sets the approval rule of job template #`id` through its edit form, as an
 * admin (approval-rule.update) may: `label` is the rule's option
 * (ruleLabel() of lib/approvals.ts), '' for none. The template page opens
 * and shows the rule.
 */
export async function setApprovalRule(page: Page, id: string, label: string): Promise<void> {
  await page.goto(`/job-template/update?id=${id}`);
  const select = page.locator('#jobtemplate-approval_rule_id');
  if (label === '') {
    await select.selectOption('');
  } else {
    await select.selectOption({ label });
  }
  await Promise.all([
    page.waitForURL(/\/job-template\/view\?id=\d+/),
    page.getByRole('button', { name: 'Save Changes' }).click(),
  ]);
  await expect(page.locator('#job-template-approval-rule')).toHaveText(label === '' ? 'None' : label);
}

/** views/job-template/view.php: the confirm dialog of Delete. */
export const TEMPLATE_DELETE_CONFIRM = 'Delete this template? Its waiting jobs are canceled and its schedules disabled.';

/**
 * controllers/JobTemplateController.php FLASH_LAUNCH_FAILED: the danger flash
 * of a launch that was refused or failed, with JobLaunchService::failureReason()
 * of the error, which leaves out the "Job launch failed: " of the service's
 * own failures, so the flash never says "Launch failed: Job launch failed: ...".
 */
export function launchFailed(reason: string): string {
  return `Launch failed: ${reason}`;
}

/**
 * Launch input the job record refuses (Job::rules()) is refused before the
 * launch with the record's own message (services/JobLaunchInput.php): a
 * verbosity outside 0-5, a limit of more than 255 characters.
 */
export const VERBOSITY_TOO_HIGH = 'Verbosity must be no greater than 5.';
export const LIMIT_TOO_LONG = 'Limit should contain at most 255 characters.';

/**
 * A verbosity that is no whole number, such as 2.5 or 'abc', is refused the
 * same way, with the message of the record's integer rule: the launch reads
 * it as that rule does (SubmittedInteger), never with an (int) cast.
 */
export const VERBOSITY_NOT_INTEGER = 'Verbosity must be an integer.';

/**
 * A list or an object as the limit, also an empty one, is refused the same
 * way, with the message of the record's string rule; such a verbosity gets
 * VERBOSITY_NOT_INTEGER. Both messages together: the limit's first
 * (JobLaunchInput::refusal()).
 */
export const LIMIT_NOT_A_STRING = 'Limit must be a string.';

/**
 * The fields of the launch form's Advanced Overrides that take one value,
 * posted as a list, such as a crafted overrides[verbosity][]=, with the
 * message that refuses each (services/JobLaunchInput.php).
 */
export const LIST_LAUNCH_FIELDS = [
  ['overrides[verbosity][]', VERBOSITY_NOT_INTEGER],
  ['overrides[limit][]', LIMIT_NOT_A_STRING],
] as const;

/**
 * Numbers that are no whole numbers, but which PHP writes as one with its 14
 * significant digits: "3" and "5". Yii's integer rule matched its pattern
 * against that text, so it took them, and the column stored them cut to 2
 * and 4; app\components\WholeNumberValidator refuses them. JSON number text,
 * as a client that computes them sends them.
 */
export const NEARLY_THREE = '2.9999999999999996';
export const NEARLY_FIVE = '4.999999999999999';

/**
 * The JSON number text of a float just above the ID `id` that PHP writes as
 * that ID with its 14 significant digits: a 1 in its 15th digit.
 */
export function nearlyId(id: string): string {
  expect(id, 'an ID').toMatch(/^\d{1,13}$/);
  return `${id}.${'0'.repeat(14 - id.length)}1`;
}

/**
 * JobTemplateCredentialService::INVALID_CREDENTIAL_IDS
 * (services/JobTemplateCredentialService.php): the error under the
 * additional credentials of the template form (credentialIdsError()) when an
 * entry of credential_ids is no credential ID as the integer rule reads an
 * ID, such as NEARLY_THREE, nearlyId() of a credential, true or an ID with a
 * space. The form read such an entry with filter_var(), which took
 * NEARLY_THREE for credential #3.
 */
export const INVALID_CREDENTIAL_IDS = 'credential_ids must contain positive integer credential IDs.';

/** The error of the additional credentials of the template form on the open page (views/job-template/form.php). */
export function credentialIdsError(page: Page): Locator {
  return page.locator('#credential-ids-error');
}

/** The ID of the first credential the template form of the open page offers as an additional one. */
export async function additionalCredentialId(page: Page): Promise<string> {
  const id = await page.locator('#jt-form input[name="credential_ids[]"]').first().getAttribute('value');
  expect(id, 'the form offers an additional credential').toMatch(/^\d+$/);
  return id ?? '';
}

/**
 * The fields of a new template that the template form of the open page
 * accepts, as JSON members: e2e-project with site.yml, e2e-inventory and the
 * first runner group (commands/E2eController.php).
 */
export async function newTemplateMembers(page: Page, name: string): Promise<string> {
  const project = await optionValue(page, '#jobtemplate-project_id', 'e2e-project');
  const inventory = await optionValue(page, '#jobtemplate-inventory_id', 'e2e-inventory');
  const runnerGroup = await page.locator('#jobtemplate-runner_group_id option:not([value=""])').first().getAttribute('value');
  expect(runnerGroup, 'a runner group').toMatch(/^\d+$/);
  return `"name": "${name}", "project_id": ${project}, "playbook": "site.yml", "inventory_id": ${inventory}, "runner_group_id": ${runnerGroup}`;
}

/**
 * controllers/JobTemplateController.php findModel(): the 404 of a job
 * template that does not exist. An id that is no ID, such as '5abc', is
 * template #0 (SubmittedId).
 */
export function templateNotFound(id: number): string {
  return `Job template #${id} not found.`;
}

/** controllers/JobTemplateController.php FLASH_DELETED */
export function templateDeleted(name: string, canceledJobs: number, disabledSchedules: number): string {
  return `Template "${name}" deleted: ${canceledJobs} waiting job(s) canceled, ${disabledSchedules} schedule(s) disabled.`;
}

/**
 * Deletes the job template whose page is open through its Delete button;
 * the text of the confirm dialog, which is accepted. The template list opens.
 */
export async function deleteOpenTemplate(page: Page): Promise<string> {
  let message = '';
  page.once('dialog', (dialog) => {
    message = dialog.message();
    void dialog.accept();
  });
  await Promise.all([
    page.waitForURL(/\/job-template\/index/),
    page.locator('#page-content form[action*="/job-template/delete"] button[type="submit"]').click(),
  ]);
  return message;
}

/**
 * commands/E2eLockedTemplateSeeder.php: e2e-admin's job template, gated by
 * e2e-approval-rule, in e2e-locked-project, which has no team, so every role
 * sees it. Its primary credential is e2e-template's, e2e-locked-credential an
 * additional one; it has extra vars, a survey and options that differ from
 * the form's defaults. A spec renames it to LOCKED_RENAMED and back.
 */
export const LOCKED_TEMPLATE = 'e2e-locked-template';
export const LOCKED_RENAMED = 'e2e-locked-template-renamed';
export const LOCKED_CREDENTIAL = 'e2e-locked-credential';
export const LOCKED_PLAYBOOK = 'site.yml';

/**
 * GatedJobTemplateLock::CHANGE_REFUSED. A job template with an
 * approval rule of its own, or on an inventory with one ("gated"): only
 * admins (approval-rule.update) may change what it runs; others may change
 * its name and description (services/GatedJobTemplateLock.php). This is the
 * 403 of a template with a rule of its own; GATED_INVENTORY_CHANGE_REFUSED
 * that of a template gated through its inventory.
 */
export const GATED_CHANGE_REFUSED = 'This job template has an approval rule: only admins may change what it runs '
  + '(approval-rule.update). You may change its name and description.';

/**
 * GatedJobTemplateLock::NOTICE: on the page and edit form of a
 * template with an approval rule of its own (also when its inventory has
 * one), for users who may not change what it runs.
 */
export const GATED_LOCK_NOTICE = 'This job template has an approval rule: only admins may change what it runs '
  + '(approval-rule.update). You may still change its name and description, launch it and manage its trigger token.';

/**
 * GatedJobTemplateLock::NOTICE_INVENTORY: the notice of a template without a
 * rule of its own on an inventory with one.
 */
export const GATED_INVENTORY_LOCK_NOTICE = 'The inventory of this job template has an approval rule: only admins may change '
  + 'what it runs (approval-rule.update). You may still change its name and description, launch it and manage '
  + 'its trigger token.';

/** GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY: the 403 of a change of what a template gated through its inventory runs. */
export const GATED_INVENTORY_CHANGE_REFUSED = 'The inventory of this job template has an approval rule: only admins may '
  + 'change what it runs (approval-rule.update). You may change its name and description.';

/**
 * GatedJobTemplateLock::GATED_INVENTORY_REFUSED: the 403 of a create or update
 * that gives a job template an inventory with an approval rule, by a user who
 * may not assign rules and may see the inventory.
 */
export const GATED_INVENTORY_REFUSED = 'The selected inventory has an approval rule: only admins may give a job '
  + 'template an inventory with an approval rule (approval-rule.update).';

/**
 * JobTemplateInventoryOptions::GATED_INVENTORIES_HINT
 * (services/JobTemplateInventoryOptions.php): below the inventory select of a
 * user who may not assign rules, on a form that is not locked, when
 * inventories with a rule were left out of it.
 */
export const GATED_INVENTORIES_HINT = 'Inventories with an approval rule are not listed: only admins may give a '
  + 'job template such an inventory.';

/**
 * The label of an inventory with an approval rule in the inventory select of
 * the template form: `label` (the inventory's name, for a static one) and
 * JobTemplateInventoryOptions::GATED_OPTION_SUFFIX with the rule's name
 * (U+00B7 between spaces).
 */
export function gatedInventoryOption(label: string, rule: string): string {
  return `${label} \u00b7 needs approval: ${rule}`;
}

/**
 * JobTemplateRuleAssignment::RULES_DIFFER (services/JobTemplateRuleAssignment.php):
 * the validation error of a template's own rule that differs from the rule of
 * its inventory (`inventory`, `rule`), under the rule select.
 */
export function rulesDiffer(inventory: string, rule: string): string {
  return `Inventory ${quoted(inventory)} has the approval rule ${quoted(rule)}: a job template on it may have only that rule `
    + 'as its own, or none.';
}

/**
 * JobTemplateRuleAssignment::INVENTORY_RULE_HINT: below the rule select of
 * admins (#job-template-inventory-rule-hint), when the inventory the form
 * names has a rule.
 */
export function inventoryRuleHint(inventory: string, rule: string): string {
  return `Its inventory ${quoted(inventory)} has the approval rule ${quoted(rule)}: every launch waits for it. `
    + 'Leave this rule empty, or choose the same one.';
}

/**
 * JobTemplateGate::LAUNCH_NOTE (services/JobTemplateGate.php): the launch
 * page's note (#launch-approval-note) for a template's own rule.
 */
export function LAUNCH_NOTE(rule: string): string {
  return `The job will wait for approval before it runs: rule "${rule}" (job template).`;
}

/** JobTemplateGate::LAUNCH_NOTE_INVENTORY: the launch page's note for the rule of the template's inventory. */
export function LAUNCH_NOTE_INVENTORY(rule: string, inventory: string): string {
  return `The job will wait for approval before it runs: rule "${rule}" (inventory "${inventory}").`;
}

/** JobTemplateGate::SCHEDULE_NOTE: the schedule page's Approval row (#schedule-approval) for a template's own rule. */
export function SCHEDULE_NOTE(rule: string): string {
  return `Each run waits for approval: rule "${rule}" (job template).`;
}

/** JobTemplateGate::SCHEDULE_NOTE_INVENTORY: the schedule page's Approval row for the rule of the template's inventory. */
export function SCHEDULE_NOTE_INVENTORY(rule: string, inventory: string): string {
  return `Each run waits for approval: rule "${rule}" (inventory "${inventory}").`;
}

/**
 * ApproverEligibility::REFUSAL_JOB (services/ApproverEligibility.php): why a
 * launch of a gated job template could never be approved: `rule` needs
 * `required` approvals from others than the launcher, but only `eligible` of
 * its approvers may decide. The launch page shows it before the click
 * (#launch-refusal), the refused launch in its danger flash (launchFailed()).
 */
export function approverRefusal(rule: string, required: number, eligible: number): string {
  return `Approval rule ${quoted(rule)} needs ${required} approval(s) from users other than the one who launches, `
    + `but only ${eligible} of its approvers may decide on this job. An admin can add approvers to the rule.`;
}

/** The launch page's note of what the job will wait for (views/job-template/launch.php). */
export function launchApprovalNote(page: Page): Locator {
  return page.locator('#page-content #launch-approval-note');
}

/** The launch page's warning, on GET only, that this user's launch could never be approved. */
export function launchRefusal(page: Page): Locator {
  return page.locator('#page-content #launch-refusal');
}

/** The notice of a gated job template, on its page and its edit form. */
export function lockedNotice(page: Page): Locator {
  return page.locator('#page-content #job-template-locked-notice');
}

/** The inputs of the edit form that stay open on a gated job template: its name and description. */
export const OPEN_INPUTS = ['#jobtemplate-name', '#jobtemplate-description'];

/**
 * The inputs of the edit form for what a job template runs: on a gated
 * template, disabled for users who may not change it (the credential
 * picker, the survey editor and the extra vars editor are checked apart).
 */
export const LOCKED_INPUTS = [
  '#jobtemplate-project_id',
  '#jobtemplate-playbook',
  '#jobtemplate-inventory_id',
  '#jobtemplate-credential_id',
  '#jobtemplate-extra_vars',
  '#jobtemplate-runner_group_id',
  '#jobtemplate-timeout_minutes',
  '#jobtemplate-verbosity',
  '#jobtemplate-forks',
  '#jobtemplate-limit',
  '#jobtemplate-tags',
  '#jobtemplate-skip_tags',
  '#jobtemplate-become',
  '#jobtemplate-become_method',
  '#jobtemplate-become_user',
];

/**
 * The open edit form is the one of a gated job template for a user who may
 * not change what it runs: the notice (`notice`: GATED_LOCK_NOTICE for a
 * template with a rule of its own, GATED_INVENTORY_LOCK_NOTICE for one gated
 * through its inventory), the name and description open, every other input
 * disabled, the additional credentials and the survey editor's inputs and
 * buttons too, and nothing typed into the extra vars editor changes the
 * extra vars.
 */
export async function expectLockedForm(page: Page, notice: string = GATED_LOCK_NOTICE): Promise<void> {
  await expect(lockedNotice(page)).toHaveText(notice);
  await expect(lockedNotice(page)).toHaveClass(/(^|\s)alert-info(\s|$)/);
  for (const selector of OPEN_INPUTS) {
    await expect(page.locator(selector), selector).toBeEnabled();
  }
  for (const selector of LOCKED_INPUTS) {
    await expect(page.locator(selector), selector).toBeDisabled();
  }
  const credentials = page.locator('#jt-form input[name="credential_ids[]"]');
  expect(await credentials.count(), 'the form lists additional credentials').toBeGreaterThan(0);
  for (const checkbox of await credentials.all()) {
    await expect(checkbox).toBeDisabled();
  }
  const surveyControls = page.locator('#survey-editor').locator('input:not([type="hidden"]), select, textarea, button');
  for (const control of await surveyControls.all()) {
    await expect(control).toBeDisabled();
  }
  const extraVars = page.locator('#jobtemplate-extra_vars');
  const before = await extraVars.inputValue();
  const editor = page.locator('.field-jobtemplate-extra_vars .CodeMirror');
  if (await editor.count() > 0) {
    await editor.first().click();
    await page.keyboard.type('"typed": 1');
  }
  await expect(extraVars).toHaveValue(before);
}

/**
 * The fields of the open template page that say what it runs, by label: the
 * Execution card (project, playbook, inventory, credentials, approval rule,
 * options), the credentials as "id:role" in precedence order, and the extra vars.
 */
export async function runsWhat(page: Page): Promise<Record<string, string>> {
  const fields = await page.locator('#page-content .card', { has: page.locator('.card-header', { hasText: /^Execution$/ }) })
    .locator('dl').evaluate((dl) => {
      const result: Record<string, string> = {};
      dl.querySelectorAll('dt').forEach((dt) => {
        const dd = dt.nextElementSibling;
        result[(dt.textContent ?? '').trim()] = (dd?.textContent ?? '').replace(/\s+/g, ' ').trim();
      });
      return result;
    });
  fields.credentialIds = (await page.locator('#template-credentials li').evaluateAll(
    (items) => items.map((item) => `${item.getAttribute('data-credential-id')}:${item.getAttribute('data-role')}`),
  )).join(',');
  const extraVars = page.getByTestId('template-extra-vars');
  fields.extraVars = (await extraVars.count()) > 0 ? ((await extraVars.textContent()) ?? '') : '';
  return fields;
}

/** The survey of the job template whose edit form is open, as its survey editor carries it (JSON). */
export async function surveyOf(page: Page): Promise<unknown> {
  return JSON.parse(await page.locator('#survey-fields-json').inputValue()) as unknown;
}
