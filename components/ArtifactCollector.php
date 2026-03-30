<?php

declare(strict_types=1);

namespace app\components;

use app\models\Job;

/**
 * Collects artifacts from a job's artifact directory and cleans up temp files.
 */
class ArtifactCollector
{
    /**
     * Collect artifacts from the job's artifact directory if present.
     *
     * @param array<string, string> $env
     */
    public function collect(Job $job, array $env): void
    {
        $artifactDir = $env['ANSILUME_ARTIFACT_DIR'] ?? null;
        if ($artifactDir === null || !is_dir($artifactDir)) {
            return;
        }

        try {
            /** @var \app\services\ArtifactService $service */
            $service = \Yii::$app->get('artifactService');
            $artifacts = $service->collectFromDirectory($job, $artifactDir);
            if (!empty($artifacts)) {
                \Yii::info("ArtifactCollector: collected " . count($artifacts) . " artifact(s) for job #{$job->id}", __CLASS__);
            }
        } catch (\Throwable $e) {
            \Yii::error("ArtifactCollector: collection failed for job #{$job->id}: " . $e->getMessage(), __CLASS__);
        } finally {
            $this->cleanupDirectory($artifactDir);
        }
    }

    /**
     * Recursively remove a temporary directory.
     *
     * Symlinks are removed (unlinked) but never followed — this prevents
     * a malicious playbook from tricking cleanup into deleting files
     * outside the artifact directory.
     */
    public function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $realDir = realpath($dir);
        if ($realDir === false) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $this->removeItem($item, $realDir);
        }

        \app\helpers\FileHelper::safeRmdir($dir);
    }

    /**
     * Remove a single filesystem item during cleanup.
     * Symlinks are unlinked but never followed; real paths are verified
     * to be inside the parent directory (defense in depth).
     */
    private function removeItem(\SplFileInfo $item, string $realDir): void
    {
        $path = $item->getPathname();

        if ($item->isLink()) {
            \app\helpers\FileHelper::safeUnlink($path);
            return;
        }

        $realPath = $item->getRealPath();
        if ($realPath === false || !str_starts_with($realPath, $realDir)) {
            return;
        }

        $item->isDir()
            ? \app\helpers\FileHelper::safeRmdir($path)
            : \app\helpers\FileHelper::safeUnlink($path);
    }
}
