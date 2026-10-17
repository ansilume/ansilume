import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { asAdmin, idWhere, indexRows } from '../../lib/scoping';
import { PASSWORD_FIELD, PLACEHOLDER, SECRET_SCHEDULE, SEEDED_SCHEDULE_ANSWER, TEXT_FIELD, expectNotInPage } from '../../lib/survey-secrets';
import { DELETED_TEMPLATE_SCHEDULE, flash, scheduleEnableRefused, scheduleRow, toggleOpenSchedule } from '../../lib/approvals';
import { HOST_INVENTORY, HOST_RULE, HOST_SCHEDULE } from '../../lib/inventories';
import { SCHEDULE_NOTE_INVENTORY } from '../../lib/job-templates';

/**
 * commands/E2eUnusablePasswordNameSeeder.php: SCHEDULE, PASSWORD_FIELD (a
 * name that cannot carry an answer) and PLAIN_ANSWER, held in plaintext as
 * 2.8.1 stored it.
 */
const UNUSABLE_NAME_SCHEDULE = 'e2e-unusable-name-schedule';
const UNUSABLE_PASSWORD_FIELD = 'contraseña_db';
const UNUSABLE_NAME_ANSWER = 'e2e-unusable-name-answer-Tz58';

test.describe('Schedules RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer gets 403 on schedule index', async ({ page }) => {
    await page.goto('/schedule/index');
    await expectForbidden(page);
  });

  test('viewer gets 403 on schedule create', async ({ page }) => {
    await page.goto('/schedule/create');
    await expectForbidden(page);
  });

  test('operator can access schedules', async ({ page }) => {
    await page.goto('/schedule/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  // e2e-secret-schedule stores a password answer (commands/E2eSurveySecretSeeder.php).
  test('operator sees a stored password answer only as $encrypted$, on the schedule page and in its edit form', async ({ page }) => {
    const id = idWhere(await indexRows(page, '/schedule/index'), 1, SECRET_SCHEDULE);
    const expected = { [TEXT_FIELD]: 'prod', [PASSWORD_FIELD]: PLACEHOLDER };

    await page.goto(`/schedule/view?id=${id}`);
    expect(JSON.parse((await page.getByTestId('schedule-extra-vars').textContent()) ?? '')).toEqual(expected);
    await expectNotInPage(page, SEEDED_SCHEDULE_ANSWER);

    await page.goto(`/schedule/update?id=${id}`);
    expect(JSON.parse(await page.locator('textarea[data-extra-vars-editor]').inputValue())).toEqual(expected);
    await expectNotInPage(page, SEEDED_SCHEDULE_ANSWER);
  });

  // Every password field name is a secret key, also one that cannot carry an answer.
  // Regression (R2V2-1): such an answer showed in plaintext on both pages.
  test('operator sees an answer under a password field name that cannot carry one only as $encrypted$', async ({ page }) => {
    const id = idWhere(await indexRows(page, '/schedule/index'), 1, UNUSABLE_NAME_SCHEDULE);
    const expected = { [TEXT_FIELD]: 'prod', [UNUSABLE_PASSWORD_FIELD]: PLACEHOLDER };

    await page.goto(`/schedule/view?id=${id}`);
    expect(JSON.parse((await page.getByTestId('schedule-extra-vars').textContent()) ?? '')).toEqual(expected);
    await expectNotInPage(page, UNUSABLE_NAME_ANSWER);

    await page.goto(`/schedule/update?id=${id}`);
    expect(JSON.parse(await page.locator('textarea[data-extra-vars-editor]').inputValue())).toEqual(expected);
    await expectNotInPage(page, UNUSABLE_NAME_ANSWER);
  });

  // commands/E2eApprovalSeeder.php: the disabled schedule of e2e-deleted-template,
  // a deleted template in e2e-project, which e2e-operator's team operates.
  // Regression: a schedule of a deleted template could be re-enabled.
  test('operator cannot enable the schedule of a deleted job template either', async ({ page, browser }, testInfo) => {
    const id = await asAdmin(browser, testInfo, async (admin) => (await scheduleRow(admin, DELETED_TEMPLATE_SCHEDULE)).id);

    await page.goto(`/schedule/view?id=${id}`);
    await expect(page.locator('#page-content dl')).toContainText('Disabled');
    await toggleOpenSchedule(page);
    await expect(flash(page, 'danger')).toHaveText(scheduleEnableRefused(DELETED_TEMPLATE_SCHEDULE));
    await expect(flash(page, 'success')).toHaveCount(0);

    await page.goto(`/schedule/view?id=${id}`);
    await expect(page.locator('#page-content dl')).toContainText('Disabled');
    await expect(page.locator('#page-content dl')).not.toContainText('Enabled');
  });

  // commands/E2eInventoryGateSeeder.php: e2e-admin's disabled schedule of
  // e2e-host-gated-template, which has no rule of its own and is gated by the
  // rule of its inventory; its project has no team, so e2e-operator sees it.
  test('operator sees on the schedule of a template gated through its inventory that each run waits for approval', async ({ page }) => {
    const { id } = await scheduleRow(page, HOST_SCHEDULE);
    await page.goto(`/schedule/view?id=${id}`);
    await expect(page.locator('#schedule-approval')).toHaveText(SCHEDULE_NOTE_INVENTORY(HOST_RULE, HOST_INVENTORY));
  });
});
