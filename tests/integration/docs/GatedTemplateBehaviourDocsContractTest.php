<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\JobTemplateCredential;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\CredentialWriteService;
use app\services\GatedJobTemplateLock;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;
use app\tests\unit\docs\ReadsOperatorDocs;

/**
 * What docs/updating.md says about job templates with an approval rule
 * (gated job templates), and about the jobs of the deleted job templates
 * that deleting a project removes, holds on the schema and the RBAC of this
 * release: the fields only admins change are the template's columns, the
 * operators hold the rights whose limit the docs describe, and the
 * read-only queries list what their text says. The queries run in the
 * test's transaction, on fixtures of the cases they are about.
 *
 * Regressions from wave 8 of step 3 (decision 5, and the purge of a
 * project's deleted templates), each test fails on the docs as they were
 * before it: no doc named the fields of the lock, nor who may change what a
 * gated template uses; no query listed the job steps that workflows added
 * for a template before it got its rule, nor the changes to gated templates
 * and to their inventories, projects and credentials; and the seventh query
 * missed the waiting jobs whose job template a project's delete removed.
 *
 * Regressions from wave 9 of step 3, each fails on the docs as they were
 * before it: the docs did not say who may delete a gated template, nor that
 * a forced credential delete, by default an admin's, detaches the
 * credential from it and is audited without naming it.
 */
class GatedTemplateBehaviourDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;
    use UpdateSectionQueries;
    use TeamScopeFixtures;
    use CancellationFixtures;

    private const UPDATE_SECTION = '## After updating from 2.9.0 or older: approvals and workflows';

    private const LOCK = '**Only admins change what a gated job template runs.**';

    private const LIMITS = '**What an approval rule does not stop.**';

    /** The queries of the section, by their place. */
    private const DELETED_TEMPLATES = 7;
    private const GATED_JOB_STEPS = 13;
    private const CHANGES_OF_GATED_TEMPLATES = 14;

    /**
     * The columns of job_template that no user sets as a field of the
     * template: bookkeeping, the trigger token (managed on its own), the
     * lint result, and the approval rule (ApprovalRuleAssignmentPolicy).
     */
    private const NOT_FIELDS = [
        'id', 'approval_rule_id', 'trigger_token', 'trigger_token_created_by',
        'lint_output', 'lint_at', 'lint_exit_code', 'created_by', 'created_at', 'updated_at', 'deleted_at',
    ];

    /** The fields only admins change on a gated job template, in the words of the docs. */
    private const FIELD_WORDS = [
        'project_id' => 'project',
        'inventory_id' => 'inventory',
        'playbook' => 'playbook',
        'credential_id' => 'credentials and their order',
        'extra_vars' => 'extra vars',
        'survey_fields' => 'survey',
        'runner_group_id' => 'runner group',
        'timeout_minutes' => 'timeout',
        'limit' => 'Ansible settings',
        'tags' => 'Ansible settings',
        'skip_tags' => 'Ansible settings',
        'verbosity' => 'Ansible settings',
        'forks' => 'Ansible settings',
        'become' => 'Ansible settings',
        'become_method' => 'Ansible settings',
        'become_user' => 'Ansible settings',
    ];

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    /**
     * Every column of job_template but the name and the description is
     * locked on a gated template (GatedJobTemplateLock), so the docs name
     * every one of them; a new column fails here until they name it.
     */
    public function testTheDocsNameEveryFieldOnlyAdminsChange(): void
    {
        $columns = array_keys(JobTemplate::getTableSchema()->columns);
        $locked = array_values(array_diff($columns, GatedJobTemplateLock::OPEN_ATTRIBUTES, self::NOT_FIELDS));
        $named = array_keys(self::FIELD_WORDS);
        sort($locked);
        sort($named);
        $this->assertSame($named, $locked, 'The docs name every field of a job template that only admins change.');

        $lists = [
            'updating.md' => $this->between($this->paragraphs(self::LOCK), 'every field but its name and description, that is ', '. Everyone else'),
            'troubleshooting.md' => $this->between(
                $this->flat('docs/troubleshooting.md', '## Saving a job template is refused: it has an approval rule'),
                'Every field but the name and the description counts: ',
                '. A field sent'
            ),
        ];
        foreach ($lists as $where => $list) {
            foreach (array_unique(self::FIELD_WORDS) as $word) {
                $this->assertMentions($word, $list, $where);
            }
        }
    }

    /**
     * Operators hold the rights to change the inventory, the project and the
     * credentials of a gated job template, but not to change the template
     * itself; the docs say who holds what.
     */
    public function testOperatorsHoldTheRightsWhoseLimitTheDocsDescribe(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $operator = array_keys($auth->getPermissionsByRole('operator'));
        foreach (['inventory.update', 'project.update', 'credential.update', 'job-template.update'] as $permission) {
            $this->assertContains($permission, $operator, "Operators hold {$permission}.");
        }
        $this->assertNotContains('approval-rule.update', $operator, 'Operators do not change gated job templates.');
        $this->assertContains('approval-rule.update', array_keys($auth->getPermissionsByRole('admin')));
        $this->assertMentions(
            'By default every operator holds them: `inventory.update` and `project.update` for the projects they may operate and their inventories',
            $this->paragraphs(self::LIMITS),
            'updating.md'
        );
    }

    /**
     * Regression (wave 9 of step 3): the docs said that everyone the lock
     * stops still deletes a gated job template, and did not say who may
     * detach a credential from one by deleting it. By default both deletes
     * are for admins: operators hold neither permission.
     */
    public function testTheDefaultRolesLeaveBothDeletesToAdmins(): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $operator = array_keys($auth->getPermissionsByRole('operator'));
        $admin = array_keys($auth->getPermissionsByRole('admin'));
        foreach (['job-template.delete', 'credential.delete'] as $permission) {
            $this->assertNotContains($permission, $operator, "Operators do not hold {$permission}.");
            $this->assertContains($permission, $admin, "Admins hold {$permission}.");
        }
        $this->assertMentions('and delete it where they hold `job-template.delete`, which the operator role does not grant', $this->paragraphs(self::LOCK), 'updating.md');
        $this->assertMentions('that needs `credential.delete`, which only admins hold by default', $this->paragraphs(self::LIMITS), 'updating.md');
    }

    /**
     * Regression (wave 9 of step 3): a forced delete of a credential
     * detaches it from a gated job template, as its primary credential and
     * from its credential list, without the lock; its audit entry counts
     * the templates but does not name them, as the docs say.
     */
    public function testAForcedCredentialDeleteDetachesItFromAGatedTemplate(): void
    {
        $admin = $this->createUserWithRole('docs-forced-credential', 'admin');
        $adminId = (int)$admin->id;
        $template = $this->cancelFixtureTemplate($adminId);
        $credential = $this->createCredential($adminId);
        $template->credential_id = (int)$credential->id;
        $template->approval_rule_id = (int)$this->usersRule($adminId, $adminId)->id;
        $this->assertTrue($template->save(false));
        $link = new JobTemplateCredential();
        $link->job_template_id = (int)$template->id;
        $link->credential_id = (int)$credential->id;
        $link->sort_order = 0;
        $this->assertTrue($link->save(false));
        $operatorId = (int)$this->createUserWithRole('docs-forced-credential-op', 'operator')->id;
        $this->assertTrue((new GatedJobTemplateLock())->appliesTo($operatorId, $template), 'The template is gated: operators may not change it.');

        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->delete($credential, true, $adminId)['deleted']);

        $template->refresh();
        $this->assertNull($template->credential_id, 'The credential is detached from the gated template.');
        $this->assertSame([], JobTemplateCredential::findAll(['job_template_id' => $template->id]), 'and from its credential list.');
        $context = $this->contextOf($this->onlyAuditOf(AuditLog::ACTION_CREDENTIAL_DELETED, 'credential', (int)$credential->id));
        $this->assertIsArray($context);
        $this->assertTrue($context['forced'] ?? null, 'The entry records forced.');
        $this->assertSame(1, $context['job_templates'] ?? null, 'and counts the job templates.');
        $this->assertNotContains((int)$template->id, array_values($context), 'It does not name them.');
        $this->assertMentions(
            'its `' . AuditLog::ACTION_CREDENTIAL_DELETED . '` audit entry records `forced` and the number of job templates it was detached from (`job_templates`), not which',
            $this->paragraphs(self::LIMITS),
            'updating.md'
        );
    }

    /**
     * Regression (decision 5, H2's limit): the thirteenth query lists the
     * job steps of live workflows whose live job template has an approval
     * rule, with the user the workflow's trigger runs as and the users who
     * created the workflow or changed its steps; not the steps of templates
     * without a rule, of deleted templates or of deleted workflows.
     */
    public function testTheThirteenthQueryListsTheJobStepsOfGatedJobTemplates(): void
    {
        $this->assertStringStartsWith('-- Job steps of live workflows whose job template has an approval rule', $this->queries()[self::GATED_JOB_STEPS - 1] ?? '');
        $owner = (int)$this->createUser('docs-gated-steps-owner')->id;
        $editor = (int)$this->createUser('docs-gated-steps-editor')->id;
        $tokenUser = (int)$this->createUser('docs-gated-steps-token')->id;
        $rule = (int)$this->usersRule($owner, $owner, $editor)->id;
        $gated = (int)$this->gatedTemplate($owner, $rule)->id;
        $deletedGated = $this->gatedTemplate($owner, $rule);
        $open = (int)$this->cancelFixtureTemplate($owner)->id;

        $triggered = $this->workflowWithToken($owner, $tokenUser);
        $listed = (int)$this->createWorkflowStep((int)$triggered->id, 1, WorkflowStep::TYPE_JOB, $gated)->id;
        $notListed = [
            (int)$this->createWorkflowStep((int)$triggered->id, 2, WorkflowStep::TYPE_JOB, $open)->id,
            (int)$this->createWorkflowStep((int)$triggered->id, 3, WorkflowStep::TYPE_APPROVAL, null, $rule)->id,
            (int)$this->createWorkflowStep((int)$triggered->id, 4, WorkflowStep::TYPE_JOB, (int)$deletedGated->id)->id,
        ];
        $this->assertTrue($deletedGated->softDelete());
        $this->auditWorkflow($triggered, AuditLog::ACTION_WORKFLOW_TEMPLATE_STEP_ADDED, $editor);
        $this->auditWorkflow($triggered, AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED, $owner);
        $this->auditWorkflow($triggered, AuditLog::ACTION_WORKFLOW_TEMPLATE_TRIGGER_TOKEN_GENERATED, $tokenUser);
        $older = $this->workflowWithToken($owner, null);
        $olderStep = (int)$this->createWorkflowStep((int)$older->id, 1, WorkflowStep::TYPE_JOB, $gated)->id;
        $plainStep = (int)$this->createWorkflowStep((int)$this->createWorkflowTemplate($owner)->id, 1, WorkflowStep::TYPE_JOB, $gated)->id;
        $deletedWorkflow = $this->createWorkflowTemplate($owner);
        $notListed[] = (int)$this->createWorkflowStep((int)$deletedWorkflow->id, 1, WorkflowStep::TYPE_JOB, $gated)->id;
        $this->assertTrue($deletedWorkflow->softDelete());

        $rows = $this->rowsByColumn(self::GATED_JOB_STEPS, 'step_id', [$listed, $olderStep, $plainStep, ...$notListed]);

        $this->assertSame([$listed, $olderStep, $plainStep], array_keys($rows));
        $this->assertSame($tokenUser, (int)$rows[$listed]['trigger_runs_as']);
        $this->assertSame(implode(',', [min($owner, $editor), max($owner, $editor)]), (string)$rows[$listed]['changed_by']);
        $this->assertSame($older->getTriggerUserId(), (int)$rows[$olderStep]['trigger_runs_as'], 'An older token runs as the creator, as the code says.');
        $this->assertNull($rows[$plainStep]['trigger_runs_as'], 'No token, no trigger.');
        $this->assertNull($rows[$plainStep]['changed_by']);
        $this->assertSame($rule, (int)$rows[$listed]['approval_rule_id']);
    }

    /**
     * Regression (decision 5, the limit): the fourteenth query lists, newest
     * first, the changes of the last 30 days to gated job templates and to
     * the project, the inventory and the credentials (primary and
     * additional) they use; not the changes of what only templates without
     * a rule use, other actions, or older changes.
     */
    public function testTheFourteenthQueryListsTheChangesToGatedTemplatesAndWhatTheyUse(): void
    {
        $this->assertStringStartsWith('-- Changes of the last 30 days to gated job templates and to the', $this->queries()[self::CHANGES_OF_GATED_TEMPLATES - 1] ?? '');
        $user = (int)$this->createUser('docs-gated-changes')->id;
        $other = (int)$this->createUser('docs-gated-changes-other')->id;
        $primary = (int)$this->createCredential($user)->id;
        $additional = (int)$this->createCredential($user)->id;
        $unrelated = (int)$this->createCredential($user)->id;
        $gated = $this->gatedTemplate($user, (int)$this->usersRule($user, $user)->id);
        $gated->credential_id = $primary;
        $this->assertTrue($gated->save(false));
        \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
            'job_template_id' => (int)$gated->id,
            'credential_id' => $additional,
            'sort_order' => 0,
        ])->execute();
        $open = $this->cancelFixtureTemplate($user);
        $open->credential_id = $unrelated;
        $this->assertTrue($open->save(false));

        $listed = [
            $this->audit(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$gated->id, $other),
            $this->audit(AuditLog::ACTION_PROJECT_UPDATED, 'project', (int)$gated->project_id, $other),
            $this->audit(AuditLog::ACTION_INVENTORY_UPDATED, 'inventory', (int)$gated->inventory_id, $other),
            $this->audit(AuditLog::ACTION_CREDENTIAL_UPDATED, 'credential', $primary, $other),
            $this->audit(AuditLog::ACTION_CREDENTIAL_UPDATED, 'credential', $additional, $user),
        ];
        $notListed = [
            $this->audit(AuditLog::ACTION_CREDENTIAL_UPDATED, 'credential', $unrelated, $other),
            $this->audit(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$open->id, $other),
            $this->audit(AuditLog::ACTION_INVENTORY_UPDATED, 'inventory', (int)$open->inventory_id, $other),
            $this->audit(AuditLog::ACTION_PROJECT_SYNCED, 'project', (int)$gated->project_id, $other),
            $this->audit(AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED, 'job_template', (int)$gated->id, $other),
            $this->audit(AuditLog::ACTION_PROJECT_UPDATED, 'project', (int)$gated->project_id, $other, 31 * 86400),
        ];

        $rows = $this->rowsByColumn(self::CHANGES_OF_GATED_TEMPLATES, 'id', [...$listed, ...$notListed]);

        $this->assertSame(array_reverse($listed), array_keys($rows), 'Newest first, and only these.');
        foreach ($rows as $row) {
            $this->assertSame((int)$gated->id, (int)$row['job_template_id']);
        }
        $this->assertSame($other, (int)$rows[$listed[0]]['user_id']);
        $this->assertSame(AuditLog::ACTION_CREDENTIAL_UPDATED, $rows[$listed[4]]['action']);
        $this->assertSame($additional, (int)$rows[$listed[4]]['object_id']);
    }

    /**
     * Regression (wave 8 of step 3, the purge of a project's deleted job
     * templates): the seventh query lists the waiting jobs whose job
     * template is gone, which the maintenance run cancels, next to the
     * waiting jobs of deleted templates; not the job of a workflow approval
     * step, which has no job template by design, a finished job, or a job
     * of a live template.
     */
    public function testTheSeventhQueryListsTheWaitingJobsThatLostTheirTemplate(): void
    {
        $this->assertStringStartsWith('-- Enabled schedules and waiting jobs of deleted job templates', $this->queries()[self::DELETED_TEMPLATES - 1] ?? '');
        $this->recordNotifications();
        ['placeholder' => $placeholder, 'launcher' => $launcher] = $this->runAtApprovalStep();
        $this->assertNull($placeholder->job_template_id, 'The job of an approval step has no job template.');
        $userId = (int)$launcher->id;
        $deleted = $this->cancelFixtureTemplate($userId);
        $ofDeleted = (int)$this->createJob((int)$deleted->id, $userId)->id;
        $this->assertTrue($deleted->softDelete());
        $live = (int)$this->cancelFixtureTemplate($userId)->id;
        $queued = $this->jobWithoutTemplate($live, $userId, Job::STATUS_QUEUED);
        $waitingForApproval = $this->jobWithoutTemplate($live, $userId, Job::STATUS_PENDING_APPROVAL);
        $ids = [
            $ofDeleted,
            $queued,
            $waitingForApproval,
            (int)$placeholder->id,
            $this->jobWithoutTemplate($live, $userId, Job::STATUS_FAILED),
            (int)$this->createJob($live, $userId)->id,
        ];

        $listed = [];
        foreach ($this->rowsOf(self::DELETED_TEMPLATES) as $row) {
            if ($row['kind'] !== 'schedule' && in_array((int)$row['id'], $ids, true)) {
                $listed[(int)$row['id']] = $row['kind'];
            }
        }

        $this->assertSame([$ofDeleted => 'job', $queued => 'job without template', $waitingForApproval => 'job without template'], $listed);
    }

    /**
     * A job of template #$templateId in $status that has lost its template,
     * as the purge of a deleted project's templates leaves it; its ID.
     */
    private function jobWithoutTemplate(int $templateId, int $userId, string $status): int
    {
        $job = $this->createJob($templateId, $userId, $status);
        $job->job_template_id = null;
        $this->assertTrue($job->save(false));

        return (int)$job->id;
    }

    /**
     * A job template of its own project, inventory and runner group, gated
     * by rule #$ruleId.
     */
    private function gatedTemplate(int $userId, int $ruleId): JobTemplate
    {
        $template = $this->cancelFixtureTemplate($userId);
        $template->approval_rule_id = $ruleId;
        $this->assertTrue($template->save(false));

        return $template;
    }

    /**
     * A workflow of $ownerId with a trigger token generated by $tokenUserId
     * (null: before Ansilume recorded who generated a token).
     */
    private function workflowWithToken(int $ownerId, ?int $tokenUserId): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate($ownerId);
        $workflow->trigger_token = hash('sha256', uniqid('docs-gated-token-', true));
        $workflow->trigger_token_created_by = $tokenUserId;
        $this->assertTrue($workflow->save(false));

        return $workflow;
    }

    private function auditWorkflow(WorkflowTemplate $workflow, string $action, int $userId): void
    {
        $this->audit($action, 'workflow_template', (int)$workflow->id, $userId);
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

    /**
     * The units of the section from the one that starts with $title up to
     * the next bold title, on one line.
     */
    private function paragraphs(string $title): string
    {
        $text = '';
        foreach ($this->units('docs/updating.md', self::UPDATE_SECTION) as $unit) {
            if ($text === '' && !str_starts_with($unit, $title)) {
                continue;
            }
            if ($text !== '' && preg_match('/^\*\*[^*]+[.:]\*\*/', $unit) === 1) {
                break;
            }
            $text .= ' ' . $unit;
        }
        $this->assertNotSame('', $text, "updating.md has no paragraph {$title}");

        return trim($text);
    }
}
