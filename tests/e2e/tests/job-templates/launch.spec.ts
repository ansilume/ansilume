import { test, expect, Page } from '@playwright/test';
import { flash } from '../../lib/approvals';
import { templateId } from '../../lib/helpers';
import { LIMIT_TOO_LONG, VERBOSITY_TOO_HIGH, launchFailed } from '../../lib/job-templates';
import { newestJobOf } from '../../lib/jobs';
import { forgePost, indexRows } from '../../lib/scoping';

/** Rows per page of the template list. */
const PAGE_SIZE = 20;

/** The seeded job template these tests launch. */
const TEMPLATE = 'e2e-template';

test.describe('Job Template Launch', () => {
  // The row of e2e-template in the template list, which is sorted by name
  // and paged: other seeded templates can push it off the first page, where
  // these tests used to look for it and then silently did nothing.
  async function openTemplateRow(page: Page) {
    const rows = await indexRows(page, '/job-template/index?sort=name');
    const index = rows.findIndex((row) => row.cells[1] === TEMPLATE);
    expect(index, 'e2e-template is listed').toBeGreaterThanOrEqual(0);
    await page.goto(`/job-template/index?sort=name&page=${Math.floor(index / PAGE_SIZE) + 1}`);
    const row = page.locator('#page-content table.table tbody tr').nth(index % PAGE_SIZE);
    await expect(row.locator('td').nth(1)).toHaveText(TEMPLATE);
    return row;
  }

  test('launch form renders for seeded template', async ({ page }) => {
    const row = await openTemplateRow(page);
    await row.locator('a:has-text("Launch")').first().click();
    await expect(page.locator('#launch-form')).toBeVisible();
    await expect(page.locator('body')).toContainText(/launch|confirm|extra|vars|run/i);
  });

  test('launch creates a job', async ({ page }) => {
    const row = await openTemplateRow(page);
    await row.locator('a:has-text("Launch")').first().click();
    await page.locator('#launch-form button[type="submit"]').click();
    await expect(page).toHaveURL(/\/job\/view\?id=\d+/, { timeout: 10_000 });
  });

  // Regression: a verbosity the job record refuses (outside 0-5) failed the
  // launch only when the record was saved, and the flash doubled its prefix
  // and showed the record's raw errors: 'Launch failed: Job launch failed:
  // Failed to save job record: {"verbosity":["Verbosity must be no greater
  // than 5."]}'. Now it is refused before the launch with the record's
  // message. The form offers 0-5 only, so the post is forged, with the
  // page's CSRF token.
  test('a forged launch with a verbosity outside 0-5 is refused with the field message', async ({ page }) => {
    const id = await templateId(page, TEMPLATE);
    const newest = await newestJobOf(page, id);
    await page.goto(`/job-template/view?id=${id}`);

    await forgePost(page, `/job-template/launch?id=${id}`, { 'overrides[verbosity]': '9' }, new RegExp(`/job-template/launch\\?id=${id}$`));

    await expect(flash(page, 'danger')).toHaveText(launchFailed(VERBOSITY_TOO_HIGH));
    await expect(page.locator('#launch-form'), 'the launch form, to correct the input').toBeVisible();
    expect(await newestJobOf(page, id), 'no job was launched').toBe(newest);
  });

  // Regression: a limit of more than 255 characters, which the form takes,
  // failed the same way: 'Launch failed: Job launch failed: Failed to save
  // job record: {"limit":["Limit should contain at most 255 characters."]}'.
  test('a limit longer than 255 characters is refused with the field message', async ({ page }) => {
    const id = await templateId(page, TEMPLATE);
    const newest = await newestJobOf(page, id);
    await page.goto(`/job-template/launch?id=${id}`);

    await page.locator('#launch-form input[name="overrides[limit]"]').fill('web:'.repeat(64) + 'x');
    await Promise.all([
      page.waitForNavigation({ url: new RegExp(`/job-template/launch\\?id=${id}$`) }),
      page.locator('#launch-form button[type="submit"]').click(),
    ]);

    await expect(flash(page, 'danger')).toHaveText(launchFailed(LIMIT_TOO_LONG));
    await expect(page.locator('#launch-form'), 'the launch form, to correct the input').toBeVisible();
    expect(await newestJobOf(page, id), 'no job was launched').toBe(newest);
  });
});
