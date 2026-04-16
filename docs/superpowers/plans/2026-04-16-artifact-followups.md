# Artifact Follow-ups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the artifact-management topic by adding PDF inline preview, retention-by-job-count, and retroactive global-quota enforcement.

**Architecture:** New MIME predicate `isInlineFrameType()` + sandboxed PDF download branch for the preview feature. Two new cleanup methods (`deleteByJobCount`, `trimToTotalBytes`) on `ArtifactCleanupService`, called in order after the existing retention-days sweep, each emitting a new `ACTION_ARTIFACT_QUOTA_TRIMMED` audit entry per trimmed job. One new env variable `ARTIFACT_MAX_JOBS_WITH_ARTIFACTS`; no schema migration.

**Tech Stack:** PHP 8.2 / Yii2, MariaDB, Redis (existing). Playwright for E2E. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-04-16-artifact-followups-design.md`

---

## File Structure

**Modified:**
- `services/ArtifactService.php` — add `isInlineFrameType()`, `maxJobsWithArtifacts` property, wrapper methods for new cleanup steps
- `services/ArtifactCleanupService.php` — new ctor params, `deleteByJobCount()`, `trimToTotalBytes()`, shared job-deletion helper
- `services/MaintenanceService.php` — extend `runIfDue` result shape; call new cleanup steps in order
- `models/AuditLog.php` — add `ACTION_ARTIFACT_QUOTA_TRIMMED`
- `controllers/JobController.php` — extend `actionDownloadArtifact` with PDF/CSP branch
- `controllers/api/v1/JobsController.php` — mirror extension; add `inline_frame` to serialization
- `controllers/SystemController.php`, `controllers/api/v1/SystemController.php` — expose `maxJobsWithArtifacts`
- `views/job/view.php` — add `<iframe sandbox>` preview branch, JS handler extension
- `views/system/artifact-stats.php` — show new config row
- `commands/E2eArtifactSeeder.php` — add XML, YAML, PDF artifacts
- `config/web.php`, `config/console.php` — wire `maxJobsWithArtifacts` from env
- `.env.example`, `.env.prod.example`, `bin/quickstart` — new env variable
- `web/openapi.yaml` — add `inline_frame`, update download endpoint description
- `tests/integration/services/ArtifactServiceTest.php` — tests for `isInlineFrameType()`
- `tests/integration/services/ArtifactCleanupServiceTest.php` — **new** file with cleanup logic tests
- `tests/integration/services/MaintenanceServiceTest.php` — extended report shape assertions
- `tests/integration/controllers/JobControllerArtifactTest.php` — PDF inline / CSP tests (create if missing)
- `tests/integration/api/v1/JobArtifactsApiTest.php` — mirror tests + `inline_frame` assertion
- `tests/integration/controllers/SystemControllerActionTest.php` — new config value passed to view
- `tests/integration/controllers/api/v1/SystemControllerTest.php` — new config value in JSON
- `tests/e2e/tests/jobs/artifacts.spec.ts` — per-format preview specs + PDF iframe spec

**Created:**
- `tests/integration/services/ArtifactCleanupServiceTest.php` (if not already present — currently `ArtifactServiceTest.php` holds these; the plan keeps that structure unless split is trivial)

---

## Conventions for every task

- Run tests inside Docker: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/path/to/TestFile.php --filter <testName>`.
- Follow PSR-12, strict types, no alignment spacing, no `@` suppression — see `CLAUDE.md`.
- Commit messages use conventional style (`feat:`, `test:`, `refactor:`). No `Co-Authored-By` footer.
- Stage files explicitly; do NOT use `git add -A`.

---

## Task 1: Add audit action constant

**Files:**
- Modify: `models/AuditLog.php:67-69`

- [ ] **Step 1: Add the constant**

Open `models/AuditLog.php` and extend the artifact actions block (currently lines 67–69):

```php
    // -- Artifact actions ------------------------------------------------------
    public const ACTION_ARTIFACT_EXPIRED = 'artifact.expired';
    public const ACTION_ARTIFACT_ORPHAN_REMOVED = 'artifact.orphan-removed';
    public const ACTION_ARTIFACT_QUOTA_TRIMMED = 'artifact.quota_trimmed';
```

- [ ] **Step 2: Syntax check**

Run: `docker compose exec -T app php -l models/AuditLog.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add models/AuditLog.php
git commit -m "feat: add ACTION_ARTIFACT_QUOTA_TRIMMED audit constant"
```

---

## Task 2: Add `isInlineFrameType()` predicate to ArtifactService

**Files:**
- Modify: `services/ArtifactService.php`
- Modify: `tests/integration/services/ArtifactServiceTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/integration/services/ArtifactServiceTest.php` (inside the class, before the closing brace):

```php
    public function testIsInlineFrameTypeReturnsTrueForPdf(): void
    {
        $service = $this->makeService();
        $this->assertTrue($service->isInlineFrameType('application/pdf'));
    }

    public function testIsInlineFrameTypeReturnsFalseForOtherTypes(): void
    {
        $service = $this->makeService();
        $this->assertFalse($service->isInlineFrameType('text/plain'));
        $this->assertFalse($service->isInlineFrameType('application/json'));
        $this->assertFalse($service->isInlineFrameType('image/png'));
        $this->assertFalse($service->isInlineFrameType('image/svg+xml'));
        $this->assertFalse($service->isInlineFrameType('application/octet-stream'));
    }
```

- [ ] **Step 2: Run test — expect failure**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/services/ArtifactServiceTest.php --filter IsInlineFrameType`
Expected: `Error: Call to undefined method ArtifactService::isInlineFrameType()`.

- [ ] **Step 3: Implement the predicate**

In `services/ArtifactService.php`, add the constant near `IMAGE_TYPES` (after line 63):

```php
    /**
     * MIME types that can be embedded in a sandboxed <iframe> for inline
     * preview. PDF is the only supported type — modern browsers render it
     * natively in a sandboxed frame without script execution.
     *
     * @var string[]
     */
    private const INLINE_FRAME_TYPES = [
        'application/pdf',
    ];
```

Add the method next to `isImageType()` (after line 391):

```php
    /**
     * Check whether a MIME type can be embedded in a sandboxed <iframe>.
     * Currently only PDF — served via ?inline=1 with sandbox CSP.
     */
    public function isInlineFrameType(string $mimeType): bool
    {
        return in_array($mimeType, self::INLINE_FRAME_TYPES, true);
    }
```

- [ ] **Step 4: Run test — expect pass**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/services/ArtifactServiceTest.php --filter IsInlineFrameType`
Expected: `OK (2 tests, N assertions)`.

- [ ] **Step 5: Commit**

