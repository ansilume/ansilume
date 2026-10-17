<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\JobTemplate;
use app\models\TeamProject;
use app\models\User;
use app\models\WorkflowStep;
use app\services\WorkflowStepFormView;
use app\services\WorkflowTemplatePage;
use app\tests\integration\DbTestCase;

/**
 * The step form of the workflow template page and of the page that edits a
 * step (WorkflowTemplatePage, contract C15): a user who may not add gated
 * job steps is not offered job templates that an approval rule gates,
 * neither by a rule of their own nor by the rule of their inventory, and
 * the hint under the field says so (GATED_TEMPLATES_HINT); admins are
 * offered every job template they may operate. The job-step help names both
 * rules (WorkflowStepFormView::HELP_JOB).
 */
class WorkflowStepFormInventoryGateTest extends DbTestCase
{
    use InventoryGatedStepFixtures;
    use WorkflowStepWriteFixtures;

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    private int $ruleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->teamScope();
        $this->ruleId = (int)$this->createApprovalRule((int)$this->s['admin']->id)->id;
    }

    /**
     * regression: the step form offered job templates that only the rule
     * of their inventory gates to users who may not add gated job steps, so
     * the form led them to a step the write path now refuses. Now neither
     * the template page's Add Step form nor the edit page lists them.
     */
    public function testRegressionTheStepFormOfferedJobTemplatesGatedThroughTheirInventory(): void
    {
        $this->moveToInventory((int)$this->s['own']->id, (int)$this->gatedInventory((int)$this->s['admin']->id, $this->ruleId)->id);
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $build = $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->s['open']->id);
        $page = new WorkflowTemplatePage((int)$this->s['member']->id);

        $view = $page->viewParams($workflow)['jobTemplateOptions'];
        $edit = $page->editParams($workflow, $build, null, null)['jobTemplateOptions'];

        $this->assertArrayNotHasKey((int)$this->s['own']->id, $view, 'the Add Step form leaves it out');
        $this->assertArrayNotHasKey((int)$this->s['own']->id, $edit, 'the edit page leaves it out');
        $this->assertSame([(int)$this->s['open']->id => (string)$this->s['open']->name], $view, 'operable and ungated');
        $this->assertSame($view, $edit);
    }

    /**
     * An admin is offered every job template they may operate, gated ones
     * included, by either rule.
     */
    public function testAnAdminIsOfferedJobTemplatesGatedThroughTheirInventory(): void
    {
        $this->moveToInventory((int)$this->s['own']->id, (int)$this->gatedInventory((int)$this->s['admin']->id, $this->ruleId)->id);
        JobTemplate::updateAll(['approval_rule_id' => $this->ruleId], ['id' => $this->s['viewed']->id]);
        $workflow = $this->createWorkflowWithJobSteps((int)$this->s['admin']->id, (int)$this->s['open']->id);

        $params = (new WorkflowTemplatePage((int)$this->s['admin']->id))->viewParams($workflow);

        foreach (['own', 'viewed', 'foreign', 'open'] as $key) {
            $this->assertSame((string)$this->s[$key]->name, $params['jobTemplateOptions'][(int)$this->s[$key]->id] ?? null, $key);
        }
        $this->assertTrue($params['mayAssignRules']);
    }

    /**
     * What the form offers is read when the page is shown: a job template
     * whose inventory gets a rule is no longer offered, and is offered again
     * once the rule is removed; one moved to an inventory with a rule is not.
     */
    public function testTheChoicesFollowTheInventorysRule(): void
    {
        $workflow = $this->createWorkflowWithJobSteps((int)$this->s['admin']->id, (int)$this->s['open']->id);
        $inventory = $this->createInventory((int)$this->s['admin']->id);
        $this->moveToInventory((int)$this->s['own']->id, (int)$inventory->id);
        $page = new WorkflowTemplatePage((int)$this->s['member']->id);
        $ownId = (int)$this->s['own']->id;
        $this->assertArrayHasKey($ownId, $page->viewParams($workflow)['jobTemplateOptions']);

        $this->giveInventoryRule((int)$inventory->id, $this->ruleId);
        $this->assertArrayNotHasKey($ownId, $page->viewParams($workflow)['jobTemplateOptions'], 'its inventory got a rule');

        $this->giveInventoryRule((int)$inventory->id, null);
        $this->assertArrayHasKey($ownId, $page->viewParams($workflow)['jobTemplateOptions'], 'and lost it');

        $this->moveToInventory($ownId, (int)$this->gatedInventory((int)$this->s['admin']->id, $this->ruleId)->id);
        $this->assertArrayNotHasKey($ownId, $page->viewParams($workflow)['jobTemplateOptions'], 'moved to a gated inventory');
    }

    /**
     * On an installation without team-restricted projects (the visibility
     * filter is null), the form leaves them out the same.
     */
    public function testWithoutAnyTeamRestrictedProjectTheyAreLeftOutToo(): void
    {
        $this->moveToInventory((int)$this->s['own']->id, (int)$this->gatedInventory((int)$this->s['admin']->id, $this->ruleId)->id);
        TeamProject::deleteAll();
        $workflow = $this->createWorkflowWithJobSteps((int)$this->s['admin']->id, (int)$this->s['open']->id);

        $options = (new WorkflowTemplatePage((int)$this->s['member']->id))->viewParams($workflow)['jobTemplateOptions'];

        $this->assertArrayNotHasKey((int)$this->s['own']->id, $options);
        foreach (['viewed', 'foreign', 'open'] as $key) {
            $this->assertArrayHasKey((int)$this->s[$key]->id, $options, $key . ': no project is restricted');
        }
    }

    /**
     * The hint under the field and the job step's help name the rule of the
     * inventory too (C15): the docs and the E2E specs quote them.
     */
    public function testTheHintAndTheHelpNameTheInventorysRule(): void
    {
        $this->assertSame(
            'Job templates with an approval rule, or on an inventory with one, are not listed: only admins may add a job step for them.',
            WorkflowTemplatePage::GATED_TEMPLATES_HINT
        );
        $this->assertSame(
            'success = exit code 0. Failure = any non-zero exit, timeout, or unreachable host. Always overrides both. If the job '
                . 'needs approval (an approval rule of its job template or of its inventory) and the approval is rejected, times out '
                . 'or is canceled, the job does not run: then only On Failure is followed (left empty, the run ends as failed), and '
                . 'Always is not.',
            WorkflowStepFormView::HELP_JOB
        );
    }
}
