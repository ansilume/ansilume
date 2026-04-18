import { test, expect } from '@playwright/test';

/**
 * Covers the "Parse Inventory" button on /inventory/view — the user-
 * reported issue that clicking it sometimes appeared to do nothing.
 * The click POSTs to /inventory/parse-hosts?id=N, which runs
 * `ansible-inventory --list` on the runner and returns groups/hosts
 * as JSON. The JS handler then renders the result inline.
 */
test.describe('Inventory parse-hosts', () => {
  async function gotoFirstInventory(page: import('@playwright/test').Page): Promise<boolean> {
    await page.goto('/inventory/index');
    const link = page.locator('table.table tbody tr a').first();
    if (!(await link.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No inventory seeded');
      return false;
    }
    const href = await link.getAttribute('href');
    if (!href) {
      test.skip(true, 'Inventory row has no link');
      return false;
    }
    await page.goto(href);
    return true;
  }

  test('parse button POSTs and renders the resolved hosts/groups', async ({ page }) => {
    if (!(await gotoFirstInventory(page))) return;

    const btn = page.locator('#btn-parse-inventory');
    await expect(btn).toBeVisible({ timeout: 5_000 });

    // Capture the actual parse-hosts request so we can assert the button
    // truly fires — "nothing happens" failure mode means the handler
    // never reaches fetch().
    const [response] = await Promise.all([
      page.waitForResponse((r) =>
        r.request().method() === 'POST' && r.url().includes('/inventory/parse-hosts'),
        { timeout: 10_000 },
      ),
      btn.click(),
    ]);
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body).toHaveProperty('groups');
    expect(body).toHaveProperty('hosts');
    // A 200 response with `error` set means the backend silently served
    // a failure (e.g. ANSIBLE_HOME permission issue — that was the v2.2.9
    // fallout from the parsed_error column widening). Lock that down.
    expect(body.error).toBeNull();

    // After render the result card should no longer say "Click Parse …".
    const container = page.locator('#inventory-result');
    await expect(container).not.toContainText(/Click .*Parse Inventory.*to resolve/, { timeout: 5_000 });

    // Button label flips to "Refresh" once a parse has succeeded.
    await expect(btn).toHaveText(/Refresh/, { timeout: 5_000 });
  });

  test('parse button disables itself while the request is in flight', async ({ page }) => {
    if (!(await gotoFirstInventory(page))) return;

    const btn = page.locator('#btn-parse-inventory');
    await expect(btn).toBeVisible({ timeout: 5_000 });

    // Intercept the parse-hosts response to hold the request open long
    // enough for us to observe the in-flight button state.
    await page.route('**/inventory/parse-hosts**', async (route) => {
      await new Promise((r) => setTimeout(r, 500));
      await route.continue();
    });

    await btn.click();
    await expect(btn).toBeDisabled({ timeout: 2_000 });
    await expect(btn).toHaveText(/Parsing/, { timeout: 2_000 });

    // And re-enables after completion.
    await expect(btn).toBeEnabled({ timeout: 10_000 });
  });
});
