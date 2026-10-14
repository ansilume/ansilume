<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\WorkflowTemplateController;
use app\models\Job;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\runstate\Texts;
use app\services\runstate\Viewer;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\AssetManager;
use yii\web\View;

/**
 * The template page's card of active runs (views/workflow-template/_active-runs.php):
 * every run that is active now, what it waits for at which step and since
 * when, the request it waits for, and who launched it.
 */
class WorkflowTemplateActiveRunsCardTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;

    private User $admin;

    private WorkflowTemplate $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('wfar-admin', 'admin');
        $this->workflow = $this->createWorkflowTemplate((int)$this->admin->id);
    }

    public function testTheCardListsEachRunWithItsStateStepAndRequest(): void
    {
        $rule = $this->ruleOf((int)$this->admin->id, [$this->admin]);
        $gate = $this->namedStep($this->workflow, 10, 'gate', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $hold = $this->namedStep($this->workflow, 20, 'hold', WorkflowStep::TYPE_PAUSE);
        $waiting = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $placeholder = $this->jobOf(null, (int)$this->admin->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($placeholder, $rule);
        $this->executionOf($waiting, $gate, WorkflowJobStep::STATUS_RUNNING, $placeholder);
        $paused = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $this->executionOf($paused, $hold, WorkflowJobStep::STATUS_RUNNING);
        $between = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $this->loginAs($this->admin);

        $html = $this->renderCard([$waiting, $paused, $between], 5);

        $this->assertStringContainsString('<div class="card mb-4" id="wf-active-runs">', $html);
        $this->assertStringContainsString('<div class="card-header">' . Texts::ACTIVE_RUNS_TITLE . '</div>', $html);
        $waitingItem = $this->item($html, $waiting);
        $this->assertStringContainsString('<a href="/workflow-job/view?id=' . $waiting->id . '">#' . $waiting->id . '</a>', $waitingItem);
        $this->assertStringContainsString('<span class="badge text-bg-info" data-run-state="awaiting_approval">Awaiting Approval</span>', $waitingItem);
        $this->assertStringContainsString(Html::encode(sprintf(Texts::ACTIVE_RUN_AT, 'gate', date('Y-m-d H:i:s', 1760000150))), $waitingItem);
        $this->assertStringContainsString('<a href="/approval/view?id=' . $request->id . '" data-run-request>Request #' . $request->id . '</a>', $waitingItem);
        $this->assertStringContainsString(Html::encode(sprintf(Texts::ACTIVE_RUN_BY, $this->admin->username)), $waitingItem);
        $pausedItem = $this->item($html, $paused);
        $this->assertStringContainsString('<span class="badge text-bg-warning" data-run-state="paused">Paused</span>', $pausedItem);
        $this->assertStringContainsString(Html::encode(sprintf(Texts::ACTIVE_RUN_AT, 'hold', date('Y-m-d H:i:s', 1760000100))), $pausedItem);
        $this->assertStringNotContainsString('data-run-request', $pausedItem);
        $betweenItem = $this->item($html, $between);
        $this->assertStringContainsString('<span class="badge text-bg-primary" data-run-state="running">Running</span>', $betweenItem);
        $this->assertStringNotContainsString('at step', $betweenItem, 'between two steps');
        $this->assertStringContainsString('<span class="text-muted me-2" id="wf-active-runs-more">' . sprintf(Texts::ACTIVE_RUNS_MORE, 2) . '</span>', $html);
        $this->assertStringContainsString(
            '<a id="wf-active-runs-all" href="/workflow-job/index?workflow_template_id=' . $this->workflow->id . '&amp;status=running">'
            . Texts::ACTIVE_RUNS_ALL . '</a>',
            $html
        );
    }

    public function testNothingIsShownWithoutActiveRuns(): void
    {
        $this->loginAs($this->admin);

        $this->assertSame('', trim($this->renderCard([], 0)));
    }

    /**
     * A viewer without approval.view sees the request named, not linked; with
     * every active run listed there is no "and N more".
     */
    public function testTheRequestIsLinkedOnlyForAViewerWhoMayOpenIt(): void
    {
        $rule = $this->ruleOf((int)$this->admin->id, [$this->admin]);
        $gate = $this->namedStep($this->workflow, 10, 'gate', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$this->admin->id);
        $placeholder = $this->jobOf(null, (int)$this->admin->id, Job::STATUS_PENDING_APPROVAL);
        $request = $this->requestOf($placeholder, $rule);
        $this->executionOf($run, $gate, WorkflowJobStep::STATUS_RUNNING, $placeholder);
        $watcher = $this->createUser('wfar-watcher');
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('wfar-watch-' . bin2hex(random_bytes(4)));
        $auth->add($role);
        $permission = $auth->getPermission('workflow.view');
        $this->assertNotNull($permission);
        $auth->addChild($role, $permission);
        $auth->assign($role, (string)$watcher->id);
        $this->loginAs($watcher);

        $html = $this->renderCard([$run], 1);

        $this->assertStringContainsString('<span data-run-request>Request #' . $request->id . '</span>', $html);
        $this->assertStringNotContainsString('/approval/view', $html);
        $this->assertStringNotContainsString('wf-active-runs-more', $html);
    }

    /**
     * Names are text: a step's and a launcher's name with markup are encoded.
     */
    public function testNamesAreEncoded(): void
    {
        $hold = $this->namedStep($this->workflow, 10, '<img src=x onerror=alert(1)>', WorkflowStep::TYPE_PAUSE);
        $launcher = $this->createUser('wfar-launcher');
        $launcher->username = '<b>bob</b>-' . bin2hex(random_bytes(3));
        $launcher->save(false);
        $run = $this->createWorkflowJob((int)$this->workflow->id, (int)$launcher->id);
        $this->executionOf($run, $hold, WorkflowJobStep::STATUS_RUNNING);
        $this->loginAs($this->admin);

        $html = $this->renderCard([$run], 1);

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>bob</b>', $html);
        $this->assertStringContainsString('at step &quot;&lt;img src=x onerror=alert(1)&gt;&quot; since', $html);
        $this->assertStringContainsString('launched by &lt;b&gt;bob&lt;/b&gt;-', $html);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * The card for $runs (their launcher loaded, oldest first), as the
     * template page renders it: the parameters of C9.
     *
     * @param list<WorkflowJob> $runs
     */
    private function renderCard(array $runs, int $activeRunCount): string
    {
        $ids = array_map(static fn (WorkflowJob $run): int => (int)$run->id, $runs);
        /** @var list<WorkflowJob> $loaded */
        $loaded = $ids === [] ? [] : WorkflowJob::find()->where(['id' => $ids])->with('launcher')->orderBy(['id' => SORT_ASC])->all();
        $params = [
            'model' => $this->workflow,
            'activeRuns' => $loaded,
            'runStates' => $this->runStates()->forRuns($loaded, Viewer::current()),
            'activeRunCount' => $activeRunCount,
        ];
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new WorkflowTemplateController('workflow-template', \Yii::$app);
        \Yii::$app->controller = $ctrl;
        try {
            return $ctrl->renderPartial('_active-runs', $params);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    /**
     * The list item of $run in $html.
     */
    private function item(string $html, WorkflowJob $run): string
    {
        if (preg_match('#<li [^>]*data-run-id="' . $run->id . '">(.*?)</li>#s', $html, $m) !== 1) {
            $this->fail('No item of run #' . $run->id);
        }

        return $m[1];
    }
}
