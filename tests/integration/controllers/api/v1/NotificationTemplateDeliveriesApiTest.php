<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\NotificationTemplatesController;
use app\models\ApiToken;
use app\models\ApprovalRequest;
use app\models\Job;
use app\models\JobTemplate;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\services\notification\VisibleDeliveries;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\controllers\WebRoutes;
use app\tests\integration\TeamScopeFixtures;

/**
 * GET /api/v1/notification-templates/{id}/deliveries: the deliveries of a
 * template, newest first, 25 per page, each with its event, kind, status,
 * attempts, last error, times and the objects it is about, never its
 * variables or the post it made; only those whose objects the caller may
 * see (spec answer 5), with meta.hidden counting the others.
 */
class NotificationTemplateDeliveriesApiTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use OpenApiSchemaAssertions;

    /** A value of the variables snapshot that no answer may carry. */
    private const MARKER = 'nt-deliveries-variables-marker-5531';

    /**
     * The deliveries of the template, newest first, each with exactly the
     * fields of the spec's NotificationDelivery; another template's are not
     * listed; meta as the spec's NotificationDeliveryListMeta says.
     */
    public function testTheDeliveriesOfATemplateNewestFirst(): void
    {
        $admin = $this->createUserWithRole('nt-deliveries-admin', 'admin');
        $template = $this->createNotificationTemplate((int)$admin->id);
        $other = $this->createNotificationTemplate((int)$admin->id);
        $first = $this->delivery($template, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN, [], [
            'status' => NotificationDelivery::STATUS_FAILED,
            'attempts' => 8,
            'last_error' => 'WebhookChannel: HTTP 503.',
            'finished_at' => time(),
        ]);
        $this->delivery($other, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN);
        $second = $this->delivery($template, NotificationTemplate::EVENT_RUNNER_OFFLINE, [], ['remote_id' => 'post-remote-id-1']);
        $this->authenticate($admin);

        $answer = $this->deliveries((int)$template->id);

        $this->assertSame([(int)$second->id, (int)$first->id], array_column($answer['data'], 'id'));
        foreach ($answer['data'] as $row) {
            $this->assertIsArray($row);
            $this->assertMatchesSchema('NotificationDelivery', $row);
        }
        $this->assertSame([
            'id' => (int)$first->id,
            'event' => NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN,
            'kind' => NotificationDelivery::KIND_MESSAGE,
            'status' => NotificationDelivery::STATUS_FAILED,
            'attempts' => 8,
            'last_error' => 'WebhookChannel: HTTP 503.',
            'workflow_job_id' => null,
            'job_id' => null,
            'approval_request_id' => null,
            'project_id' => null,
            'job_template_id' => null,
            'next_attempt_at' => (int)$first->next_attempt_at,
            'created_at' => (int)$first->created_at,
            'updated_at' => (int)$first->updated_at,
            'finished_at' => (int)$first->finished_at,
        ], $answer['data'][1]);
        $this->assertMatchesSchema('NotificationDeliveryListMeta', $answer['meta']);
        $this->assertSame(['total' => 2, 'page' => 1, 'per_page' => 25, 'pages' => 1, 'hidden' => 0], $answer['meta']);
        $text = (string)json_encode($answer);
        $this->assertStringNotContainsString(self::MARKER, $text, 'no variables');
        $this->assertStringNotContainsString('post-remote-id-1', $text, 'not the post it made');
    }

    /**
     * Regression guard: the list is newest first whatever ?sort= says; the
     * data provider of a list takes ?sort= of the request otherwise, and
     * ?sort=id answered the oldest first.
     */
    public function testTheListIsNewestFirstWhateverSortSays(): void
    {
        $admin = $this->createUserWithRole('nt-deliveries-sort', 'admin');
        $template = $this->createNotificationTemplate((int)$admin->id);
        $first = (int)$this->delivery($template, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN)->id;
        $second = (int)$this->delivery($template, NotificationTemplate::EVENT_RUNNER_OFFLINE)->id;
        $this->authenticate($admin);

        foreach (['id', '-id', 'event', 'variables'] as $sort) {
            $this->setQueryParams(['sort' => $sort]);
            $answer = $this->call('deliveries', ['id' => $template->id]);
            $this->assertIsArray($answer['data'] ?? null);
            $this->assertSame([$second, $first], array_column($answer['data'], 'id'), "?sort={$sort}");
        }
    }

    /**
     * The enums of the spec are the model's kinds and statuses.
     */
    public function testTheSpecNamesEveryKindAndStatus(): void
    {
        $properties = $this->properties('NotificationDelivery');

        $this->assertSame(NotificationDelivery::KINDS, $properties['kind']['enum'] ?? null);
        $this->assertSame(NotificationDelivery::STATUSES, $properties['status']['enum'] ?? null);
        $this->assertDocumentedList();
    }

    /**
     * 26 deliveries: 25 on the first page, one on the second, none on the
     * third, which names itself in meta.page.
     */
    public function testTheDeliveriesArePaged(): void
    {
        $admin = $this->createUserWithRole('nt-deliveries-pages', 'admin');
        $template = $this->createNotificationTemplate((int)$admin->id);
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $ids[] = (int)$this->delivery($template, NotificationTemplate::EVENT_WEBHOOK_INVALID_TOKEN)->id;
        }
        rsort($ids);
        $this->authenticate($admin);

        $first = $this->deliveries((int)$template->id);
        $second = $this->deliveries((int)$template->id, '2');
        $past = $this->deliveries((int)$template->id, '3');

        $this->assertSame(array_slice($ids, 0, 25), array_column($first['data'], 'id'));
        $this->assertSame(array_slice($ids, 25), array_column($second['data'], 'id'));
        $this->assertSame(['total' => 26, 'page' => 2, 'per_page' => 25, 'pages' => 2, 'hidden' => 0], $second['meta']);
        $this->assertSame([], $past['data']);
        $this->assertSame(['total' => 26, 'page' => 3, 'per_page' => 25, 'pages' => 2, 'hidden' => 0], $past['meta']);
    }

    /**
     * Spec answer 5: a viewer of team B, an operator of team A and an admin
     * read the same template's deliveries; each sees those whose objects
     * they may see: the job, the run, the approval request, the project or
     * the job template a delivery names, every one of them; meta.hidden
     * counts the others, which no answer names. A delivery of a group whose
     * object it does not name is shown only to a reader no team restricts.
     */
    public function testEachReaderSeesTheDeliveriesOfWhatTheyMaySee(): void
    {
        $scope = $this->teamScope('viewer');
        $admin = $scope['admin'];
        $adminId = (int)$admin->id;
        $template = $this->createNotificationTemplate($adminId);
        $jobA = $this->jobOf($scope['foreign'], $adminId);
        $jobB = $this->jobOf($scope['own'], $adminId);
        $jobOpen = $this->jobOf($scope['open'], $adminId);
        $runA = $this->runOf($scope['foreign'], $adminId);
        $runB = $this->runOf($scope['own'], $adminId);
        $rows = [
            'job of team B' => $this->delivery($template, 'job.failed', ['job_id' => $jobB]),
            'job of team A' => $this->delivery($template, 'job.failed', ['job_id' => $jobA]),
            'open job' => $this->delivery($template, 'job.failed', ['job_id' => $jobOpen]),
            'run of team B' => $this->delivery($template, 'workflow.failed', ['workflow_job_id' => $runB]),
            'run of team A' => $this->delivery($template, 'workflow.failed', ['workflow_job_id' => $runA]),
            'status of the run of team B' => $this->delivery($template, NotificationDelivery::EVENT_STATUS_REFRESH, ['workflow_job_id' => $runB], ['kind' => NotificationDelivery::KIND_STATUS]),
            'approval of team A' => $this->delivery($template, 'approval.requested', ['approval_request_id' => $this->requestOf($jobA), 'job_id' => $jobA]),
            'approval of team B' => $this->delivery($template, 'approval.requested', ['approval_request_id' => $this->requestOf($jobB), 'job_id' => $jobB]),
            'project of team A' => $this->delivery($template, 'project.sync_failed', ['project_id' => (int)$scope['foreign']->project_id]),
            'project of team B' => $this->delivery($template, 'project.sync_failed', ['project_id' => (int)$scope['own']->project_id]),
            'schedule of team A' => $this->delivery($template, 'schedule.failed_to_launch', ['job_template_id' => (int)$scope['foreign']->id]),
            'schedule of team B' => $this->delivery($template, 'schedule.failed_to_launch', ['job_template_id' => (int)$scope['own']->id]),
            'runner' => $this->delivery($template, 'runner.offline'),
            'trigger' => $this->delivery($template, 'webhook.invalid_token'),
            'open job of a run of team A' => $this->delivery($template, 'job.failed', ['job_id' => $jobOpen, 'workflow_job_id' => $runA]),
            'job event without its job' => $this->delivery($template, 'job.failed'),
            'schedule event without its job template' => $this->delivery($template, 'schedule.failed_to_launch'),
        ];

        $expected = [
            'viewer of team B' => [$scope['member'], ['job of team B', 'open job', 'run of team B', 'status of the run of team B', 'approval of team B', 'project of team B', 'runner', 'trigger']],
            'operator of team A' => [$scope['outsider'], ['job of team A', 'open job', 'run of team A', 'approval of team A', 'project of team A', 'schedule of team A', 'runner', 'trigger', 'open job of a run of team A']],
            'admin' => [$admin, array_keys($rows)],
        ];
        foreach ($expected as $reader => [$user, $visible]) {
            $this->authenticate($user);
            $answer = $this->deliveries((int)$template->id);

            $ids = array_map(static fn (string $name): int => (int)$rows[$name]->id, $visible);
            rsort($ids);
            $this->assertSame($ids, array_column($answer['data'], 'id'), $reader);
            $this->assertSame(count($rows) - count($visible), $answer['meta']['hidden'] ?? null, $reader);
            $this->assertSame(count($visible), $answer['meta']['total'] ?? null, $reader);
        }
    }

    /**
     * Without team-restricted projects, a reader sees the deliveries of
     * every group they hold the permission of: a viewer not those of
     * schedules (job.launch), and nobody without workflow.view those of a
     * run, also not a job's event of a run; an operator sees all of them.
     */
    public function testWithoutTeamsTheReadersPermissionsDecide(): void
    {
        $admin = $this->createUserWithRole('nt-deliveries-noteams-admin', 'admin');
        $adminId = (int)$admin->id;
        $jobTemplate = $this->createJobTemplate(
            (int)$this->createProject($adminId)->id,
            (int)$this->createInventory($adminId)->id,
            (int)$this->createRunnerGroup($adminId)->id,
            $adminId
        );
        $template = $this->createNotificationTemplate($adminId);
        $job = $this->jobOf($jobTemplate, $adminId);
        $run = $this->runOf($jobTemplate, $adminId);
        $rows = [
            'job' => $this->delivery($template, 'job.failed', ['job_id' => $job]),
            'run' => $this->delivery($template, 'workflow.failed', ['workflow_job_id' => $run]),
            'job of a run' => $this->delivery($template, 'job.failed', ['job_id' => $job, 'workflow_job_id' => $run]),
            'schedule' => $this->delivery($template, 'schedule.failed_to_launch', ['job_template_id' => (int)$jobTemplate->id]),
            'schedule without its job template' => $this->delivery($template, 'schedule.failed_to_launch'),
            'job event without its job' => $this->delivery($template, 'job.failed'),
        ];
        $noWorkflows = $this->createUserWithRole('nt-deliveries-noteams-nowf', 'viewer');
        $this->revoke($noWorkflows, 'workflow.view');

        $readers = [
            'viewer' => [$this->createUserWithRole('nt-deliveries-noteams-viewer', 'viewer'), ['job', 'run', 'job of a run', 'job event without its job']],
            'operator' => [$this->createUserWithRole('nt-deliveries-noteams-operator', 'operator'), array_keys($rows)],
            'viewer without workflow.view' => [$noWorkflows, ['job', 'job event without its job']],
        ];
        foreach ($readers as $reader => [$user, $visible]) {
            $this->authenticate($user);
            $answer = $this->deliveries((int)$template->id);

            $ids = array_map(static fn (string $name): int => (int)$rows[$name]->id, $visible);
            rsort($ids);
            $this->assertSame($ids, array_column($answer['data'], 'id'), $reader);
            $this->assertSame(count($rows) - count($visible), $answer['meta']['hidden'] ?? null, $reader);
        }
    }

    /**
     * The condition the list applies decides from the reader's permissions
     * and teams alone; an unknown event is shown to nobody.
     */
    public function testADeliveryOfAnUnknownEventIsShownToNobody(): void
    {
        $admin = $this->createUserWithRole('nt-deliveries-unknown', 'admin');
        $template = $this->createNotificationTemplate((int)$admin->id);
        $unknown = $this->delivery($template, 'job.exploded');
        $known = $this->delivery($template, 'webhook.invalid_token');

        $visible = NotificationDelivery::find()
            ->where(['notification_template_id' => $template->id])
            ->andWhere(VisibleDeliveries::condition((int)$admin->id))
            ->select('id')
            ->column();

        $this->assertSame([(int)$known->id], array_map('intval', $visible));
        $this->assertNotContains((int)$unknown->id, array_map('intval', $visible));
    }

    /**
     * config/web.php routes GET .../{id}/deliveries to the list, and no
     * other method.
     */
    public function testTheListIsRoutedForGetOnly(): void
    {
        $route = WebRoutes::resolve('GET', '/api/v1/notification-templates/41/deliveries');

        $this->assertNotNull($route);
        $this->assertSame(NotificationTemplatesController::class, $route['class']);
        $this->assertSame('deliveries', $route['action']);
        $this->assertSame(['id' => '41'], $route['params']);
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $other = WebRoutes::resolve($method, '/api/v1/notification-templates/41/deliveries');
            $this->assertNotSame('deliveries', $other['action'] ?? null, $method);
        }
    }

    public function testAnUnknownTemplateIsNotFound(): void
    {
        $this->authenticate($this->createUserWithRole('nt-deliveries-404', 'viewer'));

        $this->expectException(\yii\web\NotFoundHttpException::class);
        $this->expectExceptionMessage('Notification template #987654321 not found.');
        $this->call('deliveries', ['id' => 987654321]);
    }

    /**
     * The list needs notification-template.view, as the template does.
     */
    public function testTheListNeedsThePermissionToViewTemplates(): void
    {
        $admin = $this->createUserWithRole('nt-deliveries-owner', 'admin');
        $template = $this->createNotificationTemplate((int)$admin->id);
        $this->delivery($template, 'webhook.invalid_token');
        $this->authenticate($this->createUser('nt-deliveries-roleless'));

        $result = (new NotificationTemplatesController('api/v1/notification-templates', $this->app()))->runAction('deliveries', ['id' => $template->id]);

        $this->assertNull($result, 'the action did not run');
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Forbidden.']], \Yii::$app->response->data);
    }

    // -- Fixtures -----------------------------------------------------------------

    /**
     * GET /api/v1/notification-templates/$templateId/deliveries?page=$page.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function deliveries(int $templateId, ?string $page = null): array
    {
        $this->setQueryParams($page === null ? [] : ['page' => $page]);
        $answer = $this->call('deliveries', ['id' => $templateId]);
        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertIsArray($answer['data'] ?? null);
        $this->assertIsArray($answer['meta'] ?? null);
        /** @var list<array<string, mixed>> $data */
        $data = array_values($answer['data']);
        /** @var array<string, mixed> $meta */
        $meta = $answer['meta'];

        return ['data' => $data, 'meta' => $meta];
    }

    /**
     * A delivery of $event to $template, about $refs, with $attributes on
     * top, whose variables hold MARKER.
     *
     * @param array<string, int> $refs
     * @param array<string, mixed> $attributes
     */
    private function delivery(NotificationTemplate $template, string $event, array $refs = [], array $attributes = []): NotificationDelivery
    {
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = (int)$template->id;
        $delivery->event = $event;
        $delivery->kind = NotificationDelivery::KIND_MESSAGE;
        $delivery->status = NotificationDelivery::STATUS_DELIVERED;
        $delivery->attempts = 1;
        $delivery->setVariableMap(['job.name' => self::MARKER, 'event' => $event]);
        $delivery->next_attempt_at = time();
        /** @var array{workflow_job_id?: int, job_id?: int, approval_request_id?: int, project_id?: int, job_template_id?: int} $refs */
        $delivery->setRefs($refs);
        foreach ($attributes as $name => $value) {
            $delivery->setAttribute($name, $value);
        }
        $this->assertTrue($delivery->save(false));
        $delivery->refresh();

        return $delivery;
    }

    private function jobOf(JobTemplate $template, int $userId): int
    {
        return (int)$this->createJob((int)$template->id, $userId, Job::STATUS_FAILED)->id;
    }

    /**
     * A run of a workflow whose one job step runs $template.
     */
    private function runOf(JobTemplate $template, int $userId): int
    {
        $workflow = $this->createWorkflowWithJobSteps($userId, (int)$template->id);

        return (int)$this->createWorkflowJob((int)$workflow->id, $userId, WorkflowJob::STATUS_FAILED)->id;
    }

    private function requestOf(int $jobId): int
    {
        $request = new ApprovalRequest();
        $request->job_id = $jobId;
        $request->approval_rule_id = (int)$this->createApprovalRule((int)$this->createUser('nt-deliveries-rule')->id)->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $this->assertTrue($request->save(false));

        return (int)$request->id;
    }

    /**
     * Takes $permission from $user: their role gives it no longer, through
     * a role of their own that holds the role's other permissions.
     */
    private function revoke(User $user, string $permission): void
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('nt-deliveries-without-' . uniqid());
        $auth->add($role);
        foreach ($auth->getPermissionsByUser((string)$user->id) as $name => $held) {
            if ($name !== $permission) {
                $auth->addChild($role, $held);
            }
        }
        $auth->revokeAll((string)$user->id);
        $auth->assign($role, (string)$user->id);
        $this->assertFalse($auth->checkAccess((string)$user->id, $permission));
    }

    /**
     * The spec documents the list's answer as NotificationDeliveryList: its
     * data a list of NotificationDelivery, its meta NotificationDeliveryListMeta.
     */
    private function assertDocumentedList(): void
    {
        $path = '/api/v1/notification-templates/{id}/deliveries';
        $schema = $this->dig($this->spec(), ['paths', $path, 'get', 'responses', '200', 'content', 'application/json', 'schema', '$ref'], "web/openapi.yaml documents GET {$path}.");
        $this->assertSame('#/components/schemas/NotificationDeliveryList', $schema);
        $list = $this->properties('NotificationDeliveryList');
        $items = $list['data']['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertSame('#/components/schemas/NotificationDelivery', $items['$ref'] ?? null);
        $this->assertSame('#/components/schemas/NotificationDeliveryListMeta', $list['meta']['$ref'] ?? null);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $action, array $params = []): array
    {
        \Yii::$app->response->statusCode = 200;
        $result = (new NotificationTemplatesController('api/v1/notification-templates', $this->app()))->runAction($action, $params);
        $this->assertIsArray($result);
        $named = [];
        foreach ($result as $key => $value) {
            $named[(string)$key] = $value;
        }

        return $named;
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'nt-deliveries-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        \Yii::$app->user->setIdentity(null);
    }
}
