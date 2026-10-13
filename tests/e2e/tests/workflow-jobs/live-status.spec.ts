import { test, expect } from '@playwright/test';
import { LOCKED_PAUSE, LOCKED_WORKFLOW, newestRunId, runStepRow } from '../../lib/workflows';

// The workflow-job detail page polls /workflow-job/status while the
// workflow is still running so the status badge, per-step status, and
// finished-timestamp refresh in place without the operator hitting F5.
// The run of e2e-locked-workflow waits at its pause step, and no spec moves
// it on (commands/E2eWorkflowGateSeeder.php): a stable unfinished run.

test.describe('Workflow job live status', () => {
  test('status endpoint returns a JSON snapshot of the run, its steps keyed by execution', async ({ page }) => {
    const id = await newestRunId(page, LOCKED_WORKFLOW);

    const resp = await page.request.get(`/workflow-job/status?id=${id}`);
    expect(resp.status()).toBe(200);
    const data = await resp.json();

    expect(data).toMatchObject({
      id: Number(id),
      status: 'running',
      status_label: 'Running',
      is_finished: false,
      finished_at: null,
    });
    expect(data).toHaveProperty('status_css');
    expect(Array.isArray(data.steps)).toBe(true);
    expect(data.steps).toHaveLength(1);
    const step = data.steps[0];
    // A step is named and typed as it was when it ran; the execution's own
    // ID keys the row, as a step can run more than once in a run.
    expect(step).toMatchObject({
      step_name: LOCKED_PAUSE,
      step_type: 'pause',
      step_type_label: 'Pause',
      step_index: 1,
      job_id: null,
      is_current: true,
      status: 'running',
      status_label: 'Running',
      error_message: null,
    });
    expect(typeof step.id).toBe('number');
    expect(typeof step.workflow_step_id).toBe('number');
    expect(step).toHaveProperty('status_css');
    expect(step).toHaveProperty('started_label');
    expect(step).toHaveProperty('duration_label');

    await page.goto(`/workflow-job/view?id=${id}`);
    await expect(page.locator(`#wj-steps-table tbody tr[data-wjs-id="${step.id}"]`)).toHaveCount(1);
  });

  test('detail page shows the Live badge while the workflow is unfinished', async ({ page }) => {
    await page.goto(`/workflow-job/view?id=${await newestRunId(page, LOCKED_WORKFLOW)}`);

    await expect(page.locator('#wj-live')).toBeVisible();
    // The status badge is marked with its own id so the polling loop
    // can target it. Presence of that id is a signal the JS hook points
    // at the right element.
    await expect(page.locator('#wj-status')).toHaveText('Running');
    const pause = runStepRow(page, LOCKED_PAUSE);
    await expect(pause.locator('[data-wjs-type-cell]')).toHaveText('Pause');
    await expect(pause).toHaveClass(/table-active/);
    // A poll runs right after the page loaded: the row stays one row.
    await page.waitForResponse((response) => response.url().includes('/workflow-job/status'));
    await expect(page.locator('#wj-steps-table tbody tr')).toHaveCount(1);
    await expect(pause.locator('[data-wjs-status-cell] .badge')).toHaveText('Running');
  });
});
