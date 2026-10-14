import { test, expect } from '@playwright/test';

test.describe('Dashboard', () => {
  test('loads for admin', async ({ page }) => {
    await page.goto('/site/index');
    await expect(page).not.toHaveURL(/.*login.*/);
    await expect(page.locator('body')).toBeVisible();
  });

  test('shows four stat cards', async ({ page }) => {
    await page.goto('/site/index');
    // Jobs Today, Queued, Running Now, Runners Online
    await expect(page.getByRole('link', { name: 'Jobs Today' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Queued' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Running Now' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Runners Online' })).toBeVisible();
  });

  test('shows charts section', async ({ page }) => {
    await page.goto('/site/index');
    await expect(page.locator('#chart-jobs')).toBeVisible();
    await expect(page.locator('#chart-tasks')).toBeVisible();
    await expect(page.locator('#chart-range')).toBeVisible();
  });

  test('shows quick launch with job and workflow dropdowns', async ({ page }) => {
    await page.goto('/site/index');
    const quickLaunch = page.locator('.card', { hasText: 'Quick Launch' });
    await expect(quickLaunch).toBeVisible();
    // Should have at least one select (job templates or workflow templates)
    const selects = quickLaunch.locator('select');
    expect(await selects.count()).toBeGreaterThanOrEqual(1);
  });

  test('shows status summary for last 7 days', async ({ page }) => {
    await page.goto('/site/index');
    await expect(page.locator('.card', { hasText: 'Status (7 days)' })).toBeVisible();
  });

  test('shows recent jobs table', async ({ page }) => {
    await page.goto('/site/index');
    await expect(page.locator('.card', { hasText: 'Recent Jobs' })).toBeVisible();
  });

  /**
   * Regression: "Running Now" link must use ?status=running, not
   * ?JobSearchForm[status]=running — the search form loads with empty
   * prefix so nested keys are silently ignored (GitHub #7).
   */
  test('running now link uses correct filter param', async ({ page }) => {
    await page.goto('/site/index');
    const link = page.getByRole('link', { name: 'Running Now' });
    const href = await link.getAttribute('href');
    expect(href).toContain('status=running');
    expect(href).not.toContain('JobSearchForm');
  });

  // The seeded waiting runs and requests (commands/E2eWorkflowWaitSeeder.php,
  // E2eWorkflowScopingSeeder.php) fill both panels.
  test('the waiting runs and pending approvals panels link their full lists', async ({ page }) => {
    await page.goto('/site/index');
    await page.locator('#dashboard-running-workflows').getByRole('link', { name: 'All Workflow Jobs' }).click();
    await expect(page).toHaveURL(/\/workflow-job\/index$/);
    await expect(page.locator('#page-content h2').first()).toHaveText('Workflow Jobs');

    await page.goto('/site/index');
    const pending = page.locator('#dashboard-pending-approvals');
    await expect(pending.locator('thead th')).toHaveText(['Rule', 'For', 'Job', 'Requested', 'Expires', '']);
    await pending.getByRole('link', { name: 'All pending' }).click();
    await expect(page).toHaveURL(/\/approval\/index\?status=pending$/);
    await expect(page.locator('#approval-status-filter a[aria-current="page"]')).toHaveText('Pending');
  });

  test('has navigation elements', async ({ page }) => {
    await page.goto('/site/index');
    await expect(page.locator('#sidebar, nav, .navbar').first()).toBeVisible();
  });

  test('chart range selector loads different data', async ({ page }) => {
    await page.goto('/site/index');
    const rangeSelect = page.locator('#chart-range');
    // Default is 30 days
    await expect(rangeSelect).toHaveValue('30');
    // Switch to 7 days
    await rangeSelect.selectOption('7');
    // Chart should still be visible after reload
    await expect(page.locator('#chart-jobs')).toBeVisible();
  });
});
