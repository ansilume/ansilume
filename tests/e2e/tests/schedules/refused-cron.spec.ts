import { test, expect, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';
import { idWhere, indexRows } from '../../lib/scoping';
import { generateApiToken, revokeApiToken } from '../../lib/tokens';

/**
 * A schedule that an older version saved with a cron expression a save now
 * refuses runs as before, skipped runs included, until it is changed. The
 * schedules list marks it, its page and its edit form say why, a save that
 * keeps the expression shows the reason as the error of the cron
 * expression, a save that changes it as the reason says clears the
 * warning, and the API gives the reason as cron_expression_problem.
 *
 * Fixtures: commands/E2eRefusedCronSeeder.php. Rendering details and the
 * API: tests/integration/controllers/ScheduleCronProblemPageTest.php and
 * tests/integration/controllers/api/v1/SchedulesCronProblemTest.php.
 * Operator side: rbac.spec.ts.
 *
 * Review follow-up: only `php yii schedule/check` named such schedules.
 */

/** E2eRefusedCronSeeder::SCHEDULE, ::FIX_SCHEDULE and ::CRON. */
const REFUSED_SCHEDULE = 'e2e-refused-cron-schedule';
const FIX_SCHEDULE = 'e2e-refused-cron-fix-schedule';
const REFUSED_CRON = '0 18,6 * * *';

/** Schedule::CRON_REFUSED_NOTE, Schedule::CRON_REFUSED_BADGE and Schedule::CRON_AS_WRITTEN. */
const NOTE = 'An older version saved this cron expression, which a save now refuses. '
  + 'The schedule runs as before until its expression is changed as this says:';
const BADGE = 'Change needed';
const AS_WRITTEN = 'As written:';

/** CronExpressionRules::LIST_ORDER of the expression, and the expression it suggests. */
const REASON = 'Invalid cron expression: the scheduler would skip some values of the list 18,6 of the hour field. '
  + 'Write its values in ascending order, as in 6,18.';
const SUGGESTED_CRON = '0 6,18 * * *';

/** The row of schedule `name` in the schedules list, which fits on one page. */
function listRow(page: Page, name: string) {
  return page.locator('#schedule-table tbody tr', { has: page.getByRole('link', { name, exact: true }) });
}

test.describe('Schedules whose stored cron expression a save refuses', () => {
  test('the list marks them, with the reason as the title of the mark', async ({ page }) => {
    await page.goto('/schedule/index');

    const badge = listRow(page, REFUSED_SCHEDULE).getByTestId('schedule-cron-refused');
    await expect(badge).toHaveText(BADGE);
    await expect(badge).toHaveAttribute('title', REASON);
    await expect(listRow(page, 'e2e-schedule').getByTestId('schedule-cron-refused')).toHaveCount(0);
  });

  test('the page and the edit form say why, and a save that keeps the expression shows the reason as its error', async ({ page }) => {
    const id = idWhere(await indexRows(page, '/schedule/index'), 1, REFUSED_SCHEDULE);

    await page.goto(`/schedule/view?id=${id}`);
    await expect(page.getByTestId('schedule-cron-problem')).toContainText(NOTE);
    await expect(page.getByTestId('schedule-cron-problem-reason')).toHaveText(REASON);
    // Review finding: the description read as the times it runs, beside the warning that it does not.
    await expect(page.locator('#cron-human')).toHaveText(new RegExp(`^\\(${AS_WRITTEN} At `));

    await page.goto(`/schedule/update?id=${id}`);
    await expect(page.getByTestId('schedule-form-cron-problem')).toContainText(NOTE);
    await expect(page.getByTestId('schedule-form-cron-problem')).toContainText(REASON);
    // Review finding: a green check under the field the warning calls refused.
    await expect(page.locator('#cron-preview')).toContainText(AS_WRITTEN);
    await expect(page.locator('#cron-preview')).not.toContainText('✓');
    await submitForm(page);

    await expect(page.locator('.field-cron-input .help-block')).toHaveText(REASON);
    await expect(page.getByTestId('schedule-form-cron-problem')).toHaveCount(0);
    await expect(page.locator('#cron-preview')).toContainText(AS_WRITTEN);
    await page.goto(`/schedule/view?id=${id}`);
    await expect(page.locator('#cron-expr')).toHaveText(REFUSED_CRON);
    await expect(page.getByTestId('schedule-cron-problem-reason')).toHaveText(REASON);
  });

  test('a save that changes the expression as the reason says clears the warning', async ({ page }) => {
    const id = idWhere(await indexRows(page, '/schedule/index'), 1, FIX_SCHEDULE);

    await page.goto(`/schedule/update?id=${id}`);
    await expect(page.getByTestId('schedule-form-cron-problem')).toContainText(REASON);
    await page.locator('#cron-input').fill(SUGGESTED_CRON);
    await expect(page.locator('#cron-preview')).toContainText('✓');
    await expect(page.locator('#cron-preview')).not.toContainText(AS_WRITTEN);
    await submitForm(page);

    await expectFlash(page, 'success', `Schedule "${FIX_SCHEDULE}" updated.`);
    await expect(page.locator('#cron-expr')).toHaveText(SUGGESTED_CRON);
    await expect(page.getByTestId('schedule-cron-problem')).toHaveCount(0);
    await expect(page.locator('#cron-human')).toHaveText(/^\(At /);
    await page.goto('/schedule/index');
    await expect(listRow(page, FIX_SCHEDULE).getByTestId('schedule-cron-refused')).toHaveCount(0);
  });

  test('the API gives the reason as cron_expression_problem, and an update that keeps the expression answers 422', async ({ page, request, baseURL }) => {
    const id = idWhere(await indexRows(page, '/schedule/index'), 1, REFUSED_SCHEDULE);
    const tokenName = `e2e-refused-cron-${Date.now()}`;
    const token = await generateApiToken(page, tokenName);
    try {
      const headers = { Authorization: `Bearer ${token}` };
      const shown = await request.get(`${baseURL}/api/v1/schedules/${id}`, { headers });
      expect(shown.status()).toBe(200);
      const data = ((await shown.json()) as { data: { cron_expression: string; cron_expression_problem: string | null } }).data;
      expect(data.cron_expression).toBe(REFUSED_CRON);
      expect(data.cron_expression_problem).toBe(REASON);

      const renamed = await request.put(`${baseURL}/api/v1/schedules/${id}`, { headers, data: { name: REFUSED_SCHEDULE } });
      expect(renamed.status()).toBe(422);
      expect(((await renamed.json()) as { error: { message: string } }).error.message).toBe(REASON);
    } finally {
      await revokeApiToken(page, tokenName);
    }
  });
});
