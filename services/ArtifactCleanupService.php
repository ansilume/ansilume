<?php

declare(strict_types=1);

namespace app\services;

use app\models\AuditLog;
use app\models\JobArtifact;

/**
 * Retention sweep and orphan-file cleanup for artifact storage.
 *
 * Extracted from {@see ArtifactService} so that class can stay focused on
 * collection and storage. This service owns every operation that walks the
 * storage directory or removes records based on age, and emits the
 * corresponding audit-log entries so cleanup is fully traceable.
 */
class ArtifactCleanupService
{
    public function __construct(
        private string $storagePath,
        private int $retentionDays = 0,
        private int $maxJobsWithArtifacts = 0,
        private int $maxTotalBytes = 0,
    ) {
    }

    /**
     * Delete artifacts older than the configured retention period.
     *
     * Each deletion is audit-logged as a system action ({@see AuditLog::ACTION_ARTIFACT_EXPIRED})
     * BEFORE the row is deleted so the audit entry can still reference the
     * original object_id. The audit entry has no user_id since cleanup is
     * triggered by the maintenance scheduler, not a human.
     *
     * @return int Number of artifacts deleted.
     */
    public function deleteExpiredArtifacts(): int
    {
        if ($this->retentionDays <= 0) {
            return 0;
        }

        $cutoff = time() - ($this->retentionDays * 86400);
        $expired = JobArtifact::find()->where(['<', 'created_at', $cutoff])->all();
        $count = 0;

        foreach ($expired as $artifact) {
            $this->auditExpiredArtifact($artifact, $cutoff);
            \app\helpers\FileHelper::safeUnlink($artifact->storage_path);
            $artifact->delete();
            $count++;
        }

        $this->removeEmptyJobDirs();

        return $count;
    }

    /**
     * Remove orphan files from the storage directory that have no DB record.
     *
     * @return int Number of orphan files removed.
     */
    public function cleanupOrphans(): int
    {
        $base = \Yii::getAlias($this->storagePath, false);
        if ($base === false || !is_dir($base)) {
            return 0;
        }

        $removed = 0;
        $jobDirs = scandir($base);
        if ($jobDirs === false) {
            return 0;
        }

        foreach ($jobDirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            $removed += $this->cleanupOrphanDir($base . DIRECTORY_SEPARATOR . $dir);
        }

        return $removed;
    }

    /**
     * Emit an audit entry for an artifact about to be removed by the retention sweep.
     */
    private function auditExpiredArtifact(JobArtifact $artifact, int $cutoff): void
    {
        if (!\Yii::$app->has('auditService')) {
            return; // graceful no-op for stripped-down test apps
        }
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_ARTIFACT_EXPIRED,
            'artifact',
            $artifact->id,
            null,
            [
                'job_id' => $artifact->job_id,
                'display_name' => $artifact->display_name,
                'size_bytes' => $artifact->size_bytes,
                'created_at' => $artifact->created_at,
                'cutoff' => $cutoff,
                'retention_days' => $this->retentionDays,
            ]
        );
    }

    /**
     * Clean up orphan files in a single job directory. Each removal is
     * audit-logged ({@see AuditLog::ACTION_ARTIFACT_ORPHAN_REMOVED}). object_id
     * is null because there is by definition no JobArtifact row to reference;
     * the file path lives in the metadata for forensic traceability.
     */
    private function cleanupOrphanDir(string $dirPath): int
    {
        $filePaths = $this->listFilesInDir($dirPath);
        $removed = 0;
        foreach ($filePaths as $filePath) {
            $exists = JobArtifact::find()
                ->where(['storage_path' => $filePath])
                ->exists();
            if (!$exists) {
                $this->auditOrphanRemoval($filePath);
                \app\helpers\FileHelper::safeUnlink($filePath);
                $removed++;
            }
        }

        $this->removeDirIfEmpty($dirPath);

        return $removed;
    }

    /**
     * Emit an audit entry for an orphan file about to be removed by the cleanup sweep.
     */
    private function auditOrphanRemoval(string $filePath): void
    {
        if (!\Yii::$app->has('auditService')) {
            return;
        }
        $size = file_exists($filePath) ? (int)filesize($filePath) : 0;
        \Yii::$app->get('auditService')->log(
            AuditLog::ACTION_ARTIFACT_ORPHAN_REMOVED,
            'artifact',
            null,
            null,
            [
                'storage_path' => $filePath,
                'size_bytes' => $size,
            ]
        );
    }

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

    /**
     * Remove empty job_N directories from storage.
     */
    private function removeEmptyJobDirs(): void
    {
        $base = \Yii::getAlias($this->storagePath, false);
        if ($base === false || !is_dir($base)) {
            return;
        }

        foreach ($this->listSubDirs($base) as $fullPath) {
            $this->removeDirIfEmpty($fullPath);
        }
    }

    /**
     * List regular files in a directory (excluding . and ..).
     * @return list<string>
     */
    private function listFilesInDir(string $dirPath): array
    {
        if (!is_dir($dirPath)) {
            return [];
        }
        $entries = scandir($dirPath);
        if ($entries === false) {
            return [];
        }
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dirPath . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                $files[] = $path;
            }
        }
        return $files;
    }

    /**
     * List subdirectories in a directory (excluding . and ..).
     * @return list<string>
     */
    private function listSubDirs(string $basePath): array
    {
        $entries = scandir($basePath);
        if ($entries === false) {
            return [];
        }
        $dirs = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $basePath . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }
        return $dirs;
    }

    /**
     * Remove a directory if it contains only . and .. entries.
     */
    private function removeDirIfEmpty(string $dirPath): void
    {
        $remaining = scandir($dirPath);
        if ($remaining !== false && count($remaining) === 2) {
            \app\helpers\FileHelper::safeRmdir($dirPath);
        }
    }
}
