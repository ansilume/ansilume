import { test, expect } from '@playwright/test';
import { PAGE_TITLE } from '../../lib/selectors';

test.describe('Artifact Storage', () => {
  test('admin page loads with stat cards', async ({ page }) => {
    await page.goto('/system/artifact-stats');
    await expect(page.locator(PAGE_TITLE)).toContainText(/artifact storage/i);

    // Three top-level stat cards: total bytes, artifacts, jobs.
    const cards = page.locator('.row .card .card-body');
    const count = await cards.count();
    expect(count).toBeGreaterThanOrEqual(3);
  });

  test('configuration table is visible', async ({ page }) => {
    await page.goto('/system/artifact-stats');
    await expect(page.locator('text=Configuration').first()).toBeVisible();
    await expect(page.locator('th:has-text("Retention")').first()).toBeVisible();
    await expect(page.locator('th:has-text("Max file size")').first()).toBeVisible();
  });

  test('top jobs table or empty state visible', async ({ page }) => {
    await page.goto('/system/artifact-stats');
    await expect(page.locator('text=Top jobs by artifact size').first()).toBeVisible();
  });
});
