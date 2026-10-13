import { test, expect } from '@playwright/test';
import { openWorkflowTemplate } from '../../lib/triggers';
import { STEPS_WORKFLOW } from '../../lib/workflows';

// The step editor shows the routing fields that apply to the chosen step
// type, and disables the hidden ones, so a value chosen before the type
// changed is not posted:
// - job: On Success, On Failure and Always;
// - pause: only On Success, labelled "Next step" (a pause resumes as success);
// - approval: On Success and On Failure, whose empty choice ends the run as
//   failed; no Always, because a rejection would continue past it.
// The page script does this, so only a browser can check it. Fixture:
// e2e-steps-workflow, which is never launched, so its steps may change.

test.describe('Workflow step editor — routing fields per step type', () => {
  test('job, pause and approval steps offer only their own routing fields', async ({ page }) => {
    await openWorkflowTemplate(page, STEPS_WORKFLOW);

    const typeSelect = page.locator('#ws-step-type');
    await expect(typeSelect, 'the step editor renders on a workflow without active runs').toBeVisible();
    // Approval steps are for admins (approval-rule.update); e2e-admin is one.
    await expect(typeSelect.locator('option')).toHaveText(['Job', 'Approval', 'Pause']);

    const success = page.locator('#ws-route-success');
    const successLabel = success.locator('label').first();
    const failure = page.locator('#ws-route-failure');
    const failureSelect = failure.locator('select');
    const failurePrompt = failureSelect.locator('option[value=""]');
    const always = page.locator('#ws-route-always');
    const alwaysSelect = always.locator('select');
    const help = (type: string) => page.locator(`#ws-routing-help-${type}`);

    await typeSelect.selectOption('job');
    await expect(success).toBeVisible();
    await expect(successLabel).toHaveText('On Success → go to');
    await expect(failure).toBeVisible();
    await expect(failureSelect).toBeEnabled();
    await expect(failurePrompt).toHaveText('— next step (default) —');
    await expect(always).toBeVisible();
    await expect(alwaysSelect).toBeEnabled();
    await expect(help('job')).toBeVisible();
    // A job template with an approval rule gates the step: a closed gate
    // follows only On Failure.
    await expect(help('job')).toContainText(
      'If the job template has an approval rule and the approval is rejected, times out or is canceled, the job does not run: '
      + 'then only On Failure is followed (left empty, the run ends as failed), and Always is not.',
    );
    await expect(help('approval')).toBeHidden();
    await expect(help('pause')).toBeHidden();
    await expect(page.locator('#ws-target-job')).toBeVisible();
    await expect(page.locator('#ws-target-approval')).toBeHidden();

    await typeSelect.selectOption('pause');
    await expect(success).toBeVisible();
    await expect(successLabel).toHaveText('Next step →');
    await expect(failure).toBeHidden();
    await expect(failureSelect).toBeDisabled();
    await expect(always).toBeHidden();
    await expect(alwaysSelect).toBeDisabled();
    await expect(help('pause')).toBeVisible();
    await expect(help('job')).toBeHidden();
    await expect(page.locator('#ws-target-job')).toBeHidden();
    await expect(page.locator('#ws-target-approval')).toBeHidden();

    // An approval step: On Failure stays, and its empty choice ends the run
    // as failed; Always is gone (the server refuses one, END included).
    await typeSelect.selectOption('approval');
    await expect(successLabel).toHaveText('On Success → go to');
    await expect(failure).toBeVisible();
    await expect(failureSelect).toBeEnabled();
    await expect(failurePrompt).toHaveText('— end workflow as failed (default) —');
    await expect(always).toBeHidden();
    await expect(alwaysSelect).toBeDisabled();
    await expect(help('approval')).toBeVisible();
    await expect(help('approval')).toContainText('failure = rejected, timed out or canceled.');
    await expect(help('approval')).toContainText('After a failure the run ends as failed unless On Failure names a step.');
    await expect(help('approval')).toContainText('An approval step has no Always route: it would continue past a rejection.');
    await expect(help('job')).toBeHidden();
    await expect(page.locator('#ws-target-approval')).toBeVisible();
    await expect(page.locator('#ws-target-job')).toBeHidden();

    // Back to a job step: every field is offered and posted again.
    await typeSelect.selectOption('job');
    await expect(failureSelect).toBeEnabled();
    await expect(failurePrompt).toHaveText('— next step (default) —');
    await expect(always).toBeVisible();
    await expect(alwaysSelect).toBeEnabled();
  });
});
