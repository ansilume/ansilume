import { APIResponse, expect, Page } from '@playwright/test';

/**
 * Helpers for the lint card of the job template and project pages
 * (views/project/_lint.php). Lint never runs in a request: a save of a job
 * template and Run Lint queue a run, which the queue-worker makes
 * (LintService::queueForTemplate(), queueForProject()); the card shows
 * "Lint queued" and polls lint-status, and the page reloads itself once the
 * run has stored its result.
 */

/** components/LintState.php QUEUED: the badge of a queued lint run. */
export const LINT_QUEUED = 'Lint queued';

/** controllers/ProjectController.php FLASH_LINT_QUEUED: the flash of Run Lint. */
export function lintQueuedFlash(project: string): string {
  return `Lint queued for "${project}".`;
}

/** How long a queued run may take: the queue-worker runs one job after the other. */
export const LINT_RESULT_TIMEOUT = 90_000;

/** A verdict of a lint run that ran: ansible-lint found the files. */
export const LINT_RAN = /^(clean|issues found)$/;

/**
 * Waits until the open page shows the result of its queued lint run. The
 * badge "Lint queued" is rendered by the server, so only a reload removes
 * it: the card's poll reloads the page once the run has stored its result,
 * without the test reloading anything.
 */
export async function expectLintResult(page: Page): Promise<void> {
  await expect(page.getByTestId('lint-queued')).toHaveCount(0, { timeout: LINT_RESULT_TIMEOUT });
  await expect(page.getByTestId('lint-badge')).toBeVisible();
}

/** The answer of lint-status to the signed-in user, asked for JSON as the card's poll asks. */
export async function lintStatus(page: Page, controller: 'job-template' | 'project', id: string): Promise<APIResponse> {
  return page.request.get(`/${controller}/lint-status?id=${id}`, { headers: { Accept: 'application/json' } });
}

/** Opens the page of the project `name`, paging through the list (newest first); its ID. */
export async function openProject(page: Page, name: string): Promise<string> {
  for (let listPage = 1; listPage <= 10; listPage++) {
    await page.goto(`/project/index?page=${listPage}`);
    const link = page.locator('#project-table tbody tr a', { hasText: new RegExp(`^${name}$`) }).first();
    if (await link.count() > 0) {
      await link.click();
      await expect(page.locator('h2').first()).toHaveText(name);
      return new URL(page.url()).searchParams.get('id') ?? '';
    }
  }
  throw new Error(`Project ${name} is not listed`);
}
