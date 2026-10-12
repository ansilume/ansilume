import { test, expect, Page } from '@playwright/test';
import { indexRows } from '../../lib/scoping';

/** Rows per page of the template list. */
const PAGE_SIZE = 20;

test.describe('Job Template Launch', () => {
  // The row of e2e-template in the template list, which is sorted by name
  // and paged: other seeded templates can push it off the first page, where
  // these tests used to look for it and then silently did nothing.
  async function openTemplateRow(page: Page) {
    const rows = await indexRows(page, '/job-template/index?sort=name');
    const index = rows.findIndex((row) => row.cells[1] === 'e2e-template');
    expect(index, 'e2e-template is listed').toBeGreaterThanOrEqual(0);
    await page.goto(`/job-template/index?sort=name&page=${Math.floor(index / PAGE_SIZE) + 1}`);
    const row = page.locator('#page-content table.table tbody tr').nth(index % PAGE_SIZE);
    await expect(row.locator('td').nth(1)).toHaveText('e2e-template');
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
});
