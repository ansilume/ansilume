import { test, expect } from '@playwright/test';

test.describe('Workflow Job Resume', () => {
  test('admin resumes a paused workflow job', async ({ page }) => {
    // Find the paused workflow job via the workflow-jobs list page.
    await page.goto('/workflow-job/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-paused-workflow' }).first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No paused workflow fixture seeded');
      return;
    }

    await row.locator('a').first().click();
    await expect(page).toHaveURL(/workflow-job\/view/);

    // The Resume button is rendered as:
    //   <form action="/workflow-job/resume?id=N" method="post">
    //     <button type="submit" class="btn btn-success btn-sm">Resume</button>
    //   </form>
    // It is only shown when hasPausedStep && user has workflow.launch permission.
    const resume = page.locator('form[action*="/workflow-job/resume"] button[type="submit"]');
    await expect(resume).toBeVisible({ timeout: 5_000 });
    await expect(resume).toBeEnabled();

    // The form has an onsubmit confirm() dialog — accept it.
    page.once('dialog', (dialog) => dialog.accept());
    await resume.click();

    // Success flash should appear after the POST redirect.
    await expect(page.locator('.alert-success').first()).toBeVisible({ timeout: 5_000 });

    // The pause step row (matched by step name) should no longer show Running.
    const stepsTable = page.locator('table.table-bordered').last();
    const pauseRow = stepsTable.locator('tr', { hasText: 'Wait for manual resume' });
    if (await pauseRow.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await expect(pauseRow).not.toContainText(/Running/i);
    }
  });
});