```bash
git add services/ArtifactService.php tests/integration/services/ArtifactServiceTest.php
git commit -m "feat: add isInlineFrameType() predicate for PDF preview"
```

---

## Task 3: Wire `maxJobsWithArtifacts` config property + env variable

**Files:**
- Modify: `services/ArtifactService.php`
- Modify: `config/web.php:213-220`
- Modify: `config/console.php:128-131`
- Modify: `.env.example`
- Modify: `.env.prod.example`
- Modify: `bin/quickstart`

- [ ] **Step 1: Add property to ArtifactService**

In `services/ArtifactService.php`, extend the property block (around lines 36–37):

```php
    /** @var int Days to retain artifacts. 0 = keep forever. */
    public int $retentionDays = 0;

    /**
     * Maximum number of jobs (with at least one artifact) to retain.
     * 0 = unlimited. Combined with retentionDays as OR: a job's artifacts
     * are removed if either rule matches.
     */
    public int $maxJobsWithArtifacts = 0;
```

- [ ] **Step 2: Wire env variable in config/web.php**

Replace the `artifactService` block in `config/web.php` (around lines 213–220):

```php
        'artifactService' => [
            'class' => 'app\services\ArtifactService',
            'storagePath' => '@runtime/artifacts',
            'maxFileSize' => (int)(getenv('ARTIFACT_MAX_FILE_SIZE') ?: 10485760),
            'maxBytesPerJob' => (int)(getenv('ARTIFACT_MAX_BYTES_PER_JOB') ?: 52428800),
            'maxTotalBytes' => (int)(getenv('ARTIFACT_MAX_TOTAL_BYTES') ?: 0),
            'retentionDays' => (int)(getenv('ARTIFACT_RETENTION_DAYS') ?: 0),
            'maxJobsWithArtifacts' => (int)(getenv('ARTIFACT_MAX_JOBS_WITH_ARTIFACTS') ?: 0),
        ],
```

- [ ] **Step 3: Mirror change in config/console.php**

Apply the same new line (`'maxJobsWithArtifacts' => ...`) in `config/console.php` inside the `artifactService` component block.

- [ ] **Step 4: Document the variable in .env.example**

In `.env.example`, find the artifact block (contains `ARTIFACT_RETENTION_DAYS=0`) and append right after it:

```
# Retention by job count — keep only the N most recent jobs that have
# artifacts. 0 = unlimited. Combined with ARTIFACT_RETENTION_DAYS as OR:
# an artifact is removed if EITHER rule matches. Example: with N=100 and
# 500 jobs currently storing artifacts, the next maintenance sweep will
# delete artifacts from the 400 oldest jobs.
ARTIFACT_MAX_JOBS_WITH_ARTIFACTS=0
```

- [ ] **Step 5: Mirror in .env.prod.example**

Apply the same block in `.env.prod.example` (search for `ARTIFACT_RETENTION_DAYS` there).

- [ ] **Step 6: Propagate in bin/quickstart**

Open `bin/quickstart`. It copies existing artifact variables into the generated `.env`. Find where `ARTIFACT_RETENTION_DAYS` is written and add an adjacent line for `ARTIFACT_MAX_JOBS_WITH_ARTIFACTS` (default `0`). If the file pattern uses a heredoc or loop, mirror exactly that pattern — **do not** rewrite the file structure.

- [ ] **Step 7: Quick smoke test**

Run: `docker compose exec -T app php -r "require 'vendor/yiisoft/yii2/Yii.php'; \$c = require 'config/console.php'; echo \$c['components']['artifactService']['maxJobsWithArtifacts'] ?? 'missing', \"\n\";"`
Expected output: `0` (not `missing`).

- [ ] **Step 8: Commit**

```bash
git add services/ArtifactService.php config/web.php config/console.php .env.example .env.prod.example bin/quickstart
git commit -m "feat: add ARTIFACT_MAX_JOBS_WITH_ARTIFACTS config variable"
```

---

## Task 4: Implement `deleteByJobCount()` in ArtifactCleanupService

**Files:**
- Modify: `services/ArtifactCleanupService.php`
- Modify: `tests/integration/services/ArtifactServiceTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/integration/services/ArtifactServiceTest.php`:

```php
    // -------------------------------------------------------------------------
    // Tests: deleteByJobCount
    // -------------------------------------------------------------------------

    public function testDeleteByJobCountKeepsNewestN(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);

        $service = $this->makeService();
        $jobIds = [];
        for ($i = 0; $i < 5; $i++) {
            $job = $this->createJob($template->id, $user->id);
            $jobIds[] = $job->id;
            $dir = $this->tempDir . '/src' . $i;
            mkdir($dir, 0750, true);
            file_put_contents($dir . '/a.txt', 'x');
            $service->collectFromDirectory($job, $dir);
            // Space artifact timestamps so the order is deterministic.
            $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();
            $artifact->created_at = 1_000_000 + $i;
            $artifact->save(false);
        }

        $service->maxJobsWithArtifacts = 2;
        $deleted = $service->deleteByJobCount();

        // 3 jobs should be trimmed (we kept the 2 newest).
        $this->assertSame(3, $deleted);
        $this->assertCount(0, JobArtifact::find()->where(['job_id' => $jobIds[0]])->all());
        $this->assertCount(0, JobArtifact::find()->where(['job_id' => $jobIds[1]])->all());
        $this->assertCount(0, JobArtifact::find()->where(['job_id' => $jobIds[2]])->all());
        $this->assertCount(1, JobArtifact::find()->where(['job_id' => $jobIds[3]])->all());
        $this->assertCount(1, JobArtifact::find()->where(['job_id' => $jobIds[4]])->all());

        // Audit: exactly one entry per trimmed job with reason=job_count.
        $logs = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ARTIFACT_QUOTA_TRIMMED])
            ->all();
        $this->assertCount(3, $logs);
        foreach ($logs as $log) {
            $meta = json_decode((string)$log->metadata, true);
            $this->assertSame('job_count', $meta['reason']);
            $this->assertArrayHasKey('job_id', $meta);
            $this->assertArrayHasKey('artifact_count', $meta);
            $this->assertArrayHasKey('bytes_freed', $meta);
        }
    }

    public function testDeleteByJobCountReturnsZeroWhenDisabled(): void
    {
        $service = $this->makeService();
        $service->maxJobsWithArtifacts = 0;
        $this->assertSame(0, $service->deleteByJobCount());
    }

    public function testDeleteByJobCountIgnoresJobsWithoutArtifacts(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);

        // One job with artifact, two jobs without.
        $jobWith = $this->createJob($template->id, $user->id);
        $this->createJob($template->id, $user->id);
        $this->createJob($template->id, $user->id);

        $dir = $this->tempDir . '/src';
        mkdir($dir, 0750, true);
        file_put_contents($dir . '/a.txt', 'x');

        $service = $this->makeService();
        $service->collectFromDirectory($jobWith, $dir);
        $service->maxJobsWithArtifacts = 1;

        $this->assertSame(0, $service->deleteByJobCount());
        $this->assertCount(1, JobArtifact::find()->where(['job_id' => $jobWith->id])->all());
    }
```

