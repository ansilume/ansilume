<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\AuditLog;
use app\models\JobTemplate;

/**
 * Fixtures of the docs tests of the queries that list the changes of the
 * last 30 days to gated job templates and to what they use: the fourteenth
 * query of the section of 2.10.0 (GatedTemplateBehaviourDocsContractTest),
 * and its successor in the section of 2.12.0, which counts a job template
 * gated by the rule of its inventory too
 * (InventoryApprovalQueriesDocsContractTest). Use in DbTestCase subclasses
 * together with CancellationFixtures.
 */
trait GatedChangesFixtures
{
    /**
     * Audit entries around $gated, a job template whose launches wait for an
     * approval rule, which gets a primary and an additional credential:
     * - listed, oldest first: the changes of the last 30 days to $gated, its
     *   project, its inventory (by $other) and its two credentials (the
     *   additional one by $userId), which the query lists newest first;
     * - not_listed: a change of a credential that only a template without a
     *   rule uses, of that template and of its inventory, a sync of the
     *   project of $gated, a trigger token generated for it, and a change of
     *   its project older than 30 days.
     *
     * @return array{listed: list<int>, not_listed: list<int>, additional: int, open: JobTemplate}
     */
    private function gatedChanges(JobTemplate $gated, int $userId, int $other): array
    {
        $primary = (int)$this->createCredential($userId)->id;
        $additional = (int)$this->createCredential($userId)->id;
        $unrelated = (int)$this->createCredential($userId)->id;
        $gated->credential_id = $primary;
        $this->assertTrue($gated->save(false));
        \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
            'job_template_id' => (int)$gated->id,
            'credential_id' => $additional,
            'sort_order' => 0,
        ])->execute();
        $open = $this->cancelFixtureTemplate($userId);
        $open->credential_id = $unrelated;
        $this->assertTrue($open->save(false));

        return [
            'listed' => [
                $this->audit(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$gated->id, $other),
                $this->audit(AuditLog::ACTION_PROJECT_UPDATED, 'project', (int)$gated->project_id, $other),
                $this->audit(AuditLog::ACTION_INVENTORY_UPDATED, 'inventory', (int)$gated->inventory_id, $other),
                $this->audit(AuditLog::ACTION_CREDENTIAL_UPDATED, 'credential', $primary, $other),
                $this->audit(AuditLog::ACTION_CREDENTIAL_UPDATED, 'credential', $additional, $userId),
            ],
            'not_listed' => [
                $this->audit(AuditLog::ACTION_CREDENTIAL_UPDATED, 'credential', $unrelated, $other),
                $this->audit(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$open->id, $other),
                $this->audit(AuditLog::ACTION_INVENTORY_UPDATED, 'inventory', (int)$open->inventory_id, $other),
                $this->audit(AuditLog::ACTION_PROJECT_SYNCED, 'project', (int)$gated->project_id, $other),
                $this->audit(AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED, 'job_template', (int)$gated->id, $other),
                $this->audit(AuditLog::ACTION_PROJECT_UPDATED, 'project', (int)$gated->project_id, $other, 31 * 86400),
            ],
            'additional' => $additional,
            'open' => $open,
        ];
    }

    /**
     * The rows a query of the changes to gated job templates listed of the
     * entries gatedChanges() wrote, by audit ID, are exactly the listed
     * ones, newest first, each of $gated, with the user, action and object
     * of its entry.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array{listed: list<int>, not_listed: list<int>, additional: int, open: JobTemplate} $changes
     */
    private function assertListsTheGatedChanges(array $rows, JobTemplate $gated, array $changes, int $other): void
    {
        $listed = $changes['listed'];
        $this->assertSame(array_reverse($listed), array_keys($rows), 'Newest first, and only these.');
        foreach ($rows as $row) {
            $this->assertSame((int)$gated->id, (int)$row['job_template_id']);
        }
        $this->assertSame($other, (int)$rows[$listed[0]]['user_id']);
        $this->assertSame(AuditLog::ACTION_CREDENTIAL_UPDATED, $rows[$listed[4]]['action']);
        $this->assertSame($changes['additional'], (int)$rows[$listed[4]]['object_id']);
    }

    /**
     * An audit entry as AuditService writes it, $age seconds old; its ID.
     */
    private function audit(string $action, string $objectType, int $objectId, int $userId, int $age = 0): int
    {
        \Yii::$app->db->createCommand()->insert('{{%audit_log}}', [
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'user_id' => $userId,
            'created_at' => time() - $age,
        ])->execute();

        return (int)\Yii::$app->db->getLastInsertID();
    }
}
