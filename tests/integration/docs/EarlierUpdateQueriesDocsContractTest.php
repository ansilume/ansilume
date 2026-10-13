<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\components\SurveySecrets;
use app\models\AuditLog;
use app\models\Job;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;

/**
 * The read-only queries of the notes for the updates from 2.8.0 (trigger
 * tokens generated before the update) and from 2.8.1 (password answers
 * that kept their plaintext) run on the schema of this release and list
 * what their text says. The tests run the documented text in the test's
 * transaction.
 *
 * Regressions from wave 10 of step 3, each fails on the docs as they were
 * before it:
 * - the trigger token queries found the newest token entry with
 *   ORDER BY g.id DESC LIMIT 1, which MariaDB 10.11 answered with an empty
 *   token_generated_by on a small audit_log although the entry existed (a
 *   range plan on idx_audit_log_object), so a token its template's creator
 *   had generated looked like one to revoke;
 * - the password answer queries compared with the BINARY operator, which
 *   MySQL 8 reports as deprecated (warning 1287); CAST(... AS BINARY)
 *   lists the same rows.
 */
class EarlierUpdateQueriesDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;

    private const TRIGGER_SECTION = '## After updating from 2.8.0 or older';

    private const PASSWORD_SECTION = '## After updating from 2.8.1 or older';

    private const TRIGGER_QUERIES = '-- Trigger tokens generated before the update, job templates';

    private const PASSWORD_QUERIES = '-- Jobs of a job template whose extra vars hold a value for <field name>';

    /** The password field the password answer queries are run for. */
    private const FIELD = 'db_password';

    /** Whether the test made a session-local audit_log that hides the real one. */
    private bool $shadowsAuditLog = false;

    protected function tearDown(): void
    {
        if ($this->shadowsAuditLog) {
            \Yii::$app->db->createCommand('DROP TEMPORARY TABLE audit_log')->execute();
            $this->shadowsAuditLog = false;
        }
        parent::tearDown();
    }

    /**
     * Regression (wave 10 of step 3): with an audit_log that holds the
     * template's entries and a few others, MariaDB 10.11 found no entry for
     * ORDER BY g.id DESC LIMIT 1 and listed token_generated_by as empty for
     * a token the template's creator had generated, in both queries. The
     * documented queries take the newest entry with MAX(h.id) and name the
     * generator. The small table is a temporary audit_log of this session,
     * which hides the real one while the queries run.
     */
    public function testRegressionTheTriggerTokenQueriesNameTheGeneratorOnASmallAuditLog(): void
    {
        $creator = (int)$this->createUser('docs-token-creator')->id;
        $other = (int)$this->createUser('docs-token-other')->id;
        $jobTemplate = $this->tokenJobTemplate($creator);
        $workflow = $this->tokenWorkflowTemplate($creator);
        $this->shadowAuditLog();
        $this->audit([
            [$other, AuditLog::ACTION_SCHEDULE_UPDATED, 'schedule', 2],
            [null, AuditLog::ACTION_TRIGGER_FIRED, 'job_template', $jobTemplate],
            [$other, AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', $jobTemplate],
            [$other, AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED, 'workflow_template', $workflow],
            [$creator, AuditLog::ACTION_TEMPLATE_CREATED, 'job_template', $jobTemplate],
            [$creator, AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED, 'job_template', $jobTemplate],
            [$creator, AuditLog::ACTION_WORKFLOW_TEMPLATE_CREATED, 'workflow_template', $workflow],
            [$creator, AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_GENERATED, 'workflow_template', $workflow],
        ]);

        [$jobTemplates, $workflows] = $this->triggerTokenQueries();
        $expected = ['runs_as' => $creator, 'created_entry_by' => $creator, 'token_generated_by' => $creator, 'edits_by_others' => 1];

        $this->assertSame($expected, $this->listed($jobTemplates)[$jobTemplate] ?? null, 'The job template query names the generator.');
        $this->assertSame($expected, $this->listed($workflows)[$workflow] ?? null, 'The workflow template query names the generator.');
        foreach ([$jobTemplates, $workflows] as $sql) {
            $this->assertStringNotContainsString('DESC LIMIT 1', $sql, 'No ORDER BY ... DESC LIMIT 1 for the newest token entry.');
        }
        $this->assertMentions(
            'The query finds the newest entry with `MAX(h.id)` because MariaDB can answer the earlier form of these notes, `ORDER BY g.id DESC LIMIT 1`, with an empty value although the entry exists, which made such a token look like one to revoke.',
            $this->flat('docs/updating.md', self::TRIGGER_SECTION),
            'The 2.8.0 notes'
        );
    }

    /**
     * token_generated_by is the user of the newest generated entry unless a
     * revoked entry follows it; the queries list only live templates whose
     * token predates the update (no trigger_token_created_by), each once.
     */
    public function testTheTriggerTokenQueriesReadTheNewestTokenEntry(): void
    {
        $creator = (int)$this->createUser('docs-token-history-creator')->id;
        $other = (int)$this->createUser('docs-token-history-other')->id;
        $generated = $this->tokenJobTemplate($creator);
        $revoked = $this->tokenJobTemplate($creator);
        $regenerated = $this->tokenJobTemplate($creator);
        $noEntry = $this->tokenJobTemplate($creator);
        $recorded = $this->tokenJobTemplate($creator, $other);
        $deleted = $this->tokenJobTemplate($creator);
        \Yii::$app->db->createCommand()->update('{{%job_template}}', ['deleted_at' => time()], ['id' => $deleted])->execute();
        $workflow = $this->tokenWorkflowTemplate($creator);
        $generatedAction = AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED;
        $revokedAction = AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED;
        $this->audit([
            [$creator, $generatedAction, 'job_template', $generated],
            [$creator, $generatedAction, 'job_template', $revoked],
            [$other, $revokedAction, 'job_template', $revoked],
            [$other, $generatedAction, 'job_template', $regenerated],
            [$other, $revokedAction, 'job_template', $regenerated],
            [$creator, $generatedAction, 'job_template', $regenerated],
            [$other, AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_GENERATED, 'workflow_template', $workflow],
            [$creator, AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_REVOKED, 'workflow_template', $workflow],
            // A job template's entry for the workflow's ID: another object type.
            [$creator, $generatedAction, 'job_template', $workflow],
        ]);

        [$jobTemplates, $workflows] = $this->triggerTokenQueries();
        $listed = $this->listed($jobTemplates);

        $this->assertSame($creator, $listed[$generated]['token_generated_by'] ?? null, 'Generated by the creator.');
        $this->assertArrayHasKey($revoked, $listed);
        $this->assertNull($listed[$revoked]['token_generated_by'], 'A revoked entry follows the generated one.');
        $this->assertSame($creator, $listed[$regenerated]['token_generated_by'] ?? null, 'The newest generated entry counts.');
        $this->assertArrayHasKey($noEntry, $listed);
        $this->assertNull($listed[$noEntry]['token_generated_by'], 'No entry: not generated on the template page.');
        $this->assertArrayNotHasKey($recorded, $listed, 'A token that records its user is not listed.');
        $this->assertArrayNotHasKey($deleted, $listed, 'A deleted template is not listed.');
        $this->assertArrayHasKey($workflow, $this->listed($workflows));
        $this->assertNull($this->listed($workflows)[$workflow]['token_generated_by'], 'The workflow token was revoked.');
    }

    /**
     * Regression (wave 10 of step 3): the password answer queries compare
     * with CAST(... AS BINARY) instead of the BINARY operator, which MySQL 8
     * reports as deprecated. They list exactly the jobs and schedules whose
     * extra vars hold a value for the field that the code does not take for
     * the placeholder (SurveySecrets::PLACEHOLDER, compared exactly): a case
     * variant and a trailing space count as answers, the placeholder and
     * its escaped JSON form do not; neither do other keys and extra vars
     * that are no JSON.
     */
    public function testRegressionThePasswordAnswerQueriesCompareWithCast(): void
    {
        $userId = (int)$this->createUser('docs-password-answers')->id;
        $project = (int)$this->createProject($userId)->id;
        $inventory = (int)$this->createInventory($userId)->id;
        $template = $this->createJobTemplate($project, $inventory, (int)$this->createRunnerGroup($userId)->id, $userId);
        $templateId = (int)$template->id;
        $values = [
            'placeholder' => '{"db_password": "$encrypted$"}',
            'escaped placeholder' => '{"db_password": "$encrypted$"}',
            'case variant' => '{"db_password": "$ENCRYPTED$"}',
            'trailing space' => '{"db_password": "$encrypted$ "}',
            'plain answer' => '{"db_password": "example-plain-answer"}',
            'number' => '{"db_password": 4711}',
            'other key' => '{"app_env": "staging"}',
            'no JSON' => '{"db_password": "unterminated',
        ];
        $jobs = [];
        $schedules = [];
        $answers = [];
        foreach ($values as $what => $extraVars) {
            $jobs[$what] = $this->jobWithExtraVars($templateId, $userId, $extraVars);
            $schedules[$what] = $this->scheduleWithExtraVars($templateId, $userId, $extraVars);
            $decoded = json_decode($extraVars, true);
            if (is_array($decoded) && array_key_exists(self::FIELD, $decoded) && $decoded[self::FIELD] !== SurveySecrets::PLACEHOLDER) {
                $answers[] = $what;
            }
        }
        $this->assertSame(['case variant', 'trailing space', 'plain answer', 'number'], $answers, 'What the code takes for an answer.');

        [$jobQuery, $scheduleQuery, $templateQuery] = $this->passwordQueries($templateId);
        foreach ([$jobQuery, $scheduleQuery] as $sql) {
            $this->assertStringContainsString(
                "CAST(JSON_UNQUOTE(JSON_EXTRACT(extra_vars, '$.\"" . self::FIELD . "\"')) AS BINARY) <> CAST('" . SurveySecrets::PLACEHOLDER . "' AS BINARY)",
                $sql
            );
            $this->assertSame(0, preg_match('/(?<!AS )\bBINARY\s+(?:JSON_UNQUOTE|\')/', $sql), 'No BINARY operator: MySQL 8 reports it as deprecated.');
        }

        $this->assertSame($this->idsOf($jobs, $answers), $this->column($jobQuery), 'The jobs with an answer.');
        $this->assertSame($this->idsOf($schedules, $answers), $this->column($scheduleQuery), 'The schedules with an answer.');
        \Yii::$app->db->createCommand()->update('{{%job_template}}', ['extra_vars' => $values['plain answer']], ['id' => $templateId])->execute();
        $this->assertContains($templateId, $this->column($templateQuery), 'The template whose own extra vars hold the field.');
        $this->assertMentions(
            'Keep the `CAST(JSON_UNQUOTE(...) AS BINARY)` form: it compares the answer with the placeholder byte for byte, as Ansilume does, so `$ENCRYPTED$` or the placeholder with a trailing space count as answers',
            $this->flat('docs/updating.md', self::PASSWORD_SECTION),
            'The 2.8.1 notes'
        );
    }

    /**
     * The two trigger token queries of the 2.8.0 notes: job templates, then
     * workflow templates.
     *
     * @return array{0: string, 1: string}
     */
    private function triggerTokenQueries(): array
    {
        $statements = $this->statements($this->sqlBlock(self::TRIGGER_SECTION, self::TRIGGER_QUERIES));
        $this->assertCount(2, $statements, 'Job templates and workflow templates.');

        return [$statements[0], $statements[1]];
    }

    /**
     * The three password answer queries of the 2.8.1 notes (jobs, schedules,
     * job templates), for template #$templateId and FIELD.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function passwordQueries(int $templateId): array
    {
        $block = str_replace(
            ['<template id>', '<field name>'],
            [(string)$templateId, self::FIELD],
            $this->sqlBlock(self::PASSWORD_SECTION, self::PASSWORD_QUERIES)
        );
        $statements = $this->statements($block);
        $this->assertCount(3, $statements, 'Jobs, schedules and job templates.');

        return [$statements[0], $statements[1], $statements[2]];
    }

    /**
     * The ```sql block of $section that starts with the comment $start.
     */
    private function sqlBlock(string $section, string $start): string
    {
        preg_match_all('/^```sql\R(.*?)^```/ms', $this->section('docs/updating.md', $section), $blocks);
        foreach ($blocks[1] as $block) {
            if (str_starts_with($block, $start)) {
                return $block;
            }
        }
        $this->fail("updating.md has no query block that starts with: {$start}");
    }

    /**
     * The statements of $block, without their comments and semicolons.
     *
     * @return list<string>
     */
    private function statements(string $block): array
    {
        $statements = [];
        foreach (preg_split('/;\s*$/m', $block) ?: [] as $statement) {
            $statement = trim((string)preg_replace('/^\s*--.*$/m', '', $statement));
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * The rows of a trigger token query by template ID, with the columns
     * the notes explain as integers (null when empty).
     *
     * @return array<int, array{runs_as: int|null, created_entry_by: int|null, token_generated_by: int|null, edits_by_others: int|null}>
     */
    private function listed(string $sql): array
    {
        $rows = [];
        foreach (\Yii::$app->db->createCommand($sql)->queryAll() as $row) {
            $rows[(int)$row['id']] = [
                'runs_as' => self::intOrNull($row['runs_as']),
                'created_entry_by' => self::intOrNull($row['created_entry_by']),
                'token_generated_by' => self::intOrNull($row['token_generated_by']),
                'edits_by_others' => self::intOrNull($row['edits_by_others']),
            ];
        }

        return $rows;
    }

    /**
     * The IDs query $sql selects, sorted.
     *
     * @return list<int>
     */
    private function column(string $sql): array
    {
        $ids = array_map('intval', \Yii::$app->db->createCommand($sql)->queryColumn());
        sort($ids);

        return $ids;
    }

    /**
     * The IDs of $ids for the cases $cases, sorted.
     *
     * @param array<string, int> $ids
     * @param list<string> $cases
     * @return list<int>
     */
    private function idsOf(array $ids, array $cases): array
    {
        $selected = array_values(array_intersect_key($ids, array_flip($cases)));
        sort($selected);

        return $selected;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }

    /**
     * A job template of $creator with a trigger token; $tokenUser records
     * who generated it (null: before Ansilume recorded that). Its ID.
     */
    private function tokenJobTemplate(int $creator, ?int $tokenUser = null): int
    {
        $template = $this->createJobTemplate(
            (int)$this->createProject($creator)->id,
            (int)$this->createInventory($creator)->id,
            (int)$this->createRunnerGroup($creator)->id,
            $creator
        );
        $template->trigger_token = hash('sha256', uniqid('docs-token-', true));
        $template->trigger_token_created_by = $tokenUser;
        $this->assertTrue($template->save(false));

        return (int)$template->id;
    }

    /**
     * A workflow template of $creator with a trigger token that predates
     * the update. Its ID.
     */
    private function tokenWorkflowTemplate(int $creator): int
    {
        $workflow = $this->createWorkflowTemplate($creator);
        $workflow->trigger_token = hash('sha256', uniqid('docs-workflow-token-', true));
        $workflow->trigger_token_created_by = null;
        $this->assertTrue($workflow->save(false));

        return (int)$workflow->id;
    }

    /**
     * An audit_log of this session only, with the real table's columns and
     * indexes, that hides the real one until tearDown() drops it. A small
     * table like this made MariaDB choose the plan that lost the entry.
     */
    private function shadowAuditLog(): void
    {
        $db = \Yii::$app->db;
        $create = $db->createCommand('SHOW CREATE TABLE audit_log')->queryOne();
        $this->assertIsArray($create);
        $ddl = (string)$create['Create Table'];
        $this->assertStringNotContainsString('FOREIGN KEY', $ddl, 'A temporary table cannot keep foreign keys.');
        $db->createCommand((string)preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl))->execute();
        $this->shadowsAuditLog = true;
    }

    /**
     * Audit entries [user ID, action, object type, object ID], in order.
     *
     * @param list<array{0: int|null, 1: string, 2: string, 3: int}> $entries
     */
    private function audit(array $entries): void
    {
        foreach ($entries as [$userId, $action, $objectType, $objectId]) {
            \Yii::$app->db->createCommand()->insert('audit_log', [
                'user_id' => $userId,
                'action' => $action,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'created_at' => time(),
            ])->execute();
        }
    }

    /**
     * A finished job of template #$templateId whose extra vars are stored
     * as $extraVars. Its ID.
     */
    private function jobWithExtraVars(int $templateId, int $userId, string $extraVars): int
    {
        $jobId = (int)$this->createJob($templateId, $userId, Job::STATUS_SUCCEEDED)->id;
        \Yii::$app->db->createCommand()->update('{{%job}}', ['extra_vars' => $extraVars], ['id' => $jobId])->execute();

        return $jobId;
    }

    /**
     * A disabled schedule of template #$templateId whose extra vars are
     * stored as $extraVars. Its ID.
     */
    private function scheduleWithExtraVars(int $templateId, int $userId, string $extraVars): int
    {
        $db = \Yii::$app->db;
        $db->createCommand()->insert('{{%schedule}}', [
            'name' => 'docs-password-answers-' . uniqid('', true),
            'job_template_id' => $templateId,
            'cron_expression' => '0 2 * * *',
            'timezone' => 'UTC',
            'extra_vars' => $extraVars,
            'enabled' => 0,
            'created_by' => $userId,
            'created_at' => time(),
            'updated_at' => time(),
        ])->execute();

        return (int)$db->getLastInsertID();
    }
}