- [ ] **Step 2: Run test — expect failure**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/services/ArtifactServiceTest.php --filter DeleteByJobCount`
Expected: `Error: Call to undefined method ArtifactService::deleteByJobCount()`.

- [ ] **Step 3: Implement in ArtifactCleanupService**

Extend `services/ArtifactCleanupService.php`. Change the constructor signature:

```php
    public function __construct(
        private string $storagePath,
        private int $retentionDays = 0,
        private int $maxJobsWithArtifacts = 0,
        private int $maxTotalBytes = 0,
    ) {
    }
```

Append two new private helpers and the `deleteByJobCount()` method after `cleanupOrphans()`:

```php
    /**
     * Delete artifacts for all but the most-recent N jobs with artifacts.
     *
     * "Most recent" uses MAX(created_at) per job — matching the semantics
     * of the retention-days sweep. Emits one audit entry per trimmed job.
     */
    public function deleteByJobCount(): int
    {
        if ($this->maxJobsWithArtifacts <= 0) {
            return 0;
        }

        $jobIds = $this->findJobIdsBeyondCountLimit();
        $count = 0;
        foreach ($jobIds as $jobId) {
            $count += $this->deleteJobArtifacts($jobId, 'job_count');
        }

        $this->removeEmptyJobDirs();
        return $count;
    }

    /**
     * @return list<int> Job IDs whose artifacts should be trimmed by the
     *                   count-retention rule. Newest-first DESC, skip N.
     */
    private function findJobIdsBeyondCountLimit(): array
    {
        $rows = (new \yii\db\Query())
            ->select(['job_id', 'newest' => 'MAX(created_at)'])
            ->from(JobArtifact::tableName())
            ->groupBy('job_id')
            ->orderBy(['newest' => SORT_DESC])
            ->offset($this->maxJobsWithArtifacts)
            ->all();

        return array_map(static fn ($r) => (int)$r['job_id'], $rows);
    }

    /**
     * Delete every artifact row + file belonging to a single job and emit
     * one audit entry summarising the trim.
     *
     * @return int Number of artifacts removed for this job.
     */
    private function deleteJobArtifacts(int $jobId, string $reason): int
    {
        $artifacts = JobArtifact::find()->where(['job_id' => $jobId])->all();
        if (empty($artifacts)) {
            return 0;
        }

        $bytesFreed = 0;
        foreach ($artifacts as $artifact) {
            $bytesFreed += (int)$artifact->size_bytes;
            \app\helpers\FileHelper::safeUnlink($artifact->storage_path);
            $artifact->delete();
        }

        $this->auditQuotaTrim($jobId, count($artifacts), $bytesFreed, $reason);
        return count($artifacts);
    }

    /**
     * Emit an audit entry for a whole-job trim. One entry per job, not per file.
     */
    private function auditQuotaTrim(int $jobId, int $artifactCount, int $bytesFreed, string $reason): void
    {
        if (!\Yii::$app->has('auditService')) {
            return;
        }
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_ARTIFACT_QUOTA_TRIMMED,
            'job',
            $jobId,
            null,
            [
                'job_id' => $jobId,
                'artifact_count' => $artifactCount,
                'bytes_freed' => $bytesFreed,
                'reason' => $reason,
            ]
        );
    }
```

- [ ] **Step 4: Add the wrapper on ArtifactService**

In `services/ArtifactService.php`, add next to `deleteExpiredArtifacts()` (after line 304):

```php
    /**
     * Trim artifacts for all but the most-recent N jobs with artifacts.
     *
     * @return int Number of artifacts deleted.
     */
    public function deleteByJobCount(): int
    {
        return (new ArtifactCleanupService(
            $this->storagePath,
            $this->retentionDays,
            $this->maxJobsWithArtifacts,
            $this->maxTotalBytes,
        ))->deleteByJobCount();
    }
```

Also update the existing wrappers to pass all four args (otherwise cross-task state is inconsistent):

```php
    public function deleteExpiredArtifacts(): int
    {
        return (new ArtifactCleanupService(
            $this->storagePath,
            $this->retentionDays,
            $this->maxJobsWithArtifacts,
            $this->maxTotalBytes,
        ))->deleteExpiredArtifacts();
    }

    public function cleanupOrphans(): int
    {
        return (new ArtifactCleanupService(
            $this->storagePath,
            $this->retentionDays,
            $this->maxJobsWithArtifacts,
            $this->maxTotalBytes,
        ))->cleanupOrphans();
    }
```

- [ ] **Step 5: Run tests — expect pass**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/services/ArtifactServiceTest.php --filter DeleteByJobCount`
Expected: `OK (3 tests, N assertions)`.

Run full ArtifactServiceTest to confirm nothing regressed:
`docker compose exec -T app ./vendor/bin/phpunit tests/integration/services/ArtifactServiceTest.php`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add services/ArtifactService.php services/ArtifactCleanupService.php tests/integration/services/ArtifactServiceTest.php
git commit -m "feat: retention by job count (ARTIFACT_MAX_JOBS_WITH_ARTIFACTS)"
```

---

## Task 5: Implement `trimToTotalBytes()` retroactive quota

**Files:**
- Modify: `services/ArtifactCleanupService.php`
- Modify: `services/ArtifactService.php`
- Modify: `tests/integration/services/ArtifactServiceTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/integration/services/ArtifactServiceTest.php`:

```php
    // -------------------------------------------------------------------------
    // Tests: trimToTotalBytes
    // -------------------------------------------------------------------------

    public function testTrimToTotalBytesDeletesOldestJobsFirst(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);

        $service = $this->makeService();
        $sizes = [100, 200, 300]; // oldest → newest
        $jobIds = [];
        foreach ($sizes as $i => $size) {
            $job = $this->createJob($template->id, $user->id);
            $jobIds[] = $job->id;
            $dir = $this->tempDir . '/src' . $i;
            mkdir($dir, 0750, true);
            file_put_contents($dir . '/a.bin', str_repeat('x', $size));
            $service->collectFromDirectory($job, $dir);
            $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();
            $artifact->created_at = 1_000_000 + $i; // deterministic ordering
            $artifact->save(false);
        }

        // Total = 600. Limit = 400 → need to free 200+ bytes.
        // Oldest (100) deleted → 500 still over. Next oldest (200) deleted → 300 ≤ 400. Stop.
        $service->maxTotalBytes = 400;
        $deleted = $service->trimToTotalBytes();

        $this->assertSame(2, $deleted);
        $this->assertCount(0, JobArtifact::find()->where(['job_id' => $jobIds[0]])->all());
        $this->assertCount(0, JobArtifact::find()->where(['job_id' => $jobIds[1]])->all());
        $this->assertCount(1, JobArtifact::find()->where(['job_id' => $jobIds[2]])->all());

        $logs = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ARTIFACT_QUOTA_TRIMMED])
            ->all();
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $meta = json_decode((string)$log->metadata, true);
            $this->assertSame('global_quota', $meta['reason']);
        }
    }

    public function testTrimToTotalBytesUnderLimitDoesNothing(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);
        $job = $this->createJob($template->id, $user->id);

        $dir = $this->tempDir . '/src';
        mkdir($dir, 0750, true);
        file_put_contents($dir . '/a.txt', 'x');

        $service = $this->makeService();
        $service->collectFromDirectory($job, $dir);
        $service->maxTotalBytes = 1_000_000;

        $countBefore = (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ARTIFACT_QUOTA_TRIMMED])
            ->count();
        $this->assertSame(0, $service->trimToTotalBytes());
        $countAfter = (int)AuditLog::find()
            ->where(['action' => AuditLog::ACTION_ARTIFACT_QUOTA_TRIMMED])
            ->count();
        $this->assertSame($countBefore, $countAfter);
    }

    public function testTrimToTotalBytesReturnsZeroWhenDisabled(): void
    {
        $service = $this->makeService();
        $service->maxTotalBytes = 0;
        $this->assertSame(0, $service->trimToTotalBytes());
    }
