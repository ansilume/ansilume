<?php

declare(strict_types=1);

namespace app\jobs;

use app\components\ErrorText;
use app\models\JobTemplate;
use app\models\Project;
use app\services\LintService;
use app\services\ProjectService;
use app\services\VaultScanService;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * Queue job that syncs a project's SCM repository.
 *
 * The SCM sync is the load-bearing step (its outcome decides the project's
 * status). The vault scan and the lint phase are opportunistic: they run
 * *after* a successful sync to refresh the vault overview and the lint
 * badge. A failure in either must never push the project back into
 * SYNCING/ERROR or mask a successful sync.
 */
class SyncProjectJob extends BaseObject implements JobInterface
{
    /**
     * Logged when the sync failed (ProjectService stored the error on the
     * project). sprintf(): the project's ID, then what may be said about the
     * error (ErrorText): its message, but for a database error only its
     * class, SQLSTATE and driver error code. The two warnings below take the
     * same arguments.
     */
    public const SYNC_FAILED = 'SyncProjectJob: project #%d sync failed: %s';

    /** Logged (warning) when the vault scan after a sync failed; sprintf() as SYNC_FAILED. */
    public const VAULT_SCAN_FAILED = 'SyncProjectJob: vault scan for project #%d failed (sync still committed): %s';

    /** Logged (warning) when the lint phase after a sync failed; sprintf() as SYNC_FAILED. */
    public const LINT_FAILED = 'SyncProjectJob: lint phase for project #%d failed (sync still committed): %s';

    public int $projectId = 0;

    public function execute($queue): void
    {
        /** @var Project|null $project */
        $project = Project::findOne($this->projectId);

        if ($project === null) {
            \Yii::error("SyncProjectJob: project #{$this->projectId} not found.", __CLASS__);
            return;
        }

        \Yii::info("SyncProjectJob: syncing project #{$project->id} ({$project->name})", __CLASS__);

        try {
            /** @var ProjectService $svc */
            $svc = \Yii::$app->get('projectService');
            $svc->sync($project);
            \Yii::info("SyncProjectJob: project #{$project->id} synced successfully.", __CLASS__);
        } catch (\RuntimeException $e) {
            // ProjectService::sync already wrote STATUS_ERROR + last_sync_error
            // before re-throwing. Just log and bail — there's no point in
            // running lint against a tree we couldn't even update.
            \Yii::error(sprintf(self::SYNC_FAILED, (int)$project->id, ErrorText::of($e)), __CLASS__);
            return;
        }

        // Opportunistic vault scan: which encrypted files the checkout holds
        // and whether each template's vault password opens them. Like lint,
        // a failure here must not touch the committed sync.
        try {
            /** @var VaultScanService $vaultScan */
            $vaultScan = \Yii::$app->get('vaultScanService');
            $vaultScan->scanProject($project);
        } catch (\Throwable $e) {
            \Yii::warning(sprintf(self::VAULT_SCAN_FAILED, (int)$project->id, ErrorText::of($e)), __CLASS__);
        }

        // Opportunistic lint phase. Anything thrown here is a lint-side
        // problem (tool missing, permission glitch, ansible-lint crash) —
        // the sync itself is already committed and should stay as SYNCED.
        try {
            /** @var LintService $lintSvc */
            $lintSvc = \Yii::$app->get('lintService');
            $lintSvc->runForProject($project);
            // andWhere(): where() would replace the scope that hides deleted templates.
            foreach (JobTemplate::find()->andWhere(['project_id' => $project->id])->all() as $tpl) {
                $lintSvc->runForTemplate($tpl);
            }
        } catch (\Throwable $e) {
            \Yii::warning(sprintf(self::LINT_FAILED, (int)$project->id, ErrorText::of($e)), __CLASS__);
        }
    }
}
