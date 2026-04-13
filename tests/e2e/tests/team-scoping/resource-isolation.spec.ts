import { test, expect } from '@playwright/test';

/**
 * Team scoping E2E — verifies resource isolation between teams.
 *
 * Setup (via E2eController::seedTeamScopingData):
 *   - team-alpha: has alpha-proj, alpha-tmpl, alpha-inv. e2e-operator is a member.
 *   - team-beta:  has beta-proj,  beta-tmpl,  beta-inv.  e2e-viewer is a member.
 *   - e2e-admin sees everything (superadmin bypass).
 *
 * Tests verify that each role only sees their team's resources.
 */
test.describe('Team Scoping — Resource Isolation', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
    if (pn === 'admin' && !title.startsWith('admin')) test.skip();
  });

  // -- Operator (team-alpha member) ------------------------------------------

  test('operator sees own team project on project index', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator('table.table tbody')).toContainText('e2e-alpha-proj');
  });

  test('operator does not see other team project on project index', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator('table.table tbody')).not.toContainText('e2e-beta-proj');
  });

  test('operator sees own team template on template index', async ({ page }) => {
    await page.goto('/job-template/index');
    await expect(page.locator('table.table tbody')).toContainText('e2e-alpha-tmpl');
  });

  test('operator does not see other team template on template index', async ({ page }) => {
    await page.goto('/job-template/index');
    await expect(page.locator('table.table tbody')).not.toContainText('e2e-beta-tmpl');
  });

  test('operator sees own team inventory on inventory index', async ({ page }) => {
    await page.goto('/inventory/index');
    await expect(page.locator('table.table tbody')).toContainText('e2e-alpha-inv');
  });

  test('operator does not see other team inventory on inventory index', async ({ page }) => {
    await page.goto('/inventory/index');
    await expect(page.locator('table.table tbody')).not.toContainText('e2e-beta-inv');
  });

  // -- Viewer (team-beta member) ---------------------------------------------

  test('viewer sees own team project on project index', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator('table.table tbody')).toContainText('e2e-beta-proj');
  });

  test('viewer does not see other team project on project index', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator('table.table tbody')).not.toContainText('e2e-alpha-proj');
  });

  test('viewer sees own team template on template index', async ({ page }) => {
    await page.goto('/job-template/index');
    await expect(page.locator('table.table tbody')).toContainText('e2e-beta-tmpl');
  });

  test('viewer does not see other team template on template index', async ({ page }) => {
    await page.goto('/job-template/index');
    await expect(page.locator('table.table tbody')).not.toContainText('e2e-alpha-tmpl');
  });

  // -- Admin (sees everything) -----------------------------------------------

  test('admin sees all team projects on project index', async ({ page }) => {
    await page.goto('/project/index');
    const body = page.locator('table.table tbody');
    await expect(body).toContainText('e2e-alpha-proj');
    await expect(body).toContainText('e2e-beta-proj');
  });

  test('admin sees all team templates on template index', async ({ page }) => {
    await page.goto('/job-template/index');
    const body = page.locator('table.table tbody');
    await expect(body).toContainText('e2e-alpha-tmpl');
    await expect(body).toContainText('e2e-beta-tmpl');
  });

  test('admin sees all team inventories on inventory index', async ({ page }) => {
    await page.goto('/inventory/index');
    const body = page.locator('table.table tbody');
    await expect(body).toContainText('e2e-alpha-inv');
    await expect(body).toContainText('e2e-beta-inv');
  });
});
