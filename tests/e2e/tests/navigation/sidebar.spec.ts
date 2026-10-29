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

  test('the Workflows section names runs Workflow Jobs and requests Approval Requests', async ({ page }) => {
    await page.goto('/site/index');
    const sidebar = page.locator(SIDEBAR);
    await expect(sidebar.getByRole('link', { name: 'Executions', exact: true })).toHaveCount(0);

    await sidebar.getByRole('link', { name: 'Workflow Jobs', exact: true }).click();
    await expect(page).toHaveURL(/\/workflow-job\/index$/);
    await expect(page.locator('#page-content h2').first()).toHaveText('Workflow Jobs');
    await expect(sidebar.getByRole('link', { name: 'Workflow Jobs', exact: true })).toHaveClass(/(^|\s)active(\s|$)/);

    await sidebar.getByRole('link', { name: 'Approval Requests', exact: true }).click();
    await expect(page).toHaveURL(/\/approval\/index$/);
    await expect(page.locator('#page-content h2').first()).toHaveText('Approval Requests');
  });

  // The link of the open section is marked active, and no other one
  // (views/layouts/main.php). It counted active links and accepted none.
  test('active state highlights current page', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator(SIDEBAR).locator('.nav-link.active')).toHaveText(['Projects']);
  });
});
