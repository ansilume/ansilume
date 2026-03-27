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

        $artifacts = [];
        $count = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $realSourceDir = realpath($sourceDir);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            // Skip symlinks — prevents exfiltration of files outside the artifact dir
            if ($file->isLink()) {
                \Yii::warning("ArtifactService: skipping symlink {$file->getPathname()} for job #{$job->id}", __CLASS__);
                continue;
            }

            // Verify the real path is inside the source directory (defense in depth)
            $realFilePath = $file->getRealPath();
            if ($realFilePath === false || ($realSourceDir !== false && !str_starts_with($realFilePath, $realSourceDir))) {
                \Yii::warning("ArtifactService: skipping file outside source dir {$file->getPathname()} for job #{$job->id}", __CLASS__);
                continue;
            }

            if ($count >= $this->maxArtifactsPerJob) {
                \Yii::warning("ArtifactService: artifact limit ({$this->maxArtifactsPerJob}) reached for job #{$job->id}", __CLASS__);
                break;
            }

            $fileSize = $file->getSize();
            if ($fileSize > $this->maxFileSize) {
                \Yii::warning("ArtifactService: skipping oversized artifact {$file->getFilename()} ({$fileSize} bytes) for job #{$job->id}", __CLASS__);
                continue;
            }

            $relativePath = ltrim(
                substr($file->getPathname(), strlen($sourceDir)),
                DIRECTORY_SEPARATOR
            );

            $storedName = $this->generateStoredName($file->getFilename());
            $destPath = $basePath . DIRECTORY_SEPARATOR . $storedName;

            if (!copy($file->getPathname(), $destPath)) {
                \Yii::error("ArtifactService: failed to copy {$file->getPathname()} to {$destPath}", __CLASS__);
                continue;
            }

            chmod($destPath, 0640);

            $artifact = $this->saveArtifactRecord($job, $storedName, $relativePath, $file->getPathname(), $fileSize, $destPath);
            if ($artifact !== null) {
                $artifacts[] = $artifact;
                $count++;
            } else {
                \app\helpers\FileHelper::safeUnlink($destPath);
            }
        }

        return $artifacts;
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
