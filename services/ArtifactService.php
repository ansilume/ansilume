<?php

declare(strict_types=1);

namespace app\services;

use app\models\Job;
use app\models\JobArtifact;
use yii\base\Component;

/**
 * Manages job artifact storage and collection.
 *
 * Artifacts are files produced by Ansible playbook runs that operators want to
 * keep — stats files, generated configs, reports, etc. They are collected from
 * a designated directory after the playbook finishes and stored in a persistent
 * location.
 */
class ArtifactService extends Component
{
    /** @var string Base directory for artifact storage. Supports Yii aliases. */
    public string $storagePath = '@runtime/artifacts';

    /** @var int Maximum file size in bytes for a single artifact (default 10 MB). */
    public int $maxFileSize = 10485760;

    /** @var int Maximum number of artifacts per job. */
    public int $maxArtifactsPerJob = 50;

    /** @var int Days to retain artifacts. 0 = keep forever. */
    public int $retentionDays = 0;

    /** @var string[] MIME types that can be previewed inline. */
    private const PREVIEWABLE_PREFIXES = ['text/'];

    /** @var string[] Exact MIME types that can be previewed inline. */
    private const PREVIEWABLE_TYPES = [
        'application/json',
        'application/xml',
        'application/yaml',
    ];

    /**
     * Collect artifacts from a directory produced by an Ansible run.
     *
     * Scans $sourceDir for files and stores them as JobArtifact records.
     *
     * @return JobArtifact[] The saved artifact records.
     */
    public function collectFromDirectory(Job $job, string $sourceDir): array
    {
        if (!is_dir($sourceDir)) {
            return [];
        }

        $basePath = $this->resolveStoragePath($job);
        if (!is_dir($basePath) && !mkdir($basePath, 0750, true)) {
            \Yii::error("ArtifactService: failed to create storage directory: {$basePath}", __CLASS__);
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $realSourceDir = realpath($sourceDir);
        $artifacts = [];
        $count = 0;

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($count >= $this->maxArtifactsPerJob) {
                \Yii::warning("ArtifactService: artifact limit ({$this->maxArtifactsPerJob}) reached for job #{$job->id}", __CLASS__);
                break;
            }

            if (!$this->isEligibleFile($file, $realSourceDir, $job->id)) {
                continue;
            }

            $artifact = $this->collectSingleFile($job, $file, $sourceDir, $basePath);
            if ($artifact !== null) {
                $artifacts[] = $artifact;
                $count++;
            }
        }

        return $artifacts;
    }

    /**
     * Check whether a file should be collected as an artifact.
     *
     * @param string|false $realSourceDir
     */
    private function isEligibleFile(\SplFileInfo $file, $realSourceDir, int $jobId): bool
    {
        if (!$file->isFile()) {
            return false;
        }

        if ($file->isLink()) {
            \Yii::warning("ArtifactService: skipping symlink {$file->getPathname()} for job #{$jobId}", __CLASS__);
            return false;
        }

        $realFilePath = $file->getRealPath();
        if ($realFilePath === false || ($realSourceDir !== false && !str_starts_with($realFilePath, $realSourceDir))) {
            \Yii::warning("ArtifactService: skipping file outside source dir {$file->getPathname()} for job #{$jobId}", __CLASS__);
            return false;
        }

        if ($file->getSize() > $this->maxFileSize) {
            \Yii::warning("ArtifactService: skipping oversized artifact {$file->getFilename()} ({$file->getSize()} bytes) for job #{$jobId}", __CLASS__);
            return false;
        }

        return true;
    }

    /**
     * Copy a single file into artifact storage and persist the record.
     */
    private function collectSingleFile(Job $job, \SplFileInfo $file, string $sourceDir, string $basePath): ?JobArtifact
    {
        $relativePath = ltrim(
            substr($file->getPathname(), strlen($sourceDir)),
            DIRECTORY_SEPARATOR
        );

        $storedName = $this->generateStoredName($file->getFilename());
        $destPath = $basePath . DIRECTORY_SEPARATOR . $storedName;

        if (!copy($file->getPathname(), $destPath)) {
            \Yii::error("ArtifactService: failed to copy {$file->getPathname()} to {$destPath}", __CLASS__);
            return null;
        }

        chmod($destPath, 0640);

        $artifact = $this->saveArtifactRecord($job, $storedName, $relativePath, $file->getPathname(), $file->getSize(), $destPath);
        if ($artifact === null) {
            \app\helpers\FileHelper::safeUnlink($destPath);
        }

        return $artifact;
    }

    /**
     * Get all artifacts for a job.
     *
     * @return JobArtifact[]
     */
    public function getArtifacts(Job $job): array
    {
        return JobArtifact::find()
            ->where(['job_id' => $job->id])
            ->orderBy(['display_name' => SORT_ASC])
            ->all();
    }

    /**
     * Delete all artifacts for a job (files + records).
     */
    public function deleteForJob(Job $job): void
    {
        $artifacts = $this->getArtifacts($job);
        foreach ($artifacts as $artifact) {
            \app\helpers\FileHelper::safeUnlink($artifact->storage_path);
            $artifact->delete();
        }

        $dir = $this->resolveStoragePath($job);
        \app\helpers\FileHelper::safeRmdir($dir);
    }

