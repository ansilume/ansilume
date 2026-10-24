import { test, expect, type Page } from '@playwright/test';
import { expectFlash, fillForm, submitForm, getTableRowCount, deleteByRowText } from '../../lib/helpers';
import { forgePost, indexRows } from '../../lib/scoping';
import { PAGE_TITLE, CONFIRM_OK } from '../../lib/selectors';

test.describe('Schedules CRUD', () => {
  test('index page lists schedules', async ({ page }) => {
    await page.goto('/schedule/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/schedule/i);
  });

  test('view schedule detail', async ({ page }) => {
    await page.goto('/schedule/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/schedule|cron|template/i);
    }
  });

  test('create schedule', async ({ page }) => {
    await page.goto('/schedule/create');
    await page.locator('#schedule-name').fill('e2e-crud-schedule');
    await page.locator('#cron-input').fill('30 2 * * *');
    const templateSelect = page.locator('#schedule-job_template_id');
    if (await templateSelect.isVisible()) {
      const options = await templateSelect.locator('option:not([value=""])').all();
      if (options.length > 0) {
        const value = await options[0].getAttribute('value');
        if (value) await templateSelect.selectOption(value);
      }
    }
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('update schedule', async ({ page }) => {
    await page.goto('/schedule/index');
    const row = page.locator('table.table tbody tr', { hasText: 'e2e-crud-schedule' });
    await row.locator('a:has-text("Update"), a:has-text("Edit"), a[title="Update"]').first().click();
    await page.locator('#cron-input').fill('0 3 * * *');
    await submitForm(page);
    await expectFlash(page, 'success');
  });

  test('delete schedule', async ({ page }) => {
    await deleteByRowText(page, '/schedule/index', 'e2e-crud-schedule');
    await expectFlash(page, 'success');
  });

  // Regression: the form computes the next run before it validates, and
  // passed a timezone that is not text to CronExpression, which only takes a
  // string: a crafted Schedule[timezone][]=x answered 500.
  test('create with a timezone that is not text shows the error instead of failing', async ({ page }) => {
    const name = `e2e-schedule-tz-list-${Date.now()}`;
    await page.goto('/schedule/create');
    const templateId = await page.locator('#schedule-job_template_id option:not([value=""])').first().getAttribute('value');
    expect(templateId, 'a job template to schedule').toBeTruthy();

    await page.goto('/schedule/index');
    const response = page.waitForResponse((r) => new URL(r.url()).pathname === '/schedule/create' && r.request().method() === 'POST');
    await forgePost(page, '/schedule/create', {
      'Schedule[name]': name,
      'Schedule[job_template_id]': templateId ?? '',
      'Schedule[cron_expression]': '30 2 * * *',
      'Schedule[timezone][]': 'x',
      'Schedule[enabled]': '1',
    }, /\/schedule\/create/);

    expect((await response).status()).toBe(200);
    await expect(page.locator('.field-schedule-timezone .help-block')).toHaveText('Timezone must be a string.');
    const names = (await indexRows(page, '/schedule/index')).map((row) => row.cells[1]);
    expect(names).not.toContain(name);
  });

  // Regression: DateTimeZone throws a ValueError for a timezone with a NUL
  // byte, which neither the timezone rule nor the next-run computation caught:
  // the form answered 500. Without the NUL byte the value would be valid.
  test('create with a NUL byte in the timezone shows the error instead of failing', async ({ page }) => {
    const name = `e2e-schedule-tz-nul-${Date.now()}`;
    const templateId = await scheduleableTemplateId(page);

    await page.goto('/schedule/index');
    const response = page.waitForResponse((r) => new URL(r.url()).pathname === '/schedule/create' && r.request().method() === 'POST');
    await forgePost(page, '/schedule/create', {
      'Schedule[name]': name,
      'Schedule[job_template_id]': templateId,
      'Schedule[cron_expression]': '30 2 * * *',
      'Schedule[timezone]': 'UTC\u0000',
      'Schedule[enabled]': '1',
    }, /\/schedule\/create/);

    expect((await response).status()).toBe(200);
    await expect(page.locator('.field-schedule-timezone .help-block')).toHaveText('Invalid timezone identifier.');
    const names = (await indexRows(page, '/schedule/index')).map((row) => row.cells[1]);
    expect(names).not.toContain(name);
  });

  // Regression: the next run was computed before the 64-character rule ran,
  // and the cron parser's cost grows quadratically with the length: this
  // 300 KB expression held a PHP worker for about 35 s before the form
  // refused it.
  test('create with a very long cron expression is refused at once', async ({ page }) => {
    const name = `e2e-schedule-long-cron-${Date.now()}`;
    const templateId = await scheduleableTemplateId(page);

    await page.goto('/schedule/index');
    const response = page.waitForResponse((r) => new URL(r.url()).pathname === '/schedule/create' && r.request().method() === 'POST');
    const started = Date.now();
    await forgePost(page, '/schedule/create', {
      'Schedule[name]': name,
      'Schedule[job_template_id]': templateId,
      'Schedule[cron_expression]': `${Array(100000).fill('59').join(',')} 23 31 12 0`,
      'Schedule[timezone]': 'UTC',
      'Schedule[enabled]': '1',
    }, /\/schedule\/create/);

    expect((await response).status()).toBe(200);
    expect(Date.now() - started, 'the long expression must not reach the cron parser').toBeLessThan(10000);
    await expect(page.locator('.field-cron-input .help-block')).toHaveText('Cron Expression should contain at most 64 characters.');
    const names = (await indexRows(page, '/schedule/index')).map((row) => row.cells[1]);
    expect(names).not.toContain(name);
  });

  // Regression: the cron library accepts a negative step, then loops until
  // PHP runs out of memory, or throws a ValueError, when it computes the next
  // run, which the form did before validating: a 500, or a stored schedule
  // that stopped every schedule run after it.
  test('create with a negative cron step shows the cron error', async ({ page }) => {
    const name = `e2e-schedule-negative-step-${Date.now()}`;
    const templateId = await scheduleableTemplateId(page);
    await page.locator('#schedule-name').fill(name);
    await page.locator('#schedule-job_template_id').selectOption(templateId);
    await page.locator('#cron-input').fill('*/-1 * * * *');
    const response = page.waitForResponse((r) => new URL(r.url()).pathname === '/schedule/create' && r.request().method() === 'POST');
    await submitForm(page);

    expect((await response).status()).toBe(200);
    await expect(page.locator('.field-cron-input .help-block')).toHaveText('Invalid cron expression. Use standard 5-field format: min hour dom mon dow');
    const names = (await indexRows(page, '/schedule/index')).map((row) => row.cells[1]);
    expect(names).not.toContain(name);
  });

  // Regression: the cron library accepts expressions that it then never
  // runs, or runs at other times than they say, and the form saved them:
  // "5-1 * * * *" never ran (app\components\CronExpressionRules). The form
  // names the reason and saves nothing.
  const refused: Array<[string, string]> = [
    ['5-1 * * * *', 'Invalid cron expression: the range 5-1 of the minute field runs backwards. Write the smaller value first; ranges do not wrap around, so split one that would, as in 0-1,5-59.'],
    ['0 18,6 * * *', 'Invalid cron expression: the scheduler would skip some values of the list 18,6 of the hour field. Write its values in ascending order, as in 6,18.'],
    // Review findings: the suggestion was too long to save (single numbers),
    // or there was none although one fits (*/6,*/4,3), a split lost the
    // range's step (50-10/5), or the advice changed the days (5-0, Friday to
    // Sunday, as 0-5).
    ['*/2,5 * * * *', 'Invalid cron expression: the scheduler would skip some values of the list */2,5 of the minute field. Write its values in ascending order, as in 0,2,4,5,6-58/2.'],
    ['*/6,*/4,3 * * * *', 'Invalid cron expression: the scheduler would skip some values of the list */6,*/4,3 of the minute field. Write its values in ascending order, as in 0,3,4,6,8,12,16-20/2,24,28-32/2,36,40-44/2,48,52-56/2.'],
    ['50-10/5 * * * *', 'Invalid cron expression: the range 50-10/5 of the minute field runs backwards. Write the smaller value first; ranges do not wrap around, so split one that would, as in 0-10/5,50-59/5.'],
    ['0 0 * * 5-0', 'Invalid cron expression: the range 5-0 of the day-of-week field ends with Sunday as 0, a smaller value than its start. Write Sunday as 7 at the end of a range, as in 5-7.'],
    ['0 0 * * SAT-SUN', 'Invalid cron expression: the range SAT-SUN of the day-of-week field ends with SUN, so the scheduler never runs it on a Sunday. Write Sunday as 7 at the end of a range, as in SAT-7.'],
    ['*/90 * * * *', 'Invalid cron expression: the step /90 of the minute field is too large. Use a step smaller than 59, or list the values.'],
    ['0 0 * * MON-FRI/2', 'Invalid cron expression: MON-FRI/2 of the day-of-week field has a step after names. Write the range with numbers, as in 1-5/2.'],
    ['0 0 31 2 *', 'Invalid cron expression: the scheduler finds no date that matches it, so the schedule would never run. A day of the month must exist in the months it names; the 30th of February never does.'],
  ];
  for (const [cron, reason] of refused) {
    test(`regression: create with "${cron}" shows why it would not run as it says`, async ({ page }) => {
      const name = `e2e-schedule-cron-rule-${Date.now()}`;
      const templateId = await scheduleableTemplateId(page);
      await page.locator('#schedule-name').fill(name);
      await page.locator('#schedule-job_template_id').selectOption(templateId);
      await page.locator('#cron-input').fill(cron);
      const response = page.waitForResponse((r) => new URL(r.url()).pathname === '/schedule/create' && r.request().method() === 'POST');
      await submitForm(page);

      expect((await response).status()).toBe(200);
      await expect(page.locator('.field-cron-input .help-block')).toHaveText(reason);
      const names = (await indexRows(page, '/schedule/index')).map((row) => row.cells[1]);
      expect(names).not.toContain(name);
    });
  }

  test('create with a leap-day cron expression is saved', async ({ page }) => {
    const name = `e2e-schedule-leap-day-${Date.now()}`;
    const templateId = await scheduleableTemplateId(page);
    await page.locator('#schedule-name').fill(name);
    await page.locator('#schedule-job_template_id').selectOption(templateId);
    await page.locator('#cron-input').fill('0 0 29 2 *');
    await submitForm(page);

    await expectFlash(page, 'success');
    await deleteByRowText(page, '/schedule/index', name);
  });

  // What a refusal suggests saves: the list in an order the scheduler walks,
  // Sunday as 7 at the end of a range; an asterisk in an hour list, which the
  // scheduler runs every hour, as cron does, was refused before.
  for (const cron of ['0,2,4,5,6-58/2 * * * *', '0,3,4,6,8,12,16-20/2,24,28-32/2,36,40-44/2,48,52-56/2 * * * *', '0-10/5,50-59/5 * * * *', '0 0 * * 5-7', '0 *,8 * * *']) {
    test(`create with "${cron}" is saved`, async ({ page }) => {
      const name = `e2e-schedule-suggested-${Date.now()}`;
      const templateId = await scheduleableTemplateId(page);
      await page.locator('#schedule-name').fill(name);
      await page.locator('#schedule-job_template_id').selectOption(templateId);
      await page.locator('#cron-input').fill(cron);
      await submitForm(page);

      await expectFlash(page, 'success');
      await deleteByRowText(page, '/schedule/index', name);
    });
  }
});

/** The ID of the first job template the schedule form offers. */
async function scheduleableTemplateId(page: Page): Promise<string> {
  await page.goto('/schedule/create');
  const templateId = await page.locator('#schedule-job_template_id option:not([value=""])').first().getAttribute('value');
  expect(templateId, 'a job template to schedule').toBeTruthy();
  return templateId ?? '';
}
