<?php

declare(strict_types=1);

namespace app\services;

use app\models\Inventory;
use app\models\Job;
use app\models\Project;
use app\models\Runner;
use app\models\RunnerGroup;
use yii\base\Component;

/**
 * Atomically claims the next queued job for a runner group.
 *
 * Uses an optimistic UPDATE … WHERE runner_id IS NULL to ensure only
 * one runner in a group can claim a given job, even under concurrent load.
 */
class JobClaimService extends Component
{
    public function claim(RunnerGroup $group, Runner $runner): ?Job
    {
        $db = \Yii::$app->db;
        $tx = $db->beginTransaction();

        try {
            /** @var Job|null $job */
            $job = Job::find()
                ->innerJoin('{{%job_template}}', '{{%job_template}}.id = {{%job}}.job_template_id')
                ->where([
                    '{{%job}}.status' => Job::STATUS_QUEUED,
                    '{{%job_template}}.runner_group_id' => $group->id,
                    '{{%job_template}}.deleted_at' => null,
                    '{{%job}}.runner_id' => null,
                ])
                ->orderBy(['{{%job}}.id' => SORT_ASC])
                ->limit(1)
                ->one($db);

            if ($job === null) {
                $tx->rollBack();
                return null;
            }

            // Atomic claim: only succeeds if nobody else grabbed it first.
            $affected = $db->createCommand()->update(
                '{{%job}}',
                [
                    'runner_id' => $runner->id,
                    'worker_id' => $runner->name,
                    'status' => Job::STATUS_RUNNING,
                    'started_at' => time(),
                    'updated_at' => time(),
                ],
                ['id' => $job->id, 'runner_id' => null, 'status' => Job::STATUS_QUEUED]
            )->execute();

            if ($affected !== 1) {
                $tx->rollBack();
                return null;
            }

            $tx->commit();
            $job->refresh();

            \Yii::$app->get('auditService')->log(
                AuditService::ACTION_JOB_STARTED,
                'job',
                $job->id,
            );

            return $job;
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    /**
     * Build the resolved execution payload that the runner needs to run the job.
     * Resolves IDs to actual paths/content so the runner needs no DB access.
     */
    public function buildExecutionPayload(Job $job): array
    {
        $raw = json_decode($job->runner_payload ?? '{}', true) ?: [];

        $projectPath = $this->resolveProjectPath($raw);
        $playbookPath = rtrim($projectPath, '/') . '/' . ltrim($raw['playbook'] ?? 'site.yml', '/');

        $inventory = $this->resolveInventory($raw);

        return [
            'job_id' => $job->id,
            'project_path' => $projectPath,
            'playbook_path' => $playbookPath,
            'inventory_type' => $inventory['type'],
            'inventory_content' => $inventory['content'], // for static
            'inventory_path' => $inventory['path'], // for file-based
            'extra_vars' => $raw['extra_vars'] ?? null,
            'limit' => $raw['limit'] ?? null,
            'verbosity' => (int)($raw['verbosity'] ?? 0),
            'forks' => (int)($raw['forks'] ?? 5),
            'become' => !empty($raw['become']),
            'become_method' => $raw['become_method'] ?? 'sudo',
            'become_user' => $raw['become_user'] ?? 'root',
            'tags' => $raw['tags'] ?? null,
            'skip_tags' => $raw['skip_tags'] ?? null,
            'timeout_minutes' => (int)($raw['timeout_minutes'] ?? $job->timeout_minutes ?? 120),
        ];
    }

    protected function resolveProjectPath(array $payload): string
    {
        $project = Project::findOne($payload['project_id'] ?? 0);
        return $project?->local_path ?? '/tmp/ansilume/projects';
    }

    protected function resolveInventory(array $payload): array
    {
        $inventory = Inventory::findOne($payload['inventory_id'] ?? 0);
        if ($inventory === null) {
            return ['type' => 'static', 'content' => "localhost\n", 'path' => null];
        }
        if ($inventory->inventory_type === Inventory::TYPE_STATIC) {
            return ['type' => 'static', 'content' => $inventory->content ?? "localhost\n", 'path' => null];
        }
        return ['type' => 'file', 'content' => null, 'path' => $inventory->source_path];
    }
}
