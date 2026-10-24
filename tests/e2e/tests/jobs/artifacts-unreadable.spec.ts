import { test, expect, Locator, Page } from '@playwright/test';
import { seededJobId } from '../../lib/jobs';

/**
 * Regression: "Download All", the download and the preview of artifacts
 * whose files are gone or that the web server may not read answered 500.
 * They answer 404 with the reason now (ArtifactService). Fixture:
 * commands/E2eUnreadableArtifactSeeder.php: gone.json and gone.txt have no
 * files, locked.txt is root's file that www-data may not read (seeded only
 * as root).
 */
const JOB = 'e2e-unreadable-artifacts-job';

/** views/job/view.php: what the preview of a text artifact shows when its fetch failed. */
const PREVIEW_FAILED = 'Failed to load preview.';

test.describe('Artifacts whose files are gone or cannot be read', () => {
  let jobId = '';

  async function openJob(page: Page): Promise<Locator> {
    if (jobId === '') {
      jobId = await seededJobId(page, JOB);
    }
    await page.goto(`/job/view?id=${jobId}`);
    const card = page.locator('.card', { has: page.locator('.card-header', { hasText: /^\s*Artifacts\b/ }) });
    await expect(card).toBeVisible();
    return card;
  }

  function row(card: Locator, name: string): Locator {
    return card.locator('tbody tr', { has: card.page().locator(`td code:text-is("${name}")`) });
  }

  /** GETs the address of `link` in the page's session. */
  async function follow(page: Page, link: Locator): Promise<{ status: number; body: string }> {
    const href = await link.getAttribute('href');
    expect(href, 'the link has an address').toBeTruthy();
    const response = await page.request.get(href ?? '');
    return { status: response.status(), body: await response.text() };
  }

  test('regression: Download All answers 404 "No artifacts to download.", not 500', async ({ page }) => {
    const card = await openJob(page);

    const answer = await follow(page, card.locator('a:has-text("Download All")'));

    expect(answer.status).toBe(404);
    expect(answer.body).toContain('No artifacts to download.');
  });

  test('downloading an artifact whose file is gone answers 404', async ({ page }) => {
    const card = await openJob(page);

    const answer = await follow(page, row(card, 'gone.txt').locator('a:has-text("Download")'));

    expect(answer.status).toBe(404);
    expect(answer.body).toContain('Artifact file no longer exists on disk.');
  });

  test('regression: downloading an artifact whose file cannot be read answers 404, not 500', async ({ page }) => {
    const card = await openJob(page);
    test.skip(await row(card, 'locked.txt').count() === 0, 'locked.txt is seeded only as root');

    const answer = await follow(page, row(card, 'locked.txt').locator('a:has-text("Download")'));

    expect(answer.status).toBe(404);
    expect(answer.body).toContain('Artifact file could not be read.');
  });

  test('regression: the preview of an artifact whose file cannot be read fails cleanly', async ({ page }) => {
    const card = await openJob(page);
    const locked = row(card, 'locked.txt');
    test.skip(await locked.count() === 0, 'locked.txt is seeded only as root');
    const button = locked.locator('button.artifact-preview-btn');
    const id = (await button.getAttribute('data-artifact-id')) ?? '';
    const fetched = page.waitForResponse((r) => new URL(r.url()).pathname === `/job/${jobId}/artifact/${id}/content`);

    await button.click();

    expect((await fetched).status()).toBe(404);
    await expect(page.locator(`#artifact-preview-${id} .artifact-preview-content`)).toHaveText(PREVIEW_FAILED);
  });
});
