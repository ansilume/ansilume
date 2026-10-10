<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowTemplateController;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\WorkflowJob;
use app\models\WorkflowStep;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Controller;
use yii\data\ActiveDataProvider;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\View;

/**
 * Workflow templates in the web UI: steps saved by older versions, and
 * launch refusals.
 *
 * Regressions:
 * - Approval and pause steps could carry a job template ID (the add-step
 *   form posted the hidden dropdown). It restricted the workflow as if the
 *   step ran that template, and the page showed it as the step's target,
 *   naming a job template of another team.
 * - Refusing to launch a workflow the user may not even see named its job
 *   templates, those of another team.
 */
class WorkflowTemplateControllerTeamScopeTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private const PURGED = 987654321;

    /** @var array<string, mixed> */
    private array $originalComponents = [];

    /** @var Controller<\yii\base\Module>|null */
    private ?Controller $previousController = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Flashes live in $_SESSION, which outlasts a test: start without any.
        \Yii::$app->session->removeAllFlashes();
        $this->previousController = \Yii::$app->controller;
        $components = \Yii::$app->getComponents(true);
        $this->originalComponents = [
            'view' => $components['view'] ?? null,
            'assetManager' => $components['assetManager'] ?? null,
        ];
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
    }

    protected function tearDown(): void
    {
        // A launch leaves a flash behind; later tests in the process see $_SESSION too.
        \Yii::$app->session->removeAllFlashes();
        \Yii::$app->controller = $this->previousController;
        foreach ($this->originalComponents as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        parent::tearDown();
    }

    // -- Steps saved by older versions ---------------------------------------

    public function testThePageShowsOnlyTheTargetOfEachStepsType(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule($s['admin']->id);
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $job = $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_JOB, $s['own']->id, $rule->id);
        $approval = $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_APPROVAL, $s['foreign']->id, $rule->id);
        $pause = $this->createWorkflowStep($workflow->id, 2, WorkflowStep::TYPE_PAUSE, $s['foreign']->id, $rule->id);
        $this->loginAs($s['member']);

        $html = $this->makeController()->actionView((int)$workflow->id);

        $this->assertSame([$this->templateLink($s['own']->id)], $this->targetLinks($html, $job));
        $this->assertSame([$this->ruleLink($rule)], $this->targetLinks($html, $approval));
        $this->assertSame([], $this->targetLinks($html, $pause));
        $this->assertStringNotContainsString(Html::encode($s['foreign']->name), $html, 'another team\'s template is never named');
        $this->assertStringNotContainsString($this->templateLink($s['foreign']->id), $html);
    }

    public function testThePageShowsNoLeftoverOfAPurgedTemplate(): void
    {
        $s = $this->teamScope();
        $rule = $this->createApprovalRule($s['admin']->id);
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $approval = $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_APPROVAL, self::PURGED, $rule->id);
        $pause = $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_PAUSE, self::PURGED);
        $this->loginAs($s['admin']);

        $html = $this->makeController()->actionView((int)$workflow->id);

        $this->assertSame([$this->ruleLink($rule)], $this->targetLinks($html, $approval));
        $this->assertSame([], $this->targetLinks($html, $pause));
        $this->assertStringNotContainsString('#' . self::PURGED, $html);
    }

    public function testAMemberListsAndLaunchesAWorkflowWhoseOtherStepsCarryLeftovers(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowTemplate($s['admin']->id);
        $this->createWorkflowStep($workflow->id, 0, WorkflowStep::TYPE_PAUSE, $s['foreign']->id);
        $this->createWorkflowStep($workflow->id, 1, WorkflowStep::TYPE_APPROVAL, self::PURGED, $this->createApprovalRule($s['admin']->id)->id);
        $this->createWorkflowStep($workflow->id, 2, WorkflowStep::TYPE_JOB, $s['own']->id);
        $this->loginAs($s['member']);

        $index = $this->makeController();
        $index->actionIndex();
        /** @var ActiveDataProvider $listed */
        $listed = $index->capturedParams['dataProvider'];
        $this->assertContains($workflow->id, array_map('intval', $listed->getKeys()), 'listed');
        $operable = $index->capturedParams['operableIds'];
        $this->assertIsArray($operable);
        $this->assertContains($workflow->id, $operable, 'offered for launch');

        $this->setQueryParams(['id' => (string)$workflow->id]);
        $this->assertInstanceOf(Response::class, $this->makeController()->actionLaunch());

        $run = WorkflowJob::findOne(['workflow_template_id' => $workflow->id]);
        $this->assertNotNull($run);
        $this->assertSame($s['member']->id, (int)$run->launched_by);
    }

    // -- Launch refusals -----------------------------------------------------

    public function testLaunchOfAWorkflowTheMemberMayNotSeeNamesNoJobTemplates(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['foreign']->id);
        $this->loginAs($s['member']);
        $this->setPost(['id' => (string)$workflow->id]);

        $message = $this->launchRefusal();

        $this->assertSame('You may not launch this workflow.', $message);
        $this->assertFalse(WorkflowJob::find()->where(['workflow_template_id' => $workflow->id])->exists());
        $this->assertSame([], \Yii::$app->session->getAllFlashes(), 'a 403, not a failed launch');
        $denied = AuditLog::findOne(['action' => AuditLog::ACTION_WORKFLOW_LAUNCH_DENIED, 'object_id' => $workflow->id]);
        $this->assertNotNull($denied, 'the audit log keeps the details');
        $this->assertSame(
            ['source' => 'web', 'job_template_ids' => [$s['foreign']->id]],
            json_decode((string)$denied->metadata, true)
        );
    }

    public function testLaunchOfAWorkflowTheMemberMaySeeNamesTheTemplatesTheyMayNotOperate(): void
    {
        $s = $this->teamScope();
        $workflow = $this->createWorkflowWithJobSteps($s['admin']->id, $s['own']->id, $s['viewed']->id);
        $this->loginAs($s['member']);
        $this->setQueryParams(['id' => (string)$workflow->id]);

        $this->assertSame("You may not launch job template(s) #{$s['viewed']->id} of this workflow.", $this->launchRefusal());
    }

    // -- Helpers -------------------------------------------------------------

    /**
     * WorkflowTemplateController that renders views without the layout and
     * records what it passed to them; redirects are not followed.
     */
    private function makeController(): WorkflowTemplateController
    {
        $ctrl = new class ('workflow-template', \Yii::$app) extends WorkflowTemplateController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return $this->renderPartial($view, $params);
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $response = new Response();
                $response->content = 'redirected';
                return $response;
            }
        };
        \Yii::$app->controller = $ctrl;

        return $ctrl;
    }

    /**
     * The message of the 403 that refuses the launch.
     */
    private function launchRefusal(): string
    {
        try {
            $this->makeController()->actionLaunch();
        } catch (ForbiddenHttpException $e) {
            return $e->getMessage();
        }
        $this->fail('Expected ForbiddenHttpException.');
    }

    /**
     * The job template and approval rule links in the Target cell of $step's
     * row on the page.
     *
     * @return list<string>
     */
    private function targetLinks(string $html, WorkflowStep $step): array
    {
        $found = preg_match('#<tr data-step-id="' . $step->id . '">(.*?)</tr>#s', $html, $row);
        $this->assertSame(1, $found, "row of step #{$step->id}");
        preg_match_all('#href="(/(?:job-template|approval-rule)/view\?id=\d+)"#', $row[1], $links);

        return $links[1];
    }

    private function templateLink(int $templateId): string
    {
        return Url::to(['/job-template/view', 'id' => $templateId]);
    }

    private function ruleLink(ApprovalRule $rule): string
    {
        return Url::to(['/approval-rule/view', 'id' => $rule->id]);
    }
}
