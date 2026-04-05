import { test, expect } from '@playwright/test';
import { expectForbidden } from '../../lib/helpers';

test.describe('Roles RBAC', () => {
  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });

  test('viewer cannot access role index', async ({ page }) => {
    await page.goto('/role/index');
    await expectForbidden(page);
  });

  test('viewer cannot access role create form', async ({ page }) => {
    await page.goto('/role/create');
    await expectForbidden(page);
  });

  test('operator cannot access role index', async ({ page }) => {
    await page.goto('/role/index');
    await expectForbidden(page);
  });

  test('operator cannot access role create form', async ({ page }) => {
    await page.goto('/role/create');
    await expectForbidden(page);
  });

  test('operator cannot update a system role', async ({ page }) => {
    await page.goto('/role/update/viewer');
    await expectForbidden(page);
  });
});
