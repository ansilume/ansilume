# Artifact Follow-ups — Design

**Date:** 2026-04-16
**Status:** Draft → awaiting user review
**Target release:** next minor (v2.3.0 candidate)

## 1. Goals

Close out the artifact-management topic by shipping the three remaining follow-ups identified after v2.2.0:

1. **PDF preview** — inline rendering for `application/pdf` artifacts, alongside the existing text/JSON/XML/YAML/image preview.
2. **Retention by job count** — a new global cap on how many jobs with artifacts are kept, as an OR-combination with the existing `ARTIFACT_RETENTION_DAYS`.
3. **Retroactive global-quota enforcement** — when `ARTIFACT_MAX_TOTAL_BYTES` is active and currently exceeded, the scheduled sweep trims oldest jobs' artifacts until the total is back under the limit.

After this round, the artifact-preview topic is closed (no further preview formats are planned).

## 2. Non-goals

- Per-template retention overrides (decision: global is enough; per-template can be a future item if demand arises).
- Per-job retroactive quota (decision: per-job limits remain collection-time-only — retroactive trimming operates globally, deleting oldest jobs whole).
- Markdown rendering, syntax highlighting, DOCX preview, or any other new preview formats.
- SVG inline rendering (known XSS vector — operators can download).
- A dry-run / admin-confirmation flow for retroactive enforcement (audit log plus env-var gate is sufficient).

## 3. User-facing behavior

### 3.1 PDF preview

- The artifact list in the job view shows a `Preview` button next to PDF artifacts (in addition to text/JSON/XML/YAML/image).
- Clicking `Preview` expands an inline section containing a sandboxed `<iframe>` that loads the PDF at `/job/<id>/artifact/<artifact_id>?inline=1`.
- The iframe has `sandbox=""` (no tokens) so any JavaScript embedded in the PDF cannot execute, and the response carries a matching CSP header.
- The existing text/JSON/XML/YAML path (rendered into a `<pre>` block) is unchanged. It is explicitly re-verified by new E2E tests so the full preview matrix is locked in.

### 3.2 Retention by job count

- New env variable: `ARTIFACT_MAX_JOBS_WITH_ARTIFACTS` (default `0` = unlimited).
- When set to a positive integer `N`, each maintenance sweep keeps only the `N` most recent jobs that have artifacts. Older jobs have all their artifacts deleted (records, files, and the per-job storage directory).
- "Most recent" is determined by `MAX(job_artifact.created_at)` per job, matching the semantics already used for expiry.
- Combines with `ARTIFACT_RETENTION_DAYS` as OR: an artifact is removed if **either** rule triggers. In the sweep order, days-retention runs first (cheaper) and count-retention runs second on whatever remains.
- Jobs without any artifacts are irrelevant to this rule — they are neither counted nor touched.

### 3.3 Retroactive global quota enforcement

- When `ARTIFACT_MAX_TOTAL_BYTES > 0` and the current `SUM(size_bytes)` exceeds it, the sweep trims whole jobs (oldest first by `MAX(created_at)` ASC) until the total drops back under the limit.
- Per-job limits (`ARTIFACT_MAX_BYTES_PER_JOB`, plus the internal `maxArtifactsPerJob` cap) remain collection-time-only — they are not enforced retroactively. This is documented explicitly in `.env.example`.
- Each trimmed job produces exactly one audit-log entry (`ACTION_ARTIFACT_QUOTA_TRIMMED`) with the number of artifacts and bytes freed.

## 4. Architecture

### 4.1 Affected files

```
services/ArtifactService.php           + isInlineFrameType(), + maxJobsWithArtifacts
services/ArtifactCleanupService.php    + deleteByJobCount(), + trimToTotalBytes()
services/MaintenanceService.php        extend maybeRunArtifactCleanup result shape
controllers/JobController.php          PDF branch in download action (inline + CSP)
controllers/api/v1/JobsController.php  PDF branch in download-artifact action
views/job/view.php                     <iframe sandbox> for inline-frame types
models/AuditLog.php                    + ACTION_ARTIFACT_QUOTA_TRIMMED
commands/ArtifactController.php        'stats' action shows new limits/usage
commands/E2eController.php             idempotent seed for PDF/JSON/TXT/XML/YAML artifacts
config/web.php, config/console.php     + maxJobsWithArtifacts from env
.env.example, .env.prod.example        + ARTIFACT_MAX_JOBS_WITH_ARTIFACTS
bin/quickstart                         propagate new variable
web/openapi.yaml                       artifact response: + inline_frame flag
```

No database migration required — `ACTION_ARTIFACT_QUOTA_TRIMMED` is a string constant, nothing schema-level.

