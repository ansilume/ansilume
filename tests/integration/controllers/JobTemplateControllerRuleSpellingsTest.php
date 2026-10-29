<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobTemplateController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\LintService;
use app\tests\integration\RestoresComponents;
use app\tests\integration\TeamScopeFixtures;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Regression: the job template form's JobTemplate[approval_rule_id] posted
 * as "+5" or "5\n" (url-encoded), or as 5.0 by a form posted as JSON
 * (RuleIdSpellings). The template was saved gated by rule 5, but
 * job-template.created and job-template.updated recorded no approval_rule,
 * or a change from rule A to rule B as {from: A, to: null}; and a user
 * without approval-rule.update who posted the stored rule so with a new
 * name was refused with 403 ASSIGN_REFUSED. Now each spelling is the rule
 * it stands for, for the check, the save and the audit alike.
 */
class JobTemplateControllerRuleSpellingsTest extends WebControllerTestCase
{
    use RestoresComponents;
    use RuleIdSpellings;
    use TeamScopeFixtures;

    private User $admin;
    private ApprovalRule $rule;
    private ApprovalRule $otherRule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swapComponent('lintService', new class () extends LintService {
            public function runForTemplate(JobTemplate $template): void
            {
            }
        });
        $this->admin = $this->createUserWithRole('form-spelling-admin', 'admin');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->otherRule = $this->createApprovalRule((int)$this->admin->id);
    }

    protected function tearDown(): void
    {
        $this->restoreComponents();
        parent::tearDown();
    }

    /**
     * @dataProvider formSpellingProvider
     */
    public function testRegressionAnAdminsNewTemplateRecordsTheRuleInEverySpelling(string $spelling): void
    {
        $this->loginAs($this->admin);
        $name = 'form-spelling-created-' . uniqid('', true);
        $this->setPost(['JobTemplate' => $this->formFields($name) + ['approval_rule_id' => sprintf($spelling, (int)$this->rule->id)]]);

        $this->assertSaved($this->makeController()->actionCreate());

        $created = JobTemplate::findOne(['name' => $name]);
        $this->assertInstanceOf(JobTemplate::class, $created);
        $this->assertSame((int)$this->rule->id, $created->approval_rule_id);
        $this->assertSame(
            ['from' => null, 'to' => $this->ruleEntry($this->rule)],
            $this->newestAuditContext(AuditLog::ACTION_TEMPLATE_CREATED, 'job_template', (int)$created->id)['approval_rule'] ?? null
        );
    }

    /**
     * @dataProvider formSpellingProvider
     */
    public function testRegressionAnAdminsEditRecordsTheRuleInEverySpelling(string $spelling): void
    {
        $this->loginAs($this->admin);
        $template = $this->template(null);
        $this->setPost(['JobTemplate' => ['approval_rule_id' => sprintf($spelling, (int)$this->rule->id)]]);

        $this->assertSaved($this->makeController()->actionUpdate((int)$template->id));

        $this->assertSame((int)$this->rule->id, $this->stored($template)->approval_rule_id);
        $this->assertSame(
            ['from' => null, 'to' => $this->ruleEntry($this->rule)],
            $this->newestAuditContext(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$template->id)['approval_rule'] ?? null
        );
    }

    /**
     * @dataProvider formSpellingProvider
     */
    public function testRegressionAnAdminsChangeOfTheRuleRecordsBothRules(string $spelling): void
    {
        $this->loginAs($this->admin);
        $template = $this->template($this->rule);
        $this->setPost(['JobTemplate' => ['approval_rule_id' => sprintf($spelling, (int)$this->otherRule->id)]]);

        $this->assertSaved($this->makeController()->actionUpdate((int)$template->id));

        $this->assertSame((int)$this->otherRule->id, $this->stored($template)->approval_rule_id);
        $this->assertSame(
            ['from' => $this->ruleEntry($this->rule), 'to' => $this->ruleEntry($this->otherRule)],
            $this->newestAuditContext(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$template->id)['approval_rule'] ?? null,
            'a change, not a removal'
        );
    }

    /**
     * @dataProvider formSpellingProvider
     */
    public function testRegressionAnOperatorMayPostTheStoredRuleAgainInEverySpelling(string $spelling): void
    {
        $template = $this->template($this->rule);
        $this->loginAs($this->createUserWithRole('form-spelling-operator', 'operator'));
        $newName = 'form-spelling-resent-' . uniqid('', true);
        $this->setPost(['JobTemplate' => ['name' => $newName, 'approval_rule_id' => sprintf($spelling, (int)$this->rule->id)]]);

        $this->assertSaved($this->makeController()->actionUpdate((int)$template->id));

        $this->assertSame($newName, $this->stored($template)->name);
        $this->assertSame((int)$this->rule->id, $this->stored($template)->approval_rule_id, 'the gate stays');
        $this->assertArrayNotHasKey(
            'approval_rule',
            $this->newestAuditContext(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$template->id),
            'no rule change'
        );
    }

    /**
     * @dataProvider formSpellingProvider
     */
    public function testAnOperatorsOtherRuleIsRefusedInEverySpelling(string $spelling): void
    {
        $template = $this->template($this->rule);
        $name = $template->name;
        $this->loginAs($this->createUserWithRole('form-spelling-refused', 'operator'));
        $this->setPost(['JobTemplate' => ['name' => 'form-spelling-renamed', 'approval_rule_id' => sprintf($spelling, (int)$this->otherRule->id)]]);

        try {
            $this->makeController()->actionUpdate((int)$template->id);
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, $e->getMessage());
        }

        $this->assertSame((int)$this->rule->id, $this->stored($template)->approval_rule_id, 'the gate stays');
        $this->assertSame($name, $this->stored($template)->name, 'nothing is saved');
    }

    /**
     * A form posted as JSON (with the CSRF header) gives 5.0 as a float,
     * which the integer rule reads as 5: an admin's assigns rule 5 and is
     * audited so, an operator's sends the stored rule again.
     */
    public function testRegressionAFormPostedAsJsonReadsAWholeFloatAsTheRule(): void
    {
        $this->loginAs($this->admin);
        $template = $this->template(null);
        $this->setPost(['JobTemplate' => ['approval_rule_id' => (float)$this->rule->id]]);
        $this->assertSaved($this->makeController()->actionUpdate((int)$template->id));
        $this->assertSame(
            ['from' => null, 'to' => $this->ruleEntry($this->rule)],
            $this->newestAuditContext(AuditLog::ACTION_TEMPLATE_UPDATED, 'job_template', (int)$template->id)['approval_rule'] ?? null
        );

        $this->loginAs($this->createUserWithRole('form-spelling-json', 'operator'));
        $newName = 'form-spelling-json-' . uniqid('', true);
        $this->setPost(['JobTemplate' => ['name' => $newName, 'approval_rule_id' => (float)$this->rule->id]]);
        $this->assertSaved($this->makeController()->actionUpdate((int)$template->id));
        $this->assertSame($newName, $this->stored($template)->name);
        $this->assertSame((int)$this->rule->id, $this->stored($template)->approval_rule_id);
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * The action saved and redirected; a form shown again names its errors.
     *
     * @param mixed $result what the action returned
     */
    private function assertSaved(mixed $result): void
    {
        $this->assertInstanceOf(Response::class, $result, is_string($result) ? $result : 'saved');
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

    private function stored(JobTemplate $template): JobTemplate
    {
        $stored = JobTemplate::findOne($template->id);
        $this->assertInstanceOf(JobTemplate::class, $stored);

        return $stored;
    }

    /**
     * The fields of a valid new template, as the form posts them.
     *
     * @return array<string, string>
     */
    private function formFields(string $name): array
    {
        $ownerId = (int)$this->admin->id;

        return [
            'name' => $name,
            'project_id' => (string)$this->createProject($ownerId)->id,
            'inventory_id' => (string)$this->createInventory($ownerId)->id,
            'runner_group_id' => (string)$this->createRunnerGroup($ownerId)->id,
            'playbook' => 'site.yml',
            'verbosity' => '0',
            'forks' => '5',
            'timeout_minutes' => '30',
            'become' => '0',
            'become_method' => 'sudo',
            'become_user' => 'root',
        ];
    }

    private function makeController(): JobTemplateController
    {
        $app = \Yii::$app;
        $this->assertNotNull($app, 'the test bootstrap creates the application');

        return new class ('job-template', $app) extends JobTemplateController {
            public function render($view, $params = []): string
            {
                $model = $params['model'] ?? null;

                return 'rendered:' . $view . ' ' . (string)json_encode($model instanceof JobTemplate ? $model->getErrors() : null);
            }

            public function redirect($url, $statusCode = 302): Response
            {
                return new Response();
            }
        };
    }
}
