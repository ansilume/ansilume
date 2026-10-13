import { test, expect } from '@playwright/test';
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
 * Every signed-in user manages their own API tokens (ProfileController: role
 * '@'). Operators and viewers see a new token once, in its card only, as
 * admins do (profile/token-shown-once.spec.ts).
 *
 * Regression: the raw API token was printed a second time by the layout.
 */
test.describe('API tokens RBAC', () => {
  test.beforeEach(async ({}, testInfo) => {
    const title = testInfo.title.toLowerCase();
    const pn = testInfo.project.name;
    if (pn === 'viewer' && !title.startsWith('viewer')) test.skip();
    if (pn === 'operator' && !title.startsWith('operator')) test.skip();
  });

  for (const role of ['operator', 'viewer']) {
    test(`${role} sees a new API token once, in its card only`, async ({ page }) => {
      const name = `e2e-token-once-${role}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
      try {
        const token = await generateApiToken(page, name);
        await expectShownOnceInCard(page, token, API_TOKEN_CARD, API_TOKEN_FLASH);

        await page.reload();
        await expect(page.locator(API_TOKEN_CARD)).toHaveCount(0);
        await expectTokenNotInPage(page, token);

        await revokeApiToken(page, name);
      } finally {
        // An aborted run: revoke the token, so it cannot be used.
        await page.goto('/profile/tokens');
        const leftover = apiTokenRow(page, name).locator('form[action*="/profile/delete-token"]');
        if (await leftover.count() > 0) {
          await postWithPageCsrf(page, (await leftover.getAttribute('action')) ?? '');
          await page.goto('/profile/tokens');
        }
      }
    });
  }
});
