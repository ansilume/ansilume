<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\VaultAssignmentResult;
use app\controllers\api\v1\CredentialAssignmentsController;
use app\controllers\api\v1\JobTemplatesController;
use app\controllers\api\v1\NotificationTemplatesController;
use app\models\Credential;
use app\models\Inventory;
use app\models\NotificationTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\CredentialWriteService;
use app\services\GatedJobTemplateLock;
use app\services\NotificationDispatcher;
use app\services\notification\ChannelInterface;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\CancellationFixtures;
use yii\web\Request;

/**
 * What web/openapi.yaml says about the update of a job template with an
 * approval rule, and about the test of a notification template, is what
 * the API answers: each test runs the endpoint and compares its answers
 * with the spec's descriptions and examples.
 *
 * Regressions from wave 8 of step 3, each test fails on the spec as it was
 * before it: PUT /api/v1/job-templates/{id} documented no refusal of a
 * change to a gated job template (decision 5), and the test of a
 * notification template documented only {sent: true}, which it answered
 * whatever the channel did (C8).
 *
 * From 2.12.0: a job template gated through its inventory is reported by
 * the vault assignment with GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY,
 * as the spec says, and the body of GET sent back, approval_gate included,
 * stays a rename.
 */
class OpenApiGatedTemplateContractTest extends WebControllerTestCase
{
    use OpenApiSchemaAssertions;
    use CancellationFixtures;

    private const JOB_TEMPLATE = '/api/v1/job-templates/{id}';

    private const NOTIFICATION_TEST = '/api/v1/notification-templates/{id}/test';

    /** What a webhook endpoint that answers 404 makes the webhook channel throw. */
    private const CHANNEL_ERROR = 'WebhookChannel: HTTP 404 — Not Found';