```

- [ ] **Step 2: Run test — expect failure**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/services/ArtifactServiceTest.php --filter TrimToTotalBytes`
Expected: `Error: Call to undefined method ArtifactService::trimToTotalBytes()`.

- [ ] **Step 3: Implement in ArtifactCleanupService**

In `services/ArtifactCleanupService.php`, append:

```php
    /**
     * Trim whole jobs' artifacts, oldest first, until SUM(size_bytes) <= maxTotalBytes.
     *
     * Runs only if a positive quota is set AND the current total exceeds it.
     * Each trimmed job gets one audit entry.
     */
    public function trimToTotalBytes(): int
    {
        if ($this->maxTotalBytes <= 0) {
            return 0;
        }

        $current = $this->currentTotalBytes();
        if ($current <= $this->maxTotalBytes) {
            return 0;
        }

        $excess = $current - $this->maxTotalBytes;
        $count = 0;
        foreach ($this->iterateJobsOldestFirst() as $row) {
            if ($excess <= 0) {
                break;
            }
            $count += $this->deleteJobArtifacts((int)$row['job_id'], 'global_quota');
            $excess -= (int)$row['total_bytes'];
        }

        $this->removeEmptyJobDirs();
        return $count;
    }

    private function currentTotalBytes(): int
    {
        return (int)(new \yii\db\Query())
            ->select(['COALESCE(SUM(size_bytes), 0)'])
            ->from(JobArtifact::tableName())
            ->scalar();
    }

    /**
     * @return iterable<array{job_id: int, total_bytes: int}>
     */
    private function iterateJobsOldestFirst(): iterable
    {
        return (new \yii\db\Query())
            ->select([
                'job_id',
                'total_bytes' => 'SUM(size_bytes)',
                'newest' => 'MAX(created_at)',
            ])
            ->from(JobArtifact::tableName())
            ->groupBy('job_id')
            ->orderBy(['newest' => SORT_ASC])
            ->each();
    }
```

- [ ] **Step 4: Add the wrapper on ArtifactService**

In `services/ArtifactService.php`, add after `deleteByJobCount()`:

```php
    /**
     * Retroactively enforce the global byte quota. Deletes oldest jobs' artifacts
     * until the total is back under maxTotalBytes. Returns the number of artifacts
     * deleted (0 if already under the limit or quota disabled).
     */
    public function trimToTotalBytes(): int
    {
        return (new ArtifactCleanupService(
            $this->storagePath,
            $this->retentionDays,
            $this->maxJobsWithArtifacts,
            $this->maxTotalBytes,
        ))->trimToTotalBytes();
    }
```

- [ ] **Step 5: Run tests — expect pass**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/services/ArtifactServiceTest.php --filter TrimToTotalBytes`
Expected: `OK (3 tests, ...)`.

Run the full ArtifactServiceTest:
`docker compose exec -T app ./vendor/bin/phpunit tests/integration/services/ArtifactServiceTest.php`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add services/ArtifactService.php services/ArtifactCleanupService.php tests/integration/services/ArtifactServiceTest.php
git commit -m "feat: retroactive global-quota enforcement (trimToTotalBytes)"
```

---

## Task 6: Integrate new cleanup steps into MaintenanceService

**Files:**
- Modify: `services/MaintenanceService.php`
- Modify: `tests/integration/services/MaintenanceServiceTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/integration/services/MaintenanceServiceTest.php`:

```php
    public function testRunIfDueReportsAllFourCounters(): void
    {
        \Yii::$app->set('artifactService', $this->makeArtifactService(0));

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 3600;

        $report = $maintenance->runIfDue();

        $this->assertSame(['artifact-cleanup'], $report['ran']);
        $this->assertSame(
            ['expired', 'by_count', 'quota_trimmed', 'orphans'],
            array_keys($report['results']['artifact-cleanup'])
        );
        $this->assertSame(0, $report['results']['artifact-cleanup']['expired']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['by_count']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['quota_trimmed']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['orphans']);
    }

    public function testRunIfDueActuallyInvokesJobCountAndQuotaTrim(): void
    {
        $user = $this->createUser();
        $project = $this->createProject($user->id);
        $inventory = $this->createInventory($user->id);
        $group = $this->createRunnerGroup($user->id);
        $template = $this->createJobTemplate($project->id, $inventory->id, $group->id, $user->id);

        $svc = $this->makeArtifactService(0);
        // Seed three jobs with one artifact each, deterministic ordering.
        for ($i = 0; $i < 3; $i++) {
            $job = $this->createJob($template->id, $user->id);
            $dir = $this->tempDir . '/src' . $i;
            mkdir($dir, 0750, true);
            file_put_contents($dir . '/a.txt', str_repeat('x', 100));
            $svc->collectFromDirectory($job, $dir);
            $artifact = JobArtifact::find()->where(['job_id' => $job->id])->one();
            $artifact->created_at = 1_000_000 + $i;
            $artifact->save(false);
        }
        $svc->maxJobsWithArtifacts = 2; // Oldest job will be trimmed.

        \Yii::$app->set('artifactService', $svc);

        $maintenance = new MaintenanceService();
        $maintenance->artifactCleanupIntervalSeconds = 3600;

        $report = $maintenance->runIfDue();

        $this->assertSame(1, $report['results']['artifact-cleanup']['by_count']);
        $this->assertSame(0, $report['results']['artifact-cleanup']['quota_trimmed']);
    }
```

