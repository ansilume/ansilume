import { test, expect, Page } from '@playwright/test';
import { PLACEHOLDER, expectNotInPage, freshAnswer, logPoll, shownExtraVars } from '../../lib/survey-secrets';
import { openJobTemplate } from '../../lib/triggers';

test.describe('Job Template Survey Launch', () => {
  // Pages through the template list: other seeded templates push this one
  // off the first page, where the lookup used to stop and skip every test.
  async function gotoSurveyTemplate(page: Page): Promise<void> {
    await openJobTemplate(page, 'e2e-survey-template');
  }

  async function openLaunchForm(page: Page): Promise<void> {
    const launch = page.locator('a:has-text("Launch"), button:has-text("Launch")').first();
    await expect(launch).toBeVisible({ timeout: 5_000 });
    await launch.click();
  }

  /** The job numbers ("#12") on the first page of the template's job list, newest first. */
  async function jobNumbersOf(page: Page, templateId: string): Promise<string[]> {
    await page.goto(`/job/index?template_id=${templateId}`);
    return page.locator('#page-content table.table tbody tr td:first-child a').allTextContents();
  }

  test('admin launches a template with survey fields', async ({ page }) => {
    await gotoSurveyTemplate(page);
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

  // The survey's password field (optional, so the test above launches
  // without it): its answer is stored only encrypted, and the job page and
  // /job/log-poll show $encrypted$ in its place.
  test('admin launches with a password answer, which the job page and log-poll show only as $encrypted$', async ({ page }) => {
    await gotoSurveyTemplate(page);
    await openLaunchForm(page);

    const password = page.locator('#survey-db_password');
    await expect(password).toHaveAttribute('type', 'password');
    await expect(password).toHaveValue('');
    await expect(password).not.toHaveAttribute('required');

    const answer = freshAnswer('survey');
    await page.locator('[name*="target_env"]').first().fill('production');
    await password.fill(answer);
    await page.locator('#launch-form button[type="submit"]').click();
    await expect(page).toHaveURL(/\/job\/view\?id=\d+/, { timeout: 10_000 });
    const jobId = new URL(page.url()).searchParams.get('id') ?? '';

    expect(await shownExtraVars(page)).toEqual(expect.objectContaining({
      target_env: 'production',
      db_password: PLACEHOLDER,
    }));
    await expectNotInPage(page, answer);
    expect((await logPoll(page, jobId)).raw).not.toContain(answer);
  });

  // Regression: a number JSON cannot hold (1e999, which PHP reads as
  // infinity) in the launch extra vars was refused as "not valid UTF-8", and
  // as a password answer the API and triggers answered 500. Messages from
  // services/JobLaunchSecrets.php and SurveySecrets::unencodable().
  // Regression too: the Extra Vars editor wrote the text back with
  // JSON.stringify, which turned 1e999 into null, so the form launched with
  // null. It now posts the text as typed, and the launch refuses it.
  for (const { key, message } of [
    { key: 'db_password', message: 'The password answers cannot be stored: they contain a number too large for JSON.' },
    { key: 'timeout_s', message: 'The extra vars cannot be stored: they contain a number too large for JSON.' },
  ]) {
    test(`a launch whose extra vars set ${key} to a number too large for JSON is refused with the reason and launches nothing`, async ({ page }) => {
      const templateId = await openJobTemplate(page, 'e2e-survey-template');
      const before = await jobNumbersOf(page, templateId);

      await page.goto(`/job-template/launch?id=${templateId}`);
      await page.locator('[data-bs-target="#advanced-overrides"]').click();
      await expect(page.locator('#advanced-overrides.collapse.show')).toBeVisible();
      // Typed into the Extra Vars editor (CodeMirror over the textarea), as a paste.
      const typed = `{"${key}": 1e999}`;
      await page.locator('#advanced-overrides .CodeMirror').click();
      await page.keyboard.press('Control+A');
      await page.keyboard.insertText(typed);
      await expect(page.locator('textarea[name="overrides[extra_vars]"]'), 'posted as typed').toHaveValue(typed);
      await Promise.all([
        page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/job-template/launch')),
        page.locator('#launch-form button[type="submit"]').click(),
      ]);

      await expect(page.locator('.alert-danger', { hasText: 'Launch failed:' })).toContainText(`Launch failed: ${message}`);
      await expect(page).toHaveURL(new RegExp(`/job-template/launch\\?id=${templateId}$`));
      expect(await jobNumbersOf(page, templateId), 'no job was created').toEqual(before);
    });
  }

  // e2e-unusable-name-template (commands/E2eUnusablePasswordNameSeeder.php), saved before the
  // survey rules: its password field's name, contraseña_db, cannot carry an answer. Every launch
  // is refused until the field is renamed, with the name as messages quote it
  // (SurveyDefinition::quote(): every character outside printable ASCII escaped), and nothing
  // is launched.
  test('a launch of a template whose password field name cannot carry an answer is refused, naming the field escaped', async ({ page }) => {
    const templateId = await openJobTemplate(page, 'e2e-unusable-name-template');
    const before = await jobNumbersOf(page, templateId);
    const answer = freshAnswer('unusable-name');

    await page.goto(`/job-template/launch?id=${templateId}`);
    const password = page.locator('input[name="survey[contraseña_db]"]');
    await expect(password).toHaveAttribute('type', 'password');
    await password.fill(answer);
    await Promise.all([
      page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/job-template/launch')),
      page.locator('#launch-form button[type="submit"]').click(),
    ]);

    await expect(page.locator('.alert-danger', { hasText: 'Launch failed:' })).toContainText(
      'Launch failed: Survey field "contrase\\u00f1a_db" has a name that cannot be used for a password answer; '
      + 'rename it in the survey before launching.',
    );
    await expect(page).toHaveURL(new RegExp(`/job-template/launch\\?id=${templateId}$`));
    await expectNotInPage(page, answer);
    expect(await jobNumbersOf(page, templateId), 'no job was created').toEqual(before);
  });

  test('survey launch rejects missing required field', async ({ page }) => {
    await gotoSurveyTemplate(page);
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
