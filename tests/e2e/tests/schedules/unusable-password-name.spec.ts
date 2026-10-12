import { test, expect, Page } from '@playwright/test';
import { expectFlash, submitForm } from '../../lib/helpers';
import { idWhere, indexRows } from '../../lib/scoping';
import { PLACEHOLDER, expectNotInPage } from '../../lib/survey-secrets';

/**
 * A schedule of a job template saved before the survey rules whose password
 * field, contraseña_db, has a name that cannot carry an answer, as e2e-admin.
 * The answer that 2.8.1 stored under that exact name in plaintext shows only
 * as $encrypted$ on the schedule page and in the edit form, and saving the
 * form unchanged keeps it. Every password field name is a secret key; the
 * name only keeps the template from being launched (survey-launch.spec.ts).
 *
 * Fixtures: commands/E2eUnusablePasswordNameSeeder.php. That the unchanged
 * save seals the answer, rather than dropping it or storing the placeholder
 * as text: tests/integration/controllers/ScheduleControllerEveryNameTest.php.
 * Operator side: rbac.spec.ts.
 *
 * Regression (R2V2-1): once every password field name outside printable
 * ASCII could no longer carry an answer, letters included, such names were no
 * secret keys either, and the schedule page and the edit form showed the
 * answer in plaintext.
 */

/** E2eUnusablePasswordNameSeeder::SCHEDULE, ::PASSWORD_FIELD and ::PLAIN_ANSWER. */
const UNUSABLE_NAME_SCHEDULE = 'e2e-unusable-name-schedule';
const UNUSABLE_PASSWORD_FIELD = 'contraseña_db';
const UNUSABLE_NAME_ANSWER = 'e2e-unusable-name-answer-Tz58';

/** The extra vars the schedule page shows. */
async function shownExtraVars(page: Page): Promise<Record<string, unknown>> {
  return JSON.parse((await page.getByTestId('schedule-extra-vars').textContent()) ?? '') as Record<string, unknown>;
}

/** The extra vars the edit form would submit. */
async function formExtraVars(page: Page): Promise<Record<string, unknown>> {
  return JSON.parse(await page.locator('textarea[data-extra-vars-editor]').inputValue()) as Record<string, unknown>;
}

test.describe('A schedule with an answer under a password field name that cannot carry one', () => {
  test('the page and the edit form show the answer only as $encrypted$, and an unchanged save keeps it', async ({ page }) => {
    const id = idWhere(await indexRows(page, '/schedule/index'), 1, UNUSABLE_NAME_SCHEDULE);
    const masked = { app_env: 'prod', [UNUSABLE_PASSWORD_FIELD]: PLACEHOLDER };

    await page.goto(`/schedule/view?id=${id}`);
    expect(await shownExtraVars(page)).toEqual(masked);
    await expectNotInPage(page, UNUSABLE_NAME_ANSWER);

    await page.goto(`/schedule/update?id=${id}`);
    await expectNotInPage(page, UNUSABLE_NAME_ANSWER);
    await expect(page.locator('.extra-vars-editor .CodeMirror').first()).toContainText(PLACEHOLDER);
    expect(await formExtraVars(page)).toEqual(masked);
    await submitForm(page);
    await expectFlash(page, 'success', `Schedule "${UNUSABLE_NAME_SCHEDULE}" updated.`);

    expect(await shownExtraVars(page)).toEqual(masked);
    await expectNotInPage(page, UNUSABLE_NAME_ANSWER);
    await page.goto(`/schedule/update?id=${id}`);
    expect(await formExtraVars(page)).toEqual(masked);
    await expectNotInPage(page, UNUSABLE_NAME_ANSWER);
  });
});
