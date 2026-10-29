import { test, expect } from '@playwright/test';
import { expectFlash, postWithPageCsrf } from '../../lib/helpers';
import { openWorkflowTemplate } from '../../lib/triggers';
import { EMPTY_WORKFLOW, SOLO_GATE_REFUSAL, SOLO_GATE_WORKFLOW, runCount } from '../../lib/workflows';

const LAUNCH_BUTTON = '#page-content form[action*="/workflow-template/launch"] button';

test.describe('Workflow Launch', () => {
  test('launch button exists on workflow detail', async ({ page }) => {
    await openWorkflowTemplate(page, 'e2e-workflow');
    await expect(page.locator(LAUNCH_BUTTON)).toBeVisible();
  });

  test('launch from template detail page succeeds (regression #11)', async ({ page }) => {
    await openWorkflowTemplate(page, 'e2e-workflow');
    page.once('dialog', (dialog) => dialog.accept());
    // The launch is a CSRF-protected form post: it must not answer 400 Bad
    // Request. It opens the new run.
    await Promise.all([
      page.waitForURL(/\/workflow-job\/view\?id=\d+$/),
      page.locator(LAUNCH_BUTTON).click(),
    ]);
    await expectFlash(page, 'success', 'Workflow "e2e-workflow" launched.');
    await expect(page.locator('#wj-steps-table')).toContainText('e2e-step-run');
  });

  // The launcher never decides on their own launch. A workflow whose
  // approval step only the launcher may decide could never go on: the
  // launch is refused before a run is written, naming the step and the rule.
  test('a launch whose approval only the launcher could give is refused, and the page says why', async ({ page }) => {
    const before = await runCount(page, SOLO_GATE_WORKFLOW);
    const id = await openWorkflowTemplate(page, SOLO_GATE_WORKFLOW);

    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      page.waitForURL(new RegExp(`/workflow-template/view\\?id=${id}$`)),
      page.locator(LAUNCH_BUTTON).click(),
    ]);
    await expectFlash(page, 'danger', `Launch failed: ${SOLO_GATE_REFUSAL}`);
    expect(await runCount(page, SOLO_GATE_WORKFLOW), 'no run was written').toBe(before);
  });

  // A workflow without steps has nothing to run: the launch is refused
  // before a run is written, with the reason.
  test('a workflow without steps is not launched, and the page says why', async ({ page }) => {
    const before = await runCount(page, EMPTY_WORKFLOW);
    const id = await openWorkflowTemplate(page, EMPTY_WORKFLOW);
    await expect(page.locator('#page-content')).toContainText('No steps defined yet.');

    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      page.waitForURL(new RegExp(`/workflow-template/view\\?id=${id}$`)),
      page.locator(LAUNCH_BUTTON).click(),
    ]);
    await expectFlash(page, 'danger', `Launch failed: Workflow template #${id} has no steps.`);
    expect(await runCount(page, EMPTY_WORKFLOW), 'no run was written').toBe(before);
  });

  // Regression: the launch read its id with an (int) cast, so a post to
  // /workflow-template/launch?id=<id>abc launched the workflow <id> (and on
  // PHP 8.5 a JSON number beyond the int range answered 500). An id that is
  // no ID is workflow template #0, which does not exist (SubmittedId): 404,
  // and no run.
  test('a launch of an id that is no ID answers 404 and starts no run', async ({ page }) => {
    const before = await runCount(page, 'e2e-workflow');
    const id = await openWorkflowTemplate(page, 'e2e-workflow');

    const answer = await postWithPageCsrf(page, `/workflow-template/launch?id=${id}abc`);

    expect(answer.status(), 'the forged launch').toBe(404);
    expect(await answer.text()).toContain('Workflow template #0 not found.');
    expect(await runCount(page, 'e2e-workflow'), 'no run was started').toBe(before);
  });
});
