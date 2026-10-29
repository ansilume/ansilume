<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\JobTemplatesController;
use app\models\ApiToken;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\tests\integration\controllers\RuleIdSpellings;
use app\tests\integration\controllers\SendsJsonNumbers;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Regression: the approval_rule_id of POST and PUT /api/v1/job-templates
 * written as a whole number other than an int or its digits, such as 5.0,
 * 5e0, "+5" or "5\n" (RuleIdSpellings). The template was stored gated by
 * rule 5, but the audit and the check of who may assign a rule read the
 * value as no rule ID:
 * - an admin's create or update that gave a template the rule recorded no
 *   approval_rule in job-template.created or job-template.updated;
 * - an admin's change from rule A to such a spelling of rule B recorded
 *   {from: A, to: null}, a removal, and so did sending the stored rule
 *   again so;
 * - a user without approval-rule.update who sent the stored rule again so,
 *   with a new name, got 403 ASSIGN_REFUSED, although the API says that
 *   sending the stored value again is allowed for everyone.
 *
 * Now every spelling is the rule it stands for, wherever it is read. A
 * value that is no rule ID stays refused: 422 for an admin, 403 for anyone
 * else, nothing written.
 */
class JobTemplatesControllerRuleSpellingsTest extends WebControllerTestCase
{
    use RuleIdSpellings;
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private const NOT_A_RULE_ID = 'Approval rule must be an integer.';

