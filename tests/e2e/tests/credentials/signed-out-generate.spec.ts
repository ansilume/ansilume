import { test, expect, Page, Route } from '@playwright/test';
import { asUser, E2E_ADMIN } from '../../lib/session';

/**
 * What the credential form's Generate Ed25519 Key Pair says when the server
 * refuses the request with `status` (401 or 403): a literal of the script
 * in views/credential/form.php.
 */
function refused(status: number): string {
  return `The server refused the request (HTTP ${status}): your session may have ended, or you are not allowed to do this; reload the page and sign in.`;
}

const GENERATE_PATH = '/credential/generate-ssh-key';

async function openSshKeyForm(page: Page) {
  await page.goto('/credential/create');
  await page.locator('#credential-type').selectOption('ssh_key');
  const generate = page.locator('#btn-generate-key');
  await expect(generate).toBeVisible();
  return generate;
}

/** Collects the messages of the page's dialogs (alert()), accepting each. */
function dialogsOf(page: Page): string[] {
  const messages: string[] = [];
  page.on('dialog', (dialog) => {
    messages.push(dialog.message());
    void dialog.accept();
  });
  return messages;
}

async function expectNothingGenerated(page: Page) {
  const generate = page.locator('#btn-generate-key');
  await expect(generate).toBeEnabled();
  await expect(generate).toHaveText('Generate Ed25519 Key Pair');
  await expect(page.locator('#ssh-private-key')).toHaveValue('');
  await expect(page.locator('#ssh-pubkey-block')).toBeHidden();
}

test.describe('Generate Ed25519 Key Pair, refused', () => {
  // regression: signed out, the button's POST asked for anything (no
  // Accept: application/json), so the server sent it to the login page;
  // fetch followed, the script read the login page as JSON and said
  // "Request failed: SyntaxError: ...". Asked for JSON, the POST is refused
  // with 403, and the form says so before it reads the answer. In a session
  // of its own, so no other spec's session ends.
  test('regression: signed out, the button says the session may have ended', async ({ browser }, testInfo) => {
    await asUser(browser, testInfo, E2E_ADMIN, async (page) => {
      const generate = await openSshKeyForm(page);
      const dialogs = dialogsOf(page);

      // Signed out in another tab: the session is gone.
      await page.context().clearCookies();
      const [answer] = await Promise.all([
        page.waitForResponse((response) => new URL(response.url()).pathname === GENERATE_PATH),
        generate.click(),
      ]);

      expect(answer.request().method()).toBe('POST');
      expect(answer.request().headers()['accept'], 'the request asks for JSON').toBe('application/json');
      expect(answer.status(), 'refused, not sent to the login page').toBe(403);
      await expect.poll(() => dialogs, { message: 'the dialog names the status and what to do' }).toEqual([refused(403)]);
      expect(dialogs.join('\n')).not.toContain('SyntaxError');
      await expectNothingGenerated(page);
    });
  });

  test('a 401 answer says the same, with its status', async ({ page }) => {
    const generate = await openSshKeyForm(page);
    const dialogs = dialogsOf(page);
    await page.route(`**${GENERATE_PATH}`, (route: Route) => route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({ name: 'Unauthorized', message: 'Your request was made with invalid credentials.' }),
    }));

    await generate.click();

    await expect.poll(() => dialogs).toEqual([refused(401)]);
    await expectNothingGenerated(page);
  });

  test('another failure keeps its message', async ({ page }) => {
    const generate = await openSshKeyForm(page);
    const dialogs = dialogsOf(page);
    // CredentialController::actionGenerateSshKey() answers a failed generation so.
    await page.route(`**${GENERATE_PATH}`, (route: Route) => route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ ok: false, error: 'Key generation failed.' }),
    }));

    await generate.click();

    await expect.poll(() => dialogs).toEqual(['Key generation failed: Key generation failed.']);
    await expectNothingGenerated(page);

    // An answer that is no JSON, such as a proxy's error page.
    await page.unroute(`**${GENERATE_PATH}`);
    await page.route(`**${GENERATE_PATH}`, (route: Route) => route.fulfill({
      status: 502,
      contentType: 'text/html',
      body: '<html><body>Bad Gateway</body></html>',
    }));

    await generate.click();

    await expect.poll(() => dialogs.length).toBe(2);
    expect(dialogs[1]).toMatch(/^Request failed: SyntaxError/);
    await expectNothingGenerated(page);
  });
});
