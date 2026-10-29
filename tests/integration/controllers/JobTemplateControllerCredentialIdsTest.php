<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobTemplateController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\User;
use app\services\GatedJobTemplateLock;
use app\services\JobTemplateCredentialService;
use app\services\LintService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\db\Query;
use yii\helpers\Html;
use yii\web\ForbiddenHttpException;
use yii\web\JsonParser;
use yii\web\Request;

/**
 * The job template form posted as JSON (the request's JSON parser serves
 * the web forms too, config/web.php) with additional credentials
 * (credential_ids) that are no credential IDs: a float that PHP writes as
 * an ID with its 14 significant digits, true, an ID with a space. The form
 * is shown again with the error of credential_ids
 * (JobTemplateCredentialService::INVALID_CREDENTIAL_IDS, #credential-ids-error),
 * and nothing is written, audited or logged as a failure; "0<ID>" is that
 * credential. On a gated job template, a user without approval-rule.update
 * who sends the stored credentials so gets the lock's 403, and "0<ID>" is no
 * change. The views are the real ones, without the layout
 * (RendersRealViews).
 *
 * Regression: the save and the lock read an entry with filter_var(…,
 * FILTER_VALIDATE_INT), which reads a float by its text, takes true for 1
 * and trims spaces: [2.9999999999999996] named credential #3, [true] #1, an
 * ID followed by a fraction in its 15th digit, " 5" and "5 " that
 * credential, which the form saved, and "0<ID>" was refused, by the save
 * with the form's error and by the lock with 403.
 */
class JobTemplateControllerCredentialIdsTest extends WebControllerTestCase
{
    use RendersRealViews;
    use RestoresComponents;
    use TeamScopeFixtures;
    use SendsJsonNumbers;

    /** The name of what a create would create. */
    private const CREATED = 'credential-ids-form-created';

    private User $admin;
    private User $operator;
    private Project $project;
    private Inventory $inventory;
    private RunnerGroup $group;
    private ApprovalRule $rule;
    private JobTemplate $template;

