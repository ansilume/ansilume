<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\BaseController;
use app\controllers\InventoryController;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\TeamProject;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The project and the approval rule of the web inventory form, posted as
 * JSON by a signed-in user (the request's JSON parser serves the web forms
 * too, so the values are the numbers, booleans and lists of the body).
 *
 * A project that is no project ID, such as a JSON number beyond the int
 * range, a fraction, text, a boolean or a list, is refused by the model's
 * integer rule before the access check reads it: the form is shown again
 * with the field's error, and nothing is saved, audited or logged, also for
 * a project of another team. A project ID, in every form the rule accepts,
 * is checked and saved as before. An approval rule beyond the int range,
 * from an admin, gets the rule's error the same way.
 *
 * Regression: on PHP 8.5 the (int) cast of the access check
 * (TeamScopingTrait::normalizeProjectId()) and the type cast of the gated
 * inventory lock's comparison (GatedInventoryLock::sameValue()) warned for
 * such a number ("The float 1.0E+30 is not representable as an int, cast
 * occurred"), the error handler turned the warning into an exception, and
 * the page answered 500. Up to PHP 8.4 the cast took a fraction as the
 * project of its whole part, and text, false and 1e999 as project 0, which
 * every user may reach.
 */
class InventoryControllerNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private const NOT_A_PROJECT_ID = 'Project Id must be an integer.';

    private const NOT_A_RULE_ID = 'Approval rule must be an integer.';

    private const CONTENT = "all:\n  hosts:\n    web1.example.com:\n";

    /** @var array{member: User, own: int, foreign: int, inventory: Inventory} */
    private array $scope;

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
        $this->scope = $this->scope();
        $this->loginAs($this->scope['member']);
    }

    protected function tearDown(): void
    {
        \Yii::$app->session->removeAllFlashes();
        parent::tearDown();
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testACreateWithAProjectBeyondTheIntRangeShowsTheFormWithTheError(string $number): void
    {
        $auditBefore = $this->lastAuditId();
        $body = $this->sendJsonText($this->createBody($number));
        $this->assertIsFloat($body['Inventory']['project_id'], 'the parser gives a float');

        [$ctrl, $problems] = $this->posted(static fn (InventoryController $ctrl): mixed => $ctrl->actionCreate());

        $this->assertShownWithError($ctrl, 'project_id', self::NOT_A_PROJECT_ID);
        $this->assertFalse(Inventory::find()->where(['name' => 'numbers-web-created'])->exists(), 'nothing was created');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnUpdateWithAProjectBeyondTheIntRangeShowsTheFormWithTheError(string $number): void
    {
        $before = $this->storedRow();
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"Inventory": {"name": "numbers-web-renamed", "project_id": ' . $number . '}}');

        [$ctrl, $problems] = $this->posted(fn (InventoryController $ctrl): mixed => $ctrl->actionUpdate((int)$this->scope['inventory']->id));

        $this->assertShownWithError($ctrl, 'project_id', self::NOT_A_PROJECT_ID);
        $this->assertSame($before, $this->storedRow(), 'not even the name was saved');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Other values that are no project ID; %d is the ID of another team's
     * project. The check never sees them, so the answer says nothing about
     * that project either.
     *
     * @return array<string, array{0: string}>
     */
    public static function noProjectIdProvider(): array
    {
        return [
            'a fraction' => ['%d.5'],
            'decimal text' => ['"%d.0"'],
            'digits and text' => ['"%dabc"'],
            'text' => ['"abc"'],
            'true' => ['true'],
            'false' => ['false'],
            'a list of the ID' => ['[%d]'],
        ];
    }

    /**
     * @dataProvider noProjectIdProvider
     */
    public function testAnUpdateWithAValueThatIsNoProjectIdShowsTheFormWithTheError(string $value): void
    {
        $before = $this->storedRow();
        $this->sendJsonText('{"Inventory": {"project_id": ' . sprintf($value, $this->scope['foreign']) . '}}');

        [$ctrl] = $this->posted(fn (InventoryController $ctrl): mixed => $ctrl->actionUpdate((int)$this->scope['inventory']->id));

        $this->assertShownWithError($ctrl, 'project_id', self::NOT_A_PROJECT_ID);
        $this->assertSame($before, $this->storedRow());
    }

    /**
     * Every form of a project ID that the integer rule accepts is checked
     * as that project: another team's is still 403, and nothing is saved.
     *
     * @return array<string, array{0: string}>
     */
    public static function idFormProvider(): array
    {
        return [
            'the ID' => ['%d'],
            'the ID as digits' => ['"%d"'],
            'a leading zero' => ['"0%d"'],
            'a whole float' => ['%d.0'],
        ];
    }

    /**
     * @dataProvider idFormProvider
     */
    public function testAProjectOfAnotherTeamIsStillForbiddenInEveryForm(string $form): void
    {
        $before = $this->storedRow();
        $this->sendJsonText('{"Inventory": {"project_id": ' . sprintf($form, $this->scope['foreign']) . '}}');

        try {
            $this->posted(fn (InventoryController $ctrl): mixed => $ctrl->actionUpdate((int)$this->scope['inventory']->id));
            $this->fail('the project is another team\'s');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(BaseController::MODIFY_FORBIDDEN, $e->getMessage());
        }
        $this->assertSame($before, $this->storedRow());
    }

    /**
     * @dataProvider idFormProvider
     */
    public function testAProjectTheUserOperatesIsSavedInEveryForm(string $form): void
    {
        $other = (int)$this->createProject((int)$this->scope['member']->id)->id;
        $this->sendJsonText('{"Inventory": {"project_id": ' . sprintf($form, $other) . '}}');

        [$ctrl] = $this->posted(fn (InventoryController $ctrl): mixed => $ctrl->actionUpdate((int)$this->scope['inventory']->id));

        $this->assertSame(['view', 'id' => $this->scope['inventory']->id], $ctrl->capturedRedirect);
        $this->assertSame($other, Inventory::findOne($this->scope['inventory']->id)?->project_id);
    }

    /**
     * An empty value is no project, as the form's empty choice always was.
     */
    public function testAnEmptyProjectIsNoProject(): void
    {
        $this->sendJsonText('{"Inventory": {"project_id": ""}}');

        [$ctrl] = $this->posted(fn (InventoryController $ctrl): mixed => $ctrl->actionUpdate((int)$this->scope['inventory']->id));

        $this->assertSame(['view', 'id' => $this->scope['inventory']->id], $ctrl->capturedRedirect);
        $this->assertNull(Inventory::findOne($this->scope['inventory']->id)?->project_id);
    }

    /**
     * An admin's approval rule beyond the int range: the rule's error, and
     * nothing is saved (GatedInventoryLock compares it without a cast).
     *
     * @dataProvider beyondTheIntRangeProvider
     */
    public function testAnApprovalRuleBeyondTheIntRangeShowsTheFormWithTheError(string $number): void
    {
        $this->loginAs($this->createUserWithRole('numbers-web-inv-admin', 'admin'));
        $before = $this->storedRow();
        $auditBefore = $this->lastAuditId();
        $this->sendJsonText('{"Inventory": {"name": "numbers-web-ruled", "approval_rule_id": ' . $number . '}}');

        [$ctrl, $problems] = $this->posted(fn (InventoryController $ctrl): mixed => $ctrl->actionUpdate((int)$this->scope['inventory']->id));

        $this->assertShownWithError($ctrl, 'approval_rule_id', self::NOT_A_RULE_ID);
        $this->assertSame($before, $this->storedRow(), 'nothing was saved');
        $this->assertSame($auditBefore, $this->lastAuditId(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * A member of a team that operates "own"; "foreign" is another team's
     * project; "inventory" is a static inventory of "own".
     *
     * @return array{member: User, own: int, foreign: int, inventory: Inventory}
     */
    private function scope(): array
    {
        $admin = (int)$this->createUser('numbers-web-inv-owner')->id;
        $member = $this->createUserWithRole('numbers-web-inv-member', 'operator');
        $own = (int)$this->createProject($admin)->id;
        $foreign = (int)$this->createProject($admin)->id;
        $team = (int)$this->createTeam($admin)->id;
        $this->addTeamMember($team, (int)$member->id);
        $this->createTeamProject($team, $own, TeamProject::ROLE_OPERATOR);
        $this->createTeamProject((int)$this->createTeam($admin)->id, $foreign, TeamProject::ROLE_OPERATOR);
        $inventory = $this->createInventory($admin);
        $inventory->name = 'numbers-web-inventory';
        $inventory->content = self::CONTENT;
        $inventory->project_id = $own;
        $inventory->save(false);

        return ['member' => $member, 'own' => $own, 'foreign' => $foreign, 'inventory' => $inventory];
    }

    private function createBody(string $projectId): string
    {
        return '{"Inventory": {"name": "numbers-web-created", "inventory_type": "static", "content": '
            . (string)json_encode(self::CONTENT) . ', "project_id": ' . $projectId . '}}';
    }

    /**
     * Runs $action on a controller that captures what it renders and where
     * it redirects: the controller, and the errors and warnings logged.
     *
     * @param callable(InventoryController): mixed $action
     * @return array{0: InventoryController&object{capturedParams: array<string, mixed>, capturedView: string|null, capturedRedirect: mixed}, 1: list<string>}
     */
    private function posted(callable $action): array
    {
        $ctrl = new class ('inventory', \Yii::$app) extends InventoryController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];
            public ?string $capturedView = null;
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedView = $view;
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                return new Response();
            }
        };
        [, $problems] = $this->logged(static fn (): mixed => $action($ctrl));

        return [$ctrl, $problems];
    }

    /**
     * @param InventoryController&object{capturedParams: array<string, mixed>, capturedView: string|null} $ctrl
     */
    private function assertShownWithError(InventoryController $ctrl, string $attribute, string $message): void
    {
        $this->assertSame('form', $ctrl->capturedView, 'the form is shown again');
        $model = $ctrl->capturedParams['model'] ?? null;
        $this->assertInstanceOf(Inventory::class, $model);
        $this->assertSame([$message], $model->getErrors($attribute));
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(): array
    {
        $row = (new \yii\db\Query())->from(Inventory::tableName())->where(['id' => $this->scope['inventory']->id])->one();
        $this->assertIsArray($row);

        return $row;
    }

    private function lastAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }
}
