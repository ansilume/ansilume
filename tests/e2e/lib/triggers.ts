import { Page, expect } from '@playwright/test';
import { idWhere, indexRows } from './scoping';

/**
 * Helpers for inbound triggers and the trigger cards of job and workflow
 * templates (views/trigger/_usage.php).
 */

/** The header that carries a trigger token. */
export const TRIGGER_HEADER = 'X-Trigger-Token';

/** The element id prefix of a trigger card: '' on job templates, 'wf-' on workflow templates. */
export type CardPrefix = '' | 'wf-';

/** Opens the page of the job template named `name`; its id. */
export async function openJobTemplate(page: Page, name: string): Promise<string> {
  const id = idWhere(await indexRows(page, '/job-template/index?sort=name'), 1, name);
  await page.goto(`/job-template/view?id=${id}`);
  return id;
}

/** Opens the page of the workflow template named `name`; its id. */
export async function openWorkflowTemplate(page: Page, name: string): Promise<string> {
  const id = idWhere(await indexRows(page, '/workflow-template/index'), 0, name);
  await page.goto(`/workflow-template/view?id=${id}`);
  return id;
}

/** Revokes the trigger token of the open template page, if it has one. */
export async function revokeTriggerTokenIfPresent(page: Page): Promise<void> {
  const revoke = page.locator('#page-content form[action*="/revoke-trigger-token"] button');
  if (await revoke.count() > 0) {
    page.once('dialog', (dialog) => dialog.accept());
    await revoke.click();
    await expect(page.locator('#page-content form[action*="/generate-trigger-token"] button')).toBeVisible();
  }
}

/** Generates a new trigger token on the open template page; the raw token, which the card shows once. */
export async function generateTriggerToken(page: Page, prefix: CardPrefix): Promise<string> {
  await revokeTriggerTokenIfPresent(page);
  await page.locator('#page-content form[action*="/generate-trigger-token"] button').click();
  const display = page.locator(`#${prefix}trigger-token-display`);
  await expect(display).toBeVisible();
  const token = ((await display.textContent()) ?? '').trim();
  expect(token, 'a trigger token is 64 lowercase hex characters').toMatch(/^[0-9a-f]{64}$/);
  return token;
}

/** The cURL example of a trigger card for `token` and the card's endpoint URL. */
export function curlExample(token: string, endpoint: string): string {
  return `curl -X POST -H "${TRIGGER_HEADER}: ${token}" -H "Content-Type: application/json" -d "{}" ${endpoint}`;
}

/**
 * Records the text copyToClipboard() copies, for lastCopied(). Without
 * navigator.clipboard, as on plain HTTP, it copies a selected textarea with
 * document.execCommand('copy'), which this wraps. Call it before the page loads.
 */
export async function recordCopies(page: Page): Promise<void> {
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'clipboard', { configurable: true, get: () => undefined });
    const execCommand = document.execCommand.bind(document);
    document.execCommand = (command: string, showUi?: boolean, value?: string): boolean => {
      if (command === 'copy') {
        const selected = document.activeElement as HTMLTextAreaElement | null;
        (window as unknown as { e2eCopied?: string }).e2eCopied = selected?.value;
      }
      return execCommand(command, showUi, value);
    };
  });
}

/** The text copied last, see recordCopies(). */
export async function lastCopied(page: Page): Promise<string | undefined> {
  return page.evaluate(() => (window as unknown as { e2eCopied?: string }).e2eCopied);
}
