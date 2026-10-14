import { test, expect, Page, Route } from '@playwright/test';
import { asUser, E2E_ADMIN } from '../../lib/session';

/**
 * What an inventory's Parse Inventory says when the server refuses the
 * request with `status` (401 or 403): a literal of the script in
 * views/inventory/view.php.
 */
function refused(status: number): string {
  return `The server refused the request (HTTP ${status}): your session may have ended, or you are not allowed to do this; reload the page and sign in.`;
}

/** What the script said of the login page, signed out, before 2.11.0. */
const NOT_JSON = 'Server response was not JSON';

/** The seeded inventory (commands/E2eController.php). */
const INVENTORY = 'e2e-inventory';

/** The parse POST of an inventory's page: /inventory/parse-hosts/<id>. */
const PARSE_ROUTE = '**/inventory/parse-hosts/*';

/** Opens the page of the inventory named `name`, paging through the list. */
async function openInventory(page: Page, name: string) {
  for (let listPage = 1; listPage <= 10; listPage++) {
    await page.goto(`/inventory/index?page=${listPage}`);
    const link = page.locator('table.table tbody tr a', { hasText: new RegExp(`^${name}$`) }).first();
    if (await link.count() > 0) {
      await link.click();
      await expect(page.locator('#btn-parse-inventory')).toBeVisible();
      return;
    }
  }
  throw new Error(`Inventory ${name} is not listed`);
}

function isParse(url: string): boolean {
  return new URL(url).pathname.startsWith('/inventory/parse-hosts');
}

/** The failure the script shows in the result card. */
function failure(page: Page) {
  return page.locator('#inventory-result [role="alert"]');
}

test.describe('Parse Inventory, refused', () => {
  // regression: signed out, the parse POST did not ask for JSON, so the
  // server sent it to the login page (with X-Requested-With, Yii names the
  // login page in X-Redirect: the script got the redirect, with an empty
  // body) and the result card said "Server response was not JSON". Asked
  // for JSON, the POST is refused with 403, and the card says so before the
  // answer is read. In a session of its own, so no other spec's session ends.
  test('regression: signed out, the result card says the session may have ended', async ({ browser }, testInfo) => {
    await asUser(browser, testInfo, E2E_ADMIN, async (page) => {
      await openInventory(page, INVENTORY);
      const parse = page.locator('#btn-parse-inventory');

      // Signed out in another tab: the session is gone.
      await page.context().clearCookies();
      const [answer] = await Promise.all([
        page.waitForResponse((response) => isParse(response.url())),
        parse.click(),
      ]);

      expect(answer.request().method()).toBe('POST');
      expect(answer.request().headers()['accept'], 'the request asks for JSON').toBe('application/json');
      expect(answer.request().headers()['x-requested-with']).toBe('XMLHttpRequest');
      expect(answer.status(), 'refused, not sent to the login page').toBe(403);
      await expect(failure(page)).toHaveText(refused(403));
      await expect(page.locator('#inventory-result')).not.toContainText(NOT_JSON);
      await expect(parse).toBeEnabled();
    });
  });

  test('a 401 answer says the same, with its status', async ({ page }) => {
    await openInventory(page, INVENTORY);
    await page.route(PARSE_ROUTE, (route: Route) => route.fulfill({
      status: 401,
      contentType: 'text/html',
      body: '<html><body>Unauthorized</body></html>',
    }));

    await page.locator('#btn-parse-inventory').click();

    await expect(failure(page)).toHaveText(refused(401));
    await expect(page.locator('#btn-parse-inventory')).toBeEnabled();
  });

  test('another failure keeps its message', async ({ page }) => {
    await openInventory(page, INVENTORY);
    const parse = page.locator('#btn-parse-inventory');
    await page.route(PARSE_ROUTE, (route: Route) => route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'e2e: the parse failed' }),
    }));

    await parse.click();

    await expect(failure(page).locator('strong')).toHaveText('Server error');
    await expect(failure(page).locator('.badge')).toHaveText('HTTP 500');
    await expect(failure(page).locator('pre')).toHaveText('e2e: the parse failed');

    // An answer that is no JSON, such as a proxy's error page.
    await page.unroute(PARSE_ROUTE);
    await page.route(PARSE_ROUTE, (route: Route) => route.fulfill({
      status: 502,
      contentType: 'text/html',
      body: '<b>Bad Gateway</b>',
    }));

    await parse.click();

    await expect(failure(page).locator('strong')).toHaveText(NOT_JSON);
    await expect(failure(page).locator('.badge')).toHaveText('HTTP 502');
    await expect(failure(page).locator('pre'), 'the answer is shown as text').toHaveText('<b>Bad Gateway</b>');
  });
});