- [ ] **Step 2: Run tests — expect failure**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/services/MaintenanceServiceTest.php --filter FourCounters`
Expected: assertion failure on `array_keys(...)` — current shape has only `expired` and `orphans`.

- [ ] **Step 3: Update MaintenanceService**

In `services/MaintenanceService.php`, replace `maybeRunArtifactCleanup()` (lines 67–92). Update its return PHPDoc and body:

```php
    /**
     * @return array{
     *     ran: bool,
     *     reason: string,
     *     result: array{expired: int, by_count: int, quota_trimmed: int, orphans: int},
     * }
     */
    private function maybeRunArtifactCleanup(): array
    {
        $empty = ['expired' => 0, 'by_count' => 0, 'quota_trimmed' => 0, 'orphans' => 0];

        if ($this->artifactCleanupIntervalSeconds <= 0) {
            return ['ran' => false, 'reason' => 'disabled', 'result' => $empty];
        }

        if (!$this->acquireCooldown('maintenance:artifact-cleanup', $this->artifactCleanupIntervalSeconds)) {
            return ['ran' => false, 'reason' => 'cooldown', 'result' => $empty];
        }

        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');
        $expired = $svc->retentionDays > 0 ? $svc->deleteExpiredArtifacts() : 0;
        $byCount = $svc->maxJobsWithArtifacts > 0 ? $svc->deleteByJobCount() : 0;
        $quotaTrimmed = $svc->maxTotalBytes > 0 ? $svc->trimToTotalBytes() : 0;
        $orphans = $svc->cleanupOrphans();

        \Yii::info(
            "MaintenanceService: artifact cleanup ran (expired={$expired}, by_count={$byCount}, quota_trimmed={$quotaTrimmed}, orphans={$orphans})",
            __CLASS__
        );

        return [
            'ran' => true,
            'reason' => 'due',
            'result' => [
                'expired' => $expired,
                'by_count' => $byCount,
                'quota_trimmed' => $quotaTrimmed,
                'orphans' => $orphans,
            ],
        ];
    }
```

Also update the `runIfDue` return-type PHPDoc to reflect the new shape — change:
```
'results': array<string, array<string, int|string>>
```
to:
```
'results': array<string, array{expired: int, by_count: int, quota_trimmed: int, orphans: int}>
```

- [ ] **Step 4: Run tests — expect pass**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/services/MaintenanceServiceTest.php`
Expected: all green including the new assertions and the pre-existing test that checks `expired` and `orphans` keys (still present).

If the existing `testRunIfDueRunsArtifactCleanupOnFirstCall` now fails because it only asserts two specific keys, it should still pass because `array_keys` equality wasn't used there — it reads individual keys. Re-verify by re-reading the existing assertions; if any of them break, update them to reflect the four-key shape (still asserting the same individual values).

- [ ] **Step 5: Update console output**

In `commands/MaintenanceController.php`, the `actionRun` method prints the result. Find the block that formats the artifact-cleanup row and extend it to show all four counters, e.g.:

```php
$this->stdout(
    sprintf(
        "[artifact-cleanup] expired=%d by_count=%d quota_trimmed=%d orphans=%d\n",
        $r['expired'],
        $r['by_count'],
        $r['quota_trimmed'],
        $r['orphans']
    )
);
```

(Read the file first; the exact current format determines the minimal diff.)

- [ ] **Step 6: Commit**

```bash
git add services/MaintenanceService.php commands/MaintenanceController.php tests/integration/services/MaintenanceServiceTest.php
git commit -m "feat: maintenance sweep runs retention, count, and quota steps"
```

---

## Task 7: PDF inline branch in web JobController

**Files:**
- Modify: `controllers/JobController.php:179-212`
- Modify/Create: `tests/integration/controllers/JobControllerArtifactTest.php`

- [ ] **Step 1: Check whether the test file already exists**

Run: `docker compose exec -T app ls tests/integration/controllers/ | grep -i JobController`
Expected: a list containing any JobController test files. If `JobControllerArtifactTest.php` does not exist, create it with a minimal setUp / helper mirroring sibling artifact tests.

- [ ] **Step 2: Write the failing test**

Create or append (use the existing file convention in the sibling tests for setUp, createUser, createJob, etc.):

```php
    public function testPdfDownloadWithInlineSetsSandboxCsp(): void
    {
        [$job, $artifact] = $this->seedArtifact('application/pdf', 'doc.pdf', '%PDF-1.4 ...');

        $response = $this->runAction('download-artifact', [
            'id' => $job->id,
            'artifact_id' => $artifact->id,
            'inline' => '1',
        ]);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', (string)$response->headers->get('Content-Disposition'));
        $csp = (string)$response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testPdfDownloadWithoutInlineIsAttachment(): void
    {
        [$job, $artifact] = $this->seedArtifact('application/pdf', 'doc.pdf', '%PDF-1.4 ...');

        $response = $this->runAction('download-artifact', [
            'id' => $job->id,
            'artifact_id' => $artifact->id,
        ]);

        $this->assertStringStartsWith('attachment;', (string)$response->headers->get('Content-Disposition'));
    }

    public function testTextArtifactIgnoresInlineFlag(): void
    {
        [$job, $artifact] = $this->seedArtifact('text/plain', 'log.txt', 'hello');

        $response = $this->runAction('download-artifact', [
            'id' => $job->id,
            'artifact_id' => $artifact->id,
            'inline' => '1',
        ]);

        // Text files continue to be attachment by design — preview uses /artifact-content.
        $this->assertStringStartsWith('attachment;', (string)$response->headers->get('Content-Disposition'));
    }
```

The helpers `seedArtifact` and `runAction` follow whatever pattern exists in sibling tests. If nothing usable exists, read `tests/integration/controllers/api/v1/JobArtifactsApiTest.php` for the current pattern and adapt it (it uses `$this->runControllerAction(...)` or a request-container pattern depending on the version of DbTestCase; use that exact call convention).

