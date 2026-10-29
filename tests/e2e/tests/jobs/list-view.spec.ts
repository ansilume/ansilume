import { test, expect, Page } from '@playwright/test';
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
});