### 4.2 Service boundaries

`ArtifactCleanupService` stays the single owner of destructive artifact operations:

- `deleteExpiredArtifacts(): int` — existing days-based sweep (unchanged).
- `deleteByJobCount(): int` — new; queries all jobs with at least one artifact, sorted `MAX(created_at) DESC`, skips the first `maxJobsWithArtifacts`, deletes everything else.
- `trimToTotalBytes(): int` — new; reads `getTotalStoredBytes()`, if over the limit enumerates jobs `ASC` by newest-artifact-time and deletes whole jobs until under.
- `cleanupOrphans(): int` — existing, unchanged.

`ArtifactService` exposes read-side affordances and one new MIME predicate:

- `isInlineFrameType(string $mimeType): bool` — currently returns true only for `application/pdf`.
- `maxJobsWithArtifacts` public property, settable from config.

`MaintenanceService::maybeRunArtifactCleanup()` orchestrates the four steps in order: expired → by-count → quota-trimmed → orphans. The result shape becomes:

```php
'result' => [
    'expired' => int,
    'by_count' => int,
    'quota_trimmed' => int,
    'orphans' => int,
]
```

### 4.3 Sweep ordering rationale

```
1. deleteExpiredArtifacts()   days-retention, cheap, reduces work for step 2
2. deleteByJobCount()         count-retention, runs on what survives step 1
3. trimToTotalBytes()         global quota, final enforcement on what remains
4. cleanupOrphans()           filesystem hygiene, always last
```

Each step is idempotent and short-circuits when its gating config is 0 (disabled).

### 4.4 Concurrency

`MaintenanceService::acquireCooldown()` already guarantees single-sweep execution through Redis SETNX. No new locking needed. Collection (`collectFromDirectory`) writes new artifacts concurrently with a sweep — tolerated: any new artifact the sweep missed is handled on the next tick.

### 4.5 PDF security model

Responses for `?inline=1` on a PDF artifact carry:

