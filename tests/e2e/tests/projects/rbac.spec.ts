import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';
import { BTN_CREATE } from '../../lib/selectors';

test.describe('Projects RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer cannot see create button', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator(BTN_CREATE)).not.toBeVisible();
  });

  test('viewer gets 403 on project create', async ({ page }) => {
    await page.goto('/project/create');
    await expectForbidden(page);
  });

  test('operator can access project index', async ({ page }) => {
    await page.goto('/project/index');
    await expect(page.locator('body')).not.toContainText(/\b403\b|\bForbidden\b/i);
  });

  test('operator can create projects', async ({ page }) => {
    await page.goto('/project/create');
    await expect(page.locator('body')).not.toContainText(/\b403\b|\bForbidden\b/i);
  });

  test('viewer cannot see edit/delete buttons on project view', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    await link.click();
    await expect(page.locator('a:has-text("Edit")')).not.toBeVisible();
    await expect(page.locator('button:has-text("Delete"), form[action*="/project/delete"] button')).not.toBeVisible();
  });

  test('viewer gets 403 on project update URL', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    const href = await link.getAttribute('href');
    const match = href?.match(/id=(\d+)/);
    if (!match) {
      test.skip(true, 'Project link has no numeric id');
      return;
    }
    await page.goto(`/project/update?id=${match[1]}`);
    await expectForbidden(page);
  });

  test('operator can see edit button on project view', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    await link.click();
    await expect(page.locator('a:has-text("Edit")').first()).toBeVisible({ timeout: 5_000 });
  });

  test('operator cannot delete projects (delete button hidden)', async ({ page }) => {
    await page.goto('/project/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No project seeded');
      return;
    }
    await link.click();
    await expect(page.locator('form[action*="/project/delete"] button')).not.toBeVisible();
  });
});
