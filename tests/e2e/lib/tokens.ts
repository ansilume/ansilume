import { Locator, Page, expect } from '@playwright/test';

/**
 * Helpers for the pages that show a new secret token once:
 * - the runner group page (views/runner-group/view.php) shows the token of a
 *   new runner, or a regenerated one, from the data flash runner_token;
 * - the API tokens page of the profile (views/profile/tokens.php) shows a new
 *   API token from the data flash new_token.
 *
 * Each page reads its data flash with delete=true and shows the token in a
 * card of its own; the layout (views/layouts/main.php) prints only flashes of
 * the alert types success, info, warning and danger, so it never prints a
 * data flash a second time (tests/unit/controllers/FlashTypesTest.php lists
 * the data flashes).
 *
 * A token is a secret: these helpers never put one into a locator, an
 * assertion message or an expected value, so a failure report shows counts
 * only.
 */

/** The card of the runner group page that shows a runner's new token: the token field and the docker one-liner. */
export const RUNNER_TOKEN_CARD = '#page-content .alert:has(#token-display)';

/** The card of the API tokens page that shows a new API token. */
export const API_TOKEN_CARD = '#page-content .alert:has(code.user-select-all)';

/** The data flash that carries a new runner token to the runner group page. */
export const RUNNER_TOKEN_FLASH = 'runner_token';

/** The data flash that carries a new API token to the API tokens page. */
export const API_TOKEN_FLASH = 'new_token';

/** How often the open page carries a secret, counted, never shown. */
export interface SecretCounts {
  /** In the page's HTML as served: text, attributes and form values. */
  html: number;
  /** In the HTML of the page's token card; -1 when the page has no such card. */
  card: number;
  /** In the page's visible text, which leaves out form values and attributes. */
  text: number;
  /** Elements with the class alert whose text holds it, the token card included. */
  alerts: number;
}

/** Counts `secret` in the open page, see SecretCounts; `cardSelector` names the page's token card. */
export async function secretCounts(page: Page, secret: string, cardSelector: string): Promise<SecretCounts> {
  expect(secret.length, 'a secret to count').toBeGreaterThan(0);
  return page.evaluate(({ value, selector }) => {
    const count = (haystack: string): number => haystack.split(value).length - 1;
    const card = document.querySelector(selector);
    return {
      html: count(document.documentElement.outerHTML),
      card: card === null ? -1 : count(card.outerHTML),
      text: count(document.body.innerText),
      alerts: Array.from(document.querySelectorAll('.alert')).filter((alert) => (alert.textContent ?? '').includes(value)).length,
    };
  }, { value: secret, selector: cardSelector });
}

/**
 * The open page shows `secret` exactly once, in its token card only:
 * - the visible text of the page holds it once;
 * - every occurrence in the page's HTML is inside the card (the card may
 *   carry it more than once, as a field value or for a copy button);
 * - the card is the only alert whose text holds it;
 * - the layout printed no alert for the data flash `flashKey` (on the old
 *   code it printed one per value of the flash: the raw token among them).
 */
export async function expectShownOnceInCard(page: Page, secret: string, cardSelector: string, flashKey: string): Promise<void> {
  await expect(page.locator(cardSelector), 'the token card').toHaveCount(1);
  const counts = await secretCounts(page, secret, cardSelector);
  expect(counts.text, 'occurrences of the token in the visible text of the page').toBe(1);
  expect(counts.card, 'occurrences of the token in its card').toBeGreaterThan(0);
  expect(counts.html, 'occurrences of the token in the page: none outside its card').toBe(counts.card);
  expect(counts.alerts, 'alerts whose text holds the token: its card only').toBe(1);
  await expect(page.locator(`.alert-${flashKey}`), `alerts the layout printed for the data flash ${flashKey}`).toHaveCount(0);
}

/** The open page carries `secret` nowhere: not in its text, its attributes or its form values. */
export async function expectTokenNotInPage(page: Page, secret: string): Promise<void> {
  const html = await page.content();
  expect(html.split(secret).length - 1, 'occurrences of the token in the page').toBe(0);
}

/** How often the text of the only element of `locator` holds `secret`. */
export async function occurrencesInText(locator: Locator, secret: string): Promise<number> {
  expect(secret.length, 'a secret to count').toBeGreaterThan(0);
  return locator.evaluate((element, value) => (element.textContent ?? '').split(value).length - 1, secret);
}

/**
 * Clicks `button`, which submits a form whose answer redirects to a URL that
 * matches `landsOn`, and waits for that page. Unlike waitForURL(), this waits
 * for the navigation also when the page is already at such a URL, as these
 * forms are (they post from the page they return to).
 */
async function submitAndWait(page: Page, button: Locator, landsOn: RegExp): Promise<void> {
  await Promise.all([
    page.waitForNavigation({ url: landsOn }),
    button.click(),
  ]);
}