    /**
     * Create and persist a JobArtifact record.
     */
    protected function saveArtifactRecord(
        Job $job,
        string $storedName,
        string $displayName,
        string $sourcePath,
        int $fileSize,
        string $destPath,
    ): ?JobArtifact {
        $artifact = new JobArtifact();
        $artifact->job_id = $job->id;
        $artifact->filename = $storedName;
        $artifact->display_name = $displayName;
        $artifact->mime_type = $this->detectMimeType($sourcePath, $displayName);
        $artifact->size_bytes = $fileSize;
        $artifact->storage_path = $destPath;
        $artifact->created_at = time();

        if ($artifact->save()) {
            return $artifact;
        }

        \Yii::error("ArtifactService: failed to save artifact record: " . json_encode($artifact->errors), __CLASS__);
        return null;
    }

    /**
     * Delete artifacts older than the configured retention period.
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
            \app\helpers\FileHelper::safeUnlink($artifact->storage_path);
            $artifact->delete();
            $count++;
        }

        // Clean up empty job directories.
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
     * Get aggregate storage statistics.
     *
     * @return array{total_bytes: int, artifact_count: int, job_count: int}
     */
    public function getStorageStats(): array
    {
        $row = (new \yii\db\Query())
            ->select([
                'COALESCE(SUM(size_bytes), 0) AS total_bytes',
                'COUNT(*) AS artifact_count',
                'COUNT(DISTINCT job_id) AS job_count',
            ])
            ->from(JobArtifact::tableName())
            ->one();

        return [
            'total_bytes' => (int)($row['total_bytes'] ?? 0),
            'artifact_count' => (int)($row['artifact_count'] ?? 0),
            'job_count' => (int)($row['job_count'] ?? 0),
        ];
    }

    /**
     * Check whether a MIME type can be previewed inline.
     */
    public function isPreviewable(string $mimeType): bool
    {
        foreach (self::PREVIEWABLE_PREFIXES as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }

        return in_array($mimeType, self::PREVIEWABLE_TYPES, true);
    }

    /**
     * Read artifact file content for inline preview.
     *
     * Returns null for binary types, missing files, or read errors.
     */
    public function getArtifactContent(JobArtifact $artifact, int $maxBytes = 524288): ?string
    {
        if (!$this->isPreviewable($artifact->mime_type)) {
            return null;
        }

        if (!file_exists($artifact->storage_path)) {
            return null;
        }

        $handle = fopen($artifact->storage_path, 'r');
        if ($handle === false) {
            return null;
        }

        $content = fread($handle, max(1, $maxBytes));
        fclose($handle);

        return $content !== false ? $content : null;
    }

    /**
     * Create a zip archive of all artifacts for a job.
     *
     * @return string|null Path to the temporary zip file, or null if no artifacts.
     */
    public function createZipArchive(Job $job): ?string
    {
        $artifacts = $this->getArtifacts($job);
        if (empty($artifacts)) {
            return null;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'ansilume_artifacts_');
        if ($tmpFile === false) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            unlink($tmpFile);
            return null;
        }

        $added = 0;
        foreach ($artifacts as $artifact) {
            if (file_exists($artifact->storage_path)) {
                $zip->addFile($artifact->storage_path, $artifact->display_name);
                $added++;
            }
        }

        $zip->close();

        if ($added === 0) {
            unlink($tmpFile);
            return null;
        }

        return $tmpFile;
    }

    /**
     * Clean up orphan files in a single job directory.
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
                \app\helpers\FileHelper::safeUnlink($filePath);
                $removed++;
            }
        }

        $this->removeDirIfEmpty($dirPath);

        return $removed;
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

    /**
     * Resolve the storage directory for a specific job.
     */
    protected function resolveStoragePath(Job $job): string
    {
        $base = \Yii::getAlias($this->storagePath, false);
        if ($base === false) {
            throw new \RuntimeException("Invalid storage path alias: {$this->storagePath}");
        }
        return $base . DIRECTORY_SEPARATOR . 'job_' . $job->id;
    }

    private function generateStoredName(string $originalName): string
    {
        $ext = (string)pathinfo($originalName, PATHINFO_EXTENSION);
        $name = bin2hex(random_bytes(16));
        return $ext !== '' ? $name . '.' . $ext : $name;
    }

    private function detectMimeType(string $path, string $displayName): string
    {
        $ext = strtolower((string)pathinfo($displayName, PATHINFO_EXTENSION));

        $map = [
            'json' => 'application/json',
            'yaml' => 'text/yaml',
            'yml' => 'text/yaml',
            'xml' => 'application/xml',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'log' => 'text/plain',
            'html' => 'text/html',
            'htm' => 'text/html',
            'ini' => 'text/plain',
            'cfg' => 'text/plain',
            'conf' => 'text/plain',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
            'zip' => 'application/zip',
        ];

        if (isset($map[$ext])) {
            return $map[$ext];
        }

        if (function_exists('mime_content_type')) {
            $detected = mime_content_type($path);
            if ($detected !== false) {
                return $detected;
            }
        }

        return 'application/octet-stream';
    }
}