- `Content-Type: application/pdf`
- `Content-Disposition: inline; filename="<display_name>"`
- `Content-Security-Policy: default-src 'none'; object-src 'self'; plugin-types application/pdf; sandbox;`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`

The empty `sandbox` directive in CSP (and matching `sandbox=""` attribute on the iframe) disables scripts, forms, top-level navigation, and pointer-lock. Defensive doubling: both CSP and the iframe attribute apply; one would suffice, two survives proxy header loss.

Downloads without `?inline=1` remain `Content-Disposition: attachment` as today.

## 5. Configuration surface

New variable (append to existing `.env.example` artifact block):

```
# Keep only the N most recent jobs that have artifacts. 0 = unlimited (default).
# Combined with ARTIFACT_RETENTION_DAYS: an artifact is removed if EITHER rule matches.
ARTIFACT_MAX_JOBS_WITH_ARTIFACTS=0
```

Wiring in `config/web.php` and `config/console.php` (`artifactService` component):

```php
'maxJobsWithArtifacts' => (int)(getenv('ARTIFACT_MAX_JOBS_WITH_ARTIFACTS') ?: 0),
```

No changes to existing variables.

## 6. Audit trail

Single new action constant:

```php
public const ACTION_ARTIFACT_QUOTA_TRIMMED = 'artifact.quota_trimmed';
```

Written once per trimmed job, with payload:

```json
{
  "job_id": 42,
  "artifact_count": 7,
  "bytes_freed": 15728640,
  "reason": "job_count" | "global_quota"
}
```

Existing constants `ACTION_ARTIFACT_EXPIRED` and `ACTION_ARTIFACT_ORPHAN_REMOVED` are reused unchanged — days-retention and orphan paths are untouched.

## 7. OpenAPI delta

- `components.schemas.JobArtifact`: add `inline_frame: boolean` alongside `image: boolean`.
- `/api/v1/jobs/{id}/artifacts/{artifact_id}/download` description: document `?inline=1` semantics — previewable/image/PDF are served inline with CSP sandbox; all else remains attachment.
- `info.version` bumped together with `./bin/release`.

## 8. Testing

### 8.1 PHPUnit (integration)

`ArtifactCleanupServiceTest`:

- `testDeleteByJobCountKeepsNewest` — 5 jobs with artifacts, limit 2 → 3 oldest gone, files removed, 3 audit rows `reason=job_count`.
- `testDeleteByJobCountZeroDisables` — no-op.
- `testDeleteByJobCountIgnoresJobsWithoutArtifacts` — jobs without artifacts do not count.
- `testTrimToTotalBytesDeletesOldestFirst` — 3 jobs of 10/20/30 MB, limit 40 MB → oldest (10) removed, next iteration removes 20 MB job; total 30 MB ≤ 40 MB stops.
- `testTrimToTotalBytesUnderLimitDoesNothing` — no-op when under limit.
- `testTrimToTotalBytesHandlesMissingFiles` — file already gone on disk → warning logged, DB record still cleaned, sweep completes.

`ArtifactServiceTest`:

- `testIsInlineFrameTypePdf` — `application/pdf` true; `text/plain`, `image/png`, `application/octet-stream` false.

`MaintenanceServiceTest`:

- `testRunIfDueReturnsAllFourCounters` — result contains `expired`, `by_count`, `quota_trimmed`, `orphans`.
- `testMaintenanceRespectsEachDisableFlag` — any of retentionDays, maxJobsWithArtifacts, maxTotalBytes = 0 → that sub-step returns 0 without touching the DB.

`JobControllerArtifactTest`:

- `testPdfDownloadInlineSetsSandboxCsp` — `?inline=1` on PDF → Content-Disposition inline, CSP with sandbox, X-Content-Type-Options nosniff.
- `testPdfDownloadWithoutInlineIsAttachment` — default → attachment.

`JobArtifactsApiTest` (mirror of above):

- `testApiPdfDownloadInlineSetsSandboxCsp`.
- `testApiPdfDownloadWithoutInlineIsAttachment`.
- `testApiArtifactListIncludesInlineFrameFlag` — JSON response for a PDF artifact has `inline_frame: true`, image PNG `inline_frame: false, image: true`, TXT `false/false` but `previewable: true`.

### 8.2 Playwright E2E

Extend `tests/e2e/tests/jobs/artifacts.spec.ts`:

- `previews TXT artifact inline` — seeded `.txt` artifact → Preview button → content visible in `<pre>`.
- `previews JSON artifact inline` — same, with JSON content snippet.
- `previews XML artifact inline` — same.
- `previews YAML artifact inline` — same.
- `previews PDF artifact inline via sandboxed iframe` — seeded PDF → Preview button → iframe with `sandbox` attribute and `src` pointing at `?inline=1`.
- `regression: PDF inline response carries sandbox CSP` — navigate to inline URL directly, assert response header contains `sandbox`.

`commands/E2eController.php` gets an idempotent seed step that attaches one TXT, JSON, XML, YAML, and PDF artifact (with `e2e-` prefix) to an existing e2e job. Skipped if the artifacts already exist.

### 8.3 Regression tests

This round does not fix a specific production bug. However, both the new count-retention and quota-trim paths are destructive — if either regressed and started deleting too eagerly, it would be a serious operator incident. The tests above serve as lock-in regressions: any future change that makes these methods over-delete will fail `testDeleteByJobCountKeepsNewest` or `testTrimToTotalBytesDeletesOldestFirst`.

## 9. Error paths

- **Count-retention, missing storage dir** — `ArtifactCleanupService` logs a warning, still deletes the DB records (consistent with `deleteExpiredArtifacts`).
- **Quota-trim, file unlink fails** — `FileHelper::safeUnlink` logs and continues; record is deleted so it does not re-enter the sweep.
- **PDF download, file missing on disk** — existing 404 handling in download actions (unchanged).
- **CSP clash with reverse-proxy header** — we set our own; nginx config is not modified. If operators layer their own CSP, our header still applies to the PDF response.

## 10. Risks

1. **Unexpected bulk deletion** — an operator enabling `ARTIFACT_MAX_JOBS_WITH_ARTIFACTS=100` with 500 jobs in the system will see 400 jobs' worth of artifacts disappear on the next tick. Mitigation: `.env.example` comment spells this out; changelog calls it out explicitly; operators are expected to pick a value with headroom.
2. **Audit-log volume** — a large single-sweep trim can produce hundreds of audit rows. Acceptable — this is exactly what the audit log is for. No throttling logic.
3. **PDF browser compatibility** — very old browsers without a built-in PDF viewer fall back to download. Acceptable — modern Chrome/Firefox/Safari all render PDFs natively in iframes.
4. **Sandbox defensive doubling** — CSP and iframe `sandbox` are both set. If one is ever accidentally removed (say during a refactor), the other still enforces the policy. No downside.

## 11. Out-of-scope / future

- Per-template retention overrides.
- Per-job retroactive quota (would require semantic choice around which files to delete inside a job; decided against in brainstorming).
- Dry-run / preview mode for the scheduled sweep.
- Additional preview formats (Markdown, DOCX, etc).
- Purge API endpoint (manual trim via web/API) — current console command `php yii artifact/cleanup` suffices.

## 12. Release

Normal `./bin/release minor` once merged (adds new env var, new user-facing feature). OpenAPI `info.version` bumps in the same commit.
