import { test, expect, Page } from '@playwright/test';
import { E2E_WATCHER, asUser } from '../../lib/session';

// Regression: the project page answered 500 for a path the web server's user
// (www-data) may not read: a directory, a playbook, a directory below
// playbooks/, and through a symlink to /etc, which it followed into the
// directories of /etc it may not read. It shows what it can read and names
// the rest; symlinks are shown, not followed (ProjectFilesystemScanner).
// Fixture: commands/E2eUnreadableProjectSeeder.php.
const PROJECT = 'e2e-unreadable-project';

/** ProjectFilesNote::UNREADABLE_PATHS with the fixture's paths (E2eUnreadableProjectSeeder::UNREADABLE). */
const NOTE = "The web server cannot read these paths in the project directory, so the playbooks and the repository structure below may be incomplete: playbooks/locked, private, secret.yml. Give the web server's user permission to read them.";

/** E2eUnreadableProjectSeeder::UNLISTABLE_PROJECT, whose directory is root's, mode 0700. */
const UNLISTABLE = 'e2e-unlistable-project';

/** ProjectFilesNote::UNREADABLE_DIRECTORY. */
const UNLISTABLE_NOTE = "The web server cannot read the project directory, so its playbooks and repository structure are not shown. Give the web server's user permission to read and enter it.";

/** E2eUnreadableProjectSeeder::UNREACHABLE_PROJECT, below a directory only root may enter. */
const UNREACHABLE = 'e2e-unreachable-project';

/** views/project/view.php: the hint for a project with nothing to show yet. */
const SYNC_HINT = 'Sync the project to detect playbooks and browse the repository structure.';

/** The address of the project's page, paging through the list (newest first); null when it is not seeded. */
async function projectAddress(page: Page, name: string): Promise<string | null> {
  for (let listPage = 1; listPage <= 10; listPage++) {
    await page.goto(`/project/index?page=${listPage}`);
    const link = page.locator('#project-table tbody tr a', { hasText: new RegExp(`^${name}$`) }).first();
    if (await link.count() > 0) {
      return link.getAttribute('href');
    }
  }
  return null;
}

/** Opens the page and checks what it shows. */
async function expectThePageNamesWhatItCannotRead(page: Page, address: string): Promise<void> {
  const response = await page.goto(address);
  expect(response?.status(), 'the project page renders').toBe(200);
  await expect(page.locator('h2').first()).toHaveText(PROJECT);

  await expect(page.getByTestId('project-unreadable')).toHaveText(NOTE);
  const playbooks = page.locator('.card', { has: page.locator('.card-header', { hasText: 'Detected Playbooks' }) });
  await expect(playbooks.locator('li code')).toHaveText(['playbooks/deploy.yml', 'site.yml']);

  const tree = page.getByTestId('project-tree');
  await expect(tree).toContainText('📁 private (not readable)');
  await expect(tree).toContainText('📁 locked (not readable)');
  await expect(tree).toContainText('secret.yml (not readable)');
  await expect(tree).toContainText('📁 group.yml');
  await expect(tree).toContainText('🔗 etc');
  // The link to /etc is not followed.
  await expect(tree).not.toContainText('passwd');
}

test.describe('Project files the web server cannot read', () => {
  test('the project page names what it cannot read and shows the rest', async ({ page }) => {
    const address = await projectAddress(page, PROJECT);
    test.skip(address === null, `${PROJECT} is not seeded (the seeder must run as root)`);

    await expectThePageNamesWhatItCannotRead(page, address ?? '');
  });

  test('a project directory the web server may not list says so, without the sync hint', async ({ page }) => {
    const address = await projectAddress(page, UNLISTABLE);
    test.skip(address === null, `${UNLISTABLE} is not seeded (the seeder must run as root)`);

    const response = await page.goto(address ?? '');
    expect(response?.status(), 'the project page renders').toBe(200);
    await expect(page.getByTestId('project-unreadable')).toHaveText(UNLISTABLE_NOTE);
    await expect(page.getByTestId('project-tree')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText(SYNC_HINT);
  });

  test('a manual project below a directory the web server may not enter says so', async ({ page }) => {
    const address = await projectAddress(page, UNREACHABLE);
    test.skip(address === null, `${UNREACHABLE} is not seeded (the seeder must run as root)`);

    const response = await page.goto(address ?? '');
    expect(response?.status(), 'the project page renders').toBe(200);
    const note = page.getByTestId('project-unreadable');
    await expect(note).toContainText('The web server cannot find the project directory /');
    await expect(note).toContainText("/project, or may not enter a directory above it. Check that it exists, and give the web server's user permission to enter every directory on its path.");
    await expect(page.locator('body')).not.toContainText(SYNC_HINT);
  });

  test('a viewer sees the same page', async ({ page, browser }, testInfo) => {
    const address = await projectAddress(page, PROJECT);
    test.skip(address === null, `${PROJECT} is not seeded (the seeder must run as root)`);

    await asUser(browser, testInfo, E2E_WATCHER, async (viewer) => {
      await expectThePageNamesWhatItCannotRead(viewer, address ?? '');
      // A viewer may not create job templates from the playbooks.
      await expect(viewer.locator('a:has-text("Create Template")')).toHaveCount(0);
    });
  });
});