- [ ] **Step 3: Run tests — expect failure**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/controllers/JobControllerArtifactTest.php`
Expected: the PDF inline test fails because the current code only honours `?inline=1` for images.

- [ ] **Step 4: Implement the PDF branch**

In `controllers/JobController.php`, replace the body of `actionDownloadArtifact` (lines 179–212):

```php
    public function actionDownloadArtifact(int $id, int $artifact_id): Response
    {
        $job = $this->findModel($id);
        $this->requireJobView($job);

        /** @var JobArtifact|null $artifact */
        $artifact = JobArtifact::findOne(['id' => $artifact_id, 'job_id' => $id]);
        if ($artifact === null) {
            throw new NotFoundHttpException("Artifact not found.");
        }

        if (!file_exists($artifact->storage_path)) {
            throw new NotFoundHttpException("Artifact file no longer exists on disk.");
        }

        /** @var ArtifactService $svc */
        $svc = \Yii::$app->get('artifactService');
        $request = \Yii::$app->request;
        assert($request instanceof \yii\web\Request);
        $inlineRequested = $request->getQueryParam('inline') === '1';
        $isImage = $svc->isImageType($artifact->mime_type);
        $isFrame = $svc->isInlineFrameType($artifact->mime_type);
        $inline = $inlineRequested && ($isImage || $isFrame);

        $webResponse = \Yii::$app->response;
        assert($webResponse instanceof \yii\web\Response);

        // PDF + inline: serve with a hardened CSP so embedded JavaScript in the
        // document cannot escape the sandboxed <iframe> into the parent page.
        // The empty "sandbox" directive disables scripts, forms, top-navigation
        // and pointer-lock. X-Frame-Options keeps the response framable from
        // our own origin (the job-view page) but blocks cross-origin embedding.
        if ($inline && $isFrame) {
            $webResponse->headers->set('Content-Security-Policy', "default-src 'none'; object-src 'self'; plugin-types application/pdf; sandbox;");
            $webResponse->headers->set('X-Content-Type-Options', 'nosniff');
            $webResponse->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $webResponse->sendFile(
            $artifact->storage_path,
            $artifact->display_name,
            ['mimeType' => $artifact->mime_type, 'inline' => $inline]
        );
    }
```

- [ ] **Step 5: Run tests — expect pass**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/controllers/JobControllerArtifactTest.php`
Expected: all new tests pass.

- [ ] **Step 6: Commit**

```bash
git add controllers/JobController.php tests/integration/controllers/JobControllerArtifactTest.php
git commit -m "feat: PDF inline preview with sandbox CSP in web controller"
```

---

## Task 8: Mirror PDF branch in API JobsController + `inline_frame` serialization

**Files:**
- Modify: `controllers/api/v1/JobsController.php`
- Modify: `tests/integration/api/v1/JobArtifactsApiTest.php`

- [ ] **Step 1: Read current state**

Read `controllers/api/v1/JobsController.php` to locate `actionDownloadArtifact` and the `serializeArtifact` / artifact-row serialization. Note the exact helpers and response-format pattern used (`$this->asJson`, `sendFile`, etc.).

- [ ] **Step 2: Write the failing tests**

Append to `tests/integration/api/v1/JobArtifactsApiTest.php`:

```php
    public function testApiPdfDownloadInlineSetsSandboxCsp(): void
    {
        [$job, $artifact] = $this->seedArtifact('application/pdf', 'doc.pdf', '%PDF-1.4 ...');
        $token = $this->createApiToken();

        $response = $this->apiGet(
            "/api/v1/jobs/{$job->id}/artifacts/{$artifact->id}/download?inline=1",
            $token
        );

        $this->assertSame('application/pdf', $response->headers['Content-Type']);
        $this->assertStringStartsWith('inline;', $response->headers['Content-Disposition']);
        $this->assertStringContainsString('sandbox', $response->headers['Content-Security-Policy']);
    }

    public function testApiArtifactListIncludesInlineFrameFlag(): void
    {
        [$job, $pdf] = $this->seedArtifact('application/pdf', 'doc.pdf', '%PDF-1.4');
        [$job2, $png] = $this->seedArtifact('image/png', 'shot.png', "\x89PNG\r\n\x1a\n");
        [$job3, $txt] = $this->seedArtifact('text/plain', 'log.txt', 'x');
        $token = $this->createApiToken();

        $pdfRow = $this->apiGetJson("/api/v1/jobs/{$job->id}/artifacts", $token)['data'][0];
        $this->assertTrue($pdfRow['inline_frame']);
        $this->assertFalse($pdfRow['image']);
        $this->assertFalse($pdfRow['previewable']);

        $pngRow = $this->apiGetJson("/api/v1/jobs/{$job2->id}/artifacts", $token)['data'][0];
        $this->assertFalse($pngRow['inline_frame']);
        $this->assertTrue($pngRow['image']);

        $txtRow = $this->apiGetJson("/api/v1/jobs/{$job3->id}/artifacts", $token)['data'][0];
        $this->assertFalse($txtRow['inline_frame']);
        $this->assertTrue($txtRow['previewable']);
    }
```

(Helper names — `apiGet`, `apiGetJson`, `createApiToken`, `seedArtifact` — mirror whatever the existing file uses. If any are missing, read the file and adapt signatures.)

- [ ] **Step 3: Run tests — expect failure**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/api/v1/JobArtifactsApiTest.php --filter "Pdf|InlineFrame"`
Expected: assertion failures / missing field.

- [ ] **Step 4: Implement PDF branch in API controller**

In `controllers/api/v1/JobsController.php`, inside `actionDownloadArtifact`, mirror the web controller's CSP/sandbox branch. The shape is identical — set the three response headers when the mime is an inline-frame type and `?inline=1`.

- [ ] **Step 5: Add `inline_frame` to artifact serialization**

Locate the serialization helper that returns artifact rows (grep for `previewable` within the same file). Extend it:

```php
        return [
            'id' => (int)$artifact->id,
            'display_name' => $artifact->display_name,
            'mime_type' => $artifact->mime_type,
            'size_bytes' => (int)$artifact->size_bytes,
            'previewable' => $svc->isPreviewable($artifact->mime_type),
            'image' => $svc->isImageType($artifact->mime_type),
            'inline_frame' => $svc->isInlineFrameType($artifact->mime_type),
            'created_at' => (int)$artifact->created_at,
        ];
```

- [ ] **Step 6: Run tests — expect pass**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/api/v1/JobArtifactsApiTest.php`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add controllers/api/v1/JobsController.php tests/integration/api/v1/JobArtifactsApiTest.php
git commit -m "feat: PDF inline preview + inline_frame flag in REST API"
```

---

## Task 9: Sandboxed iframe branch in the job-view UI

**Files:**
- Modify: `views/job/view.php`

- [ ] **Step 1: Extend the preview button wiring**

In `views/job/view.php`, around line 275, replace the block that picks the preview URL. Add a third branch for inline-frame types:

```php
                        <?php
                        $isImage = $artifactService->isImageType($artifact->mime_type);
                        $isText  = $artifactService->isPreviewable($artifact->mime_type);
                        $isFrame = $artifactService->isInlineFrameType($artifact->mime_type);
                        if ($isImage || $isText || $isFrame) :
                            if ($isImage) {
                                $previewUrl = Url::to(['download-artifact', 'id' => $job->id, 'artifact_id' => $artifact->id, 'inline' => 1]);
                                $previewKind = 'image';
                            } elseif ($isFrame) {
                                $previewUrl = Url::to(['download-artifact', 'id' => $job->id, 'artifact_id' => $artifact->id, 'inline' => 1]);
                                $previewKind = 'frame';
                            } else {
                                $previewUrl = Url::to(['artifact-content', 'id' => $job->id, 'artifact_id' => $artifact->id]);
                                $previewKind = 'text';
                            }
                            ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-info artifact-preview-btn"
                                    data-url="<?= Html::encode($previewUrl) ?>"
                                    data-artifact-id="<?= $artifact->id ?>"
                                    data-preview-kind="<?= Html::encode($previewKind) ?>">Preview</button>
                        <?php endif; ?>
```

- [ ] **Step 2: Add the iframe container markup**

In the same file, find the preview row (around line 291, the `<tr class="artifact-preview-row ...">` block). Add a third child next to the `.artifact-preview-image` div:

```php
                        <div class="p-2 bg-dark artifact-preview-frame d-none">
                            <iframe sandbox="" style="width:100%;height:600px;border:0;background:white;" loading="lazy"></iframe>
                        </div>
```

- [ ] **Step 3: Extend the JS handler**

In the `<script>` block at the bottom of the file, extend the `click` handler. After the `kind === 'image'` branch, add:

```js
        if (kind === 'frame') {
            var frameWrap = row.querySelector('.artifact-preview-frame');
            var frame     = frameWrap.querySelector('iframe');
            if (!frame.dataset.loaded) {
                frame.src = url;
                frame.dataset.loaded = '1';
            }
            frameWrap.classList.remove('d-none');
            row.classList.remove('d-none');
            return;
        }
```

Also ensure the existing branches `classList.add('d-none')` on the other two sibling wrappers when toggling, so switching preview types in one row hides the previous visual. Current code already toggles the row; if it toggles individual wrappers, add `.artifact-preview-frame` to that list.

- [ ] **Step 4: Run an ad-hoc PHP syntax check**

Run: `docker compose exec -T app php -l views/job/view.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add views/job/view.php
git commit -m "feat: sandboxed iframe PDF preview in job view"
```

---

## Task 10: Extend E2E artifact seeder with XML, YAML, and PDF

**Files:**
- Modify: `commands/E2eArtifactSeeder.php`

- [ ] **Step 1: Add three `createArtifact` calls**

In `commands/E2eArtifactSeeder.php`, inside the `seed()` method, after the PNG block (line 69 or so), append:

```php
        $this->createArtifact(
            $job->id,
            $storagePath . '/config.xml',
            'config.xml',
            'application/xml',
            '<?xml version="1.0" encoding="UTF-8"?><config><ok>true</ok></config>',
        );
        $this->createArtifact(
            $job->id,
            $storagePath . '/vars.yaml',
            'vars.yaml',
            'application/yaml',
            "environment: e2e\nstatus: ok\nvalues:\n  - 1\n  - 2\n  - 3\n",
        );
        // Minimal valid PDF (opens as an empty one-page document in any reader).
        $minimalPdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 300]>>endobj\n"
            . "xref\n0 4\n0000000000 65535 f \n0000000010 00000 n \n0000000053 00000 n \n0000000098 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n150\n%%EOF";
        $this->createArtifact(
            $job->id,
            $storagePath . '/report.pdf',
            'report.pdf',
            'application/pdf',
            $minimalPdf,
        );
