import { test, expect } from '@playwright/test';

// Documents — and locks in — the behaviour of the client-side
// data-table-filter input on paginated pages. It only filters the rows
// the server rendered for the current page, never the full dataset.
// That's the current design (web/js/table-filter.js) and probably
// surprising to operators, so:
//   1. a regression where it also hides pager controls would break this,
//   2. a future rewrite that promotes it to a server-side filter must
//      update this spec explicitly rather than silently change UX.
//
// Uses /webhook/index because the paginator fixture (E2ePaginationSeeder)
// already spans two pages there and no other spec asserts specific page-1
// content on it.

const INDEX_URL = '/webhook/index';
const TABLE_ID = 'webhook-table';
const ROW_ON_PAGE_1 = 'e2e-pag-030'; // Highest seeded id → always on page 1 (id DESC).

test.describe('Client-side table filter', () => {
  test('filter hides non-matching rows on the current page only', async ({ page }) => {
    await page.goto(INDEX_URL);

    const filter = page.locator(`input[data-table-filter="${TABLE_ID}"]`);
    await expect(filter).toBeVisible();

    const allRows = page.locator(`table#${TABLE_ID} tbody tr`);
    const initialVisible = await allRows.evaluateAll((rows) =>
      rows.filter((r) => (r as HTMLElement).style.display !== 'none').length,
    );
    expect(initialVisible).toBeGreaterThan(0);

    await filter.fill(ROW_ON_PAGE_1);
    await expect.poll(async () =>
      allRows.evaluateAll((rows) =>
        rows.filter((r) => (r as HTMLElement).style.display !== 'none').length,
      ),
    ).toBeLessThan(initialVisible);

    // The one matching row must still be visible.
    const match = page.locator(`table#${TABLE_ID} tbody tr`, { hasText: ROW_ON_PAGE_1 });
    await expect(match.first()).toBeVisible();

    // Pager markup must stay on the page — the filter is rows-only.
    const pager = page.locator('.pagination');
    await expect(pager).toBeVisible();

    // Clearing the input restores the original page.
    await filter.fill('');
    await expect.poll(async () =>
      allRows.evaluateAll((rows) =>
        rows.filter((r) => (r as HTMLElement).style.display !== 'none').length,
      ),
    ).toBe(initialVisible);
  });

  test('filter does not reach across pages (documents current scope)', async ({ page }) => {
    await page.goto(INDEX_URL);

    // Filter for a term that only appears on the *last* page — the filter
    // is a no-op because those rows aren't in the current DOM.
    const filter = page.locator(`input[data-table-filter="${TABLE_ID}"]`);
    await filter.fill('e2e-pag-001'); // Lowest-id seeded row → on last page.
    const visibleAfter = await page
      .locator(`table#${TABLE_ID} tbody tr`)
      .evaluateAll((rows) => rows.filter((r) => (r as HTMLElement).style.display !== 'none').length);
    expect(visibleAfter).toBe(0);
  });
});