    /** @var array<string, Credential> the credentials of the template: P (primary), A and B */
    private array $credentials = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapComponent('lintService', new class () extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
        \Yii::$app->session->removeAllFlashes();
        $this->admin = $this->createUserWithRole('credential-ids-form-admin', 'admin');
        $this->operator = $this->createUserWithRole('credential-ids-form-operator', 'operator');
        $owner = (int)$this->admin->id;
        $this->project = $this->createProject($owner);
        $this->inventory = $this->createInventory($owner);
        $this->group = $this->createRunnerGroup($owner);
        $this->rule = $this->createApprovalRule($owner);
        foreach (['P', 'A', 'B'] as $symbol) {
            $this->credentials[$symbol] = $this->createCredential($owner);
        }
        $this->template = $this->createJobTemplate((int)$this->project->id, (int)$this->inventory->id, (int)$this->group->id, $owner);
        JobTemplate::updateAll(['credential_id' => $this->credentials['P']->id], ['id' => $this->template->id]);
        foreach (['P', 'A', 'B'] as $order => $symbol) {
            \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $this->template->id,
                'credential_id' => $this->credentials[$symbol]->id,
                'sort_order' => $order,
            ])->execute();
        }
        $this->loginAs($this->admin);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        \Yii::$app->session->removeAllFlashes();
        parent::tearDown();
    }

    /**
     * Each entry that is no credential ID, as a JSON text ({A}: the ID of
     * credential A; 'above' and 'below': that ID followed by a fraction in
     * its 15th digit), on the create and on the update form.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noCredentialIdProvider(): array
    {
        $entries = [
            '2.9999999999999996, text "3"' => '2.9999999999999996',
            'the ID with a fraction in its 15th digit, just above it' => 'above',
            'the ID with a fraction in its 15th digit, just below it' => 'below',
            'true' => 'true',
            'the ID after a space' => '" {A}"',
            'the ID before a space' => '"{A} "',
        ];
        $cases = [];
        foreach (['create', 'update'] as $action) {
            foreach ($entries as $name => $entry) {
                $cases["{$action}: {$name}"] = [$action, $entry];
            }
        }

        return $cases;
    }

    /**
     * Regression: the form saved such an entry as credential A, #3 or #1.
     *
     * @dataProvider noCredentialIdProvider
     */
    public function testRegressionTheFormShowsTheErrorOfAnEntryThatIsNoCredentialId(string $action, string $entry): void
    {
        $ctrl = new class ('job-template', \Yii::$app) extends JobTemplateController {
            use CapturesRenderedForm;
        };
        $before = $this->stored($action);
        $auditBefore = (int)AuditLog::find()->max('id');
        $this->sendForm($action, $this->entry($entry));

        [$html, $problems] = $this->logged(fn (): mixed => $this->post($ctrl, $action));

        $this->assertSame('form', $ctrl->renderedView, 'the form is shown again');
        $model = $ctrl->renderedParams['model'] ?? null;
        $this->assertInstanceOf(JobTemplate::class, $model);
        $this->assertSame([JobTemplateCredentialService::INVALID_CREDENTIAL_IDS], $model->getErrors('credential_ids'));
        $this->assertIsString($html);
        $this->assertStringContainsString(
            'id="credential-ids-error">' . Html::encode(JobTemplateCredentialService::INVALID_CREDENTIAL_IDS) . '</div>',
            $html,
            'the page shows the error under the additional credentials'
        );
        $this->assertSame($before, $this->stored($action), 'nothing was written');
        $this->assertSame($auditBefore, (int)AuditLog::find()->max('id'), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Regression: the form refused "0<ID>", which the integer rule reads
     * as the ID. It is that credential, on a create and an update.
     */
    public function testRegressionAnIdWithALeadingZeroIsThatCredential(): void
    {
        $a = (int)$this->credentials['A']->id;
        $b = (int)$this->credentials['B']->id;
        $ctrl = new class ('job-template', \Yii::$app) extends JobTemplateController {
            use CapturesRenderedForm;
        };
        $this->sendForm('create', '"0' . $a . '"');

        $this->post($ctrl, 'create');

        $this->assertNull($ctrl->renderedView, 'the form is not shown again');
        $created = JobTemplate::findOne(['name' => self::CREATED]);
        $this->assertNotNull($created);
        $this->assertSame([$a], array_column($created->credentialSnapshot(), 'id'));

        $ctrl = new class ('job-template', \Yii::$app) extends JobTemplateController {
            use CapturesRenderedForm;
        };
        $this->sendForm('update', '"0' . $b . '"');
        $this->post($ctrl, 'update');

        $this->assertNull($ctrl->renderedView, 'the form is not shown again');
        $this->assertSame([(int)$this->credentials['P']->id, $b], array_column($this->fresh()->credentialSnapshot(), 'id'));
    }

    /**
     * Regression: the lock read the stored credentials of a gated template,
     * sent with the first one followed by a fraction in its 15th digit, as
     * the stored ones, and the form saved them. It is a change of the
     * credentials, which the lock refuses with 403 for a user without
     * approval-rule.update; nothing is written.
     */
    public function testRegressionTheLockRefusesAnEntryThatIsNoCredentialId(): void
    {
        $this->gate();
        $before = $this->stored('update');
        $auditBefore = (int)AuditLog::find()->max('id');
        $this->loginAs($this->operator);
        $ctrl = new class ('job-template', \Yii::$app) extends JobTemplateController {
            use CapturesRenderedForm;
        };
        $this->sendForm('update', $this->entry('above') . ', ' . $this->credentials['B']->id);

        try {
            $this->post($ctrl, 'update');
            $this->fail('the lock refuses the change');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED, $e->getMessage());
        }
        $this->assertSame($before, $this->stored('update'), 'nothing was written');
        $this->assertSame($auditBefore, (int)AuditLog::find()->max('id'), 'nothing was audited');
    }

    /**
     * Regression: the lock refused the stored credentials of a gated
     * template sent as "0<ID>" with 403. They are no change: the rename is
     * saved, the credentials stay.
     */
    public function testRegressionTheLockTakesTheStoredIdsWithALeadingZero(): void
    {
        $this->gate();
        $this->loginAs($this->operator);
        $ctrl = new class ('job-template', \Yii::$app) extends JobTemplateController {
            use CapturesRenderedForm;
        };
        $this->sendForm('update', '"0' . $this->credentials['A']->id . '", "0' . $this->credentials['B']->id . '"');

        $this->post($ctrl, 'update');

        $this->assertNull($ctrl->renderedView, 'the form is not shown again');
        $fresh = $this->fresh();
        $this->assertSame('credential-ids-form-renamed', $fresh->name);
        $this->assertSame(
            [(int)$this->credentials['P']->id, (int)$this->credentials['A']->id, (int)$this->credentials['B']->id],
            array_column($fresh->credentialSnapshot(), 'id')
        );
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * The JSON text of an entry of noCredentialIdProvider().
     */
    private function entry(string $entry): string
    {
        $a = (int)$this->credentials['A']->id;
        $text = match ($entry) {
            'above' => $a . '.' . str_repeat('0', 14 - strlen((string)$a)) . '1',
            'below' => ($a - 1) . '.' . str_repeat('9', 15 - strlen((string)($a - 1))),
            default => str_replace('{A}', (string)$a, $entry),
        };
        if (in_array($entry, ['above', 'below'], true)) {
            $this->assertSame((string)$a, (string)json_decode($text), 'precondition: PHP writes it as the ID');
        }

        return $text;
    }

    /**
     * What $action would write: the templates of the create's name, or
     * every column of the template and its credentials.
     */
    private function stored(string $action): mixed
    {
        if ($action === 'create') {
            return (new Query())->from(JobTemplate::tableName())->where(['name' => self::CREATED])->count();
        }

        return [
            (new Query())->from(JobTemplate::tableName())->where(['id' => $this->template->id])->one(),
            array_column($this->fresh()->credentialSnapshot(), 'id'),
        ];
    }

    /**
     * Posts the create form, or the edit form of the template renamed, as
     * JSON with the additional credentials $entries, JSON texts.
     */
    private function sendForm(string $action, string $entries): void
    {
        $fields = $action === 'create'
            ? [
                'name' => self::CREATED,
                'project_id' => (int)$this->project->id,
                'inventory_id' => (int)$this->inventory->id,
                'playbook' => 'site.yml',
                'runner_group_id' => (int)$this->group->id,
            ]
            : ['name' => 'credential-ids-form-renamed'];
        $posted = (string)json_encode(['JobTemplate' => $fields, 'credential_ids' => '@entries@']);
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->parsers = ['application/json' => JsonParser::class];
        $request->getHeaders()->set('Content-Type', 'application/json');
        $request->setRawBody(str_replace('"@entries@"', '[' . $entries . ']', $posted));
        $request->setBodyParams(null);
        $this->assertIsArray($request->getBodyParams(), 'the parser read the body');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        \Yii::$app->response->statusCode = 200;
    }

    /**
     * Runs the create or update action of $ctrl with its real views.
     */
    private function post(JobTemplateController $ctrl, string $action): mixed
    {
        return $this->withRealViews(
            $ctrl,
            "/job-template/{$action}",
            fn (): mixed => $action === 'create' ? $ctrl->actionCreate() : $ctrl->actionUpdate((int)$this->template->id)
        );
    }

    /** Gives the template the approval rule: only admins change what it runs. */
    private function gate(): void
    {
        JobTemplate::updateAll(['approval_rule_id' => $this->rule->id], ['id' => $this->template->id]);
    }

    private function fresh(): JobTemplate
    {
        $fresh = JobTemplate::findOne($this->template->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }
}
