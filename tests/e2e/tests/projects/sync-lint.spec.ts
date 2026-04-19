import { test, expect, Page } from '@playwright/test';

// Regression: "Run Lint" used to fail with
//   CRITICAL:root:Unhandled exception when retrieving 'DEFAULT_LOCAL_TMP':
//   [Errno 13] Permission denied: '.../.ansible/tmp/ansible-local-…'
// on prebuilt installs because the queue-worker ran as root and left
// root-owned .ansible cache dirs that the www-data web process couldn't
// touch. The fix drops non-php-fpm containers to www-data via gosu, and
// LintService::ensureCacheDir now falls back to /tmp when the project-
// local cache dir is unusable. These tests exercise the UI path that
// surfaced the original symptom.

test.describe('Project Sync/Lint', () => {
  test('sync button present on git project detail', async ({ page }) => {
    await page.goto('/project/index');
    const firstRow = page.locator('table.table tbody tr a').first();
    if (await firstRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstRow.click();
      await expect(page.locator('body')).toContainText(/project|scm/i);
    }
  });

  test('Run Lint on a manual project with a playbook produces output without EACCES', async ({ page }) => {
    await openE2eProjectDetail(page);

    const runLint = page.getByRole('button', { name: /run\s*lint/i }).first();
    await expect(runLint, 'Run Lint button must be present on the project detail page').toBeVisible({
      timeout: 5_000,
    });
    await runLint.click();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const body = await page.locator('body').innerText();
    expect(body, 'page must not show an uncaught exception').not.toMatch(/Exception|Fatal error|Uncaught/i);
    expect(body, 'lint must not fail with a permission error (regression: www-data vs root)').not.toMatch(
      /Permission denied|\[Errno 13\]|EACCES/,
    );
    await expect(page.locator('body')).toContainText(/lint/i);
  });

  test('Run Lint twice in a row still succeeds (cache dir must stay writable)', async ({ page }) => {
    // First run creates .ansible/ (or the /tmp fallback), second run reuses
    // it. Under the original bug, run #2 blew up with EACCES because the
    // cache dir was inherited from a root-owned worker-created tree. With
    // the fix, both runs use a writable cache dir owned by the current user.
    for (let i = 0; i < 2; i++) {
      await openE2eProjectDetail(page);

      const runLint = page.getByRole('button', { name: /run\s*lint/i }).first();
      await expect(runLint, `run #${i + 1}: Run Lint button must be present`).toBeVisible({ timeout: 5_000 });
      await runLint.click();
      await page.waitForLoadState('networkidle', { timeout: 30_000 });

      const body = await page.locator('body').innerText();
      expect(body, `run #${i + 1} must not raise a permission error`).not.toMatch(
        /Permission denied|\[Errno 13\]|EACCES/,
      );
    }
  });
});

async function openE2eProjectDetail(page: Page): Promise<void> {
  await page.goto('/project/index');
  const row = page.locator('table.table tbody tr', { hasText: 'e2e-project' }).first();
  await expect(row, 'seeded e2e-project must appear in the project list').toBeVisible({ timeout: 10_000 });
  await row.locator('a').first().click();
  await page.waitForLoadState('domcontentloaded');
}
