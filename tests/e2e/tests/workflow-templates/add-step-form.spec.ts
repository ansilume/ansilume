import { test, expect } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';
import { openWorkflowTemplate } from '../../lib/triggers';
import { APPROVAL_STEPS_JOB, APPROVAL_STEPS_WORKFLOW, ROUTE_END, ROUTE_NEXT_STEP, addStepFormErrors, templateStepRow } from '../../lib/workflows';

/**
 * The add-step form takes a step of each type: the page offers only the
 * target and the routes of the chosen type, and the fields of the other
 * types must neither be checked nor posted.
 *
 * Regression: a required target select of another type, hidden but still
 * checked by the form's client-side validation, kept the form from being
 * sent: the job template ("A job step needs a job template.") for pause and
 * approval steps since 2.8.1, and, for admins, the approval rule ("An
 * approval step needs an approval rule.") for job and pause steps.
 *
 * Admins: here (approval steps: approval-steps.spec.ts); operators:
 * workflow-templates/rbac.spec.ts. Fixture: e2e-approval-steps-workflow,
 * never launched, with the one job step e2e-approval-steps-job
 * (commands/E2eWorkflowGateSeeder.php). Each test removes the step it added.
 */
const STEPS = '#workflow-steps-table tbody tr';

test.describe('Add steps through the form (admin)', () => {
  test('admin adds a job step through the form and removes it again', async ({ page }) => {
    const name = 'e2e-added-job';
    await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    await expect(page.locator(STEPS)).toHaveCount(1);

    await page.locator('#workflowstep-name').fill(name);
    await page.locator('#ws-step-type').selectOption('job');
    await page.locator('#workflowstep-step_order').fill('20');
    await page.locator('#workflowstep-job_template_id').selectOption({ label: 'e2e-template' });
    await page.locator('#workflowstep-on_failure_step_id').selectOption({ label: '— end workflow —' });
    await page.locator('#workflowstep-on_always_step_id').selectOption({ label: `#10 ${APPROVAL_STEPS_JOB}` });
    await submitForm(page, 'Add Step');
    // The form was sent: no field, hidden or not, refused it.
    expect(await addStepFormErrors(page)).toEqual([]);

    await expectFlash(page, 'success', `Step "${name}" added.`);
    await expect(page.locator(STEPS)).toHaveCount(2);
    const added = templateStepRow(page, name);
    await expect(added.locator('td').nth(0)).toHaveText('20');
    await expect(added.locator('td').nth(2)).toHaveText('Job');
    await expect(added.locator('td').nth(3)).toHaveText('e2e-template');
    await expect(added.locator('td[data-route="success"]')).toHaveText(ROUTE_NEXT_STEP);
    await expect(added.locator('td[data-route="failure"]')).toHaveText(ROUTE_END);
    await expect(added.locator('td[data-route="always"]')).toHaveText(`#10 ${APPROVAL_STEPS_JOB}`);

    page.once('dialog', (dialog) => dialog.accept());
    await added.locator('form[action*="/workflow-template/remove-step"] button').click();
    await expectFlash(page, 'success', 'Step removed.');
    await expect(page.locator(STEPS)).toHaveCount(1);
    await expect(templateStepRow(page, name)).toHaveCount(0);
  });

  test('admin adds a pause step through the form and removes it again', async ({ page }) => {
    const name = 'e2e-added-pause';
    await openWorkflowTemplate(page, APPROVAL_STEPS_WORKFLOW);
    await expect(page.locator(STEPS)).toHaveCount(1);

    await page.locator('#workflowstep-name').fill(name);
    await page.locator('#ws-step-type').selectOption('pause');
    await page.locator('#workflowstep-step_order').fill('20');
    await page.locator('#workflowstep-on_success_step_id').selectOption({ label: '— end workflow —' });
    await submitForm(page, 'Add Step');
    // The form was sent: no field, hidden or not, refused it.
    expect(await addStepFormErrors(page)).toEqual([]);

    await expectFlash(page, 'success', `Step "${name}" added.`);
    await expect(page.locator(STEPS)).toHaveCount(2);
    const added = templateStepRow(page, name);
    await expect(added.locator('td').nth(2)).toHaveText('Pause');
    await expect(added.locator('td').nth(3)).toHaveText('—');
    await expect(added.locator('td[data-route="success"]')).toHaveText(ROUTE_END);
    await expect(added.locator('td[data-route="failure"]')).toHaveText(ROUTE_NEXT_STEP);
    await expect(added.locator('td[data-route="always"]')).toHaveText('—');

    page.once('dialog', (dialog) => dialog.accept());
    await added.locator('form[action*="/workflow-template/remove-step"] button').click();
    await expectFlash(page, 'success', 'Step removed.');
    await expect(page.locator(STEPS)).toHaveCount(1);
    await expect(templateStepRow(page, name)).toHaveCount(0);
  });
});
