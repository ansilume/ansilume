import { Locator, Page, expect } from '@playwright/test';

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
 * A job template with an approval rule ("gated"): only admins
 * (approval-rule.update) may change what it runs; others may change its name
 * and description (services/GatedJobTemplateLock.php).
 */
export const GATED_CHANGE_REFUSED = 'This job template has an approval rule: only admins may change what it runs '
  + '(approval-rule.update). You may change its name and description.';

/** services/GatedJobTemplateLock.php NOTICE: on the template page and its edit form, for users who may not change what it runs. */
export const GATED_LOCK_NOTICE = 'This job template has an approval rule: only admins may change what it runs '
  + '(approval-rule.update). You may still change its name and description, launch it and manage its trigger token.';

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
 * not change what it runs: the notice, the name and description open, every
 * other input disabled, the additional credentials and the survey editor's
 * inputs and buttons too, and nothing typed into the extra vars editor
 * changes the extra vars.
 */
export async function expectLockedForm(page: Page): Promise<void> {
  await expect(lockedNotice(page)).toHaveText(GATED_LOCK_NOTICE);
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
