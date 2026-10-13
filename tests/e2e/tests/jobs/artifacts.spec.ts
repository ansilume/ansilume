import { test, expect, Locator, Page } from '@playwright/test';
import { ARTIFACT_JOB, LOGSTREAM_FINISHED_JOB, seededJobId } from '../../lib/jobs';

/**
 * Job artifacts (views/job/view.php), on the job commands/E2eArtifactSeeder.php
 * seeds with report.txt, results.json, screenshot.png, config.xml, vars.yaml
 * and report.pdf.
 *
 * The tests used to look for that job on the first page of the job list,
 * newest first, and skipped themselves when the jobs launched since the seed
 * had pushed it off: they tested nothing.
 */
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
});