```

Update the final log line to reflect the new count:

```php
        ($this->logger)("  Created job #{$job->id} with 6 artifacts.\n");
```

- [ ] **Step 2: Idempotency check**

The existing early-return (`if ($existing !== null)`) already prevents reseeding. No change needed — confirm by reading the method.

- [ ] **Step 3: Syntax check**

Run: `docker compose exec -T app php -l commands/E2eArtifactSeeder.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add commands/E2eArtifactSeeder.php
git commit -m "test: seed XML, YAML, PDF artifacts for e2e preview coverage"
```

---

## Task 11: Extend Playwright specs for every preview format

**Files:**
- Modify: `tests/e2e/tests/jobs/artifacts.spec.ts`

- [ ] **Step 1: Append per-format preview tests**

At the bottom of the `test.describe('Job Artifacts', ...)` block, before the closing `});`, add:

```ts
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
```

- [ ] **Step 2: Commit**

```bash
git add tests/e2e/tests/jobs/artifacts.spec.ts
git commit -m "test(e2e): cover TXT/JSON/XML/YAML/PDF artifact preview"
```

---

## Task 12: Surface new limit in System Artifact-Stats view + API

**Files:**
- Modify: `controllers/SystemController.php`
- Modify: `controllers/api/v1/SystemController.php`
- Modify: `views/system/artifact-stats.php`
- Modify: `tests/integration/controllers/SystemControllerActionTest.php`
- Modify: `tests/integration/controllers/api/v1/SystemControllerTest.php`

- [ ] **Step 1: Extend web controller**

In `controllers/SystemController.php`, extend the action that renders `artifact-stats`. Find where `maxArtifactsPerJob` is passed to the view (around line 37) and add `maxJobsWithArtifacts`:

```php
            'maxArtifactsPerJob' => $svc->maxArtifactsPerJob,
            'maxJobsWithArtifacts' => $svc->maxJobsWithArtifacts,
```

- [ ] **Step 2: Extend API controller**

In `controllers/api/v1/SystemController.php`, around line 41, extend the `config` payload:

```php
                'max_artifacts_per_job' => $svc->maxArtifactsPerJob,
                'max_jobs_with_artifacts' => $svc->maxJobsWithArtifacts,
```

- [ ] **Step 3: Extend the view**

In `views/system/artifact-stats.php`, extend the `@var` list at the top:

```php
/** @var int $maxJobsWithArtifacts */
```

Find the `<tr>` for `$maxArtifactsPerJob` and add a row below:

```php
                <tr>
                    <td>Max jobs with artifacts</td>
                    <td><?= $maxJobsWithArtifacts === 0 ? '<em>unlimited</em>' : number_format($maxJobsWithArtifacts) ?></td>
                </tr>
