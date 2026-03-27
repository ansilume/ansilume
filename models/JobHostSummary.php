<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Per-host Ansible PLAY RECAP data for a job.
 *
 * @property int    $id
 * @property int    $job_id
 * @property string $host
 * @property int    $ok
 * @property int    $changed
 * @property int    $failed
 * @property int    $skipped
 * @property int    $unreachable
 * @property int    $rescued
 * @property int    $created_at
 *
 * @property Job    $job
 */
class JobHostSummary extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%job_host_summary}}';
    }

    public function getJob(): \yii\db\ActiveQuery
    {
        return $this->hasOne(Job::class, ['id' => 'job_id']);
    }

    /**
     * Aggregate all host summaries for a job into a single totals array.
     *
     * @param  self[] $summaries
     * @return array{ok:int, changed:int, failed:int, skipped:int, unreachable:int, rescued:int, hosts:int}
     */
    public static function aggregate(array $summaries): array
    {
        $totals = ['ok' => 0, 'changed' => 0, 'failed' => 0, 'skipped' => 0, 'unreachable' => 0, 'rescued' => 0, 'hosts' => 0];
        foreach ($summaries as $s) {
            $totals['ok'] += $s->ok;
            $totals['changed'] += $s->changed;
            $totals['failed'] += $s->failed;
            $totals['skipped'] += $s->skipped;
            $totals['unreachable'] += $s->unreachable;
            $totals['rescued'] += $s->rescued;
            $totals['hosts']++;
        }
        return $totals;
    }
}
