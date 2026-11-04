import { test, expect, Page } from '@playwright/test';
import { templateId } from '../../lib/helpers';
import {
  FINISHED_SINCE_NOTE,
  OLD_RECOVERY_TEMPLATE,
  OPEN_FAILURE_TEMPLATE,
  RECOVERY_TEMPLATE,
  expectTheListNamesTheRecoveringRun,
  listedJobIds,
  recoveryJobs,
} from '../../lib/recovery';
import { PAGE_TITLE } from '../../lib/selectors';

/** The link of the first job of the open job list (newest first). */
async function firstJobLink(page: Page): Promise<string | null> {
  return page.locator('#page-content table.table tbody tr td:first-child a').first().getAttribute('href');
}

test.describe('Jobs List & View', () => {
  test('index page loads', async ({ page }) => {
    await page.goto('/job/index');
    await expect(page.locator(PAGE_TITLE)).toContainText(/job/i);
  });

  // The seeded jobs (commands/E2eArtifactSeeder.php, E2eLogStreamSeeder.php)
  // are listed, each with its status badge. It counted badges anywhere on
  // the page and accepted none.
  test('shows status badges', async ({ page }) => {
    await page.goto('/job/index');
    const badges = page.locator('#page-content table.table tbody tr td:nth-child(2) .badge');
    expect(await badges.count(), 'a status badge per listed job').toBeGreaterThan(0);
  });

  test('view job detail', async ({ page }) => {
    await page.goto('/job/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/job|status|template|log/i);
    }
  });

  // Regression: a date_from of only whitespace (?date_from=%20) failed the
  // date rule but was applied all the same: the list showed Clear filters for
  // it, the date input carried it, and on PHP 8.3, where strtotime(' ') was
  // now, it listed no job. A NUL byte (?date_from=%00) made the date rule
  // throw a ValueError: 500. Both are trimmed to no date now, which is no
  // filter (models/JobSearchForm.php).
  for (const [what, value] of [['a space', '%20'], ['a NUL byte', '%00']]) {
    test(`a date_from of ${what} is no filter: the whole list, an empty date and no Clear filters`, async ({ page }) => {
      await page.goto('/job/index');
      const unfiltered = await firstJobLink(page);
      expect(unfiltered, 'a job to compare').toMatch(/[?&]id=\d+/);

      const answer = await page.goto(`/job/index?date_from=${value}`);

      expect(answer?.status(), 'the job list').toBe(200);
      await expect(page.locator('#page-content input[name="date_from"]')).toHaveAttribute('value', '');
      await expect(page.locator('#page-content').getByRole('link', { name: 'Clear filters' })).toHaveCount(0);
      expect(await firstJobLink(page), 'the newest job, as without the filter').toBe(unfiltered);
    });
  }

  // A failed job that a later run of the same job template recovered shows
  // a muted "recovered by #N" next to its status, linking that run
  // (commands/E2eRecoveredFailureSeeder.php).
  test('a recovered failure shows the run that recovered it next to its status', async ({ page }) => {
    await expectTheListNamesTheRecoveringRun(page, await recoveryJobs(page));
  });

  // The filter Recovered lists the failures that a later run recovered, or
  // those that none recovered, never a job of another status; it is kept
  // and offers Clear filters.
  test('the Recovered filter lists the recovered or the unrecovered failures', async ({ page }) => {
    const jobs = await recoveryJobs(page);
    const template = await templateId(page, RECOVERY_TEMPLATE);
    const openTemplate = await templateId(page, OPEN_FAILURE_TEMPLATE);
    const filter = page.locator('#page-content form');

    await page.goto(`/job/index?template_id=${template}`);
    expect(await listedJobIds(page), 'both jobs of the template, newest first').toEqual([jobs.success, jobs.failure]);
    await filter.locator('#job-filter-recovered').selectOption('1');
    await filter.getByRole('button', { name: 'Filter' }).click();
    await expect(page).toHaveURL(/[?&]recovered=1(&|$)/);
    await expect(page.locator('#job-filter-recovered')).toHaveValue('1');
    expect(await listedJobIds(page), 'only the recovered failure').toEqual([jobs.failure]);
    await expect(page.locator('#page-content').getByRole('link', { name: 'Clear filters' })).toHaveCount(1);

    await filter.locator('#job-filter-recovered').selectOption('0');
    await filter.getByRole('button', { name: 'Filter' }).click();
    await expect(page).toHaveURL(/[?&]recovered=0(&|$)/);
    await expect(page.locator('#page-content')).toContainText('No jobs match the current filters.');

    await page.goto(`/job/index?template_id=${openTemplate}&recovered=0`);
    expect(await listedJobIds(page), 'the failure that no later run recovered').toEqual([jobs.open]);
    await page.goto(`/job/index?template_id=${openTemplate}&recovered=1`);
    await expect(page.locator('#page-content')).toContainText('No jobs match the current filters.');

    // Any other value is no filter: the whole list of the template.
    await page.goto(`/job/index?template_id=${template}&recovered=yes`);
    await expect(page.locator('#job-filter-recovered')).toHaveValue('');
    expect(await listedJobIds(page)).toEqual([jobs.success, jobs.failure]);
  });

  // finished_since (a Unix time, which the dashboard's link passes) lists the
  // jobs that finished then or later, and the list says so, with a link that
  // removes it and keeps the other filters; a value that is no Unix time is
  // no filter. The jobs of e2e-recovery-old-template finished three days
  // before the seed.
  test('finished_since lists the jobs that finished since, says so, and its note removes it', async ({ page }) => {
    const jobs = await recoveryJobs(page);
    const oldTemplate = await templateId(page, OLD_RECOVERY_TEMPLATE);
    const dayAgo = Math.floor(Date.now() / 1000) - 86400;

    await page.goto(`/job/index?template_id=${oldTemplate}&finished_since=${dayAgo}`);
    await expect(page.locator('#page-content')).toContainText('No jobs match the current filters.');
    const note = page.locator('#job-filter-finished-since');
    await expect(note).toHaveText(FINISHED_SINCE_NOTE);
    await expect(page.locator('#page-content form input[type="hidden"][name="finished_since"]')).toHaveValue(String(dayAgo));
    await expect(page.locator('#page-content').getByRole('link', { name: 'Clear filters' })).toHaveCount(1);

    await note.getByRole('link', { name: 'Remove this filter' }).click();
    await expect(page).toHaveURL(new RegExp(`/job/index\\?template_id=${oldTemplate}$`));
    await expect(page.locator('#job-filter-finished-since')).toHaveCount(0);
    const every = await listedJobIds(page);
    expect(every, 'both jobs of the template, the failure last').toHaveLength(2);
    expect(every[1]).toBe(jobs.old);

    for (const value of ['yesterday', '1.5', '-1']) {
      await page.goto(`/job/index?template_id=${oldTemplate}&finished_since=${value}`);
      expect(await listedJobIds(page), value).toEqual(every);
      await expect(page.locator('#job-filter-finished-since'), value).toHaveCount(0);
      await expect(page.locator('#page-content').getByRole('link', { name: 'Clear filters' }), value).toHaveCount(1);
    }
  });
});
