import { test, expect, Page } from '@playwright/test';
import { templateId } from '../../lib/helpers';
import { forgePost, optionLabels } from '../../lib/scoping';
import { APPROVAL_RULE, SOLO_RULE, SOLO_TEMPLATE, flash, ruleLabel } from '../../lib/approvals';
import {
  LOCKED_INPUTS,
  LOCKED_PLAYBOOK,
  LOCKED_TEMPLATE,
  TEMPLATE_DELETE_CONFIRM,
  deleteOpenTemplate,
  lockedNotice,
  runsWhat,
  templateDeleted,
} from '../../lib/job-templates';

/**
 * The approval rule of a job template, as an admin assigns it
 * (views/job-template/form.php, view.php), and what an admin may change on
 * a template that has one. Only admins hold approval-rule.update; the
 * operator's side is in rbac.spec.ts. Fixtures: commands/E2eApprovalSeeder.php
 * and commands/E2eLockedTemplateSeeder.php.
 */

/** services/JobTemplateRuleAssignment.php FORM_HINT */
const FORM_HINT = 'Every launch waits until the rule\'s approvers approve it. '
  + 'Only admins (approval-rule.update) may assign or remove a rule.';

/** Sets the playbook of the template whose edit form is open, and saves the form; the template page opens. */
async function savePlaybook(page: Page, playbook: string): Promise<void> {
  await page.locator('#jobtemplate-playbook').fill(playbook);
  await Promise.all([
    page.waitForURL(/\/job-template\/view\?id=\d+/),
    page.getByRole('button', { name: 'Save Changes' }).click(),
  ]);
}

/** Fills the create form of a template on the seeded project and inventory. */
async function fillNewTemplate(page: Page, name: string): Promise<void> {
  await page.goto('/job-template/create');
  await page.locator('#jobtemplate-name').fill(name);
  await page.locator('#jobtemplate-project_id').selectOption({ label: 'e2e-project' });
  await page.locator('#jobtemplate-playbook').fill('site.yml');
  await page.locator('#jobtemplate-inventory_id').selectOption({ label: 'e2e-inventory' });
  const runnerGroup = await page.locator('#jobtemplate-runner_group_id option:not([value=""])').first().getAttribute('value');
  await page.locator('#jobtemplate-runner_group_id').selectOption(runnerGroup ?? '');
}

