import { test, expect } from '@playwright/test';
import { expectFlash } from '../../lib/helpers';
import { LINT_QUEUED, LINT_RAN, expectLintResult, lintQueuedFlash, lintStatus, openProject } from '../../lib/lint';

// Run Lint queues a lint run of the whole project, which the queue-worker
// makes (LintService::queueForProject()): the flash says so, the lint card
// shows "Lint queued" and reloads the page itself once the result is
// stored. Up to this release Run Lint ran ansible-lint in the request.

test.describe('Project lint queue', () => {
  test('Run Lint shows the flash and Lint queued, then the result without a reload', async ({ page }) => {
    test.setTimeout(150_000);
    const id = await openProject(page, 'e2e-project');

    await page.getByRole('button', { name: /run\s*lint/i }).click();

    await expectFlash(page, 'success', lintQueuedFlash('e2e-project'));
    await expect(page.getByTestId('lint-queued')).toHaveText(LINT_QUEUED);
    await expectLintResult(page);
    await expect(page.getByTestId('lint-badge')).toHaveText(LINT_RAN);
    // The queue-worker sees the project's directory (the runtime directory of the checkout).
    await expect(page.locator('#lint-output')).not.toContainText('Project path not found');
    const answer = await lintStatus(page, 'project', id);
    expect(answer.status()).toBe(200);
    expect(((await answer.json()) as { queued: boolean }).queued).toBe(false);
  });
});