    /**
     * Regression (decision 5): an operator's change of what a gated job
     * template runs answers 403 with the spec's example, and writes nothing;
     * a rule change gets its own refusal first; the body of a GET sent back
     * with a new name is a rename; an admin changes everything.
     */
    public function testAGatedJobTemplateChangesAsTheSpecSays(): void
    {
        $admin = $this->userWithRole('gated-contract-admin', 'admin');
        $operator = $this->userWithRole('gated-contract-operator', 'operator');
        $template = $this->jobTemplateOwnedBy($admin);
        $template->approval_rule_id = (int)$this->usersRule((int)$admin->id, (int)$admin->id, (int)$operator->id)->id;
        $this->assertTrue($template->save(false));
        $id = (int)$template->id;
        $ctrl = new JobTemplatesController('api/v1/job-templates', $this->app());
        $examples = $this->exampleMessages(self::JOB_TEMPLATE, 'put', '403');

        $this->loginAs($operator);
        $this->body(['playbook' => 'other.yml']);
        $refused = $ctrl->actionUpdate($id);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => GatedJobTemplateLock::CHANGE_REFUSED]], $refused);
        $this->assertSame($examples['gatedTemplate'] ?? null, $refused['error']['message'], 'The spec quotes the refusal.');
        $template->refresh();
        $this->assertSame('site.yml', $template->playbook, 'Nothing was written.');

        $this->body(['approval_rule_id' => null, 'playbook' => 'other.yml']);
        $ruleChange = $ctrl->actionUpdate($id);
        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED]], $ruleChange);
        $this->assertSame($examples['approvalRule'] ?? null, $ruleChange['error']['message'], 'A rule change is refused with its own message first.');

        $this->body(['name' => 'gated-contract-renamed'] + $this->data($ctrl->actionView($id)));
        $renamed = $this->data($ctrl->actionUpdate($id));
        $this->assertSame(200, \Yii::$app->response->statusCode, 'The GET body sent back with a new name is a rename.');
        $this->assertSame('gated-contract-renamed', $renamed['name']);
        $this->assertMatchesSchema('JobTemplate', $renamed);
        $this->assertIsArray($renamed['approval_gate'], '2.12.0: the GET body sent back holds approval_gate, which is read-only.');
        $this->assertSame([$template->approval_rule_id, 'job_template'], [$renamed['approval_gate']['approval_rule_id'], $renamed['approval_gate']['required_by']]);

        $this->loginAs($admin);
        $this->body(['playbook' => 'other.yml']);
        $changed = $this->data($ctrl->actionUpdate($id));
        $this->assertSame(200, \Yii::$app->response->statusCode, 'An admin changes what it runs.');
        $this->assertSame('other.yml', $changed['playbook']);

        $put = $this->textOf(['paths', self::JOB_TEMPLATE, 'put', 'description']);
        $this->assertStringContainsString('answers 403 `' . GatedJobTemplateLock::CHANGE_REFUSED . '` and writes nothing', $put);
        $this->assertStringContainsString('A change of `approval_rule_id` is refused with its own message first.', $put);
        $this->assertStringContainsString(
            'while it is set only they change what the template runs: everyone else may change only its `name` and `description`',
            $this->textOf(['components', 'schemas', 'JobTemplate', 'properties', 'approval_rule_id', 'description'])
        );
        $this->assertStringContainsString(
            'answers 403 `' . GatedJobTemplateLock::CHANGE_REFUSED . '`',
            $this->textOf(['components', 'schemas', 'JobTemplateInput', 'properties', 'approval_rule_id', 'description'])
        );
    }

    /**
     * Decision 5 reaches every save of a job template, also the assignment
     * of a vault password to several templates: for an operator, a gated
     * template is reported failed with the lock's refusal and left as it
     * was, and the others are assigned, as the spec says.
     */
    public function testAVaultAssignmentReportsAGatedTemplateAsTheSpecSays(): void
    {
        $admin = $this->userWithRole('gated-vault-admin', 'admin');
        $operator = $this->userWithRole('gated-vault-operator', 'operator');
        $gated = $this->jobTemplateOwnedBy($admin);
        $rule = $this->usersRule((int)$admin->id, (int)$admin->id, (int)$operator->id);
        $gated->approval_rule_id = (int)$rule->id;
        $this->assertTrue($gated->save(false));
        // 2.12.0: a template gated through its inventory is reported with
        // the refusal of the inventory's rule.
        $inventoryGated = $this->jobTemplateOwnedBy($admin);
        $this->assertSame(1, Inventory::updateAll(['approval_rule_id' => (int)$rule->id], ['id' => $inventoryGated->inventory_id]));
        $open = $this->jobTemplateOwnedBy($admin);
        $vault = new Credential();
        $vault->name = 'gated-vault-' . uniqid('', true);
        $vault->credential_type = Credential::TYPE_VAULT;
        $vault->created_by = (int)$admin->id;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($vault, ['vault_password' => 'gated-vault-secret']));

        $this->loginAs($operator);
        $this->body(['job_template_ids' => [(int)$gated->id, (int)$inventoryGated->id, (int)$open->id]]);
        $result = $this->data((new CredentialAssignmentsController('api/v1/credential-assignments', $this->app()))->actionCreate((int)$vault->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $statuses = array_column($this->listOf($result['results'] ?? null), 'status', 'job_template_id');
        $errors = array_column($this->listOf($result['results'] ?? null), 'error', 'job_template_id');
        $this->assertSame(
            [
                (int)$gated->id => VaultAssignmentResult::FAILED,
                (int)$inventoryGated->id => VaultAssignmentResult::FAILED,
                (int)$open->id => VaultAssignmentResult::ASSIGNED,
            ],
            $statuses
        );
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED, $errors[(int)$gated->id]);
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, $errors[(int)$inventoryGated->id]);
        foreach ([$gated, $inventoryGated] as $refused) {
            $refused->refresh();
            $this->assertNull($refused->credential_id, 'The gated template is left as it was.');
            $this->assertSame([], $refused->jobTemplateCredentials);
        }

        $post = $this->textOf(['paths', '/api/v1/credentials/{id}/job-templates', 'post', 'description']);
        $this->assertStringContainsString(
            'reported with the status `failed` and the error `' . GatedJobTemplateLock::CHANGE_REFUSED . '`, and the other templates are assigned',
            $post
        );
        $this->assertStringContainsString(
            'So is a job template whose inventory has an approval rule, with the error `' . GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY . '`',
            $post
        );
    }

    /**
     * Regression (C8): the test of a notification template answers
     * {sent: true} only when the channel took the message; 502 with the
     * channel's error when it did not, and 500 for an error outside the
     * delivery, as the spec's examples quote them.
     */
    public function testATestNotificationAnswersAsTheSpecSays(): void
    {
        $operator = $this->userWithRole('nt-contract-operator', 'operator');
        $this->loginAs($operator);
        $id = (int)$this->createNotificationTemplate((int)$operator->id)->id;
        $ctrl = new NotificationTemplatesController('api/v1/notification-templates', $this->app());
        $before = $this->app()->getComponents(true)['notificationDispatcher'] ?? null;
        try {
            $this->app()->set('notificationDispatcher', $this->dispatcherWith(null));
            $this->assertSame(['data' => ['sent' => true]], $ctrl->actionTest($id));
            $this->assertSame(200, \Yii::$app->response->statusCode);

            $this->app()->set('notificationDispatcher', $this->dispatcherWith(new \RuntimeException(self::CHANNEL_ERROR)));
            $failed = $ctrl->actionTest($id);
            $this->assertSame(502, \Yii::$app->response->statusCode);
            $this->assertSame(['error' => ['message' => sprintf(NotificationTemplatesController::TEST_FAILED, self::CHANNEL_ERROR)]], $failed);
            $this->assertSame($this->exampleOf('502'), $failed, 'The spec\'s 502 example is the answer.');

            $this->app()->set('notificationDispatcher', $this->throwingDispatcher());
            $broken = $ctrl->actionTest($id);
            $this->assertSame(500, \Yii::$app->response->statusCode);
            $this->assertSame($this->exampleOf('500'), $broken, 'The spec\'s 500 example is the answer.');
        } finally {
            $this->app()->set('notificationDispatcher', $before);
        }

        $description = $this->textOf(['paths', self::NOTIFICATION_TEST, 'post', 'description']);
        $this->assertStringContainsString('Answers `{"sent": true}` only when the channel took the message.', $description);
        $this->assertStringContainsString('the call answers 502 with `Test failed: ` and the channel\'s error', $description);
        $this->assertStringContainsString('Another error answers 500 with `Test failed: ` and the error', $description);
    }

    /**
     * A dispatcher whose email channel takes every message, or throws $error.
     */
    private function dispatcherWith(?\Throwable $error): NotificationDispatcher
    {
        $dispatcher = new NotificationDispatcher();
        $dispatcher->setChannel(NotificationTemplate::CHANNEL_EMAIL, new class ($error) implements ChannelInterface {
            public function __construct(private readonly ?\Throwable $error)
            {
            }

            public function send(NotificationTemplate $template, string $subject, string $body, array $variables): void
            {
                if ($this->error !== null) {
                    throw $this->error;
                }
            }
        });

        return $dispatcher;
    }

    /**
     * A dispatcher that fails outside the delivery, on a lost database
     * connection.
     */
    private function throwingDispatcher(): NotificationDispatcher
    {
        return new class () extends NotificationDispatcher {
            public function sendSingle(NotificationTemplate $nt, array $variables, string $event = 'test'): ?string
            {
                throw new \yii\db\Exception('MySQL server has gone away', ['HY000', 2006, 'MySQL server has gone away']);
            }
        };
    }

    /**
     * The answer the spec's example of the $status response of the
     * notification test shows.
     *
     * @return array<mixed>
     */
    private function exampleOf(string $status): array
    {
        $example = $this->dig(
            $this->spec(),
            ['paths', self::NOTIFICATION_TEST, 'post', 'responses', $status, 'content', 'application/json', 'example'],
            "The {$status} of the notification test has an example."
        );
        $this->assertIsArray($example);

        return $example;
    }

    /**
     * The messages of the examples of the $status response of $method
     * $path, by the example's name.
     *
     * @return array<string, mixed>
     */
    private function exampleMessages(string $path, string $method, string $status): array
    {
        $examples = $this->dig($this->spec(), ['paths', $path, $method, 'responses', $status, 'content', 'application/json', 'examples'], "{$method} {$path} has {$status} examples.");
        $this->assertIsArray($examples);
        $messages = [];
        foreach ($examples as $name => $example) {
            $messages[(string)$name] = $this->dig($example, ['value', 'error', 'message'], 'An example of the error shape.');
        }

        return $messages;
    }

    /**
     * $value as a list of rows.
     *
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        $this->assertIsArray($value);
        $this->assertTrue(array_is_list($value));
        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function body(array $body): void
    {
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->setBodyParams($body);
    }

    /**
     * The text at $keys of the spec, on one line.
     *
     * @param list<string> $keys
     */
    private function textOf(array $keys): string
    {
        $text = $this->dig($this->spec(), $keys, 'web/openapi.yaml has ' . implode('.', $keys) . '.');
        $this->assertIsString($text);

        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}
