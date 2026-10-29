<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobTemplateController;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\User;
use app\services\LintService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\db\Query;
use yii\helpers\Html;
use yii\web\JsonParser;
use yii\web\Request;

/**
 * The job template form posted as JSON (the request's JSON parser serves
 * the web forms too, config/web.php) with a whole-number field that holds a
 * float that is no whole number, but whose text at PHP's precision of 14
 * significant digits reads as one: forks 2.9999999999999996 (text "3"),
 * verbosity 4.999999999999999 ("5"), an inventory ID followed by a fraction
 * in its 15th digit. The form is shown again with the field's error, as for
 * any fraction, and nothing is written, audited or logged as a failure. The
 * views are the real ones, without the layout (RendersRealViews).
 *
 * Regression: Yii's integer rule matched its pattern against the float's
 * text, so it took such a float for the whole number of its text, and the
 * column's typecast saved its truncation: forks 2.9999999999999996 created
 * a template with 2 forks, and an inventory ID with a 1 in its 15th digit
 * created it on that inventory, or moved the edited one there.
 * WholeNumberValidator, the class of every integer rule, refuses it.
 */
class JobTemplateControllerNearWholeNumbersTest extends WebControllerTestCase
{
    use RendersRealViews;
    use RestoresComponents;
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    /** The name of what a create would create. */
    private const CREATED = 'near-whole-form-created';

    private User $admin;
    private Project $project;
    private Inventory $inventory;
    private Inventory $otherInventory;
    private RunnerGroup $group;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapComponent('lintService', new class () extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
        \Yii::$app->session->removeAllFlashes();
        $this->admin = $this->createUserWithRole('near-whole-form-admin', 'admin');
        $owner = (int)$this->admin->id;
        $this->project = $this->createProject($owner);
        $this->inventory = $this->createInventory($owner);
        $this->otherInventory = $this->createInventory($owner);
        $this->group = $this->createRunnerGroup($owner);
        $this->template = $this->createJobTemplate((int)$this->project->id, (int)$this->inventory->id, (int)$this->group->id, $owner);
        $this->loginAs($this->admin);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        \Yii::$app->session->removeAllFlashes();
        parent::tearDown();
    }

    /**
     * The fields, the JSON number (null: one 15th digit above the ID of the
     * other inventory) and the error the form shows.
     *
     * @return array<string, array{0: string, 1: string|null, 2: string, 3: string}>
     */
    public static function nearWholeNumberProvider(): array
    {
        $cases = [];
        foreach (['create', 'update'] as $action) {
            $cases["{$action}: forks 2.9999999999999996, text \"3\""] = [$action, 'forks', '2.9999999999999996', 'Forks must be an integer.'];
            $cases["{$action}: verbosity 4.999999999999999, text \"5\""] = [$action, 'verbosity', '4.999999999999999', 'Verbosity must be an integer.'];
            $cases["{$action}: the ID of another inventory and a fraction in its 15th digit"] = [$action, 'inventory_id', null, 'Inventory Id must be an integer.'];
        }

        return $cases;
    }

    /**
     * Regression: forks 2.9999999999999996 saved 2 forks; the inventory ID
     * with a fraction saved that inventory.
     *
     * @dataProvider nearWholeNumberProvider
     */
    public function testRegressionTheFormShowsTheErrorOfANumberWhoseTextReadsAsAWholeNumber(string $action, string $field, ?string $number, string $error): void
    {
        $number ??= self::justAbove((int)$this->otherInventory->id);
        $this->assertIsFloat(json_decode($number), 'precondition: the parser gives a float');
        $ctrl = new class ('job-template', \Yii::$app) extends JobTemplateController {
            use CapturesRenderedForm;
        };
        $stored = $action === 'create'
            ? static fn (): mixed => (new Query())->from(JobTemplate::tableName())->where(['name' => self::CREATED])->count()
            : fn (): mixed => (new Query())->from(JobTemplate::tableName())->where(['id' => $this->template->id])->one();
        $before = $stored();
        $auditBefore = (int)AuditLog::find()->max('id');
        $this->sendForm($action, $field, $number);

        [$html, $problems] = $this->logged(fn (): mixed => $this->withRealViews(
            $ctrl,
            "/job-template/{$action}",
            fn (): mixed => $action === 'create' ? $ctrl->actionCreate() : $ctrl->actionUpdate((int)$this->template->id)
        ));

        $this->assertSame('form', $ctrl->renderedView, 'the form is shown again');
        $model = $ctrl->renderedParams['model'] ?? null;
        $this->assertInstanceOf(JobTemplate::class, $model);
        $this->assertSame([$error], $model->getErrors($field));
        $this->assertIsString($html);
        $this->assertStringContainsString(Html::encode($error), $html, 'the page shows the error');
        $this->assertSame($before, $stored(), 'nothing was written');
        $this->assertSame($auditBefore, (int)AuditLog::find()->max('id'), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * The control: the same post with a whole number at the field is
     * taken, so the post reaches the field.
     */
    public function testTheSamePostWithAWholeNumberIsTaken(): void
    {
        $ctrl = new class ('job-template', \Yii::$app) extends JobTemplateController {
            use CapturesRenderedForm;
        };
        $this->sendForm('create', 'forks', '3');

        $this->withRealViews($ctrl, '/job-template/create', fn (): mixed => $ctrl->actionCreate());

        $this->assertNull($ctrl->renderedView, 'the form is not shown again');
        $this->assertSame('3', (string)(new Query())->select('forks')->from(JobTemplate::tableName())->where(['name' => self::CREATED])->scalar());
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * The JSON text of a float just above $id whose text at 14 significant
     * digits is $id: its 15th digit is a 1.
     */
    private static function justAbove(int $id): string
    {
        return $id . '.' . str_repeat('0', 14 - strlen((string)$id)) . '1';
    }

    /**
     * Posts the create form, or the edit form of the template renamed, as
     * JSON with $field set to the JSON number $number.
     */
    private function sendForm(string $action, string $field, string $number): void
    {
        $fields = $action === 'create'
            ? [
                'name' => self::CREATED,
                'project_id' => (int)$this->project->id,
                'inventory_id' => (int)$this->inventory->id,
                'playbook' => 'site.yml',
                'runner_group_id' => (int)$this->group->id,
            ]
            : ['name' => 'near-whole-form-renamed'];
        $fields[$field] = '@value@';
        $posted = ['JobTemplate' => $fields];
        if ($action === 'update') {
            // As the edit form posts: the stored additional credentials stay.
            $posted[JobTemplateController::KEEP_CREDENTIALS] = '1';
        }
        $request = \Yii::$app->request;
        $this->assertInstanceOf(Request::class, $request);
        $request->parsers = ['application/json' => JsonParser::class];
        $request->getHeaders()->set('Content-Type', 'application/json');
        $request->setRawBody(str_replace('"@value@"', $number, (string)json_encode($posted)));
        $request->setBodyParams(null);
        $this->assertIsArray($request->getBodyParams(), 'the parser read the body');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        \Yii::$app->response->statusCode = 200;
    }
}