    private User $admin;
    private ApprovalRule $rule;
    private ApprovalRule $otherRule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('rule-spelling-admin', 'admin');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->otherRule = $this->createApprovalRule((int)$this->admin->id);
    }

    /**
     * @dataProvider jsonSpellingProvider
     */
    public function testRegressionAnAdminsCreateRecordsTheRuleInEverySpelling(string $spelling): void
    {
        $this->authenticate($this->admin);
        $name = 'rule-spelling-created-' . uniqid('', true);
        $this->sendJsonText($this->jsonWithRule($this->createFields($name), sprintf($spelling, (int)$this->rule->id)));

        $result = $this->api()->runAction('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($result));
        $created = JobTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(JobTemplate::class, $created);
        $this->assertSame((int)$this->rule->id, $created->approval_rule_id, 'the template is gated by the rule');
        $this->assertSame((int)$this->rule->id, $this->data($result)['approval_rule_id']);
        $this->assertSame(
            ['from' => null, 'to' => $this->ruleEntry($this->rule)],
            $this->newestAuditContext(AuditLog::ACTION_TEMPLATE_CREATED, 'job_template', (int)$created->id)['approval_rule'] ?? null
        );
    }

    /**
     * @dataProvider jsonSpellingProvider
     */
    public function testRegressionAnAdminsUpdateRecordsTheRuleInEverySpelling(string $spelling): void
    {
        $this->authenticate($this->admin);
        $template = $this->template(null);
        $this->sendJsonText('{"approval_rule_id": ' . sprintf($spelling, (int)$this->rule->id) . '}', 'PUT');

        $result = $this->api()->runAction('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame((int)$this->rule->id, $this->stored($template)->approval_rule_id, 'the template is gated by the rule');
        $this->assertSame((int)$this->rule->id, $this->data($result)['approval_rule_id']);
        $this->assertSame(
            ['from' => null, 'to' => $this->ruleEntry($this->rule)],
            $this->newestAuditContext(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$template->id)['approval_rule'] ?? null
        );
    }

    /**
     * @dataProvider jsonSpellingProvider
     */
    public function testRegressionAnAdminsChangeOfTheRuleRecordsBothRules(string $spelling): void
    {
        $this->authenticate($this->admin);
        $template = $this->template($this->rule);
        $this->sendJsonText('{"approval_rule_id": ' . sprintf($spelling, (int)$this->otherRule->id) . '}', 'PUT');

        $result = $this->api()->runAction('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame((int)$this->otherRule->id, $this->stored($template)->approval_rule_id);
        $this->assertSame(
            ['from' => $this->ruleEntry($this->rule), 'to' => $this->ruleEntry($this->otherRule)],
            $this->newestAuditContext(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$template->id)['approval_rule'] ?? null,
            'a change, not a removal'
        );
    }

    /**
     * @dataProvider jsonSpellingProvider
     */
    public function testRegressionAnAdminWhoSendsTheStoredRuleAgainChangesNoRule(string $spelling): void
    {
        $this->authenticate($this->admin);
        $template = $this->template($this->rule);
        $newName = 'rule-spelling-admin-resent-' . uniqid('', true);
        $this->sendJsonText($this->jsonWithRule(['name' => $newName], sprintf($spelling, (int)$this->rule->id)), 'PUT');

        $result = $this->api()->runAction('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame($newName, $this->stored($template)->name);
        $this->assertSame((int)$this->rule->id, $this->stored($template)->approval_rule_id);
        $this->assertArrayNotHasKey(
            'approval_rule',
            $this->newestAuditContext(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$template->id),
            'no rule change'
        );
    }

    /**
     * Sending the stored value again is allowed for everyone, in every
     * spelling of the rule's ID: an operator renames a gated template.
     *
     * @dataProvider jsonSpellingProvider
     */
    public function testRegressionAnOperatorMaySendTheStoredRuleAgainInEverySpelling(string $spelling): void
    {
        $template = $this->template($this->rule);
        $this->authenticate($this->createUserWithRole('rule-spelling-operator', 'operator'));
        $newName = 'rule-spelling-resent-' . uniqid('', true);
        $this->sendJsonText($this->jsonWithRule(['name' => $newName], sprintf($spelling, (int)$this->rule->id)), 'PUT');

        $result = $this->api()->runAction('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($result));
        $this->assertSame($newName, $this->data($result)['name']);
        $this->assertSame($newName, $this->stored($template)->name);
        $this->assertSame((int)$this->rule->id, $this->stored($template)->approval_rule_id, 'the gate stays');
        $this->assertArrayNotHasKey(
            'approval_rule',
            $this->newestAuditContext(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$template->id),
            'no rule change'
        );
    }

    /**
     * Any other rule, in any spelling, stays a change that only admins make:
     * on a gated template, on an ungated one, and on a new one.
     *
     * @dataProvider jsonSpellingProvider
     */
    public function testAnOperatorsOtherRuleIsRefusedInEverySpelling(string $spelling): void
    {
        $gated = $this->template($this->rule);
        $ungated = $this->template(null);
        $rows = [$this->storedRow($gated), $this->storedRow($ungated)];
        $auditBefore = $this->newestAuditId();
        $this->authenticate($this->createUserWithRole('rule-spelling-refused', 'operator'));
        $otherRule = sprintf($spelling, (int)$this->otherRule->id);
        $name = 'rule-spelling-refused-' . uniqid('', true);

        foreach ([$gated, $ungated] as $template) {
            $this->sendJsonText($this->jsonWithRule(['name' => $name], $otherRule), 'PUT');
            $this->assertRefused($this->api()->runAction('update', ['id' => $template->id]));
        }
        $this->sendJsonText($this->jsonWithRule($this->createFields($name), $otherRule));
        $this->assertRefused($this->api()->runAction('create'));

        $this->assertSame($rows, [$this->storedRow($gated), $this->storedRow($ungated)], 'nothing was written');
        $this->assertFalse(JobTemplate::find()->where(['name' => $name])->exists(), 'nothing was created');
        $this->assertSame($auditBefore, $this->newestAuditId(), 'nothing was audited');
    }

    /**
     * A value that is no rule ID is still kept as it is, for the integer
     * rule to refuse: an admin's answers 422, and a gated template keeps its
     * rule; anyone else's is a rule change they may not make.
     *
     * @dataProvider noRuleIdProvider
     */
    public function testAValueThatIsNoRuleIdIsStillRefused(string $value): void
    {
        $template = $this->template($this->rule);
        $row = $this->storedRow($template);
        $auditBefore = $this->newestAuditId();
        $body = $this->jsonWithRule(['name' => 'rule-spelling-invalid'], sprintf($value, (int)$this->rule->id));

        $this->authenticate($this->admin);
        $this->sendJsonText($body, 'PUT');
        $this->assertSame(['error' => ['message' => self::NOT_A_RULE_ID]], $this->api()->runAction('update', ['id' => $template->id]));
        $this->assertSame(422, \Yii::$app->response->statusCode);

        $this->authenticate($this->createUserWithRole('rule-spelling-invalid', 'operator'));
        $this->sendJsonText($body, 'PUT');
        $this->assertRefused($this->api()->runAction('update', ['id' => $template->id]));

        $this->assertSame($row, $this->storedRow($template), 'nothing was written');
        $this->assertSame($auditBefore, $this->newestAuditId(), 'nothing was audited');
    }

    /**
     * The spec states what these tests show: the stored rule sent again, in
     * the spellings it names, is allowed for everyone and no change; the
     * integer fields read each spelling as its number.
     */
    public function testTheSpecSaysEverySpellingOfTheStoredRuleIsNoChange(): void
    {
        $this->assertStringContainsString(
            'Sending the stored value again, as an integer, its digits or a number without a fraction (`3`, `"3"`, `"+3"`,'
            . ' `"03"` or `3.0` for rule 3), or leaving the field out, is allowed for everyone and changes no rule.',
            $this->ruleFieldSpecText('JobTemplateInput')
        );
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * @param mixed $result what the action returned
     */
    private function assertRefused(mixed $result): void
    {
        $this->assertSame(['error' => ['message' => ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED]], $result);
        $this->assertSame(403, \Yii::$app->response->statusCode);
    }

    /**
     * A template of the admin's, gated by $rule unless it is null.
     */
    private function template(?ApprovalRule $rule): JobTemplate
    {
        $ownerId = (int)$this->admin->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
        JobTemplate::updateAll(['approval_rule_id' => $rule?->id], ['id' => $template->id]);

        return $this->stored($template);
    }

    /**
     * The fields of a valid create body.
     *
     * @return array<string, mixed>
     */
    private function createFields(string $name): array
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

    private function stored(JobTemplate $template): JobTemplate
    {
        $stored = JobTemplate::findOne($template->id);
        $this->assertInstanceOf(JobTemplate::class, $stored);

        return $stored;
    }

    /**
     * The template's row as stored, for "nothing was written".
     *
     * @return array<string, mixed>
     */
    private function storedRow(JobTemplate $template): array
    {
        $row = (new \yii\db\Query())->from(JobTemplate::tableName())->where(['id' => $template->id])->one();
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * @param mixed $result what the action returned
     * @return array<string, mixed>
     */
    private function data(mixed $result): array
    {
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result, (string)json_encode($result));
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'rule-spelling-test');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        \Yii::$app->response->statusCode = 200;
    }

    private function api(): JobTemplatesController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new JobTemplatesController('api/v1/job-templates', $app);
    }
}
