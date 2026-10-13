import { test, expect } from '@playwright/test';
import { flash } from '../../lib/approvals';
import { fillForm } from '../../lib/helpers';
import { removeGroupsNamed } from '../../lib/runner-groups';
import {
  RUNNER_TOKEN_CARD,
  RUNNER_TOKEN_FLASH,
  addRunner,
  deleteRunner,
  expectShownOnceInCard,
  expectTokenNotInPage,
  occurrencesInText,
  regenerateRunnerToken,
} from '../../lib/tokens';

/**
 * The token of a new runner, and a regenerated one, is shown once: by the
 * token card of the runner group page (the token field, with the docker
 * one-liner that embeds it), and by nothing else on the page. The page reads
 * the data flash runner_token with delete=true, and the layout prints only
 * alert types, so the layout cannot print it again.
 *
 * Regression: the raw runner token was printed a second time by the layout.
 * The page read the flash without deleting it, and the layout printed every
 * flash that was left as an unstyled alert, one per value: the runner's id,
 * its name and the raw token.
 *
 * The spec makes its own runner group and runner and removes both; the token
 * is never printed (lib/tokens.ts counts it). Operator and viewer:
 * runner-groups/rbac.spec.ts.
 */
test.describe('Runner token shown once', () => {
  const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
  const group = `e2e-token-group-${suffix}`;
  // e2e-ui-*: the next e2e/seed deletes such a runner if a run leaves one behind.
  const runner = `e2e-ui-token-runner-${suffix}`;

  test.afterEach(async ({ page }) => {
    // Also deletes a runner an aborted run left, which ends its token.
    await removeGroupsNamed(page, group);
  });

  test('a new runner token and a regenerated one are shown once, in their card only', async ({ page }) => {
    await page.goto('/runner-group/create');
    await fillForm(page, 'runnergroup', { name: group, description: 'E2E: a runner token is shown once' });
    await Promise.all([
      page.waitForURL(/\/runner-group\/view\?id=\d+$/),
      page.locator('#page-content button[type="submit"]').click(),
    ]);
    await expect(flash(page, 'success')).toHaveText(`Runner group "${group}" created.`);
    await expect(page.locator(RUNNER_TOKEN_CARD), 'no token before a runner exists').toHaveCount(0);

    // Regression: the raw runner token was printed a second time by the layout.
    const created = await addRunner(page, runner);
    await expectShownOnceInCard(page, created, RUNNER_TOKEN_CARD, RUNNER_TOKEN_FLASH);
    expect(await occurrencesInText(page.locator('#docker-snippet'), `-e RUNNER_TOKEN=${created} `), 'the docker one-liner sets RUNNER_TOKEN to it').toBe(1);

    const regenerated = await regenerateRunnerToken(page, runner);
    expect(regenerated === created, 'the regenerated token is a new one').toBe(false);
    await expectShownOnceInCard(page, regenerated, RUNNER_TOKEN_CARD, RUNNER_TOKEN_FLASH);
    expect(await occurrencesInText(page.locator('#docker-snippet'), `-e RUNNER_TOKEN=${regenerated} `), 'the docker one-liner sets RUNNER_TOKEN to it').toBe(1);
    await expectTokenNotInPage(page, created);

    // Shown once only: the next request shows no token.
    await page.reload();
    await expect(page.locator(RUNNER_TOKEN_CARD)).toHaveCount(0);
    await expect(page.locator(`.alert-${RUNNER_TOKEN_FLASH}`)).toHaveCount(0);
    await expectTokenNotInPage(page, regenerated);

    await deleteRunner(page, runner);
  });
});
