<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Notifications v2 — drop the job_template_notification pivot and rewrite
 * legacy event names to the new catalog.
 *
 * v1 scoped every notification template to individual job templates via a
 * many-to-many pivot. v2 makes subscriptions global: every template row that
 * lists an event in its `events` column fires for every occurrence of that
 * event. This is the only model that makes sense for non-job events like
 * runner.offline, webhook.invalid_token, schedule.failed_to_launch — nothing
 * to scope them to.
 *
 * Event string rewrites:
 *   job.started    -> removed (no equivalent, rarely useful)
 *   job.timed_out  -> job.failed (timeouts are a failure mode)
 *
 * NOT backwards compatible. The pivot is dropped; any UI or API that relied
 * on per-template selection is removed in the same commit.
 */
class m000060_000000_notifications_v2 extends Migration
{
    public function safeUp(): void
    {
        // 1) Drop the scoping pivot — all subscriptions become global.
        if ($this->db->schema->getTableSchema('{{%job_template_notification}}', true) !== null) {
            $this->dropTable('{{%job_template_notification}}');
        }

        // Track the last runner.offline transition we notified about, so the
        // health checker can fire runner.recovered exactly once when the
        // runner comes back.
        $runnerTable = $this->db->schema->getTableSchema('{{%runner}}', true);
        if ($runnerTable !== null && $runnerTable->getColumn('offline_notified_at') === null) {
            $this->addColumn('{{%runner}}', 'offline_notified_at', $this->integer()->null());
        }

        // Track the last project.sync_failed transition for the same reason.
        $projectTable = $this->db->schema->getTableSchema('{{%project}}', true);
        if ($projectTable !== null && $projectTable->getColumn('last_sync_event') === null) {
            $this->addColumn('{{%project}}', 'last_sync_event', $this->string(32)->null());
        }

        // 2) Rewrite legacy event strings in every existing template.
        /** @var array<int, array{id: int, events: string|null}> $rows */
        $rows = $this->db->createCommand(
            'SELECT id, events FROM {{%notification_template}}'
        )->queryAll();

        foreach ($rows as $row) {
            $events = array_values(array_filter(
                array_map('trim', explode(',', (string)$row['events']))
            ));
            if ($events === []) {
                continue;
            }

            $rewritten = [];
            foreach ($events as $event) {
                if ($event === 'job.started') {
                    continue; // removed in v2
                }
                if ($event === 'job.timed_out') {
                    $rewritten[] = 'job.failed';
                    continue;
                }
                $rewritten[] = $event;
            }
            $rewritten = array_values(array_unique($rewritten));
            if ($rewritten === []) {
                // Leave the row but fall back to the default failure subscription
                // so operators don't end up with an inert template.
                $rewritten = ['job.failed'];
            }

            $this->db->createCommand()
                ->update(
                    '{{%notification_template}}',
                    [
                        'events' => implode(',', $rewritten),
                        'updated_at' => time(),
                    ],
                    ['id' => (int)$row['id']]
                )
                ->execute();
        }
    }

    public function safeDown(): void
    {
        // The pivot is intentionally not recreated — v2 does not support
        // per-job-template scoping, so a down migration has nothing to restore.
        // Event name rewrites are forward-only (job.timed_out was already a
        // synonym for failure).
    }
}
