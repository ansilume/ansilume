import { test, expect } from '@playwright/test';

test.describe('Job Template Survey Launch', () => {
  async function gotoSurveyTemplate(page: import('@playwright/test').Page): Promise<boolean> {
    await page.goto('/job-template/index');
    const row = page.locator('table.table tbody tr').filter({
      hasText: 'e2e-survey-template',
    }).first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'e2e-survey-template not seeded');
      return false;
    }
    const link = row.locator('a').first();
    const href = await link.getAttribute('href');
    if (!href) {
      test.skip(true, 'Template row has no link');
      return false;
    }
    await page.goto(href);
    return true;
  }

  async function openLaunchForm(page: import('@playwright/test').Page): Promise<void> {
    const launch = page.locator('a:has-text("Launch"), button:has-text("Launch")').first();
    await expect(launch).toBeVisible({ timeout: 5_000 });
    await launch.click();
  }

  test('admin launches a template with survey fields', async ({ page }) => {
    if (!(await gotoSurveyTemplate(page))) return;
    await openLaunchForm(page);

    // Survey fields use name="survey[<fieldname>]" (Yii form helper convention).
    // The [name*="target_env"] attribute selector matches that pattern.
    // Boolean fields render as a hidden "0" input plus a visible checkbox
    // that share the same name, so we must exclude type="hidden" for dry_run.
    const targetEnv = page.locator('[name*="target_env"]').first();
    const dryRun = page.locator('input[name*="dry_run"][type="checkbox"]');
    const logLevel = page.locator('[name*="log_level"]').first();

    await expect(targetEnv).toBeVisible({ timeout: 5_000 });
    await expect(dryRun).toBeVisible();
    await expect(logLevel).toBeVisible();

    await targetEnv.fill('production');
    await dryRun.check();
    await logLevel.selectOption('debug');

    // Scope the submit to #launch-form — a generic `form button[type="submit"]`
    // would match the layout's logout form first and POST to /logout instead.
    const submit = page.locator('#launch-form button[type="submit"]');
    await submit.click();

    // Should land on the new job's view page.
    await expect(page).toHaveURL(/\/job\/view\?id=\d+/, { timeout: 10_000 });

    // The job view renders extra_vars in a <pre> block when present.
    // Survey values are merged into extra_vars by JobLaunchService, so
    // both chosen values should appear in that panel.
    await expect(page.locator('body')).toContainText('production', { timeout: 5_000 });
    await expect(page.locator('body')).toContainText('debug');
  });

  test('survey launch rejects missing required field', async ({ page }) => {
    if (!(await gotoSurveyTemplate(page))) return;
    await openLaunchForm(page);

    const targetEnv = page.locator('[name*="target_env"]').first();
    await expect(targetEnv).toBeVisible({ timeout: 5_000 });
    await targetEnv.fill('');

    const submit = page.locator('#launch-form button[type="submit"]');
    await submit.click();

    // Either the HTML5 required attribute blocks submission (URL stays on
    // launch) or the server returns a validation error. Either way, we must
    // not reach a job view.
    await expect(page).not.toHaveURL(/\/job\/view\?id=\d+/, { timeout: 3_000 });
  });
});
