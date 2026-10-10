import { test, expect } from '@playwright/test';
import { idWhere, indexRows } from '../../lib/scoping';

test.describe('Workflow Steps', () => {
  test('workflow detail shows steps', async ({ page }) => {
    await page.goto('/workflow-template/index');
    await page.locator('table.table tbody tr', { hasText: 'e2e-workflow' }).first().locator('a').first().click();
    await expect(page.locator('body')).toContainText(/step/i);
  });

  test('add step form exists', async ({ page }) => {
    await page.goto('/workflow-template/index');
    await page.locator('table.table tbody tr', { hasText: 'e2e-workflow' }).first().locator('a').first().click();
    await expect(page.locator('#add-step-form')).toBeVisible();
  });

  // Regression: the web app parses JSON bodies, and the step type rule
  // compared loosely, so step_type true passed as a type. The step was
  // stored as "1", and a run that reached it waited there until canceled.
  test('a forged JSON add-step request with step type true is refused', async ({ page }) => {
    const id = idWhere(await indexRows(page, '/workflow-template/index'), 0, 'e2e-workflow');
    await page.goto(`/workflow-template/view?id=${id}`);
    const steps = page.locator('#workflow-steps-table tbody tr');
    const before = await steps.count();
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');

    const response = await page.request.post(`/workflow-template/add-step?id=${id}`, {
      headers: { 'X-CSRF-Token': csrf ?? '' },
      data: { WorkflowStep: { name: 'e2e-json-true-type', step_type: true, step_order: 10 } },
    });

    // The action redirects to the workflow, which shows the refusal.
    expect(response.status()).toBe(200);
    expect(await response.text()).toContain('Step not added: Step Type must be a string.');
    await page.reload();
    await expect(steps).toHaveCount(before);
    await expect(page.locator('#workflow-steps-table')).not.toContainText('e2e-json-true-type');
  });
});
