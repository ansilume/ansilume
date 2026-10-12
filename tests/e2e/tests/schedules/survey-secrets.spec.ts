import { test, expect, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';
import { indexRows } from '../../lib/scoping';
import {
  PASSWORD_FIELD,
  PLACEHOLDER,
  SECRET_TEMPLATE,
  TEXT_FIELD,
  expectNotInPage,
  freshAnswer,
  shownExtraVars,
} from '../../lib/survey-secrets';

/**
 * Password answers in schedule extra vars, as e2e-admin. A value whose key is
 * a password field of the schedule's job template is stored encrypted; the
 * schedule page and the edit form show $encrypted$, and submitting the
 * placeholder unchanged keeps the stored answer. e2e-secret-template
 * (commands/E2eSurveySecretSeeder.php) has the password field db_password;
 * no runner serves its group, so fired jobs stay queued.
 * Operator side: rbac.spec.ts.
 */

/** Replaces the content of the extra vars editor (CodeMirror over the hidden textarea). */
async function setExtraVars(page: Page, json: string): Promise<void> {
  await page.locator('.extra-vars-editor .CodeMirror').first().evaluate((element, value) => {
    (element as unknown as { CodeMirror: { setValue(text: string): void } }).CodeMirror.setValue(value);
  }, json);
}

/** The extra vars the schedule form submits. */
async function submittedExtraVars(page: Page): Promise<Record<string, unknown>> {
  return JSON.parse(await page.locator('textarea[data-extra-vars-editor]').inputValue()) as Record<string, unknown>;
}

/** The extra vars the schedule page shows. */
async function scheduleExtraVars(page: Page): Promise<Record<string, unknown>> {
  return JSON.parse((await page.getByTestId('schedule-extra-vars').textContent()) ?? '') as Record<string, unknown>;
}

/** Fills the new schedule form for e2e-secret-template, disabled, with these extra vars. */
async function fillNewSchedule(page: Page, name: string, extraVars: Record<string, string>): Promise<void> {
  await page.goto('/schedule/create');
  await page.locator('#schedule-name').fill(name);
  // The options read "<name> (<id>)".
  const template = page.locator('#schedule-job_template_id');
  const value = await template.locator('option').evaluateAll(
    (options, wanted) => options.find((option) => (option.textContent ?? '').trim().startsWith(`${wanted} (`))?.getAttribute('value') ?? '',
    SECRET_TEMPLATE,
  );
  expect(value, `the schedule form offers ${SECRET_TEMPLATE}`).not.toBe('');
  await template.selectOption(value);
  await page.locator('#cron-input').fill('0 0 1 1 *');
  await page.locator('#schedule-enabled').uncheck();
  await setExtraVars(page, JSON.stringify(extraVars));
}

test.describe('Schedules with password survey answers', () => {
  test('a schedule stores its password answer encrypted and keeps it through an unchanged edit', async ({ page, request, baseURL }) => {
    const name = `e2e-secret-schedule-${Date.now()}`;
    const answer = freshAnswer('schedule');
    await fillNewSchedule(page, name, { [TEXT_FIELD]: 'prod', [PASSWORD_FIELD]: answer });
    await submitForm(page);
    await expectFlash(page, 'success', `Schedule "${name}" created.`);
    const id = new URL(page.url()).searchParams.get('id') ?? '';

    // Regression: the schedule page showed the answer in plain text.
    await expectNotInPage(page, answer);
    expect(await scheduleExtraVars(page)).toEqual({ [TEXT_FIELD]: 'prod', [PASSWORD_FIELD]: PLACEHOLDER });
    await expect(page.locator('body')).toContainText('Password answers are stored encrypted and show as $encrypted$.');

    // The edit form shows the placeholder; saved unchanged, it keeps the answer.
    await page.goto(`/schedule/update?id=${id}`);
    await expectNotInPage(page, answer);
    await expect(page.locator('.field-schedule-extra_vars')).toContainText('leave it to keep the stored answer');
    await expect(page.locator('.extra-vars-editor .CodeMirror').first()).toContainText(PLACEHOLDER);
    expect(await submittedExtraVars(page)).toEqual({ [TEXT_FIELD]: 'prod', [PASSWORD_FIELD]: PLACEHOLDER });
    await submitForm(page);
    await expectFlash(page, 'success', `Schedule "${name}" updated.`);
    expect(await scheduleExtraVars(page)).toEqual({ [TEXT_FIELD]: 'prod', [PASSWORD_FIELD]: PLACEHOLDER });

    // The run gets the kept answer: without it the launch would refuse the placeholder.
    const fired = await request.post(`${baseURL}/e2e/fire-schedule`, { params: { name } });
    expect(fired.status()).toBe(200);
    const { latest_job_id: jobId } = (await fired.json()) as { latest_job_id: number };
    await page.goto(`/job/view?id=${jobId}`);
    await expect(page.locator('body')).toContainText(SECRET_TEMPLATE);
    expect(await shownExtraVars(page)).toEqual({ [TEXT_FIELD]: 'prod', [PASSWORD_FIELD]: PLACEHOLDER });
    await expectNotInPage(page, answer);

    // Removing the key drops the answer.
    await page.goto(`/schedule/update?id=${id}`);
    await setExtraVars(page, JSON.stringify({ [TEXT_FIELD]: 'prod' }));
    await submitForm(page);
    await expectFlash(page, 'success', `Schedule "${name}" updated.`);
    expect(await scheduleExtraVars(page)).toEqual({ [TEXT_FIELD]: 'prod' });

    // The firing enabled it: delete it rather than leave it to the teardown.
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#page-content form[action*="/delete"] button[type="submit"]').click();
    await expectFlash(page, 'success', `Schedule "${name}" deleted.`);
  });

  test('the placeholder without a stored answer is refused with a visible error', async ({ page }) => {
    const name = `e2e-secret-schedule-refused-${Date.now()}`;
    await fillNewSchedule(page, name, { [TEXT_FIELD]: 'prod', [PASSWORD_FIELD]: PLACEHOLDER });
    await submitForm(page);

    await expect(page.locator('.field-schedule-extra_vars .help-block'))
      .toHaveText(`"${PASSWORD_FIELD}" has no stored value; enter the real value.`);
    const names = (await indexRows(page, '/schedule/index')).map((row) => row.cells[1]);
    expect(names).not.toContain(name);
  });
});
