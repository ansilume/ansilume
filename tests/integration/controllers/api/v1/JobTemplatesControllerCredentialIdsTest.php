<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
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
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * An entry of credential_ids, the additional credentials of POST and PUT
 * /api/v1/job-templates, is read as the integer rule reads an ID
 * (SubmittedId): an integer, its digits as text with a sign or leading
 * zeros ("05" is 5), or a number without a fraction. Anything else, a
 * fraction however close to a whole number, a boolean and text with a
 * space included, answers 422 JobTemplateCredentialService::INVALID_CREDENTIAL_IDS,
 * and nothing is written, audited or logged as an error. The lock of a
 * gated job template reads an entry the same way, so it compares the list
 * the save stores: for a user without approval-rule.update an entry that is
 * no ID is a change of the credentials (403
 * GatedJobTemplateLock::CHANGE_REFUSED), and the stored IDs written another
 * way, such as "05", are none. The bodies are JSON texts, decoded as the
 * request's JSON parser decodes them (SendsJsonNumbers).
 *
 * Regression: the save (JobTemplateCredentialService::checkedIds()) and the
 * lock (GatedJobTemplateLock::changesCredentials()) read an entry with
 * filter_var(…, FILTER_VALIDATE_INT), which reads a float by its text of 14
 * significant digits, takes true for 1 and trims spaces: [2.9999999999999996]
 * named credential #3, [true] #1, an ID followed by a fraction in its 15th
 * digit, " 5" and "5 " that credential, and "05" was refused, by the save
 * with 422 and by the lock as a change.
 */
class JobTemplatesControllerCredentialIdsTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    /** The name of what a create would create. */
    private const CREATED = 'credential-ids-created';

    private User $admin;
    private User $operator;
    private Project $project;
    private Inventory $inventory;
    private RunnerGroup $group;
    private ApprovalRule $rule;

    /** @var array<string, Credential> the credentials of the templates: P (primary), A and B */
    private array $credentials = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('credential-ids-admin', 'admin');
        $this->operator = $this->createUserWithRole('credential-ids-operator', 'operator');
        $ownerId = (int)$this->admin->id;
        $this->project = $this->createProject($ownerId);
        $this->inventory = $this->createInventory($ownerId);
        $this->group = $this->createRunnerGroup($ownerId);
        $this->rule = $this->createApprovalRule($ownerId);
        foreach (['P', 'A', 'B'] as $symbol) {
            $this->credentials[$symbol] = $this->createCredential($ownerId);
        }
        $this->loginAs($this->admin);
    }

    /**
     * Entries that are no credential ID, as JSON texts: {A} stands for the
     * ID of credential A, 'above' and 'below' for that ID followed by a
     * fraction in its 15th digit (justAbove(), justBelow()), which PHP
     * writes as the ID.
     *
     * @return array<string, array{0: string}>
     */
    public static function noCredentialIdProvider(): array
    {
        return [
            '2.9999999999999996, text "3"' => ['2.9999999999999996'],
            'the ID with a fraction in its 15th digit, just above it' => ['above'],
            'the ID with a fraction in its 15th digit, just below it' => ['below'],
            'true' => ['true'],
            'the ID after a space' => ['" {A}"'],
            'the ID before a space' => ['"{A} "'],
        ];
    }

    /**
     * Regression: [2.9999999999999996] named credential #3, [true] #1, and
     * the other entries credential A, which the create attached (201).
     *
     * @dataProvider noCredentialIdProvider
     */
    public function testRegressionACreateWithAnEntryThatIsNoCredentialIdIs422(string $entry): void
    {
        $audits = $this->templateAuditCount();
        $this->sendJsonText($this->json($this->createFields(), $this->entry($entry)));

        [$result, $problems] = $this->logged(fn (): array => $this->api()->actionCreate());

        $this->assertSame(
            [422, ['error' => ['message' => JobTemplateCredentialService::INVALID_CREDENTIAL_IDS]]],
            [\Yii::$app->response->statusCode, $result]
        );
        $this->assertFalse(JobTemplate::find()->where(['name' => self::CREATED])->exists(), 'no template was created');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Regression: such an entry changed the additional credentials of the
     * template to credential A, #3 or #1 (200).
     *
     * @dataProvider noCredentialIdProvider
     */
    public function testRegressionAnUpdateWithAnEntryThatIsNoCredentialIdIs422AndWritesNothing(string $entry): void
    {
        $template = $this->template(null);
        $before = $this->snapshot($template);
        $audits = $this->templateAuditCount();
        $this->sendJsonText($this->json(['name' => 'credential-ids-renamed'], $this->entry($entry)), 'PUT');

        [$result, $problems] = $this->logged(fn (): array => $this->api()->actionUpdate((int)$template->id));

        $this->assertSame(
            [422, ['error' => ['message' => JobTemplateCredentialService::INVALID_CREDENTIAL_IDS]]],
            [\Yii::$app->response->statusCode, $result]
        );
        $this->assertSame($before, $this->snapshot($template), 'not even the name was written');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Regression: "0<ID>", which the integer rule reads as the ID, was
     * refused with 422. It is that credential, on a create and an update.
     */
    public function testRegressionAnIdWithALeadingZeroIsThatCredential(): void
    {
        $a = (int)$this->credentials['A']->id;
        $b = (int)$this->credentials['B']->id;
        $this->sendJsonText($this->json($this->createFields(), '"0' . $a . '"'));

        $created = $this->api()->actionCreate();

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($created));
        $this->assertSame([$a], $this->data($created)['credential_ids']);
        $template = JobTemplate::findOne(['name' => self::CREATED]);
        $this->assertNotNull($template);
        $this->assertSame([$a], array_column($template->credentialSnapshot(), 'id'), 'the credential is stored');

        $this->sendJsonText($this->json(['name' => self::CREATED], '"00' . $b . '", "0' . $a . '"'), 'PUT');
        $updated = $this->api()->actionUpdate((int)$template->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($updated));
        $this->assertSame([$b, $a], $this->data($updated)['credential_ids']);
    }

    /**
     * Regression: the lock read the stored credentials written as such an
     * entry as the stored ones, no change, and the save stored them: 200.
     * As the save refuses such an entry, the lock counts it as a change of
     * the credentials, for a user without approval-rule.update: 403 with
     * GatedJobTemplateLock::CHANGE_REFUSED, before the save's 422, and
     * nothing is written.
     *
     * @dataProvider noCredentialIdProvider
     */
    public function testRegressionTheLockCountsAnEntryThatIsNoCredentialIdAsAChange(string $entry): void
    {
        $template = $this->template((int)$this->rule->id);
        $before = $this->snapshot($template);
        $audits = $this->templateAuditCount();
        $this->loginAs($this->operator);
        $this->sendJsonText($this->json(['name' => 'credential-ids-renamed'], $this->entry($entry) . ', ' . $this->credentials['B']->id), 'PUT');

        [$result, $problems] = $this->logged(fn (): array => $this->api()->actionUpdate((int)$template->id));

        $this->assertSame(
            [403, ['error' => ['message' => GatedJobTemplateLock::CHANGE_REFUSED]]],
            [\Yii::$app->response->statusCode, $result]
        );
        $this->assertSame($before, $this->snapshot($template), 'not even the name was written');
        $this->assertSame($audits, $this->templateAuditCount(), 'nothing was audited');
        $this->assertSame([], $problems, 'nothing was logged as a failure');
    }

    /**
     * Regression: the lock read "0<ID>" as no credential ID, a change, and
     * refused the stored credentials written so (403). They are the stored
     * ones, no change: the rename is saved, the credentials stay.
     */
    public function testRegressionTheLockReadsTheStoredIdsWithALeadingZeroAsNoChange(): void
    {
        $template = $this->template((int)$this->rule->id);
        $before = $this->runs($template);
        $this->loginAs($this->operator);
        $name = 'credential-ids-renamed';
        $this->sendJsonText($this->json(['name' => $name], '"0' . $this->credentials['A']->id . '", "0' . $this->credentials['B']->id . '"'), 'PUT');

        $result = $this->api()->actionUpdate((int)$template->id);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame($name, $this->data($result)['name']);
        $this->assertSame($before, $this->runs($template), 'what the template runs stays as stored');
        $this->assertSame($name, $this->fresh($template)->name);
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * The JSON text of $entry of noCredentialIdProvider().
     */
    private function entry(string $entry): string
    {
        $a = (int)$this->credentials['A']->id;
        $text = match ($entry) {
            'above' => self::justAbove($a),
            'below' => self::justBelow($a),
            default => str_replace('{A}', (string)$a, $entry),
        };
        if (in_array($entry, ['above', 'below'], true)) {
            $this->assertSame((string)$a, (string)json_decode($text), 'precondition: PHP writes it as the ID');
        }

        return $text;
    }

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
    private function createFields(): array
    {
        return [
            'name' => self::CREATED,
            'project_id' => (int)$this->project->id,
            'inventory_id' => (int)$this->inventory->id,
            'playbook' => 'site.yml',
            'runner_group_id' => (int)$this->group->id,
        ];
    }

    /**
     * The JSON text of $fields with credential_ids holding the JSON texts
     * $entries as written, which json_encode() would write another way.
     *
     * @param array<string, mixed> $fields
     */
    private function json(array $fields, string $entries): string
    {
        $fields['credential_ids'] = '@entries@';

        return str_replace('"@entries@"', '[' . $entries . ']', (string)json_encode($fields));
    }

    /**
     * A template with the credentials P (primary), A and B, gated by $ruleId
     * unless it is null.
     */
    private function template(?int $ruleId): JobTemplate
    {
        $ownerId = (int)$this->admin->id;
        $template = $this->createJobTemplate((int)$this->project->id, (int)$this->inventory->id, (int)$this->group->id, $ownerId);
        JobTemplate::updateAll(['approval_rule_id' => $ruleId, 'credential_id' => $this->credentials['P']->id], ['id' => $template->id]);
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
     * Every column of the template as stored, and its credentials in order.
     *
     * @return array<string, mixed>
     */
    private function snapshot(JobTemplate $template): array
    {
        $row = JobTemplate::find()->where(['id' => $template->id])->asArray()->one();
        $this->assertIsArray($row);

        return $row + ['credentials' => array_column($this->fresh($template)->credentialSnapshot(), 'id')];
    }

    /**
     * What the template runs: the snapshot without its name, its
     * description and the time it was changed.
     *
     * @return array<string, mixed>
     */
    private function runs(JobTemplate $template): array
    {
        return array_diff_key($this->snapshot($template), array_flip(['name', 'description', 'updated_at']));
    }

    private function fresh(JobTemplate $template): JobTemplate
    {
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function templateAuditCount(): int
    {
        return (int)AuditLog::find()
            ->where(['action' => [AuditLog::ACTION_TEMPLATE_CREATED, AuditLog::ACTION_TEMPLATE_UPDATED]])
            ->count();
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

    private function api(): JobTemplatesController
    {
        $this->assertNotNull(\Yii::$app);
        \Yii::$app->response->statusCode = 200;

        return new JobTemplatesController('api/v1/job-templates', \Yii::$app);
    }
}