test.describe('Job Template Approval Rule', () => {
  test('the form offers every rule with its approvals, none by default, and says who may assign one', async ({ page }) => {
    await page.goto('/job-template/create');
    const select = page.locator('#jobtemplate-approval_rule_id');
    await expect(select).toHaveValue('');
    const labels = await optionLabels(page, '#jobtemplate-approval_rule_id');
    expect(labels[0]).toBe('— None —');
    expect(labels).toEqual(expect.arrayContaining([ruleLabel(APPROVAL_RULE), ruleLabel(SOLO_RULE)]));
    await expect(page.locator('.field-jobtemplate-approval_rule_id .hint-block')).toHaveText(FORM_HINT);
    await expect(page.locator('#job-template-approval-rule-readonly')).toHaveCount(0);
  });

  test('an admin assigns a rule to a new template, removes it, and deletes the template', async ({ page }) => {
    const name = `e2e-rule-assign-${Date.now()}`;
    await fillNewTemplate(page, name);
    await page.locator('#jobtemplate-approval_rule_id').selectOption({ label: ruleLabel(APPROVAL_RULE) });
    await Promise.all([
      page.waitForURL(/\/job-template\/view\?id=\d+/),
      page.getByRole('button', { name: 'Create Template' }).click(),
    ]);
    const link = page.locator('#job-template-approval-rule a');
    await expect(link).toHaveText(ruleLabel(APPROVAL_RULE));
    await expect(link).toHaveAttribute('href', /\/approval-rule\/view\?id=\d+/);
    const id = new URL(page.url()).searchParams.get('id');

    await page.goto(`/job-template/update?id=${id}`);
    await expect(page.locator('#jobtemplate-approval_rule_id option:checked')).toHaveText(ruleLabel(APPROVAL_RULE));
    await page.locator('#jobtemplate-approval_rule_id').selectOption('');
    await Promise.all([
      page.waitForURL(/\/job-template\/view\?id=\d+/),
      page.getByRole('button', { name: 'Save Changes' }).click(),
    ]);
    await expect(page.locator('#job-template-approval-rule')).toHaveText('None');
    await expect(page.locator('#job-template-approval-rule a')).toHaveCount(0);

    expect(await deleteOpenTemplate(page)).toBe(TEMPLATE_DELETE_CONFIRM);
    await expect(flash(page, 'success')).toHaveText(templateDeleted(name, 0, 0));
  });

  test('a rule that does not exist is a validation error, and nothing is saved', async ({ page }) => {
    const name = `e2e-rule-unknown-${Date.now()}`;
    await fillNewTemplate(page, name);
    // Not one of the offered rules: a real form post with a valid CSRF token.
    const fields = await page.locator('#jt-form').evaluate((form) => {
      const csrfParam = document.querySelector('meta[name="csrf-param"]')?.getAttribute('content') || '_csrf';
      const values: Record<string, string> = {};
      new FormData(form as HTMLFormElement).forEach((value, key) => {
        if (typeof value === 'string' && key !== csrfParam) {
          values[key] = value;
        }
      });
      return values;
    });
    await page.goto('/job-template/index');
    await forgePost(page, '/job-template/create', { ...fields, 'JobTemplate[approval_rule_id]': '999999999' }, /\/job-template\/create/);

    await expect(page.locator('.field-jobtemplate-approval_rule_id .help-block')).toHaveText('The selected approval rule does not exist.');
    await page.goto('/job/index');
    const names = await page.locator('select[name="template_id"] option').allTextContents();
    expect(names.map((label) => label.trim())).not.toContain(name);
  });

  test('a launch is refused when only the launcher is an approver who may decide', async ({ page }) => {
    const id = await templateId(page, SOLO_TEMPLATE);
    await page.goto(`/job-template/launch?id=${id}`);
    await page.locator('#launch-form button[type="submit"]').click();

    await expect(flash(page, 'danger')).toHaveText(
      `Launch failed: Approval rule "${SOLO_RULE}" needs 1 approval(s) from users other than the one who launches, `
      + 'but only 0 of its approvers may decide on this job. An admin can add approvers to the rule.',
    );
    await expect(page).toHaveURL(/\/job-template\/launch/);
    await page.goto(`/job/index?template_id=${id}`);
    await expect(page.locator('#page-content')).toContainText('No jobs match the current filters.');
  });

  test('an admin sees no locked notice on a gated template and changes what it runs', async ({ page }) => {
    const id = await templateId(page, LOCKED_TEMPLATE);
    await page.goto(`/job-template/view?id=${id}`);
    await expect(page.locator('h2').first()).toHaveText(LOCKED_TEMPLATE);
    await expect(page.locator('#job-template-approval-rule')).toHaveText(ruleLabel(APPROVAL_RULE));
    await expect(lockedNotice(page)).toHaveCount(0);
    const before = await runsWhat(page);

    await page.goto(`/job-template/update?id=${id}`);
    await expect(lockedNotice(page)).toHaveCount(0);
    for (const selector of LOCKED_INPUTS) {
      await expect(page.locator(selector), selector).toBeEnabled();
    }
    await expect(page.locator('#jobtemplate-approval_rule_id option:checked')).toHaveText(ruleLabel(APPROVAL_RULE));
    const playbook = `e2e-locked-admin-${Date.now()}.yml`;
    try {
      await savePlaybook(page, playbook);
      await expect(flash(page, 'success')).toHaveText(`Template "${LOCKED_TEMPLATE}" updated.`);
      expect(await runsWhat(page)).toEqual({ ...before, Playbook: playbook });
      await expect(lockedNotice(page)).toHaveCount(0);
    } finally {
      await page.goto(`/job-template/update?id=${id}`);
      await savePlaybook(page, LOCKED_PLAYBOOK);
    }
    expect(await runsWhat(page)).toEqual(before);
  });
});
