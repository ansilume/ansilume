import { test, expect, Page } from '@playwright/test';
import { postWithPageCsrf } from '../../lib/helpers';
import {
  API_TOKEN_CARD,
  API_TOKEN_FLASH,
  apiTokenRow,
  expectShownOnceInCard,
  expectTokenNotInPage,
  generateApiToken,
  revokeApiToken,
} from '../../lib/tokens';

/**
 * A new API token is shown once: by the token card of the API tokens page
 * (views/profile/tokens.php), and by nothing else on the page. The page
 * reads the data flash new_token with delete=true, and the layout prints only
 * alert types, so the layout cannot print it again.
 *
 * Regression: the raw API token was printed a second time by the layout. The
 * controller read the flash without deleting it, and the layout printed every
 * flash that was left: the raw token as an unstyled alert above the card.
 *
 * The token is revoked at the end and never printed (lib/tokens.ts counts
 * it). Operator and viewer: profile/rbac.spec.ts.
 */

/** Revokes what an aborted run left of the API token `name`. */
async function revokeLeftover(page: Page, name: string): Promise<void> {
  await page.goto('/profile/tokens');
  const leftover = apiTokenRow(page, name).locator('form[action*="/profile/delete-token"]');
  if (await leftover.count() > 0) {
    await postWithPageCsrf(page, (await leftover.getAttribute('action')) ?? '');
    // Uses up the flash of the revoke, which the next spec would find otherwise.
    await page.goto('/profile/tokens');
  }
}

test.describe('API token shown once', () => {
  const name = `e2e-token-once-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;

  test.afterEach(async ({ page }) => {
    await revokeLeftover(page, name);
  });

  test('a new API token is shown once, in its card only', async ({ page }) => {
    // Regression: the raw API token was printed a second time by the layout.
    const token = await generateApiToken(page, name);
    await expectShownOnceInCard(page, token, API_TOKEN_CARD, API_TOKEN_FLASH);
    await expect(page.locator(API_TOKEN_CARD)).toContainText('New token created.');

    // Shown once only: the next request shows no token.
    await page.reload();
    await expect(page.locator(API_TOKEN_CARD)).toHaveCount(0);
    await expect(page.locator(`.alert-${API_TOKEN_FLASH}`)).toHaveCount(0);
    await expectTokenNotInPage(page, token);
    await expect(apiTokenRow(page, name), 'the token is listed by its name').toHaveCount(1);

    await revokeApiToken(page, name);
  });
});
