import { test, expect, Page } from '@playwright/test';

// Regression: when ansible-lint cannot start, here because the web server's
// user may list but not enter the project directory (PHP 8.3,
// ProcessStarter), lint stores "Lint did not run: …" with the exit code -1,
// which was badged "issues found". Fixture:
// commands/E2eLintLockedProjectSeeder.php.
const PROJECT = 'e2e-lint-locked-project';

/** Opens the project page, paging through the list (newest first); false when it is not seeded. */
async function openProject(page: Page, name: string): Promise<boolean> {
  for (let listPage = 1; listPage <= 10; listPage++) {
    await page.goto(`/project/index?page=${listPage}`);
    const link = page.locator('#project-table tbody tr a', { hasText: new RegExp(`^${name}$`) }).first();
    if (await link.count() > 0) {
      await link.click();
      await expect(page.locator('h2').first()).toHaveText(name);
      return true;
    }
  }
  return false;
}

test.describe('Lint that cannot start', () => {
  test('Run Lint on a directory the server may not enter says "not run" and why', async ({ page }) => {
    test.setTimeout(120_000);
    if (!(await openProject(page, PROJECT))) {
      test.skip(true, `${PROJECT} is not seeded (the seeder must run as root)`);
      return;
    }

    await page.locator('button:has-text("Run Lint")').click();

    // The output first: the seed leaves no lint result, whose badge says "not run" too.
    await expect(page.locator('#lint-output')).toContainText('Lint did not run: ansible-lint could not be started in ', { timeout: 90_000 });
    await expect(page.locator('#lint-output')).toContainText('posix_spawn() failed: Permission denied');
    await expect(page.getByTestId('lint-badge')).toHaveText('not run');
  });
});