```

- [ ] **Step 4: Update existing tests**

In `tests/integration/controllers/SystemControllerActionTest.php`, find the test asserting `capturedParams['maxArtifactsPerJob']` (around line 86). Extend the same test to assert `$ctrl->capturedParams['maxJobsWithArtifacts']`, setting the value on the service in the arrange block (mirror the existing `$service->maxArtifactsPerJob = 7` pattern).

In `tests/integration/controllers/api/v1/SystemControllerTest.php`, similarly assert the new `max_jobs_with_artifacts` field.

- [ ] **Step 5: Run tests — expect pass**

Run: `docker compose exec -T app ./vendor/bin/phpunit tests/integration/controllers/SystemControllerActionTest.php tests/integration/controllers/api/v1/SystemControllerTest.php`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add controllers/SystemController.php controllers/api/v1/SystemController.php views/system/artifact-stats.php tests/integration/controllers/SystemControllerActionTest.php tests/integration/controllers/api/v1/SystemControllerTest.php
git commit -m "feat: expose maxJobsWithArtifacts in system stats"
```

---

## Task 13: OpenAPI — `inline_frame` flag and endpoint description

**Files:**
- Modify: `web/openapi.yaml`

- [ ] **Step 1: Update `JobArtifact` schema**

In `web/openapi.yaml`, extend the `JobArtifact` schema (currently around lines 1927–1936):

```yaml
    JobArtifact:
      type: object
      properties:
        id:           { type: integer }
        display_name: { type: string }
        mime_type:    { type: string }
        size_bytes:   { type: integer }
        previewable:  { type: boolean, description: "True for text/JSON/XML/YAML — fetch via /content endpoint." }
        image:        { type: boolean, description: "True for image/png|jpeg|gif|webp — fetch via /download?inline=1 to embed as <img>." }
        inline_frame: { type: boolean, description: "True for application/pdf — fetch via /download?inline=1 to embed in sandboxed <iframe>." }
        created_at:   { type: integer }
```

- [ ] **Step 2: Update the download endpoint description**

Around lines 467–490, replace the description of `/api/v1/jobs/{id}/artifacts/{artifact_id}/download` with:

```yaml
      description: |
        Requires `job.view`. By default the response uses
        `Content-Disposition: attachment` so the browser saves the file.

        Pass `?inline=1` to receive `Content-Disposition: inline`. This is
        honoured for two MIME categories:
        - Image types (`image/png`, `image/jpeg`, `image/gif`, `image/webp`)
          — rendered as `<img>` in the UI.
        - PDF (`application/pdf`) — served with a hardened
          `Content-Security-Policy: ... sandbox;` header so the document can
          be embedded in a sandboxed `<iframe>` without script execution.

        Non-image / non-PDF artifacts ignore the parameter and continue to
        download.
```

- [ ] **Step 3: Extend `ArtifactStorageStats.config`**

Around lines 1955–1962, add the new field:

```yaml
            max_total_bytes:         { type: integer, description: "Global cap; 0 = unlimited." }
            max_jobs_with_artifacts: { type: integer, description: "Keep only the N most recent jobs with artifacts; 0 = unlimited." }
```

- [ ] **Step 4: Bump `info.version` later**

Do NOT bump `info.version` here — it is bumped together with `./bin/release`.

- [ ] **Step 5: Validate the spec**

Run: `docker compose exec -T app ./bin/tests-lint.sh 2>&1 | grep -E "(openapi|yaml)"`
Expected: OpenAPI parse/ref check passes; no errors.

If the lint script cannot be isolated this way, run the full lint script:
`docker compose exec -T app ./bin/tests-lint.sh`

- [ ] **Step 6: Commit**

```bash
git add web/openapi.yaml
git commit -m "docs: OpenAPI updates for PDF inline preview and job-count retention"
```

---

## Task 14: Update docs (artifacts.md)

**Files:**
- Modify: `docs/artifacts.md`

- [ ] **Step 1: Add the new env variable to the config table**

Find the section that documents env variables (the grep found mentions around `maxArtifactsPerJob`). Add a row for `ARTIFACT_MAX_JOBS_WITH_ARTIFACTS`, describing:

- Purpose: keep only N most recent jobs with artifacts
- Default: 0 (unlimited)
- Interaction with `ARTIFACT_RETENTION_DAYS`: OR combination
- Behaviour when changed: next maintenance tick will trim whatever is over — warn operators to pick a value with headroom

- [ ] **Step 2: Add a short "PDF preview" subsection**

Under the existing preview documentation, add a paragraph explaining PDFs are rendered inline in a sandboxed iframe, that CSP prevents PDF-embedded JavaScript from executing, and that SVG is still excluded (XSS).

- [ ] **Step 3: Add a "retroactive quota" subsection**

Briefly state: when `ARTIFACT_MAX_TOTAL_BYTES` is set and current storage exceeds it, the next maintenance sweep trims whole jobs (oldest first) until under the limit. Each trim is audited. Per-job limits remain collection-time-only.

- [ ] **Step 4: Commit**

```bash
git add docs/artifacts.md
git commit -m "docs: document PDF preview, job-count retention, retroactive quota"
```

---

## Task 15: Final green check

**Files:** _(none — verification only)_

- [ ] **Step 1: Re-read the spec and mentally map each requirement**

Open `docs/superpowers/specs/2026-04-16-artifact-followups-design.md`. For each of sections 3.1, 3.2, 3.3, walk through the tasks above and confirm the requirement is implemented and tested. Note any gap in a scratch list.

- [ ] **Step 2: Run only the touched PHPUnit tests first (fast feedback)**

Run:
```
docker compose exec -T app ./vendor/bin/phpunit \
  tests/integration/services/ArtifactServiceTest.php \
  tests/integration/services/MaintenanceServiceTest.php \
  tests/integration/controllers/JobControllerArtifactTest.php \
  tests/integration/api/v1/JobArtifactsApiTest.php \
  tests/integration/controllers/SystemControllerActionTest.php \
  tests/integration/controllers/api/v1/SystemControllerTest.php
```
Expected: all green.

- [ ] **Step 3: Stop here**

Do NOT run the full `bin/tests.sh`. That is reserved for the user's `PUSH IT` flow — see `CLAUDE.md` for the release workflow.

Report to the user: "Implementation complete, integration tests green. Ready for `PUSH IT`."

---

## Self-review (already applied)

- **Spec coverage:** PDF preview → Tasks 2, 7, 8, 9, 11. Retention-by-count → Tasks 3, 4, 6. Retroactive quota → Tasks 5, 6. Audit → Task 1 (constant) + Tasks 4/5 (emit). Config surface → Task 3. Docs/OpenAPI → Tasks 13, 14. System stats → Task 12. E2E coverage → Tasks 10, 11.
- **No placeholders:** every step shows actual code/commands.
- **Type consistency:** `maxJobsWithArtifacts` used consistently; `deleteByJobCount`, `trimToTotalBytes`, `isInlineFrameType` match spelling throughout; audit reason values `'job_count'` and `'global_quota'` match between service and test.
