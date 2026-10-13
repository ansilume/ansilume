import { test, expect, Locator, Page } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { indexRows } from '../../lib/scoping';
import { deleteOpenTemplate } from '../../lib/job-templates';

// Clone action: one-click duplicate of a template. The operator lands on
// the edit form for the new row (with "<source> (copy)" pre-filled) and
// adjusts whatever needs adjusting before saving.

/** Rows per page of the template list. */
const PAGE_SIZE = 20;

/**
 * The row of the job template `name` in the template list, sorted by name,
 * on the page that shows it. The tests used to look for e2e-template on the
 * first page of the list sorted newest first, which the templates seeded
 * after it fill, and then found no row to clone.
 */
async function templateRow(page: Page, name: string): Promise<Locator> {
  const rows = await indexRows(page, '/job-template/index?sort=name');
  const index = rows.findIndex((row) => row.cells[1] === name);
  expect(index, `${name} is listed`).toBeGreaterThanOrEqual(0);
  await page.goto(`/job-template/index?sort=name&page=${Math.floor(index / PAGE_SIZE) + 1}`);
  const row = page.locator('#template-table tbody tr').nth(index % PAGE_SIZE);
  await expect(row.locator('td').nth(1)).toHaveText(name);
  return row;
}

test.describe('Job Template Clone', () => {
  test('Clone button on the index row duplicates the template and redirects to edit', async ({ page }) => {
    const row = await templateRow(page, 'e2e-template');

    await row.getByRole('button', { name: 'Clone' }).click();
    await page.waitForLoadState('domcontentloaded');

    // Must end up on the Update form for the new row.
    await expect(page).toHaveURL(/\/job-template\/update\?id=\d+/);

    // Success flash confirms the clone.
    await expectFlash(page, 'success', 'Cloned');

    // Name field pre-filled with "<source> (copy)", or "(copy N)" when that name is taken.
    const nameInput = page.locator('#jobtemplate-name');
    await expect(nameInput).toHaveValue(/^e2e-template \(copy( \d+)?\)$/);

    // Remove the copy again, so copies do not pile up in the template list.
    await page.goto(`/job-template/view?id=${new URL(page.url()).searchParams.get('id')}`);
    await deleteOpenTemplate(page);
  });

  test('Clone button on the detail page produces a new template visible in the index', async ({ page }) => {
    const row = await templateRow(page, 'e2e-template');
    await row.getByRole('link', { name: 'e2e-template', exact: true }).click();
    await page.waitForLoadState('domcontentloaded');

    // Clone from the detail toolbar.
    await page.getByRole('button', { name: 'Clone' }).click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page).toHaveURL(/\/job-template\/update\?id=\d+/);
    const cloneId = new URL(page.url()).searchParams.get('id');

    // Save as-is (no rename) — the clone should persist with the
    // auto-picked name, landing us back on the view.
    await page.getByRole('button', { name: /save/i }).first().click();
    await page.waitForLoadState('networkidle');

    // The clone is among the newest templates, on the first page of the list sorted newest first.
    await page.goto('/job-template/index?sort=-id');
    const clone = page.locator(`#template-table tbody tr:has(a[href$="/job-template/view?id=${cloneId}"])`);
    await expect(clone).toHaveCount(1);
    await expect(clone.locator('td').nth(1)).toContainText('e2e-template (copy');

    await page.goto(`/job-template/view?id=${cloneId}`);
    await deleteOpenTemplate(page);
  });
});
