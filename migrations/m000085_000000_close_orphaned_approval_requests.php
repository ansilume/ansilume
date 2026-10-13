<?php

declare(strict_types=1);

use yii\db\Migration;
use yii\db\Query;

/**
 * Closes what releases up to 2.9.0 left behind when a job was canceled or a
 * job template deleted:
 * - approval requests still pending although their job no longer waits for
 *   approval (it was canceled, or a workflow run with it): their status
 *   becomes canceled, resolved at the time the job finished (now when it
 *   has no finish time). Such a request could still be approved or rejected,
 *   and stayed on the dashboard and in the pending list for good. Canceling
 *   a job closes its request from this release on.
 * - enabled schedules of deleted (soft-deleted) job templates: disabled,
 *   without a next run. They failed at every due time, each time with a
 *   failed-to-launch notification. Deleting a template disables its
 *   schedules from this release on.
 *
 * Waiting jobs of deleted templates are not canceled here: a job of a
 * workflow step needs the workflow advance, which only the services do.
 *
 * Data only, with the values as stored, not the models' constants: a
 * migration must keep working when the models change. Running it again
 * changes nothing. It prints counts only.
 */
class m000085_000000_close_orphaned_approval_requests extends Migration
{
    public function safeUp(): void
    {
        $now = time();
        $closed = $this->db->createCommand(
            'UPDATE {{%approval_request}} [[r]]'
                . ' INNER JOIN {{%job}} [[j]] ON [[j]].[[id]] = [[r]].[[job_id]]'
                . ' SET [[r]].[[status]] = :canceled, [[r]].[[resolved_at]] = COALESCE([[j]].[[finished_at]], :now)'
                . ' WHERE [[r]].[[status]] = :pending AND [[j]].[[status]] <> :waiting',
            [':canceled' => 'canceled', ':now' => $now, ':pending' => 'pending', ':waiting' => 'pending_approval']
        )->execute();
        echo "    > Closed {$closed} pending approval request(s) whose job no longer waits for approval.\n";

        $deletedTemplates = (new Query())
            ->select('id')
            ->from('{{%job_template}}')
            ->where(['not', ['deleted_at' => null]]);
        $disabled = $this->db->createCommand()->update(
            '{{%schedule}}',
            ['enabled' => 0, 'next_run_at' => null, 'updated_at' => $now],
            ['and', ['<>', 'enabled', 0], ['in', 'job_template_id', $deletedTemplates]]
        )->execute();
        echo "    > Disabled {$disabled} schedule(s) of deleted job templates.\n";
    }

    public function safeDown(): bool
    {
        // Nothing to restore: a closed request belonged to a job that no
        // longer waited for it, and a disabled schedule to a template that is
        // deleted. Reopening or enabling them would only bring the stale rows
        // back, and the schema is unchanged.
        return true;
    }
}
