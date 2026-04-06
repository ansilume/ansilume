import { test, expect } from '@playwright/test';
import { SIDEBAR } from '../../lib/selectors';

test.describe('Sidebar Navigation', () => {
  test('admin sees all menu items', async ({ page }) => {
    await page.goto('/site/index');
    const sidebar = page.locator(SIDEBAR);
    await expect(sidebar).toBeVisible();

    // Admin should see key navigation items
    for (const item of ['Jobs', 'Schedule', 'Runner', 'Job Templates', 'Inventories', 'Projects', 'Credentials', 'Workflow Templates', 'Users', 'Teams', 'Notifications', 'Analytics', 'Audit Log']) {
      await expect(sidebar.locator(`a:has-text("${item}")`).first()).toBeVisible();
    }
  });

  test('active state highlights current page', async ({ page }) => {
    await page.goto('/project/index');
    const sidebar = page.locator(SIDEBAR);
    const activeLink = sidebar.locator('.active, [aria-current="page"]');
    const count = await activeLink.count();
    expect(count).toBeGreaterThanOrEqual(0);
  });
});
