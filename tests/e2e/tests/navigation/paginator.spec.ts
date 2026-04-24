import { test, expect } from '@playwright/test';

// Regression guard for v2.3.6-era pager bugs: the Last/First affordances
// only help operators who reach the last page and back again. Plain
// "go to page 2" walks didn't exercise either button and let them break
// silently. The seeded e2e-pag-* webhook rows span two paginator pages
// (/webhook/index pageSize 25 + 30 seeded rows), which gives the spec
// predictable data to assert against without polluting indexes other
// specs read.

const INDEX_URL = '/webhook/index';
const TABLE_SELECTOR = 'table#webhook-table';

test.describe('Paginator regression', () => {
  test('Last and First buttons move to the correct pages', async ({ page }) => {
    await page.goto(INDEX_URL);

    const pagerLast = page.locator('.pagination li.last a').first();
    await expect(pagerLast).toBeVisible({ timeout: 5_000 });

    // Capture the set of row IDs on page 1 to compare with page N later.
    const page1Ids = await page.locator(`${TABLE_SELECTOR} tbody tr td:first-child`).allTextContents();
    expect(page1Ids.length).toBeGreaterThan(0);

    await pagerLast.click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page).toHaveURL(/[?&]page=\d+/);

    const pageLastIds = await page.locator(`${TABLE_SELECTOR} tbody tr td:first-child`).allTextContents();
    // Last page must not duplicate page 1 entirely — otherwise the pager
    // silently collapsed to a single page (the bug class we're guarding).
    expect(pageLastIds.some((id) => !page1Ids.includes(id))).toBe(true);

    // First button rewinds to page 1. Yii's LinkPager emits ?page=1 explicitly,
    // so assert on page content (same row IDs as the initial page) rather
    // than URL shape — the URL encoding isn't load-bearing, the contents are.
    const pagerFirst = page.locator('.pagination li.first a').first();
    await expect(pagerFirst).toBeVisible();
    await pagerFirst.click();
    await page.waitForLoadState('domcontentloaded');
    const rewoundIds = await page.locator(`${TABLE_SELECTOR} tbody tr td:first-child`).allTextContents();
    expect(rewoundIds).toEqual(page1Ids);
  });

  test('Next advances one page and Prev rewinds it', async ({ page }) => {
    await page.goto(INDEX_URL);

    const next = page.locator('.pagination li.next a').first();
    await expect(next).toBeVisible();
    await next.click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page).toHaveURL(/[?&]page=2\b/);

    const page2Ids = await page.locator(`${TABLE_SELECTOR} tbody tr td:first-child`).allTextContents();

    const prev = page.locator('.pagination li.prev a').first();
    await expect(prev).toBeVisible();
    await prev.click();
    await page.waitForLoadState('domcontentloaded');
    // Prev from page 2 goes back to a different row set than page 2 held.
    const afterPrevIds = await page.locator(`${TABLE_SELECTOR} tbody tr td:first-child`).allTextContents();
    expect(afterPrevIds).not.toEqual(page2Ids);
  });
});
