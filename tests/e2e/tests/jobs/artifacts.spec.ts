import { test, expect, Locator, Page } from '@playwright/test';
import { ARTIFACT_JOB, LOGSTREAM_FINISHED_JOB, seededJobId } from '../../lib/jobs';
import { LOGIN_PASSWORD, LOGIN_SUBMIT, LOGIN_USERNAME } from '../../lib/selectors';
import { E2E_ADMIN, asUser } from '../../lib/session';

/**
 * Job artifacts (views/job/view.php), on the job commands/E2eArtifactSeeder.php
 * seeds with report.txt, results.json, screenshot.png, config.xml, vars.yaml
 * and report.pdf.
 *
 * The tests used to look for that job on the first page of the job list,
 * newest first, and skipped themselves when the jobs launched since the seed
 * had pushed it off: they tested nothing.
 *
 * The preview of a text artifact is fetched as JSON from
 * /job/<id>/artifact/<artifact_id>/content; an answer that is not OK fails
 * it with PREVIEW_FAILED, and opened again, the preview is fetched again.
 */

/** views/job/view.php: what the preview of a text artifact shows when its fetch failed. */
const PREVIEW_FAILED = 'Failed to load preview.';

test.describe('Job Artifacts', () => {
  /** The ID of the seeded job with artifacts, looked up once per worker. */
  let artifactJobId = '';

  async function goToJobWithArtifacts(page: Page): Promise<void> {
    if (artifactJobId === '') {
      artifactJobId = await seededJobId(page, ARTIFACT_JOB);
    }
    await page.goto(`/job/view?id=${artifactJobId}`);
    await expect(artifactsCard(page)).toBeVisible();
  }

  function artifactsCard(page: Page): Locator {
    return page.locator('.card', { has: page.locator('.card-header', { hasText: /^\s*Artifacts\b/ }) });
  }

  /**
   * The Preview button of the artifact `name` on the open job page, the
   * text its preview shows, and the path of the address it fetches.
   */
  async function textPreview(page: Page, name: string): Promise<{ button: Locator; content: Locator; path: string }> {
    const button = artifactsCard(page).locator('tbody tr', { has: page.locator(`td code:text-is("${name}")`) })
      .locator('button.artifact-preview-btn');
    await expect(button, `the Preview button of ${name}`).toHaveAttribute('data-preview-kind', 'text');
    const id = (await button.getAttribute('data-artifact-id')) ?? '';
    const path = (await button.getAttribute('data-url')) ?? '';
    expect(path, `the content address of ${name}`).toBe(`/job/${artifactJobId}/artifact/${id}/content`);
    return { button, content: page.locator(`#artifact-preview-${id} .artifact-preview-content`), path };
  }

  test('artifacts table is visible on job with artifacts', async ({ page }) => {
    await goToJobWithArtifacts(page);
    // One row per seeded artifact file; each preview has a row of its own.
    const artifactRows = artifactsCard(page).locator('table tbody tr:not(.artifact-preview-row)');
    await expect(artifactRows).toHaveCount(6);
    // By file name.
    await expect(artifactRows.locator('td code')).toHaveText(['config.xml', 'report.pdf', 'report.txt', 'results.json', 'screenshot.png', 'vars.yaml']);
  });

  test('download button exists for artifacts', async ({ page }) => {
    await goToJobWithArtifacts(page);
    const downloadBtn = artifactsCard(page).locator('a:has-text("Download")').first();
    await expect(downloadBtn).toBeVisible();
  });

  test('preview button exists for text artifacts', async ({ page }) => {
    await goToJobWithArtifacts(page);
    const previewBtn = page.locator('button.artifact-preview-btn').first();
    await expect(previewBtn).toBeVisible();
  });

  test('preview button loads content inline', async ({ page }) => {
    await goToJobWithArtifacts(page);
    const previewBtn = page.locator('button.artifact-preview-btn').first();
    await previewBtn.click();

    // Wait for the preview row to become visible and contain text
    const previewRow = page.locator('.artifact-preview-row:not(.d-none)').first();
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    const previewContent = previewRow.locator('.artifact-preview-content');
    // Wait for content to be loaded (non-empty text)
    await expect(previewContent).not.toBeEmpty({ timeout: 5_000 });
  });

  test('image preview renders inline <img>', async ({ page }) => {
    await goToJobWithArtifacts(page);
    const imageBtn = page.locator('button.artifact-preview-btn[data-preview-kind="image"]').first();
    await imageBtn.click();

    const previewRow = page.locator('.artifact-preview-row:not(.d-none)').first();
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    const imageWrap = previewRow.locator('.artifact-preview-image:not(.d-none)');
    await expect(imageWrap).toBeVisible({ timeout: 5_000 });
    const img = imageWrap.locator('img');
    await expect(img).toHaveAttribute('src', /\/job\/\d+\/artifact\/\d+.*inline=1/);
  });

  test('download all button visible when multiple artifacts', async ({ page }) => {
    await goToJobWithArtifacts(page);
    // The seeded job has several artifacts, so "Download All" should be visible
    const downloadAllBtn = page.locator('a:has-text("Download All")');
    await expect(downloadAllBtn).toBeVisible();
  });

  test('artifacts section hidden when job has no artifacts', async ({ page }) => {
    // The seeded finished log-stream job has log chunks but no artifacts.
    await page.goto(`/job/view?id=${await seededJobId(page, LOGSTREAM_FINISHED_JOB)}`);
    await expect(page.locator('#job-log')).toContainText('PLAY RECAP');
    await expect(artifactsCard(page)).toHaveCount(0);
    await expect(page.locator('a:has-text("Download All")')).toHaveCount(0);
  });

  async function openPreviewByExtension(page: Page, ext: string): Promise<Locator> {
    await goToJobWithArtifacts(page);
    const row = page.locator('table.table tbody tr').filter({
      has: page.locator(`code:has-text(".${ext}")`),
    }).first();
    await row.locator('button.artifact-preview-btn').click();
    return page.locator('.artifact-preview-row:not(.d-none)').first();
  }

  test('previews TXT artifact inline', async ({ page }) => {
    const previewRow = await openPreviewByExtension(page, 'txt');
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    await expect(previewRow.locator('.artifact-preview-content')).not.toBeEmpty({ timeout: 5_000 });
  });

  test('previews JSON artifact inline', async ({ page }) => {
    const previewRow = await openPreviewByExtension(page, 'json');
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    await expect(previewRow.locator('.artifact-preview-content')).toContainText('tests_passed', { timeout: 5_000 });
  });

  test('previews XML artifact inline', async ({ page }) => {
    const previewRow = await openPreviewByExtension(page, 'xml');
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    await expect(previewRow.locator('.artifact-preview-content')).toContainText('<config>', { timeout: 5_000 });
  });

  test('previews YAML artifact inline', async ({ page }) => {
    const previewRow = await openPreviewByExtension(page, 'yaml');
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    await expect(previewRow.locator('.artifact-preview-content')).toContainText('environment:', { timeout: 5_000 });
  });

  test('previews PDF artifact in sandboxed iframe', async ({ page }) => {
    const previewRow = await openPreviewByExtension(page, 'pdf');
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    const frame = previewRow.locator('.artifact-preview-frame:not(.d-none) iframe');
    await expect(frame).toBeVisible({ timeout: 5_000 });
    await expect(frame).toHaveAttribute('sandbox', '');
    await expect(frame).toHaveAttribute('src', /\/job\/\d+\/artifact\/\d+.*inline=1/);
  });

  test('download-all returns a valid ZIP archive', async ({ page }) => {
    await goToJobWithArtifacts(page);
    const downloadAllLink = page.locator('a:has-text("Download All")');
    await expect(downloadAllLink).toHaveAttribute('href', new RegExp(`/job/${artifactJobId}/artifacts/download-all$`));
    const href = (await downloadAllLink.getAttribute('href')) ?? '';

    const response = await page.request.get(href);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type'] ?? '').toMatch(/zip/i);
    const bytes = await response.body();
    // ZIP local file header signature is "PK\x03\x04".
    expect(bytes[0]).toBe(0x50); // P
    expect(bytes[1]).toBe(0x4b); // K
    expect(bytes[2]).toBe(0x03);
    expect(bytes[3]).toBe(0x04);
  });

  // regression: the preview read every answer as the artifact. An error
  // answer is JSON too (yii\web\ErrorHandler: name, message, code, status),
  // so a preview refused (403: no access any more) or not found (404: the
  // artifact or its file gone) showed an empty preview instead of failing
  // (git show HEAD:views/job/view.php, `.then(function (r) { return r.json(); })`).
  // The answers here are the test's; opened again, the preview is fetched
  // again, and the server's answer shows the artifact.
  test('regression: a preview answered with an error showed an empty preview', async ({ page }) => {
    await goToJobWithArtifacts(page);
    const { button, content, path } = await textPreview(page, 'results.json');
    const errors: Record<number, { name: string; message: string }> = {
      403: { name: 'Forbidden', message: 'You do not have access to this resource.' },
      404: { name: 'Not Found', message: 'Artifact file could not be read.' },
    };
    let answer: number | null = 403;
    const accepts: string[] = [];
    await page.route((url) => url.pathname === path, async (route) => {
      accepts.push(route.request().headers()['accept'] ?? '');
      if (answer === null) {
        await route.continue();
      } else {
        await route.fulfill({ status: answer, json: { ...errors[answer], code: 0, status: answer } });
      }
    });

    for (const status of [403, 404]) {
      answer = status;
      const fetches = accepts.length;
      // Blank, so the preview shows only what this answer wrote.
      await content.evaluate((element) => {
        element.textContent = '';
      });
      await button.click();
      await expect.poll(() => accepts.length, { message: `the preview is fetched (${status})` }).toBe(fetches + 1);
      await expect(content, `the preview answered ${status}`).toHaveText(PREVIEW_FAILED);
      await expect(content).toBeVisible();
      await expect(button).toHaveText('Preview');
      await expect(button).toBeEnabled();
      // Closed; opened again, it is fetched again.
      await button.click();
      await expect(content).toBeHidden();
    }

    answer = null;
    await button.click();
    await expect(content).toContainText('tests_passed');
    expect(accepts, 'every preview asks for JSON').toEqual(['application/json', 'application/json', 'application/json']);
  });

  // regression: after a sign-out, signing in again led to the artifact's
  // JSON. The signed-out preview asked for anything (fetch's Accept: */*),
  // so the server sent it to the login page and kept the artifact's
  // address to return to after the next sign-in, and the preview read the
  // login page as JSON. Asked for JSON, it is refused with 403 and keeps no
  // address. In a session of its own, so no other spec's session ends.
  test('regression: after a sign-out, signing in again led to the preview\'s JSON', async ({ browser }, testInfo) => {
    await asUser(browser, testInfo, E2E_ADMIN, async (page) => {
      await goToJobWithArtifacts(page);
      const { button, content, path } = await textPreview(page, 'report.txt');

      // Signed out in another tab: the session is gone.
      await page.context().clearCookies();
      const [refused] = await Promise.all([
        page.waitForResponse((response) => new URL(response.url()).pathname === path),
        button.click(),
      ]);
      expect(refused.status(), 'the preview of a signed-out page is refused, not sent to the login page').toBe(403);
      await expect(content).toHaveText(PREVIEW_FAILED);
      await expect(button).toBeEnabled();

      await page.goto('/site/login');
      await page.locator(LOGIN_USERNAME).fill(E2E_ADMIN.username);
      await page.locator(LOGIN_PASSWORD).fill(E2E_ADMIN.password);
      await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/site/login'), { timeout: 10_000 }),
        page.locator(LOGIN_SUBMIT).click(),
      ]);
      expect(new URL(page.url()).pathname, 'signed in again, the dashboard').toBe('/');
    });
  });
});
