import { test, expect } from '@playwright/test';

// Regression: when the "pause" step type is selected in the step editor,
// the on_failure and on_always fields must not be visible (they're dead
// code for pause steps — a pause only resumes as success), and the
// on_success field should expand to full width with a clearer label.
// The job/approval types should keep all three routing fields visible.

test.describe('Workflow step editor — pause hides irrelevant routing fields', () => {
  test('selecting pause hides on_failure and on_always, shows pause explainer', async ({ page }) => {
    // Target the seeded e2e-workflow template by name so we don't depend on
    // ordering of the template index.
    await page.goto('/workflow-template/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-workflow' }).first();
    if (!(await row.isVisible({ timeout: 5_000 }).catch(() => false))) {
      test.skip(true, 'e2e-workflow template not seeded');
      return;
    }
    await row.locator('a').first().click();
    await page.waitForLoadState('domcontentloaded');

    const typeSelect = page.locator('#ws-step-type').first();
    await expect(typeSelect, 'Step editor must render on the seeded workflow template').toBeVisible({
      timeout: 5_000,
    });

    // Default state (job) — all three routing fields visible, pause help hidden.
    await typeSelect.selectOption('job');
    await expect(page.locator('#ws-route-success')).toBeVisible();
    await expect(page.locator('#ws-route-failure')).toBeVisible();
    await expect(page.locator('#ws-route-always')).toBeVisible();
    await expect(page.locator('#ws-routing-help-job')).toBeVisible();
    await expect(page.locator('#ws-routing-help-pause')).toBeHidden();

    // Switch to pause — failure/always hidden, success visible, pause help shown.
    await typeSelect.selectOption('pause');
    await expect(page.locator('#ws-route-success')).toBeVisible();
    await expect(page.locator('#ws-route-failure')).toBeHidden();
    await expect(page.locator('#ws-route-always')).toBeHidden();
    await expect(page.locator('#ws-routing-help-pause')).toBeVisible();

    // Label swapped to "Next step →"
    const successLabel = page.locator('#ws-route-success label').first();
    await expect(successLabel).toContainText(/Next step/i);

    // Switch back to approval — all three fields visible again, approval help shown.
    await typeSelect.selectOption('approval');
    await expect(page.locator('#ws-route-failure')).toBeVisible();
    await expect(page.locator('#ws-route-always')).toBeVisible();
    await expect(page.locator('#ws-routing-help-approval')).toBeVisible();
    await expect(successLabel).toContainText(/On Success/i);
  });
});
