<?php

declare(strict_types=1);

namespace app\services\audit;

use app\models\AuditLog;

/**
 * Persists audit entries to the database via the AuditLog ActiveRecord model.
 * This is the default (always-active) target.
 */
class DatabaseAuditTarget implements AuditTargetInterface
{
    public function send(array $entry): void
    {
        $record = new AuditLog();
        $record->action = $entry['action'];
        $record->object_type = $entry['object_type'] ?? null;
        $record->object_id = $entry['object_id'] ?? null;
        $record->user_id = $entry['user_id'] ?? null;
        $record->metadata = $entry['metadata'] ?? null;
        $record->ip_address = $entry['ip_address'] ?? null;
        $record->user_agent = $entry['user_agent'] ?? null;
        $record->created_at = $entry['created_at'];

        if (!$record->save()) {
            \Yii::error('DatabaseAuditTarget: failed to save: ' . json_encode($record->errors), __CLASS__);
        }
    }
}
