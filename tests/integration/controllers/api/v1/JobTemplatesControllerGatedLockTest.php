<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Credential;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedJobTemplateLock;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Event;
use yii\base\Model;

/**
 * What a gated job template runs is for admins, in the REST API: for
 * everyone else PUT /api/v1/job-templates/{id} changes the name and the
 * description only; any other change, credential_ids included, answers 403
 * {"error": {"message": GatedJobTemplateLock::CHANGE_REFUSED}} and writes
 * nothing. A field sent with its stored value is no change, so the body of
 * a GET sent back with another name is a rename.
 */
class JobTemplatesControllerGatedLockTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const VARS = '{"env": "prod"}';
    private const SURVEY = '[{"name":"region","label":"Region","type":"text","required":true,"default":"eu","options":[],"hint":""}]';

    private JobTemplatesController $ctrl;
    private User $admin;
    private User $operator;
    private ApprovalRule $rule;
    private JobTemplate $template;

    /** @var array<string, Credential> the gated template's credentials: P (primary), A and B */
    private array $credentials = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotNull(\Yii::$app);
        $this->ctrl = new JobTemplatesController('api/v1/job-templates', \Yii::$app);
        $this->admin = $this->createUserWithRole('api-gl-admin', 'admin');
        $this->operator = $this->createUserWithRole('api-gl-operator', 'operator');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        foreach (['P', 'A', 'B'] as $symbol) {
            $this->credentials[$symbol] = $this->createCredential((int)$this->admin->id);
        }
        $this->template = $this->template((int)$this->rule->id);
    }

    public function testAnOperatorRenamesAndDescribesAGatedTemplate(): void
    {
        $before = $this->snapshot($this->template);
        $this->loginAs($this->operator);
        $name = 'api-renamed-' . uniqid('', true);
        $this->setBody(['name' => $name, 'description' => 'Renamed through the API.']);

        $data = $this->data($this->ctrl->actionUpdate((int)$this->template->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame($name, $data['name']);
        $this->assertSame('Renamed through the API.', $data['description']);
        $this->assertSame($before, $this->snapshot($this->fresh($this->template)));
    }

    public function testTheBodyOfAGetSentBackWithAnotherNameIsARename(): void
    {
        $before = $this->snapshot($this->template);
        $this->loginAs($this->operator);
        $body = $this->data($this->ctrl->actionView((int)$this->template->id));
        $name = 'api-get-put-' . uniqid('', true);
        $body['name'] = $name;
        $this->setBody($body);

        $data = $this->data($this->ctrl->actionUpdate((int)$this->template->id));

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($data));
        $this->assertSame($name, $data['name']);
        $this->assertSame($before, $this->snapshot($this->fresh($this->template)));
    }

    public function testValuesSentAsStoredAreNoChange(): void
    {
        $this->loginAs($this->operator);
        $name = 'api-resent-' . uniqid('', true);
        $this->setBody([
            'name' => $name,
            'project_id' => (string)$this->template->project_id,
            'credential_id' => (string)$this->credentials['P']->id,
            'credential_ids' => [(string)$this->credentials['A']->id, (int)$this->credentials['B']->id],
            'become' => false,
            'forks' => '5',
            'limit' => null,
            'extra_vars' => "{\n  \"env\": \"prod\"\n}",
            'survey_fields' => json_decode(self::SURVEY, true),
            'approval_rule_id' => (int)$this->rule->id,
        ]);

        $data = $this->data($this->ctrl->actionUpdate((int)$this->template->id));

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($data));
        $this->assertSame($name, $data['name']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lockedChangeProvider(): array
    {
        return [
            'the extra vars' => ['extra_vars'],
            'the playbook' => ['playbook'],
            'the inventory' => ['inventory_id'],
            'the runner group' => ['runner_group_id'],
            'the project' => ['project_id'],
            'the survey' => ['survey_fields'],
            'no survey' => ['survey_none'],
            'the primary credential' => ['credential_id'],
            'the credential list, another order' => ['credential_order'],
            'the credential list, one removed' => ['credential_removed'],
            'the credential list, none' => ['credential_none'],
            'become' => ['become'],
            'the forks' => ['forks'],
            'the limit' => ['limit'],
            'the timeout' => ['timeout_minutes'],
        ];
    }

    /**
     * @dataProvider lockedChangeProvider
     */
    public function testAnyOtherChangeOfAGatedTemplateIs403AndWritesNothing(string $change): void
    {
        $before = $this->snapshot($this->template);
        $audits = $this->auditCount();
        $this->loginAs($this->operator);
        $this->setBody(['name' => 'api-forged-' . uniqid('', true)] + $this->change($change));

        $result = $this->ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => GatedJobTemplateLock::CHANGE_REFUSED]], $result);
        $stored = $this->fresh($this->template);
        $this->assertSame($this->template->name, $stored->name, 'not even the name is written');
        $this->assertSame($before, $this->snapshot($stored));
        $this->assertSame($audits, $this->auditCount());
    }

    public function testAnAdminChangesWhatAGatedTemplateRuns(): void
    {
        $this->loginAs($this->admin);
        $other = $this->createCredential((int)$this->admin->id);
        $this->setBody(['playbook' => 'admin.yml', 'extra_vars' => '{"chosen_by": "admin"}', 'credential_ids' => [(int)$other->id]]);

        $data = $this->data($this->ctrl->actionUpdate((int)$this->template->id));

        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame('admin.yml', $data['playbook']);
        $this->assertSame([(int)$other->id], $data['credential_ids']);
        $this->assertSame('{"chosen_by": "admin"}', $this->fresh($this->template)->extra_vars);
    }

    public function testATemplateThatGetsARuleLaterIsLocked(): void
    {
        $template = $this->template(null);
        $this->loginAs($this->operator);
        $this->setBody(['playbook' => 'before-the-rule.yml']);
        $this->data($this->ctrl->actionUpdate((int)$template->id));
        $this->assertSame('before-the-rule.yml', $this->fresh($template)->playbook, 'without a rule, operators change it');

        JobTemplate::updateAll(['approval_rule_id' => $this->rule->id], ['id' => $template->id]);
        $this->setBody(['playbook' => 'after-the-rule.yml']);
        $result = $this->ctrl->actionUpdate((int)$template->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => GatedJobTemplateLock::CHANGE_REFUSED]], $result);
        $this->assertSame('before-the-rule.yml', $this->fresh($template)->playbook);
    }

    /**
     * A rule assigned while the request runs (here: during its validation)
     * is seen on the template row the save's transaction locks.
     */
    public function testARuleAssignedWhileTheSaveRunsIsSeen(): void
    {
        $template = $this->template(null);
        $assign = function (Event $event) use ($template): void {
            if ($event->sender instanceof JobTemplate && (int)$event->sender->id === (int)$template->id) {
                JobTemplate::updateAll(['approval_rule_id' => $this->rule->id], ['id' => $template->id]);
            }
        };
        Event::on(JobTemplate::class, Model::EVENT_AFTER_VALIDATE, $assign);
        $this->loginAs($this->operator);
        $this->setBody(['playbook' => 'raced.yml']);

        try {
            $result = $this->ctrl->actionUpdate((int)$template->id);
        } finally {
            Event::off(JobTemplate::class, Model::EVENT_AFTER_VALIDATE, $assign);
        }

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => GatedJobTemplateLock::CHANGE_REFUSED]], $result);
        $this->assertSame('site.yml', $this->fresh($template)->playbook);
    }

    public function testARuleChangeIsRefusedFirst(): void
    {
        $this->loginAs($this->operator);
        $this->setBody(['approval_rule_id' => null, 'playbook' => 'other.yml']);

        $result = $this->ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame(403, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED]], $result);
    }

    public function testAnInvalidNameIsStill422(): void
    {
        $this->loginAs($this->operator);
        $this->setBody(['name' => '']);

        $result = $this->ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'Name cannot be blank.']], $result);
    }

    public function testACredentialListThatIsNoListIsStill422(): void
    {
        $this->loginAs($this->operator);
        $this->setBody(['credential_ids' => 'all']);

        $result = $this->ctrl->actionUpdate((int)$this->template->id);

        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'credential_ids must be an array of credential IDs.']], $result);
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function change(string $change): array
    {
        $ownerId = (int)$this->admin->id;
        $credentials = [(int)$this->credentials['A']->id, (int)$this->credentials['B']->id];

        return match ($change) {
            'extra_vars' => ['extra_vars' => '{"chosen_by": "operator"}'],
            'playbook' => ['playbook' => 'other.yml'],
            'inventory_id' => ['inventory_id' => (int)$this->createInventory($ownerId)->id],
            'runner_group_id' => ['runner_group_id' => (int)$this->createRunnerGroup($ownerId)->id],
            'project_id' => ['project_id' => (int)$this->createProject($ownerId)->id],
            'survey_fields' => ['survey_fields' => [['name' => 'other', 'label' => 'Other', 'type' => 'text']]],
            'survey_none' => ['survey_fields' => null],
            'credential_id' => ['credential_id' => null],
            'credential_order' => ['credential_ids' => array_reverse($credentials)],
            'credential_removed' => ['credential_ids' => [$credentials[0]]],
            'credential_none' => ['credential_ids' => []],
            'become' => ['become' => true],
            'forks' => ['forks' => 20],
            'limit' => ['limit' => 'web*'],
            'timeout_minutes' => ['timeout_minutes' => 5],
            default => $this->fail('unknown change ' . $change),
        };
    }

    /**
     * A template with extra vars, a survey and the credentials P (primary),
     * A and B; gated by $ruleId unless it is null.
     */
    private function template(?int $ruleId): JobTemplate
    {
        $ownerId = (int)$this->admin->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
        JobTemplate::updateAll([
            'approval_rule_id' => $ruleId,
            'extra_vars' => self::VARS,
            'survey_fields' => self::SURVEY,
            'credential_id' => $this->credentials['P']->id,
        ], ['id' => $template->id]);
        foreach (['P', 'A', 'B'] as $order => $symbol) {
            \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $this->credentials[$symbol]->id,
                'sort_order' => $order,
            ])->execute();
        }

        return $this->fresh($template);
    }

    /**
     * Everything the template runs: its columns but name, description and
     * the timestamps, and its credentials in order.
     *
     * @return array<string, mixed>
     */
    private function snapshot(JobTemplate $template): array
    {
        $columns = $template->getAttributes(null, ['name', 'description', 'updated_at']);

        return $columns + ['credentials' => array_column($this->fresh($template)->credentialSnapshot(), 'id')];
    }

    private function fresh(JobTemplate $template): JobTemplate
    {
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function auditCount(): int
    {
        return (int)AuditLog::find()->where(['object_type' => 'job_template', 'object_id' => $this->template->id])->count();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
        \Yii::$app->response->statusCode = 200;
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
}
