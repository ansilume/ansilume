import { test, expect, Page } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { asAdmin, idWhere, indexRows, optionLabels } from '../../lib/scoping';

/**
 * A schedule launches its jobs as its creator, so its job template must be
 * one the user may operate: the form lists only those, a template the user
 * cannot see is reported as not existing, and one they see but may not
 * operate is refused.
 *
 * Fixtures: commands/E2eTeamScopingSeeder.php (e2e-operator's team operates
 * e2e-alpha-proj, only views e2e-alpha-viewed-proj, and cannot see
 * e2e-beta-proj) and the e2e-team fixtures of E2eController (e2e-template).
 */
const TEMPLATE_SELECT = '#schedule-job_template_id';

/** The template names the dropdown offers; it labels them "name (id)". */
async function offeredTemplates(page: Page): Promise<string[]> {
  return (await optionLabels(page, TEMPLATE_SELECT)).map((label) => label.replace(/ \(\d+\)$/, ''));
}

/** Fills the create form, offering `templateId` in the dropdown as a manipulated form would. */
async function fillScheduleWith(page: Page, name: string, templateId: string) {
  await page.goto('/schedule/create');
  await page.locator('#schedule-name').fill(name);
  await page.locator('#cron-input').fill('0 3 1 1 *');
  await page.locator(TEMPLATE_SELECT).evaluate((select, value) => {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = 'forged';
    select.appendChild(option);
  }, templateId);
  await page.locator(TEMPLATE_SELECT).selectOption(templateId);
}

async function submitSchedule(page: Page) {
  await Promise.all([
    page.waitForResponse((response) => response.url().includes('/schedule/create') && response.request().method() === 'POST'),
    page.locator('#schedule-form button[type="submit"]').click(),
  ]);
}

test.describe('Team Scoping — Schedules', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
    if (pn === 'admin' && !title.startsWith('admin')) test.skip();
  });

  test('operator is offered only job templates they may operate for a schedule', async ({ page }) => {
    await page.goto('/schedule/create');
    const labels = await offeredTemplates(page);
    expect(labels).toContain('e2e-alpha-tmpl');
    expect(labels).toContain('e2e-template');
    expect(labels).not.toContain('e2e-beta-tmpl');
    expect(labels).not.toContain('e2e-alpha-viewed-tmpl');
    await expect(page.locator('#page-content')).toContainText('Scheduled jobs run as you.');
  });

  test('operator cannot schedule a job template they may not operate', async ({ page, browser }, testInfo) => {
    const [betaTmpl, viewedTmpl] = await asAdmin(browser, testInfo, async (admin) => {
      const rows = await indexRows(admin, '/job-template/index?sort=name');
      return [idWhere(rows, 1, 'e2e-beta-tmpl'), idWhere(rows, 1, 'e2e-alpha-viewed-tmpl')];
    });
    const hidden = `e2e-forged-schedule-hidden-${Date.now()}`;
    const viewOnly = `e2e-forged-schedule-viewed-${Date.now()}`;

    // Another team's template, which the operator cannot see: reported like an unknown one.
    await fillScheduleWith(page, hidden, betaTmpl);
    await submitSchedule(page);
    await expect(page.locator('.field-schedule-job_template_id .help-block'))
      .toHaveText('The selected job template does not exist.');

    // A template the operator sees but may not operate: refused.
    await fillScheduleWith(page, viewOnly, viewedTmpl);
    await submitSchedule(page);
    await expectForbidden(page);

    await asAdmin(browser, testInfo, async (admin) => {
      const names = (await indexRows(admin, '/schedule/index')).map((row) => row.cells[1]);
      expect(names).not.toContain(hidden);
      expect(names).not.toContain(viewOnly);
    });
  });

  test('operator cannot schedule a job template they may not operate by a zero-padded ID', async ({ page, browser }, testInfo) => {
    // Regression: the check read the ID with filter_var(), which rejects a
    // leading zero, so "0<id>" skipped it while the schedule stored <id>.
    const [betaTmpl, viewedTmpl] = await asAdmin(browser, testInfo, async (admin) => {
      const rows = await indexRows(admin, '/job-template/index?sort=name');
      return [idWhere(rows, 1, 'e2e-beta-tmpl'), idWhere(rows, 1, 'e2e-alpha-viewed-tmpl')];
    });
    const hidden = `e2e-padded-schedule-hidden-${Date.now()}`;
    const viewOnly = `e2e-padded-schedule-viewed-${Date.now()}`;

    // Another team's template, which the operator cannot see: reported like an unknown one.
    await fillScheduleWith(page, hidden, `0${betaTmpl}`);
    await submitSchedule(page);
    await expect(page.locator('.field-schedule-job_template_id .help-block'))
      .toHaveText('The selected job template does not exist.');

    // A template the operator sees but may not operate: refused.
    await fillScheduleWith(page, viewOnly, `0${viewedTmpl}`);
    await submitSchedule(page);
    await expectForbidden(page);

    await asAdmin(browser, testInfo, async (admin) => {
      const names = (await indexRows(admin, '/schedule/index')).map((row) => row.cells[1]);
      expect(names).not.toContain(hidden);
      expect(names).not.toContain(viewOnly);
    });
  });

  test('admin is offered the job templates of every team for a schedule', async ({ page }) => {
    await page.goto('/schedule/create');
    const labels = await offeredTemplates(page);
    expect(labels).toEqual(expect.arrayContaining(['e2e-alpha-tmpl', 'e2e-beta-tmpl', 'e2e-alpha-viewed-tmpl', 'e2e-template']));
  });
});
