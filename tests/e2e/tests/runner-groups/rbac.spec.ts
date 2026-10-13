import { test, expect, Page } from '@playwright/test';
import { expectForbidden, postWithPageCsrf } from '../../lib/helpers';
import { GROUP_WITH_TEMPLATE, groupId, groupRow } from '../../lib/runner-groups';
import {
  RUNNER_TOKEN_CARD,
  RUNNER_TOKEN_FLASH,
  addRunner,
  deleteRunner,
  expectShownOnceInCard,
  expectTokenNotInPage,
  regenerateRunnerToken,
  runnerRow,
} from '../../lib/tokens';

/** Fixture from commands/E2eController.php seedSecondRunnerGroup(), with the runners of E2eRunnerRegistrationSeeder. */
const SECOND_GROUP = 'e2e-runner-group-2';

/** Opens the page of the runner group `name` from the runner group list; its id. */
async function openGroup(page: Page, name: string): Promise<string> {
  const id = await groupId(page, name);
  await page.goto(`/runner-group/view?id=${id}`);
  await expect(page.locator('#page-content h2').first()).toHaveText(name);
  return id;
}

/**
 * Only admins hold runner-group.delete: the list offers no Delete, and a
 * delete posted with the page's CSRF token answers 403. The group is one in
 * use (commands/E2eRunnerGroupGuardSeeder.php), so a delete that got past the
 * permission check would still be refused, not carried out.
 */
async function expectDeleteForbidden(page: Page): Promise<void> {
  const id = await groupId(page, GROUP_WITH_TEMPLATE);
  await page.goto('/runner-group/index');
  await expect(groupRow(page, GROUP_WITH_TEMPLATE)).toHaveCount(1);
  await expect(page.locator('#page-content form[action*="/runner-group/delete"]')).toHaveCount(0);

  const response = await postWithPageCsrf(page, `/runner-group/delete?id=${id}`);

  expect(response.status()).toBe(403);
  await page.goto('/runner-group/index');
  await expect(groupRow(page, GROUP_WITH_TEMPLATE)).toHaveCount(1);
}

test.describe('Runner Groups RBAC', () => {

  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !(title.startsWith('viewer') || title.startsWith('secrets'))) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });
  test('viewer can view index', async ({ page }) => {
    await page.goto('/runner-group/index');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('viewer sees re-registration notices but no runner actions', async ({ page }) => {
    await page.goto('/runner-group/index');
    await page.locator('table.table tbody tr a', { hasText: 'e2e-runner-group-2' }).first().click();

    const row = page.locator('table.table tbody tr', { hasText: 'e2e-reregistered-runner' });
    await expect(row.getByTestId('runner-reregistered-count')).toHaveText('2× in 24 h');
    await expect(row.locator('button:has-text("Regen Token"), button:has-text("Delete")')).toHaveCount(0);
  });

  test('viewer gets 403 on create', async ({ page }) => {
    await page.goto('/runner-group/create');
    await expectForbidden(page);
  });

  test('operator can create runner groups', async ({ page }) => {
    await page.goto('/runner-group/create');
    await expect(page.locator('body')).not.toContainText(/\bForbidden\b/i);
  });

  test('operator may not delete a runner group', async ({ page }) => {
    await expectDeleteForbidden(page);
  });

  // Operators hold runner-group.update: they add runners and regenerate
  // their tokens, and see each token once, as admins do
  // (runner-groups/token-shown-once.spec.ts).
  // Regression: the raw runner token was printed a second time by the layout.
  test('operator sees a new runner token and a regenerated one once, in their card only', async ({ page }) => {
    // e2e-ui-*: the next e2e/seed deletes such a runner if a run leaves one behind.
    const runner = `e2e-ui-token-op-runner-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
    await openGroup(page, SECOND_GROUP);
    try {
      const created = await addRunner(page, runner);
      await expectShownOnceInCard(page, created, RUNNER_TOKEN_CARD, RUNNER_TOKEN_FLASH);

      const regenerated = await regenerateRunnerToken(page, runner);
      expect(regenerated === created, 'the regenerated token is a new one').toBe(false);
      await expectShownOnceInCard(page, regenerated, RUNNER_TOKEN_CARD, RUNNER_TOKEN_FLASH);
      await expectTokenNotInPage(page, created);

      await page.reload();
      await expect(page.locator(RUNNER_TOKEN_CARD)).toHaveCount(0);
      await expectTokenNotInPage(page, regenerated);

      await deleteRunner(page, runner);
    } finally {
      // An aborted run: delete the runner, which also ends its token.
      await openGroup(page, SECOND_GROUP);
      const leftover = runnerRow(page, runner).locator('form[action*="/runner/delete"]');
      if (await leftover.count() > 0) {
        await postWithPageCsrf(page, (await leftover.getAttribute('action')) ?? '');
        await openGroup(page, SECOND_GROUP);
      }
    }
  });

  test('viewer may not add a runner to a group', async ({ page }) => {
    const runner = 'e2e-ui-token-viewer-runner';
    const id = await openGroup(page, SECOND_GROUP);
    await expect(page.locator('#page-content form[action*="/runner/create"]'), 'no Add Runner form').toHaveCount(0);

    const response = await postWithPageCsrf(page, '/runner/create', { group_id: id, name: runner });

    expect(response.status()).toBe(403);
    await openGroup(page, SECOND_GROUP);
    await expect(runnerRow(page, runner)).toHaveCount(0);
    await expect(page.locator(RUNNER_TOKEN_CARD)).toHaveCount(0);
  });

  test('viewer may not delete a runner group', async ({ page }) => {
    await expectDeleteForbidden(page);
  });

  // Fixture from commands/E2eRunnerRegistrationSeeder.php
  test('viewer sees the plain HTTP warning of a runner group', async ({ page }) => {
    await page.goto('/runner-group/index');
    await page.locator('table.table tbody tr a', { hasText: 'e2e-runner-group-2' }).first().click();

    await expect(page.getByTestId('runner-group-plaintext-warning')).toContainText('e2e-plaintext-runner');
  });
});
