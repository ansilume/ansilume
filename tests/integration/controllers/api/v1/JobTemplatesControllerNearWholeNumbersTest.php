<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
use app\models\AuditLog;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\RunnerGroup;
use app\models\User;
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * A whole-number field of POST and PUT /api/v1/job-templates sent as a JSON
 * number that is no whole number, but whose text at PHP's precision of 14
 * significant digits reads as one: 4.999999999999999 (text "5"),
 * 2.9999999999999996 ("3"), an ID followed by a fraction in its 15th digit.
 * It is refused as a fraction is: 422 with the field's message, nothing
 * written, nothing audited, nothing logged as an error. The bodies are JSON
 * texts, decoded as the request's JSON parser decodes them
 * (SendsJsonNumbers).
 *
 * Regression: Yii's integer rule matched its pattern against the float's
 * text, so it took such a float for the whole number of its text, and the
 * column's typecast saved its truncation: "verbosity": 4.999999999999999
 * created a template at verbosity 4, and an inventory_id one 15th digit
 * above the ID of an inventory created the template on that inventory
 * (201), or moved it there (200). WholeNumberValidator, the class of every
 * integer rule, refuses it.
 */
class JobTemplatesControllerNearWholeNumbersTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    /** The name of what a create would create. */
    private const CREATED = 'near-whole-created';

    /** The integer fields of the body, with the message of each one's integer rule. */
    private const MESSAGES = [
        'project_id' => 'Project Id must be an integer.',
        'inventory_id' => 'Inventory Id must be an integer.',
        'verbosity' => 'Verbosity must be an integer.',
        'forks' => 'Forks must be an integer.',
        'timeout_minutes' => 'Timeout Minutes must be an integer.',
    ];

    private User $admin;
    private Project $project;
    private Inventory $inventory;
    private RunnerGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('near-whole-admin', 'admin');
        $ownerId = (int)$this->admin->id;
        $this->project = $this->createProject($ownerId);
        $this->inventory = $this->createInventory($ownerId);
        $this->group = $this->createRunnerGroup($ownerId);
        $this->loginAs($this->admin);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nearWholeNumberProvider(): array
    {
        return [
            'verbosity 4.999999999999999, text "5"' => ['verbosity', '4.999999999999999'],
            'verbosity 0.999999999999999, text "1"' => ['verbosity', '0.999999999999999'],
            'forks 2.9999999999999996, text "3"' => ['forks', '2.9999999999999996'],
            'forks -2.9999999999999996, text "-3"' => ['forks', '-2.9999999999999996'],
            'timeout_minutes 10.00000000000001, text "10"' => ['timeout_minutes', '10.00000000000001'],
            'project_id 4.999999999999999, text "5"' => ['project_id', '4.999999999999999'],
            'inventory_id 4.999999999999999, text "5"' => ['inventory_id', '4.999999999999999'],
        ];
    }

    /**
     * Regression: "verbosity": 4.999999999999999 created a template at
     * verbosity 4.
     *
     * @dataProvider nearWholeNumberProvider
     */
    public function testRegressionACreateWithANumberWhoseTextReadsAsAWholeNumberIs422(string $field, string $number): void
    {
        $audits = $this->templateAuditCount();
        $this->sendJsonText($this->json($this->createFields(self::CREATED), $field, $number));

        [$result, $problems] = $this->logged(fn (): array => $this->api()->actionCreate());

        $this->assertSame([422, ['error' => ['message' => self::MESSAGES[$field]]]], [\Yii::$app->response->statusCode, $result]);
        $this->assertFalse(JobTemplate::find()->where(['name' => self::CREATED])->exists(), 'no template was created');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * The ID of the fixture written with a fraction in its 15th digit, just
     * above it (text: the ID, truncation: the ID) or just below it (text:
     * the ID, truncation: the ID before it), and with a fraction that the
     * text shows (".5").
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function idProvider(): array
    {
        $cases = [];
        foreach (['project_id', 'inventory_id'] as $field) {
            foreach (['just above the ID', 'just below the ID'] as $where) {
                $cases["{$field}: {$where}"] = [$field, $where];
            }
        }

        return $cases;
    }

    /**
     * Regression: such an ID named the project or inventory of its text, or
     * of its truncation, and created the template there. It is refused as
     * a fraction of the ID is, which the text shows.
     *
     * @dataProvider idProvider
     */
    public function testRegressionAnIdWhoseTextReadsAsAnIdIsRefusedAsAFractionIs(string $field, string $where): void
    {
        $id = $field === 'project_id' ? (int)$this->project->id : (int)$this->inventory->id;
        $number = $where === 'just above the ID' ? self::justAbove($id) : self::justBelow($id);
        $this->assertSame((string)$id, (string)json_decode($number), 'precondition: its text reads as the ID');
        $audits = $this->templateAuditCount();

        $this->sendJsonText($this->json($this->createFields(self::CREATED), $field, $number));
        [$result, $problems] = $this->logged(fn (): array => $this->api()->actionCreate());
        $answer = [\Yii::$app->response->statusCode, $result];
        $this->sendJsonText($this->json($this->createFields(self::CREATED), $field, $id . '.5'));
        $fraction = [\Yii::$app->response->statusCode, $this->api()->actionCreate()];

        $this->assertSame([422, ['error' => ['message' => self::MESSAGES[$field]]]], $answer);
        $this->assertSame($fraction, $answer, 'the answer of a fraction the text shows');
        $this->assertFalse(JobTemplate::find()->where(['name' => self::CREATED])->exists(), 'no template was created');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');

        $this->sendJsonText($this->json($this->createFields(self::CREATED), $field, (string)$id));
        $this->api()->actionCreate();
        $this->assertSame(201, \Yii::$app->response->statusCode, 'control: the body with the ID is taken');
    }

    /**
     * Regression: PUT with an inventory_id one 15th digit above the ID of
     * another inventory moved the template to that inventory (200). It is
     * refused now, and the template stays as stored.
     */
    public function testRegressionAnUpdateDoesNotMoveTheTemplateToTheInventoryOfTheText(): void
    {
        $template = $this->createJobTemplate((int)$this->project->id, (int)$this->inventory->id, (int)$this->group->id, (int)$this->admin->id);
        $other = $this->createInventory((int)$this->admin->id);
        $before = $this->row((int)$template->id);
        $audits = $this->templateAuditCount();
        $this->sendJsonText($this->json(['name' => 'near-whole-renamed'], 'inventory_id', self::justAbove((int)$other->id)), 'PUT');

        [$result, $problems] = $this->logged(fn (): array => $this->api()->actionUpdate((int)$template->id));

        $this->assertSame([422, ['error' => ['message' => self::MESSAGES['inventory_id']]]], [\Yii::$app->response->statusCode, $result]);
        $this->assertSame($before, $this->row((int)$template->id), 'not even the name was written');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
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
     * The JSON text of a float just below $id whose text at 14 significant
     * digits is $id: the ID before it, then nines up to the 15th digit.
     */
    private static function justBelow(int $id): string
    {
        $before = $id - 1;

        return $before . '.' . str_repeat('9', 15 - strlen((string)$before));
    }

    /**
     * The fields of a valid create body.
     *
     * @return array<string, mixed>
     */
    private function createFields(string $name): array
    {
        return [
            'name' => $name,
            'project_id' => (int)$this->project->id,
            'inventory_id' => (int)$this->inventory->id,
            'playbook' => 'site.yml',
            'runner_group_id' => (int)$this->group->id,
        ];
    }

    /**
     * The JSON text of $fields with $field set to the JSON number $value as
     * written, which json_encode() would write another way.
     *
     * @param array<string, mixed> $fields
     */
    private function json(array $fields, string $field, string $value): string
    {
        $fields[$field] = '@value@';

        return str_replace('"@value@"', $value, (string)json_encode($fields));
    }

    /**
     * Every column of the template as stored.
     *
     * @return array<string, mixed>
     */
    private function row(int $templateId): array
    {
        $row = JobTemplate::find()->where(['id' => $templateId])->asArray()->one();
        $this->assertIsArray($row);

        return $row;
    }

    private function templateAuditCount(): int
    {
        return (int)AuditLog::find()
            ->where(['action' => [AuditLog::ACTION_TEMPLATE_CREATED, AuditLog::ACTION_TEMPLATE_UPDATED]])
            ->count();
    }

    private function api(): JobTemplatesController
    {
        $this->assertNotNull(\Yii::$app);
        \Yii::$app->response->statusCode = 200;

        return new JobTemplatesController('api/v1/job-templates', \Yii::$app);
    }
}