/** Accepts the next dialog and records its message in `messages`. */
function acceptNextDialog(page: Page, messages: string[]): void {
  page.once('dialog', (dialog) => {
    messages.push(dialog.message());
    void dialog.accept();
  });
}

// ── Runner group page ───────────────────────────────────────────────────────

const RUNNER_GROUP_PAGE = /\/runner-group\/view\?id=\d+$/;

/** The row of the runner `name` in the runner table of the open runner group page. */
export function runnerRow(page: Page, name: string): Locator {
  return page.locator('#page-content table.table tbody tr').filter({
    has: page.locator('span.fw-semibold', { hasText: new RegExp(`^${name}$`) }),
  });
}

/** The token the token card of the open runner group page shows for the runner `name`; the caller must not print it. */
async function shownRunnerToken(page: Page, name: string): Promise<string> {
  await expect(page.locator(RUNNER_TOKEN_CARD).locator('h5')).toHaveText(`Token for runner ${name}`);
  const token = await page.locator('#token-display').inputValue();
  expect(/^[0-9a-f]{64}$/.test(token), 'the token field holds a runner token: 64 lowercase hex characters').toBe(true);
  return token;
}

/**
 * Adds the runner `name` through the Add Runner form of the open runner group
 * page; the group page opens again with the runner's token card. The new
 * token, which the caller must not print.
 */
export async function addRunner(page: Page, name: string): Promise<string> {
  const form = page.locator('#page-content form[action*="/runner/create"]');
  await form.locator('input[name="name"]').fill(name);
  await form.locator('input[name="description"]').fill('E2E: shows its token once');
  await submitAndWait(page, form.locator('button[type="submit"]'), RUNNER_GROUP_PAGE);
  return shownRunnerToken(page, name);
}

/**
 * Regenerates the token of the runner `name` on the open runner group page
 * and accepts the confirm dialog; the group page opens again with the token
 * card. The new token, which the caller must not print.
 */
export async function regenerateRunnerToken(page: Page, name: string): Promise<string> {
  const messages: string[] = [];
  acceptNextDialog(page, messages);
  await submitAndWait(page, runnerRow(page, name).getByRole('button', { name: 'Regen Token' }), RUNNER_GROUP_PAGE);
  expect(messages).toEqual([`Regenerate token for "${name}"? The old token will stop working immediately.`]);
  return shownRunnerToken(page, name);
}

/** Deletes the runner `name` from the open runner group page, accepting the confirm dialog. */
export async function deleteRunner(page: Page, name: string): Promise<void> {
  const messages: string[] = [];
  acceptNextDialog(page, messages);
  await submitAndWait(page, runnerRow(page, name).getByRole('button', { name: 'Delete' }), RUNNER_GROUP_PAGE);
  expect(messages).toEqual([`Delete runner "${name}"?`]);
  await expect(page.locator('#page-content > .alert-success')).toHaveText(`Runner "${name}" deleted.`);
  await expect(runnerRow(page, name)).toHaveCount(0);
}

// ── API tokens page of the profile ──────────────────────────────────────────

const API_TOKENS_PAGE = /\/profile\/tokens$/;

/** The row of the API token `name` on the open API tokens page. */
export function apiTokenRow(page: Page, name: string): Locator {
  return page.locator('#page-content table tbody tr').filter({
    has: page.locator('td:first-child', { hasText: new RegExp(`^${name}$`) }),
  });
}

/**
 * Generates the API token `name` on the API tokens page of the signed-in
 * user; the page opens again with the token card. The new token, which the
 * caller must not print.
 */
export async function generateApiToken(page: Page, name: string): Promise<string> {
  await page.goto('/profile/tokens');
  const form = page.locator('#page-content form[action*="/profile/create-token"]');
  await form.locator('input[name="name"]').fill(name);
  await submitAndWait(page, form.locator('button[type="submit"]'), API_TOKENS_PAGE);
  await expect(page.locator('#page-content > .alert-success')).toHaveText('Token created. Copy it now — it will not be shown again.');
  await expect(apiTokenRow(page, name)).toHaveCount(1);
  const token = ((await page.locator(API_TOKEN_CARD).locator('code').textContent()) ?? '').trim();
  expect(/^[0-9a-f]{64}$/.test(token), 'the card holds an API token: 64 lowercase hex characters').toBe(true);
  return token;
}

/** Revokes the API token `name` on the open API tokens page, accepting the confirm dialog. */
export async function revokeApiToken(page: Page, name: string): Promise<void> {
  const messages: string[] = [];
  acceptNextDialog(page, messages);
  await submitAndWait(page, apiTokenRow(page, name).getByRole('button', { name: 'Revoke' }), API_TOKENS_PAGE);
  expect(messages).toEqual([`Revoke token "${name}"?`]);
  await expect(page.locator('#page-content > .alert-success')).toHaveText(`Token "${name}" revoked.`);
  await expect(apiTokenRow(page, name)).toHaveCount(0);
}
