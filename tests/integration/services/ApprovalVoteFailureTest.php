<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\AuditLog;
use app\models\Job;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\ApprovalDecisionRefusedException;
use app\services\ApprovalService;
use app\services\AuditService;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\db\BaseActiveRecord;
use yii\db\IntegrityException;

/**
 * What a vote does when something other than the vote itself goes wrong:
 * a follow-up after the commit fails (the audit entry, the notification,
 * the workflow advance), the vote's INSERT hits an integrity error, or the
 * client sends a comment that is no text.
 */
class ApprovalVoteFailureTest extends DbTestCase
{
    use TeamScopeFixtures;
    use CancellationFixtures;
    use ApprovalVoteFixtures;

    private RecordingNotificationDispatcher $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->recordNotifications();
    }

    protected function tearDown(): void
    {
        $this->restoreNotifications();
        parent::tearDown();
    }

    // -- Follow-ups after the commit --------------------------------------------------

    /**
     * Regression: a vote whose workflow advance failed after the commit
     * ended in a server error, although the vote was recorded (a retry then
     * got 409 "already resolved"). The vote is answered as recorded, and the
     * failure is logged with its class, SQLSTATE and driver error code,
     * never with its message, which quotes the SQL with its values.
     */
    public function testRegressionAFailingWorkflowAdvanceAfterAVoteEndedInAServerError(): void
    {
        $launcher = $this->approverUser('effects-launcher');
        $approver = $this->approverUser('effects-approver');
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_APPROVAL, $this->listRule((int)$launcher->id, [$approver]), $launcher);
        $request = $flow['request'];

        ['result' => $vote, 'errors' => $errors] = $this->withFailingWorkflowAdvance(
            $this->lostConnection(),
            fn (): ApprovalDecision => $this->approvals()->recordDecision($request, (int)$approver->id, ApprovalDecision::DECISION_APPROVED, 'go')
        );

        $this->assertInstanceOf(ApprovalDecision::class, $vote);
        $this->assertSame('go', $vote->comment);
        $this->assertSame(1, $this->decisionCount($request), 'the vote is stored');
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $request->status, 'the caller\'s model is refreshed');
        $this->assertSame(ApprovalRequest::STATUS_APPROVED, $this->reloadRequest($request)->status);
        $this->assertSame(Job::STATUS_SUCCEEDED, $this->reloadJob($flow['job'])->status, 'the placeholder left pending_approval');
        $this->assertSame(
            [sprintf(ApprovalService::VOTE_EFFECTS_FAILED, (int)$request->id, (int)$approver->id, \yii\db\Exception::class, 'HY000', '2006')],
            $errors
        );
        $this->assertSame(
            sprintf(
                'Approval request #%d: the audit entry, notification or workflow advance after the vote of user #%d failed: '
                . 'yii\\db\\Exception (SQLSTATE HY000, driver error 2006).',
                (int)$request->id,
                (int)$approver->id
            ),
            $errors[0]
        );
        $this->assertStringNotContainsString('value-that-must-not-be-logged', implode("\n", $errors));
        // What ran before the advance stays done.
        $this->onlyAuditOf(AuditLog::ACTION_APPROVAL_DECIDED, 'approval_request', (int)$request->id);
        $this->assertCount(1, $this->notifications->payloadsOf(NotificationTemplate::EVENT_APPROVAL_APPROVED));
        // The engine never moved the step on.
        $execution = WorkflowJobStep::findOne($flow['execution']->id);
        $this->assertNotNull($execution);
        $this->assertSame(WorkflowJobStep::STATUS_RUNNING, $execution->status);
    }

    /**
     * A rejection's advance (a closed gate) is guarded the same way, and an
     * error that is no database error is logged with dashes.
     */
    public function testAFailingAdvanceAfterARejectionIsLoggedAndTheRejectionStands(): void
    {
        $launcher = $this->approverUser('effects-reject-launcher');
        $approver = $this->approverUser('effects-reject-approver');
        $flow = $this->workflowStepWaiting(WorkflowStep::TYPE_JOB, $this->listRule((int)$launcher->id, [$approver]), $launcher);
        $request = $flow['request'];

        ['result' => $vote, 'errors' => $errors] = $this->withFailingWorkflowAdvance(
            new \RuntimeException('the engine failed'),
            fn (): ApprovalDecision => $this->approvals()->recordDecision($request, (int)$approver->id, ApprovalDecision::DECISION_REJECTED)
        );

        $this->assertInstanceOf(ApprovalDecision::class, $vote);
        $this->assertSame(ApprovalDecision::DECISION_REJECTED, $vote->decision);
        $this->assertSame(ApprovalRequest::STATUS_REJECTED, $this->reloadRequest($request)->status);
        $this->assertSame(Job::STATUS_REJECTED, $this->reloadJob($flow['job'])->status);
        $this->assertSame(
            [sprintf(ApprovalService::VOTE_EFFECTS_FAILED, (int)$request->id, (int)$approver->id, \RuntimeException::class, '-', '-')],
            $errors
        );
    }

    /**
     * A vote that finds the job no longer waiting closes the request; when
     * the audit entry of that close fails, the vote is still answered with
     * the 409 refusal, and the failure is logged.
     */
    public function testAFailingFollowUpOfAClosedRequestStillAnswersTheRefusal(): void
    {
        $approver = $this->approverUser('effects-close-approver');
        ['job' => $job, 'request' => $request] = $this->waitingRequest([$approver]);
        Job::updateAll(['status' => Job::STATUS_FAILED], ['id' => $job->id]);
        $previousAudit = \Yii::$app->get('auditService');
        \Yii::$app->set('auditService', new class () extends AuditService {
            public function log(string $action, ?string $objectType = null, ?int $objectId = null, ?int $userId = null, array $context = []): void
            {
                throw new \LogicException('the audit failed');
            }
        });
        try {
            ['result' => $refusal, 'errors' => $errors] = $this->loggedErrorsOf(
                fn (): ?ApprovalDecisionRefusedException => $this->voteAs($request, $approver, ApprovalDecision::DECISION_APPROVED)
            );
        } finally {
            \Yii::$app->set('auditService', $previousAudit);
        }

        $this->assertInstanceOf(ApprovalDecisionRefusedException::class, $refusal);
        $this->assertSame(ApprovalDecisionRefusedException::REASON_JOB_NOT_WAITING, $refusal->reason());
        $this->assertSame(409, $refusal->httpStatus());
        $this->assertSame(ApprovalRequest::STATUS_CANCELED, $this->reloadRequest($request)->status);
        $this->assertSame(0, $this->decisionCount($request));
        $this->assertSame(
            [sprintf(ApprovalService::VOTE_EFFECTS_FAILED, (int)$request->id, (int)$approver->id, \LogicException::class, '-', '-')],
            $errors
        );
    }

    /**
     * Timeouts log their failures in the same form, with the driver's
     * error code as well; a failure after the commit as
     * TIMEOUT_EFFECTS_FAILED (the timeout stands), not as TIMEOUT_FAILED.
     */
    public function testATimeoutWhoseAdvanceFailsIsLoggedWithTheDriverErrorCode(): void
    {
        $launcher = $this->approverUser('effects-timeout-launcher');
        $flow = $this->workflowStepWaiting(
            WorkflowStep::TYPE_APPROVAL,
            $this->listRule((int)$launcher->id, [$this->approverUser('effects-timeout-approver')]),
            $launcher
        );
        $this->expireRequest($flow['request'], 60);

        ['result' => $resolved, 'errors' => $errors] = $this->withFailingWorkflowAdvance(
            $this->lostConnection(),
            fn (): int => $this->approvals()->processTimeouts()
        );

        $this->assertSame(1, $resolved);
        $this->assertSame(ApprovalRequest::STATUS_TIMED_OUT, $this->reloadRequest($flow['request'])->status);
        $this->assertSame(
            [sprintf(ApprovalService::TIMEOUT_EFFECTS_FAILED, (int)$flow['request']->id, \yii\db\Exception::class, 'HY000', '2006')],
            $errors
        );
        $this->assertSame(
            sprintf(
                'Approval request #%d was settled by its timeout, but the audit entry, notification or workflow advance '
                . 'after it failed: yii\\db\\Exception (SQLSTATE HY000, driver error 2006).',
                (int)$flow['request']->id
            ),
            $errors[0]
        );
        $this->assertStringNotContainsString('value-that-must-not-be-logged', implode("\n", $errors));
    }

    // -- Integrity errors of the vote's INSERT ----------------------------------------

    /**
     * Only a duplicate key (SQLSTATE 23000, error 1062) is a second vote;
     * every other integrity error is rethrown as it is.
     *
     * @return array<string, array{0: array<int, mixed>, 1: bool}>
     */
    public static function integrityErrorProvider(): array
    {
        return [
            'duplicate key' => [['23000', 1062, 'Duplicate entry'], true],
            'duplicate key, code as text' => [['23000', '1062', 'Duplicate entry'], true],
            'foreign key' => [['23000', 1452, 'Cannot add or update a child row'], false],
            'column cannot be null' => [['23000', 1048, 'Column cannot be null'], false],
            'another SQLSTATE' => [['HY000', 1062, 'Duplicate entry'], false],
            'no error info' => [[], false],
        ];
    }

    /**
     * @dataProvider integrityErrorProvider
     * @param array<int, mixed> $errorInfo
     */
    public function testOnlyADuplicateKeyIsRefusedAsASecondVote(array $errorInfo, bool $alreadyVoted): void
    {
        $approver = $this->approverUser('integrity-approver');
        ['request' => $request] = $this->waitingRequest([$approver]);
        $thrown = new IntegrityException('The SQL being executed was: INSERT ... a value', $errorInfo);
        $throw = static function () use ($thrown): void {
            throw $thrown;
        };
        Event::on(ApprovalDecision::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $throw);
        try {
            $refusal = $this->voteAs($request, $approver, ApprovalDecision::DECISION_APPROVED);
            $this->assertTrue($alreadyVoted, 'only a duplicate key is refused as a second vote');
            $this->assertNotNull($refusal);
            $this->assertSame(ApprovalDecisionRefusedException::REASON_ALREADY_VOTED, $refusal->reason());
            $this->assertSame(ApprovalDecisionRefusedException::ALREADY_VOTED, $refusal->getMessage());
            $this->assertSame(422, $refusal->httpStatus());
        } catch (IntegrityException $e) {
            $this->assertFalse($alreadyVoted, 'any other integrity error is rethrown');
            $this->assertSame($thrown, $e);
        } finally {
            Event::off(ApprovalDecision::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $throw);
        }

        $this->assertSame(0, $this->decisionCount($request));
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($request)->status, 'rolled back');
    }

    /**
     * Regression: every integrity error of the vote's INSERT was answered
     * as "User has already voted on this request." (422), also one that is
     * no second vote at all. Here the voter's account disappears before the
     * INSERT, and the foreign key refuses it: that is a server error, not a
     * refusal of the vote.
     */
    public function testRegressionAnotherIntegrityErrorOfTheVoteWasReportedAsAlreadyVoted(): void
    {
        $approver = $this->approverUser('integrity-fk-approver');
        ['request' => $request] = $this->waitingRequest([$approver]);
        $missingUserId = (int)User::find()->max('id') + 1000;
        $unknownVoter = static function (Event $event) use ($missingUserId): void {
            /** @var ApprovalDecision $vote */
            $vote = $event->sender;
            $vote->user_id = $missingUserId;
        };
        Event::on(ApprovalDecision::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $unknownVoter);
        try {
            $this->approvals()->recordDecision($request, (int)$approver->id, ApprovalDecision::DECISION_APPROVED);
            $this->fail('Expected the integrity error to be rethrown');
        } catch (IntegrityException $e) {
            $this->assertSame('23000', $e->errorInfo[0] ?? null);
            $this->assertSame(1452, $e->errorInfo[1] ?? null, 'the foreign key refused the vote');
        } finally {
            Event::off(ApprovalDecision::class, BaseActiveRecord::EVENT_BEFORE_INSERT, $unknownVoter);
        }

        $this->assertSame(0, $this->decisionCount($request));
        $this->assertSame(ApprovalRequest::STATUS_PENDING, $this->reloadRequest($request)->status);
    }

    // -- The comment's type -----------------------------------------------------------

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function commentThatIsNoTextProvider(): array
    {
        return [
            'list' => [['x']],
            'object' => [['text' => 'x']],
            'empty list' => [[]],
            'integer' => [5],
            'float' => [1.5],
            'boolean' => [true],
            'object instance' => [new \stdClass()],
        ];
    }

    /**
     * @dataProvider commentThatIsNoTextProvider
     */
    public function testACommentThatIsNoTextIsAnInvalidDecision(mixed $comment): void
    {
        $approver = $this->approverUser('comment-approver');
        ['request' => $request] = $this->waitingRequest([$approver]);

        try {
            ApprovalService::commentOf($request, $comment);
            $this->fail('Expected the comment to be refused');
        } catch (ApprovalDecisionRefusedException $e) {
            $this->assertSame(ApprovalDecisionRefusedException::REASON_INVALID, $e->reason());
            $this->assertSame(422, $e->httpStatus());
            $this->assertSame(
                sprintf(ApprovalDecisionRefusedException::INVALID_DECISION, ApprovalService::COMMENT_NOT_A_STRING),
                $e->getMessage()
            );
            $this->assertSame('The decision cannot be recorded: Comment must be a string.', $e->getMessage());
            $this->assertSame(ApprovalRequest::STATUS_PENDING, $e->requestStatus());
        }
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function commentProvider(): array
    {
        return [
            'none' => [null],
            'empty' => [''],
            'text' => ['ship it'],
        ];
    }

    /**
     * @dataProvider commentProvider
     */
    public function testATextCommentOrNoneIsTakenAsItIs(?string $comment): void
    {
        $approver = $this->approverUser('comment-text-approver');
        ['request' => $request] = $this->waitingRequest([$approver]);

        $this->assertSame($comment, ApprovalService::commentOf($request, $comment));
    }
}
