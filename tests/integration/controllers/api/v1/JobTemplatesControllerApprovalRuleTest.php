<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
use app\models\ApiToken;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\JobTemplateDeletionService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\RestoresComponents;
use app\tests\integration\services\RecordingNotificationDispatcher;
use app\tests\integration\TeamScopeFixtures;
use yii\web\NotFoundHttpException;

/**
 * The approval rule of job templates in the REST API (only users who may
 * assign rules change it; every response names it), and DELETE, which
 * cancels the template's waiting jobs and disables its schedules.
 */
class JobTemplatesControllerApprovalRuleTest extends WebControllerTestCase
{
    use RestoresComponents;
    use TeamScopeFixtures;

    private JobTemplatesController $ctrl;
    private User $admin;
    private ApprovalRule $rule;
    private ApprovalRule $otherRule;

    protected function setUp(): void
    {
        parent::setUp();
        // Swapped before any write, so that tearDown() restores it also when
        // a write fails.
        $this->swapComponent('notificationDispatcher', new RecordingNotificationDispatcher());
        $this->assertNotNull(\Yii::$app);
        $this->ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
        $this->admin = $this->createUserWithRole('api-rule-admin', 'admin');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->otherRule = $this->createApprovalRule((int)$this->admin->id);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    // -- Create and update: who may assign -------------------------------------------

    /**
     * Regression: an operator could set or remove a template's approval rule
     * through the API: approval_rule_id was an ordinary nullable field of
     * POST and PUT.
     */
    public function testAnOperatorCannotCreateAGatedTemplate(): void
    {
        $this->authenticate($this->createUserWithRole('api-rule-operator', 'operator'));
        $name = 'api-crafted-' . uniqid('', true);
        $this->setBody($this->templateBody($name) + ['approval_rule_id' => (int)$this->rule->id]);

        $result = $this->ctrl->actionCreate();

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED]], $result);
        $this->assertNull(JobTemplate::findOne(['name' => $name]), 'nothing is written');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function changedRuleProvider(): array
    {
        return ['another rule' => ['other'], 'no rule (null)' => ['null'], 'no rule (empty)' => ['empty']];
    }

    /**
     * Regression: an operator could set or remove a template's approval rule
     * through the API, removing the gate of every launch.
     *
     * @dataProvider changedRuleProvider
     */
    public function testAnOperatorCannotChangeOrRemoveTheRule(string $change): void
    {
        $template = $this->gatedTemplate();
        $this->authenticate($this->createUserWithRole('api-rule-operator', 'operator'));
        $submitted = match ($change) {
            'other' => (int)$this->otherRule->id,
            'null' => null,
            default => '',
        };
        $this->setBody(['name' => 'renamed-' . uniqid('', true), 'approval_rule_id' => $submitted]);

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED]], $result);
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);
        $this->assertSame((int)$this->rule->id, (int)$stored->approval_rule_id);
        $this->assertSame($template->name, $stored->name, 'nothing is written');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sameRuleProvider(): array
    {
        return ['as a number' => ['int'], 'as text' => ['string']];
    }

    /**
     * @dataProvider sameRuleProvider
     */
    public function testAnOperatorMaySendTheStoredRuleAgain(string $form): void
    {
        $template = $this->gatedTemplate();
        $this->authenticate($this->createUserWithRole('api-rule-operator', 'operator'));
        $newName = 'resent-' . uniqid('', true);
        $this->setBody(['name' => $newName, 'approval_rule_id' => $form === 'int' ? (int)$this->rule->id : (string)$this->rule->id]);

        $data = $this->data($this->ctrl->actionUpdate((int)$template->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame($newName, $data['name']);
        $this->assertSame((int)$this->rule->id, $data['approval_rule_id']);
    }

    public function testAnOperatorsUpdateWithoutTheRuleKeepsIt(): void
    {
        $template = $this->gatedTemplate();
        $this->authenticate($this->createUserWithRole('api-rule-operator', 'operator'));
        $this->setBody(['name' => 'kept-' . uniqid('', true)]);

        $data = $this->data($this->ctrl->actionUpdate((int)$template->id));

        $this->assertSame((int)$this->rule->id, $data['approval_rule_id']);
        $this->assertSame($this->rule->name, $data['approval_rule_name']);
    }

    public function testAnAdminCreatesAGatedTemplate(): void
    {
        $this->authenticate($this->admin);
        $this->setBody($this->templateBody('api-gated-' . uniqid('', true)) + ['approval_rule_id' => (int)$this->rule->id]);

        $data = $this->data($this->ctrl->actionCreate());

        $this->assertSame(201, \Yii::$app->response->statusCode);
        $this->assertSame((int)$this->rule->id, $data['approval_rule_id']);
        $this->assertSame($this->rule->name, $data['approval_rule_name']);
        $context = $this->lastContext(AuditLog::ACTION_TEMPLATE_CREATED, (int)$data['id']);
        $this->assertSame('api', $context['source'] ?? null);
        $this->assertSame(['from' => null, 'to' => ['id' => (int)$this->rule->id, 'name' => $this->rule->name]], $context['approval_rule'] ?? null);
    }

    public function testAnAdminChangesAndRemovesTheRule(): void
    {
        $template = $this->gatedTemplate();
        $this->authenticate($this->admin);

        $this->setBody(['approval_rule_id' => (int)$this->otherRule->id]);
        $changed = $this->data($this->ctrl->actionUpdate((int)$template->id));
        $this->assertSame((int)$this->otherRule->id, $changed['approval_rule_id']);
        $this->assertSame($this->otherRule->name, $changed['approval_rule_name']);

        $this->setBody(['approval_rule_id' => null]);
        $removed = $this->data($this->ctrl->actionUpdate((int)$template->id));
        $this->assertNull($removed['approval_rule_id']);
        $this->assertNull($removed['approval_rule_name']);
        $this->assertSame(
            ['from' => ['id' => (int)$this->otherRule->id, 'name' => $this->otherRule->name], 'to' => null],
            $this->lastContext(AuditLog::ACTION_TEMPLATE_UPDATED, (int)$template->id)['approval_rule'] ?? null
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function assignerProvider(): array
    {
        return ['a superadmin' => ['superadmin'], 'a custom role with approval-rule.update' => ['custom']];
    }

    /**
     * @dataProvider assignerProvider
     */
    public function testEveryHolderOfTheAssignPermissionMayChangeTheRule(string $kind): void
    {
        $template = $this->gatedTemplate();
        // Both also hold the operator role: PUT itself needs job-template.update.
        $user = $this->createUserWithRole('api-rule-' . $kind, 'operator');
        if ($kind === 'superadmin') {
            $user->is_superadmin = true;
            $user->save(false);
        } else {
            $this->grantAssignPermission($user);
        }
        $this->authenticate($user);
        $this->setBody(['approval_rule_id' => (int)$this->otherRule->id]);

        $data = $this->data($this->ctrl->actionUpdate((int)$template->id));

        $this->assertSame((int)$this->otherRule->id, $data['approval_rule_id']);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidRuleProvider(): array
    {
        return [
            'an unknown rule' => [987654321, 'The selected approval rule does not exist.'],
            'no number' => ['abc', 'Approval rule must be an integer.'],
            'a list' => [[1], 'Approval rule must be an integer.'],
        ];
    }

    /**
     * @dataProvider invalidRuleProvider
     */
    public function testAnInvalidRuleIs422(mixed $submitted, string $message): void
    {
        $template = $this->gatedTemplate();
        $this->authenticate($this->admin);
        $this->setBody(['approval_rule_id' => $submitted]);

        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => $message]], $result);
        $template->refresh();
        $this->assertSame((int)$this->rule->id, (int)$template->approval_rule_id);
    }

    // -- Responses ---------------------------------------------------------------------

    public function testEveryCallerWhoMayViewATemplateSeesItsRule(): void
    {
        $gated = $this->gatedTemplate();
        $ungated = $this->template();
        $this->authenticate($this->createUserWithRole('api-rule-viewer', 'viewer'));

        $view = $this->data($this->ctrl->actionView((int)$gated->id));
        $list = $this->ctrl->actionIndex();

        $this->assertSame((int)$this->rule->id, $view['approval_rule_id']);
        $this->assertSame($this->rule->name, $view['approval_rule_name']);
        $listed = [];
        foreach ($this->data($list) as $item) {
            $this->assertIsArray($item);
            $listed[(int)$item['id']] = [$item['approval_rule_id'], $item['approval_rule_name']];
        }
        $this->assertSame([(int)$this->rule->id, $this->rule->name], $listed[(int)$gated->id] ?? null);
        $this->assertSame([null, null], $listed[(int)$ungated->id] ?? null);
    }

    // -- Delete ---------------------------------------------------------------------------

    /**
     * Regression: deleting a job template left its waiting jobs queued, and
     * its schedules enabled.
     */
    public function testDeleteCancelsWaitingJobsAndDisablesSchedulesAndCountsThem(): void
    {
        $template = $this->template();
        $queued = $this->createJob((int)$template->id, (int)$this->admin->id, Job::STATUS_QUEUED);
        $running = $this->createJob((int)$template->id, (int)$this->admin->id, Job::STATUS_RUNNING);
        $schedule = $this->schedule($template);
        $this->authenticate($this->admin);

        $data = $this->data($this->ctrl->actionDelete((int)$template->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(['deleted' => true, 'canceled_jobs' => 1, 'disabled_schedules' => 1], $data);
        $queued->refresh();
        $this->assertSame(Job::STATUS_CANCELED, $queued->status);
        $running->refresh();
        $this->assertSame(Job::STATUS_RUNNING, $running->status);
        $schedule->refresh();
        $this->assertFalse((bool)$schedule->enabled);
        $context = $this->lastContext(AuditLog::ACTION_TEMPLATE_DELETED, (int)$template->id);
        $this->assertSame(
            ['name' => $template->name, 'source' => JobTemplateDeletionService::SOURCE_API, 'canceled_jobs' => 1, 'disabled_schedules' => 1],
            $context
        );
    }

    public function testDeletingATemplateDeletedMeanwhileIs404(): void
    {
        $template = $this->template();
        $this->authenticate($this->admin);
        $original = \Yii::$app->getComponents(true)['jobTemplateDeletionService'] ?? null;
        \Yii::$app->set('jobTemplateDeletionService', new class extends JobTemplateDeletionService {
            public function delete(JobTemplate $template, ?int $actorId, string $source): array
            {
                return ['deleted' => false, 'canceled_jobs' => 0, 'disabled_schedules' => 0];
            }
        });

        try {
            $this->ctrl->actionDelete((int)$template->id);
            $this->fail('Expected NotFoundHttpException');
        } catch (NotFoundHttpException $e) {
            $this->assertSame("Template #{$template->id} not found.", $e->getMessage());
        } finally {
            \Yii::$app->set('jobTemplateDeletionService', $original);
        }
    }

    // -- helpers -------------------------------------------------------------------------

    private function template(): JobTemplate
    {
        $ownerId = (int)$this->admin->id;

        return $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
    }

    private function gatedTemplate(): JobTemplate
    {
        $template = $this->template();
        $template->approval_rule_id = (int)$this->rule->id;
        $template->save(false);

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    private function templateBody(string $name): array
    {
        $ownerId = (int)$this->admin->id;

        return [
            'name' => $name,
            'project_id' => (int)$this->createProject($ownerId)->id,
            'inventory_id' => (int)$this->createInventory($ownerId)->id,
            'runner_group_id' => (int)$this->createRunnerGroup($ownerId)->id,
            'playbook' => 'site.yml',
        ];
    }

    private function schedule(JobTemplate $template): Schedule
    {
        $schedule = new Schedule();
        $schedule->name = 'api-delete-' . uniqid('', true);
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 2 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = (int)$this->admin->id;
        $schedule->computeNextRunAt();
        $schedule->save(false);

        return $schedule;
    }

    private function grantAssignPermission(User $user): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('api-rule-assigners-' . uniqid());
        $auth->add($role);
        $permission = $auth->getPermission(ApprovalRuleAssignmentPolicy::PERMISSION);
        $this->assertNotNull($permission);
        $auth->addChild($role, $permission);
        $auth->assign($role, (string)$user->id);
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'rule-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        /** @var \yii\web\User<\yii\web\IdentityInterface> $userComponent */
        $userComponent = \Yii::$app->user;
        $userComponent->loginByAccessToken($raw);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function data(array $result): array
    {
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastContext(string $action, int $templateId): array
    {
        $entry = AuditLog::find()
            ->where(['action' => $action, 'object_type' => 'job_template', 'object_id' => $templateId])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertInstanceOf(AuditLog::class, $entry);
        $context = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($context);

        return $context;
    }
}
