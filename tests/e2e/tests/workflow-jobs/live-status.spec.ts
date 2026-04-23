import { test, expect } from '@playwright/test';

// The workflow-job detail page polls /workflow-job/status while the
// workflow is still running so the status badge, per-step status, and
// finished-timestamp refresh in place without the operator hitting F5.
// The e2e-paused-workflow fixture leaves a workflow stuck at a pause
// step — perfect for asserting the Live badge and the JSON endpoint.

test.describe('Workflow job live status', () => {
  test('status endpoint returns a JSON snapshot of the job and its steps', async ({ page }) => {
    await page.goto('/workflow-job/index');
    const runningRow = page.locator('table.table tbody tr', { hasText: /Running|paused/i }).first();
    if (!(await runningRow.isVisible({ timeout: 3_000 }).catch(() => false))) {
      test.skip(true, 'No running workflow job seeded');
      return;
    }

    const link = runningRow.locator('a').first();
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    expect(match, 'workflow-job row must link to a detail page with id=N').not.toBeNull();
    const id = match![1];

    const resp = await page.request.get(`/workflow-job/status?id=${id}`);
    expect(resp.status()).toBe(200);
    const data = await resp.json();

    expect(data).toHaveProperty('id', Number(id));
    expect(data).toHaveProperty('status');
    expect(data).toHaveProperty('status_label');
    expect(data).toHaveProperty('status_css');
    expect(data).toHaveProperty('is_finished');
    expect(Array.isArray(data.steps)).toBe(true);

    if (data.steps.length > 0) {
      const first = data.steps[0];
      expect(first).toHaveProperty('workflow_step_id');
      expect(first).toHaveProperty('status');
      expect(first).toHaveProperty('status_label');
      expect(first).toHaveProperty('status_css');
      expect(first).toHaveProperty('is_current');
    }
  });

  test('detail page shows the Live badge while the workflow is unfinished', async ({ page }) => {
    await page.goto('/workflow-job/index');
    const runningRow = page.locator('table.table tbody tr', { hasText: /Running|paused/i }).first();
    if (!(await runningRow.isVisible({ timeout: 3_000 }).catch(() => false))) {
      test.skip(true, 'No running workflow job seeded');
      return;
    }
    await runningRow.locator('a').first().click();
    await page.waitForLoadState('domcontentloaded');

    await expect(page.locator('#wj-live')).toBeVisible();
    // The status badge is marked with its own id so the polling loop
    // can target it. Presence of that id is a signal the JS hook points
    // at the right element.
    await expect(page.locator('#wj-status')).toBeVisible();
  });
});
