<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\ApprovalRuleUsage;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowStep;
use app\services\ApprovalRuleUsageService;
use app\services\ApprovalRuleWriteService;
use app\tests\integration\DbTestCase;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\BaseActiveRecord;
use yii\db\Exception as DbException;

/**
 * Creating, updating and deleting approval rules: created_by from the
 * caller, audit entries with the source and the settings, and no delete of
 * a rule in use, also when the delete loses a lock conflict to a save that
 * assigns the rule.
 */
class ApprovalRuleWriteServiceTest extends DbTestCase
{
    private ApprovalRuleWriteService $service;
    private int $userId;

    /** The handler failRuleDeletes() installed; removed in tearDown(). */
    private ?\Closure $deleteFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApprovalRuleWriteService();
        $user = $this->createUser('rule-writer');
        $user->is_superadmin = true;
        $user->save(false);
        $this->userId = (int)$user->id;
    }

    /** Some tests swap in a usage service that misses a use, or fail deletes; undo both. */
    protected function tearDown(): void
    {
        \Yii::$app->set('approvalRuleUsageService', ['class' => ApprovalRuleUsageService::class]);
        if ($this->deleteFailure !== null) {
            Event::off(ApprovalRule::class, BaseActiveRecord::EVENT_BEFORE_DELETE, $this->deleteFailure);
            $this->deleteFailure = null;
        }
        parent::tearDown();
    }

    // -- Create / update ----------------------------------------------------------

    public function testCreateSetsTheCreatorAndAuditsTheSettings(): void
    {
        $rule = $this->newRule();
        $rule->created_by = 999999;

        $this->assertTrue($this->service->create($rule, $this->userId, ApprovalRuleWriteService::SOURCE_API));

        $rule->refresh();
        $this->assertSame($this->userId, (int)$rule->created_by, 'the caller, not a submitted value');
        $this->assertSame([
            'name' => $rule->name,
            'approver_type' => ApprovalRule::APPROVER_TYPE_USERS,
            'approver_config' => ['user_ids' => [$this->userId]],
            'required_approvals' => 1,
            'timeout_minutes' => 30,
            'timeout_action' => ApprovalRule::TIMEOUT_ACTION_REJECT,
            'source' => 'api',
        ], $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_CREATED, (int)$rule->id));
    }

    public function testAnInvalidRuleIsNeitherCreatedNorAudited(): void
    {
        $rule = $this->newRule();
        $rule->name = '';
        $before = ApprovalRule::find()->count();

        $this->assertFalse($this->service->create($rule, $this->userId, ApprovalRuleWriteService::SOURCE_WEB));

        $this->assertTrue($rule->hasErrors('name'));
        $this->assertSame($before, ApprovalRule::find()->count());
        $this->assertSame(0, (int)AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_RULE_CREATED, 'user_id' => $this->userId])->count());
    }

    public function testUpdateAuditsEachChangedFieldBeforeAndAfter(): void
    {
        $rule = $this->storedRule();
        $other = $this->createUser('rule-writer-other');
        // As a form posts them: text, and the same config with other spacing.
        $rule->setAttributes([
            'required_approvals' => '2',
            'timeout_minutes' => '',
            'approver_config' => (string)json_encode(['user_ids' => [$this->userId, (int)$other->id]]),
            'description' => 'now described',
            'timeout_action' => ApprovalRule::TIMEOUT_ACTION_REJECT,
        ]);

        $this->assertTrue($this->service->update($rule, ApprovalRuleWriteService::SOURCE_WEB));

        $audit = $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_UPDATED, (int)$rule->id);
        $this->assertSame('web', $audit['source']);
        $this->assertSame($rule->name, $audit['name']);
        $this->assertSame([
            'description' => ['from' => null, 'to' => 'now described'],
            'approver_config' => ['from' => ['user_ids' => [$this->userId]], 'to' => ['user_ids' => [$this->userId, (int)$other->id]]],
            'required_approvals' => ['from' => 1, 'to' => 2],
            'timeout_minutes' => ['from' => 30, 'to' => null],
        ], $audit['changes']);
        $rule->refresh();
        $this->assertSame(2, (int)$rule->required_approvals);
        $this->assertNull($rule->timeout_minutes);
    }

    public function testValuesThatOnlyLookDifferentAreNoChange(): void
    {
        $rule = $this->storedRule();
        $rule->setAttributes([
            'required_approvals' => '1',
            'timeout_minutes' => '30',
            'approver_config' => '{ "user_ids" : [' . $this->userId . '] }',
        ]);

        $this->assertTrue($this->service->update($rule, ApprovalRuleWriteService::SOURCE_API));

        $this->assertSame([], $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_UPDATED, (int)$rule->id)['changes']);
    }

    /**
     * A config that is JSON but no object (stored by an older version,
     * which took any JSON) is recorded as it is stored when an update
     * replaces it: the rule had no approvers, which the audit must show
     * plainly.
     */
    public function testAConfigThatIsNoObjectIsAuditedAsStored(): void
    {
        $rule = $this->storedRule();
        ApprovalRule::updateAll(['approver_type' => ApprovalRule::APPROVER_TYPE_ROLE, 'approver_config' => '7'], ['id' => $rule->id]);
        $rule->refresh();
        $rule->approver_config = '{"role": "operator"}';

        $this->assertTrue($this->service->update($rule, ApprovalRuleWriteService::SOURCE_API));

        $this->assertSame(
            ['approver_config' => ['from' => '7', 'to' => ['role' => 'operator']]],
            $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_UPDATED, (int)$rule->id)['changes']
        );
    }

    /**
     * Regression: an approver config that is JSON but no object (a list, a
     * number) was saved; the rule then named no approvers, and the API
     * returned the config as a JSON list although it documents an object.
     */
    public function testAnUpdateToAConfigThatIsNoObjectIsRefusedAndNotAudited(): void
    {
        $rule = $this->storedRule();
        // A role rule: a users rule without user IDs fails its approver count.
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_ROLE;
        $rule->approver_config = '7';

        $this->assertFalse($this->service->update($rule, ApprovalRuleWriteService::SOURCE_API));

        $this->assertSame('Approver Config must be a JSON object.', $rule->getFirstError('approver_config'));
        $rule->refresh();
        $this->assertSame(ApprovalRule::APPROVER_TYPE_USERS, $rule->approver_type);
        $this->assertNull(AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_RULE_UPDATED, 'object_id' => $rule->id])->one());
    }

    public function testAnInvalidUpdateIsNeitherSavedNorAudited(): void
    {
        $rule = $this->storedRule();
        $rule->required_approvals = 0;

        $this->assertFalse($this->service->update($rule, ApprovalRuleWriteService::SOURCE_API));

        $this->assertTrue($rule->hasErrors('required_approvals'));
        $rule->refresh();
        $this->assertSame(1, (int)$rule->required_approvals);
        $this->assertNull(AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_RULE_UPDATED, 'object_id' => $rule->id])->one());
    }

    // -- Delete -----------------------------------------------------------------

    public function testAnUnusedRuleIsDeletedAndAudited(): void
    {
        $rule = $this->storedRule();

        $result = $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_API);

        $this->assertTrue($result['deleted']);
        $this->assertFalse($result['usage']->isInUse());
        $this->assertNull(ApprovalRule::findOne($rule->id));
        $this->assertSame([
            'name' => $rule->name,
            'approver_type' => ApprovalRule::APPROVER_TYPE_USERS,
            'approver_config' => ['user_ids' => [$this->userId]],
            'required_approvals' => 1,
            'timeout_minutes' => 30,
            'timeout_action' => ApprovalRule::TIMEOUT_ACTION_REJECT,
            'detached_job_templates' => 0,
            'detached_workflow_steps' => 0,
            'source' => 'api',
        ], $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_DELETED, (int)$rule->id));
    }

    /**
     * Regression: deleting a rule silently ungated its templates.
     */
    public function testARuleThatGatesALiveJobTemplateIsNotDeleted(): void
    {
        $rule = $this->storedRule();
        $template = $this->templateOf();
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);

        $result = $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_WEB);

        $this->assertFalse($result['deleted']);
        $this->assertSame(1, $result['usage']->jobTemplateTotal);
        $this->assertNotNull(ApprovalRule::findOne($rule->id));
        $template->refresh();
        $this->assertSame((int)$rule->id, (int)$template->approval_rule_id, 'the template stays gated');
        $this->assertNull(AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_RULE_DELETED, 'object_id' => $rule->id])->one());
    }

    public function testARuleOfALiveWorkflowStepIsNotDeleted(): void
    {
        $rule = $this->storedRule();
        $workflow = $this->createWorkflowTemplate($this->userId);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);

        $result = $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_WEB);

        $this->assertFalse($result['deleted']);
        $this->assertSame(1, $result['usage']->workflowStepTotal);
        $this->assertNotNull(ApprovalRule::findOne($rule->id));
    }

    /**
     * Regression: deleting a rule deleted its pending requests (and every
     * other request with its decisions); their jobs waited for a decision
     * that could never come.
     */
    public function testARuleWithAPendingRequestIsNotDeleted(): void
    {
        $rule = $this->storedRule();
        $request = $this->requestFor($rule, Job::STATUS_PENDING_APPROVAL, ApprovalRequest::STATUS_PENDING);

        $result = $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_WEB);

        $this->assertFalse($result['deleted']);
        $this->assertSame(1, $result['usage']->pendingRequestCount);
        $request->refresh();
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $request->status);
        $this->assertSame((int)$rule->id, (int)$request->approval_rule_id);
    }

    /**
     * Soft-deleted templates and the steps of soft-deleted workflows cannot
     * run and no restore exists: they lose the rule instead of keeping it
     * forever. Resolved requests keep their history without the rule.
     */
    public function testWhatCannotRunAnyMoreIsDetachedAndHistoryKept(): void
    {
        $rule = $this->storedRule();
        $template = $this->templateOf();
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
        $template->softDelete();
        $workflow = $this->createWorkflowTemplate($this->userId);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $workflow->softDelete();
        $resolved = $this->requestFor($rule, Job::STATUS_SUCCEEDED, ApprovalRequest::STATUS_APPROVED);
        $decision = $this->decisionOn($resolved);
        // Left pending when its job was canceled, before cancels closed requests.
        $orphan = $this->requestFor($rule, Job::STATUS_CANCELED, ApprovalRequest::STATUS_PENDING);

        $result = $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_WEB);

        $this->assertTrue($result['deleted']);
        $this->assertNull(ApprovalRule::findOne($rule->id));
        $this->assertNull($this->ruleIdOfTemplate((int)$template->id));
        $step->refresh();
        $this->assertNull($step->approval_rule_id);
        foreach ([$resolved, $orphan] as $request) {
            $request->refresh();
            $this->assertNull($request->approval_rule_id, 'the request stays, without its rule');
        }
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $resolved->status);
        $this->assertTrue(ApprovalDecision::find()->where(['id' => $decision->id])->exists());
        $audit = $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_DELETED, (int)$rule->id);
        $this->assertSame(1, $audit['detached_job_templates']);
        $this->assertSame(1, $audit['detached_workflow_steps']);
        $this->assertSame('web', $audit['source']);
    }

    /**
     * A use the check missed, such as an assignment between the check and
     * the delete: the RESTRICT foreign key refuses the delete, and the
     * answer is the same refusal with the usage as it is now.
     */
    public function testAUseTheCheckMissedEndsInTheSameRefusal(): void
    {
        $rule = $this->storedRule();
        $template = $this->templateOf();
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
        $this->missTheFirstUse();

        $result = $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_API);

        $this->assertFalse($result['deleted']);
        $this->assertSame(1, $result['usage']->jobTemplateTotal, 'the usage as it is after the refusal');
        $this->assertNotNull(ApprovalRule::findOne($rule->id));
        $template->refresh();
        $this->assertSame((int)$rule->id, (int)$template->approval_rule_id);
        $this->assertNull(AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_RULE_DELETED, 'object_id' => $rule->id])->one());
    }

    /**
     * A rule another request deleted meanwhile: deleted, and that request
     * wrote the audit entry.
     */
    public function testARuleDeletedMeanwhileCountsAsDeleted(): void
    {
        $rule = $this->storedRule();
        ApprovalRule::deleteAll(['id' => $rule->id]);

        $result = $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_WEB);

        $this->assertTrue($result['deleted']);
        $this->assertSame((string)$rule->name, $result['usage']->ruleName);
        $this->assertFalse($result['usage']->isInUse());
        $this->assertNull(AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_RULE_DELETED, 'object_id' => $rule->id])->one());
    }

    public function testARefusedDeleteChangesNothing(): void
    {
        $rule = $this->storedRule();
        $template = $this->templateOf();
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
        $template->softDelete();
        $refuse = static function (ModelEvent $event): void {
            $event->isValid = false;
        };
        Event::on(ApprovalRule::class, BaseActiveRecord::EVENT_BEFORE_DELETE, $refuse);
        try {
            $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_WEB);
            $this->fail('expected the refused delete to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame("Deleting approval rule #{$rule->id} was refused.", $e->getMessage());
        } finally {
            Event::off(ApprovalRule::class, BaseActiveRecord::EVENT_BEFORE_DELETE, $refuse);
        }

        $this->assertNotNull(ApprovalRule::findOne($rule->id));
        $this->assertSame((int)$rule->id, $this->ruleIdOfTemplate((int)$template->id), 'the detach is rolled back with the delete');
    }

    // -- Delete: lock conflicts -----------------------------------------------------

    /**
     * Regression: a delete that ran into a save assigning the same rule
     * ended in an InnoDB deadlock (error 1213, a server error for the user)
     * instead of the in-use refusal. Its check ran before the save's
     * assignment was visible; its DELETE then lost the deadlock to the save,
     * which committed. The second attempt sees the assignment and refuses.
     */
    public function testADeleteThatLosesADeadlockToAnAssignmentEndsInTheRefusal(): void
    {
        $rule = $this->storedRule();
        $template = $this->templateOf();
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
        $this->missTheFirstUse();
        $deletes = $this->failRuleDeletes(self::deadlock(), 1);

        $result = $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_API);

        $this->assertFalse($result['deleted']);
        $this->assertSame(1, $result['usage']->jobTemplateTotal, 'the usage the second attempt found');
        $this->assertSame(
            'Approval rule ' . json_encode($rule->name) . ' is in use by 1 job template(s). '
            . 'Remove it from them, and let pending requests be decided or their jobs canceled, before deleting it.',
            ApprovalRuleWriteService::refusal($result['usage'])
        );
        $this->assertSame(1, $deletes->count, 'the second attempt refused before it deleted');
        $this->assertNotNull(ApprovalRule::findOne($rule->id));
        $template->refresh();
        $this->assertSame((int)$rule->id, (int)$template->approval_rule_id, 'the template stays gated');
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_RULE_DELETED, 'object_id' => $rule->id])->exists());
    }

    /**
     * A lock wait timeout is retried as well. When the other transaction
     * did not end up using the rule, the second attempt deletes it, and
     * the delete is audited once.
     */
    public function testALockWaitTimeoutIsRetriedAndTheUnusedRuleDeletedOnce(): void
    {
        $rule = $this->storedRule();
        $template = $this->templateOf();
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
        $template->softDelete();
        $deletes = $this->failRuleDeletes(self::lockWaitTimeout(), 1);

        $result = $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_WEB);

        $this->assertTrue($result['deleted']);
        $this->assertSame(2, $deletes->count);
        $this->assertNull(ApprovalRule::findOne($rule->id));
        $this->assertNull($this->ruleIdOfTemplate((int)$template->id), 'detached in the attempt that deleted');
        $entries = AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_RULE_DELETED, 'object_id' => $rule->id])->all();
        $this->assertCount(1, $entries);
        $this->assertSame(1, $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_DELETED, (int)$rule->id)['detached_job_templates']);
        $this->assertSame('web', $this->auditOf(AuditLog::ACTION_APPROVAL_RULE_DELETED, (int)$rule->id)['source']);
    }

    /**
     * A second lock conflict is not retried again: it is thrown, after
     * its attempt was rolled back.
     */
    public function testASecondLockConflictIsThrown(): void
    {
        $rule = $this->storedRule();
        $template = $this->templateOf();
        $template->approval_rule_id = (int)$rule->id;
        $template->save(false);
        $template->softDelete();
        $deadlock = self::deadlock();
        $deletes = $this->failRuleDeletes($deadlock, 2);

        try {
            $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_API);
            $this->fail('expected the second deadlock to be thrown');
        } catch (DbException $e) {
            $this->assertSame($deadlock, $e);
        }

        $this->assertSame(2, $deletes->count);
        $this->assertNotNull(ApprovalRule::findOne($rule->id));
        $this->assertSame((int)$rule->id, $this->ruleIdOfTemplate((int)$template->id), 'the detach is rolled back');
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_APPROVAL_RULE_DELETED, 'object_id' => $rule->id])->exists());
    }

    /**
     * @return array<string, array{0: array<int, int|string>|null, 1: bool}>
     */
    public static function databaseErrorProvider(): array
    {
        return [
            'deadlock' => [['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'], true],
            'lock wait timeout' => [['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'], true],
            'serialization failure, SQLSTATE only' => [['40001', 0, 'Serialization failure'], true],
            'missing table' => [['42S02', 1146, "Table 'ansilume.approval_rule' doesn't exist"], false],
            'server gone' => [['HY000', 2006, 'MySQL server has gone away'], false],
            // A converted exception that did not come from PDO carries none.
            'no error info' => [null, false],
        ];
    }

    /**
     * Only a lock conflict is retried; any other database error is thrown
     * after the first attempt.
     *
     * @dataProvider databaseErrorProvider
     * @param array<int, int|string>|null $errorInfo
     */
    public function testOnlyALockConflictIsRetried(?array $errorInfo, bool $retried): void
    {
        $rule = $this->storedRule();
        $error = $errorInfo === null
            ? ApprovalRule::getDb()->getSchema()->convertException(new \RuntimeException('refused'), 'DELETE FROM `approval_rule`')
            : new DbException('SQLSTATE: refused', $errorInfo);
        $deletes = $this->failRuleDeletes($error, 1);

        try {
            $result = $this->service->delete($rule, $this->userId, ApprovalRuleWriteService::SOURCE_API);
            $this->assertTrue($retried, 'only a retried error lets the delete finish');
            $this->assertTrue($result['deleted']);
            $this->assertNull(ApprovalRule::findOne($rule->id));
        } catch (DbException $e) {
            $this->assertFalse($retried, 'a lock conflict must be retried, not thrown');
            $this->assertSame($error, $e);
            $this->assertNotNull(ApprovalRule::findOne($rule->id));
        }
        $this->assertSame($retried ? 2 : 1, $deletes->count);
    }

    public function testTheRefusalNamesTheUsageAndWhatToDo(): void
    {
        $usage = new ApprovalRuleUsage('nightly', [], 1, [], 0, 2);

        $this->assertSame(
            'Approval rule "nightly" is in use by 1 job template(s) and 2 pending approval request(s). '
            . 'Remove it from them, and let pending requests be decided or their jobs canceled, before deleting it.',
            ApprovalRuleWriteService::refusal($usage)
        );
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * The usage service misses every use the first time it is asked, as a
     * check that runs before another transaction's assignment is visible.
     */
    private function missTheFirstUse(): void
    {
        \Yii::$app->set('approvalRuleUsageService', new class extends ApprovalRuleUsageService {
            private int $calls = 0;

            public function forRule(ApprovalRule $rule, ?int $viewerId): ApprovalRuleUsage
            {
                if ($this->calls++ === 0) {
                    return new ApprovalRuleUsage((string)$rule->name, [], 0, [], 0, 0);
                }
                return parent::forRule($rule, $viewerId);
            }
        });
    }

    /**
     * From now on the first $times deletes of an approval rule fail with
     * $error, as if MariaDB had refused the DELETE (removed in tearDown()).
     *
     * @return object{count: int} the number of deletes tried so far
     */
    private function failRuleDeletes(DbException $error, int $times): object
    {
        $deletes = new class {
            public int $count = 0;
        };
        $this->deleteFailure = static function () use ($deletes, $error, $times): void {
            $deletes->count++;
            if ($deletes->count <= $times) {
                throw $error;
            }
        };
        Event::on(ApprovalRule::class, BaseActiveRecord::EVENT_BEFORE_DELETE, $this->deleteFailure);

        return $deletes;
    }

    private static function deadlock(): DbException
    {
        return new DbException(
            "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction\n"
            . 'The SQL being executed was: DELETE FROM `approval_rule` WHERE `id`=1',
            ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction']
        );
    }

    private static function lockWaitTimeout(): DbException
    {
        return new DbException(
            "SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction\n"
            . 'The SQL being executed was: DELETE FROM `approval_rule` WHERE `id`=1',
            ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction']
        );
    }

    private function newRule(): ApprovalRule
    {
        $rule = new ApprovalRule();
        $rule->name = 'write-rule-' . uniqid('', true);
        $rule->approver_type = ApprovalRule::APPROVER_TYPE_USERS;
        $rule->approver_config = (string)json_encode(['user_ids' => [$this->userId]]);
        $rule->required_approvals = 1;
        $rule->timeout_minutes = 30;
        $rule->timeout_action = ApprovalRule::TIMEOUT_ACTION_REJECT;

        return $rule;
    }

    private function storedRule(): ApprovalRule
    {
        $rule = $this->newRule();
        $this->assertTrue($this->service->create($rule, $this->userId, ApprovalRuleWriteService::SOURCE_WEB));
        $stored = ApprovalRule::findOne($rule->id);
        $this->assertNotNull($stored);

        return $stored;
    }

    private function templateOf(): JobTemplate
    {
        return $this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$this->createRunnerGroup($this->userId)->id,
            $this->userId
        );
    }

    /**
     * The rule of a job template, soft-deleted or not.
     */
    private function ruleIdOfTemplate(int $templateId): ?int
    {
        $template = JobTemplate::findWithDeleted()->where(['id' => $templateId])->one();
        $this->assertInstanceOf(JobTemplate::class, $template);

        return $template->approval_rule_id === null ? null : (int)$template->approval_rule_id;
    }

    private function requestFor(ApprovalRule $rule, string $jobStatus, string $status): ApprovalRequest
    {
        $job = $this->createJob((int)$this->templateOf()->id, $this->userId, $jobStatus);
        $request = new ApprovalRequest();
        $request->job_id = (int)$job->id;
        $request->approval_rule_id = (int)$rule->id;
        $request->status = $status;
        $request->requested_at = time();
        $request->save(false);

        return $request;
    }

    private function decisionOn(ApprovalRequest $request): ApprovalDecision
    {
        $decision = new ApprovalDecision();
        $decision->approval_request_id = (int)$request->id;
        $decision->user_id = $this->userId;
        $decision->decision = ApprovalDecision::DECISION_APPROVED;
        $decision->created_at = time();
        $decision->save(false);

        return $decision;
    }

    /**
     * @return array<string, mixed> the metadata of the newest audit entry
     */
    private function auditOf(string $action, int $ruleId): array
    {
        /** @var AuditLog|null $entry */
        $entry = AuditLog::find()
            ->where(['action' => $action, 'object_type' => 'approval_rule', 'object_id' => $ruleId])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($entry, "{$action} for rule #{$ruleId}");
        $metadata = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($metadata);

        return $metadata;
    }
}
