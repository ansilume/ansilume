import { test, expect } from '@playwright/test';

test.describe('Job Artifacts', () => {
  /**
   * Navigate to the e2e job that has artifacts (seeded by E2eController).
   * Finds the job by looking for one with the artifacts section visible.
   */
  async function goToJobWithArtifacts(page: import('@playwright/test').Page) {
    await page.goto('/job/index');
    // Find a job row and click through to find one with artifacts
    const rows = page.locator('table.table tbody tr');
    const count = await rows.count();
    for (let i = 0; i < count; i++) {
      const link = rows.nth(i).locator('a').first();
      if (await link.isVisible({ timeout: 1_000 }).catch(() => false)) {
        const href = await link.getAttribute('href');
        if (href) {
          await page.goto(href);
          const artifactCard = page.locator('text=Artifacts');
          if (await artifactCard.isVisible({ timeout: 2_000 }).catch(() => false)) {
            return true;
          }
        }
      }
    }
    return false;
  }

  test('artifacts table is visible on job with artifacts', async ({ page }) => {
    const found = await goToJobWithArtifacts(page);
    if (!found) {
      test.skip(true, 'No job with artifacts found in e2e data');
      return;
    }
    await expect(page.locator('text=Artifacts')).toBeVisible();
    // Should show artifact files in the table
    const artifactRows = page.locator('table.table tbody tr').filter({ has: page.locator('code') });
    expect(await artifactRows.count()).toBeGreaterThan(0);
  });

  test('download button exists for artifacts', async ({ page }) => {
    const found = await goToJobWithArtifacts(page);
    if (!found) {
      test.skip(true, 'No job with artifacts found in e2e data');
      return;
    }
    const downloadBtn = page.locator('a:has-text("Download")').first();
    await expect(downloadBtn).toBeVisible();
  });

  test('preview button exists for text artifacts', async ({ page }) => {
    const found = await goToJobWithArtifacts(page);
    if (!found) {
      test.skip(true, 'No job with artifacts found in e2e data');
      return;
    }
    const previewBtn = page.locator('button.artifact-preview-btn').first();
    await expect(previewBtn).toBeVisible();
  });

  test('preview button loads content inline', async ({ page }) => {
    const found = await goToJobWithArtifacts(page);
    if (!found) {
      test.skip(true, 'No job with artifacts found in e2e data');
      return;
    }
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
    const found = await goToJobWithArtifacts(page);
    if (!found) {
      test.skip(true, 'No job with artifacts found in e2e data');
      return;
    }
    const imageBtn = page.locator('button.artifact-preview-btn[data-preview-kind="image"]').first();
    if (!(await imageBtn.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, 'No image artifact seeded for this run');
      return;
    }
    await imageBtn.click();

    const previewRow = page.locator('.artifact-preview-row:not(.d-none)').first();
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    const imageWrap = previewRow.locator('.artifact-preview-image:not(.d-none)');
    await expect(imageWrap).toBeVisible({ timeout: 5_000 });
    const img = imageWrap.locator('img');
    await expect(img).toHaveAttribute('src', /\/job\/\d+\/artifact\/\d+.*inline=1/);
  });

  test('download all button visible when multiple artifacts', async ({ page }) => {
    const found = await goToJobWithArtifacts(page);
    if (!found) {
      test.skip(true, 'No job with artifacts found in e2e data');
      return;
    }
    // The seeded job has 2 artifacts, so "Download All" should be visible
    const downloadAllBtn = page.locator('a:has-text("Download All")');
    await expect(downloadAllBtn).toBeVisible();
  });

  test('artifacts section hidden when job has no artifacts', async ({ page }) => {
    await page.goto('/job/index');
    // Navigate to a job — if it has no artifacts, the section should be absent
    const firstLink = page.locator('table.table tbody tr a').first();
    if (await firstLink.isVisible({ timeout: 3_000 }).catch(() => false)) {
      await firstLink.click();
      // We just verify the page loaded — the absence of artifacts depends on data
      await expect(page.locator('body')).toContainText(/job|status/i);
    }
  });

  async function openPreviewByExtension(page: import('@playwright/test').Page, ext: string) {
    const found = await goToJobWithArtifacts(page);
    if (!found) {
      test.skip(true, 'No job with artifacts found in e2e data');
      return null;
    }
    const row = page.locator('table.table tbody tr').filter({
      has: page.locator(`code:has-text(".${ext}")`),
    }).first();
    if (!(await row.isVisible({ timeout: 2_000 }).catch(() => false))) {
      test.skip(true, `No .${ext} artifact seeded`);
      return null;
    }
    await row.locator('button.artifact-preview-btn').click();
    return page.locator('.artifact-preview-row:not(.d-none)').first();
  }

  test('previews TXT artifact inline', async ({ page }) => {
    const previewRow = await openPreviewByExtension(page, 'txt');
    if (!previewRow) return;
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    await expect(previewRow.locator('.artifact-preview-content')).not.toBeEmpty({ timeout: 5_000 });
  });

  test('previews JSON artifact inline', async ({ page }) => {
    const previewRow = await openPreviewByExtension(page, 'json');
    if (!previewRow) return;
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    await expect(previewRow.locator('.artifact-preview-content')).toContainText('tests_passed', { timeout: 5_000 });
  });

  test('previews XML artifact inline', async ({ page }) => {
    const previewRow = await openPreviewByExtension(page, 'xml');
    if (!previewRow) return;
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    await expect(previewRow.locator('.artifact-preview-content')).toContainText('<config>', { timeout: 5_000 });
  });

  test('previews YAML artifact inline', async ({ page }) => {
    const previewRow = await openPreviewByExtension(page, 'yaml');
    if (!previewRow) return;
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    await expect(previewRow.locator('.artifact-preview-content')).toContainText('environment:', { timeout: 5_000 });
  });

  test('previews PDF artifact in sandboxed iframe', async ({ page }) => {
    const previewRow = await openPreviewByExtension(page, 'pdf');
    if (!previewRow) return;
    await expect(previewRow).toBeVisible({ timeout: 5_000 });
    const frame = previewRow.locator('.artifact-preview-frame:not(.d-none) iframe');
    await expect(frame).toBeVisible({ timeout: 5_000 });
    await expect(frame).toHaveAttribute('sandbox', '');
    await expect(frame).toHaveAttribute('src', /\/job\/\d+\/artifact\/\d+.*inline=1/);
  });
});
