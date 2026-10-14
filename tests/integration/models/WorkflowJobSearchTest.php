<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Job;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobSearch;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\tests\integration\DbTestCase;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * The filters of the workflow run lists: what a run waits for, its
 * workflow template and its stored status. The web ignores an invalid
 * value; the API reports it (validate()).
 */
class WorkflowJobSearchTest extends DbTestCase
{
    use TeamScopeFixtures;
    use RunStateFixtures;

    private User $admin;

    private WorkflowTemplate $workflow;

    private WorkflowTemplate $other;

    /** @var array<string, WorkflowJob> */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('wjs-admin', 'admin');
        $adminId = (int)$this->admin->id;
        $this->workflow = $this->createWorkflowTemplate($adminId);
        $this->other = $this->createWorkflowTemplate($adminId);
        $templateId = (int)$this->openJobTemplate($adminId)->id;
        $rule = $this->ruleOf($adminId, [$this->namedUser('wjs-approver', 'operator')]);
        $pause = $this->namedStep($this->workflow, 10, 'hold', WorkflowStep::TYPE_PAUSE);
        $approval = $this->namedStep($this->workflow, 20, 'approve', WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id);
        $job = $this->namedStep($this->other, 10, 'deploy', WorkflowStep::TYPE_JOB, $templateId);

        $this->runs['paused'] = $this->createWorkflowJob((int)$this->workflow->id, $adminId);
        $this->executionOf($this->runs['paused'], $pause, 'running');
        $this->runs['approval'] = $this->createWorkflowJob((int)$this->workflow->id, $adminId);
        $placeholder = $this->jobOf(null, $adminId, Job::STATUS_PENDING_APPROVAL);
        $this->requestOf($placeholder, $rule);
        $this->executionOf($this->runs['approval'], $approval, 'running', $placeholder);
        $this->runs['queued'] = $this->createWorkflowJob((int)$this->other->id, $adminId);
        $this->executionOf($this->runs['queued'], $job, 'running', $this->jobOf($templateId, $adminId, Job::STATUS_QUEUED, 1760000160));
        $this->runs['succeeded'] = $this->createWorkflowJob((int)$this->other->id, $adminId, WorkflowJob::STATUS_SUCCEEDED);
        $this->runs['canceled'] = $this->createWorkflowJob((int)$this->workflow->id, $adminId, WorkflowJob::STATUS_CANCELED);
    }

    public function testWithoutFiltersEveryRunIsListed(): void
    {
        foreach ([[], ['waiting_for' => '', 'workflow_template_id' => '', 'status' => ''], ['other' => 'x']] as $params) {
            $search = $this->searchOf($params);
            $this->assertTrue($search->validate());
            $this->assertSame(['canceled', 'succeeded', 'queued', 'approval', 'paused'], $this->listed($search));
        }
    }

    public function testEachFilterKeepsItsRuns(): void
    {
        $this->assertSame(['approval'], $this->listed($this->searchOf(['waiting_for' => 'approval'])));
        $this->assertSame(['paused'], $this->listed($this->searchOf(['waiting_for' => 'resume'])));
        $this->assertSame(['queued'], $this->listed($this->searchOf(['waiting_for' => 'runner'])));
        $this->assertSame(['canceled', 'approval', 'paused'], $this->listed($this->searchOf(['workflow_template_id' => (string)$this->workflow->id])));
        $this->assertSame(['succeeded', 'queued'], $this->listed($this->searchOf(['workflow_template_id' => (int)$this->other->id])));
        $this->assertSame(['succeeded'], $this->listed($this->searchOf(['status' => 'succeeded'])));
        $this->assertSame(['queued', 'approval', 'paused'], $this->listed($this->searchOf(['status' => 'running'])));
        $this->assertSame(['paused'], $this->listed($this->searchOf([
            'waiting_for' => 'resume',
            'workflow_template_id' => (string)$this->workflow->id,
            'status' => 'running',
        ])));
        $this->assertSame([], $this->listed($this->searchOf(['waiting_for' => 'resume', 'status' => 'canceled'])), 'a finished run waits for nothing');
    }

    public function testLeadingZerosNameTheSameWorkflowTemplate(): void
    {
        $search = $this->searchOf(['workflow_template_id' => '00' . $this->other->id]);

        $this->assertTrue($search->validate());
        $this->assertSame((int)$this->other->id, $search->activeWorkflowTemplateId());
        $this->assertSame(['succeeded', 'queued'], $this->listed($search));
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidFilterData(): array
    {
        return [
            'unknown wait' => ['waiting_for', 'everything'],
            'wait in upper case' => ['waiting_for', 'APPROVAL'],
            'wait as a list' => ['waiting_for', ['approval']],
            'wait as a number' => ['waiting_for', 1],
            'state instead of a wait' => ['waiting_for', 'paused'],
            'unknown status' => ['status', 'paused'],
            'status in upper case' => ['status', 'Running'],
            'status as a list' => ['status', ['running']],
            'zero' => ['workflow_template_id', '0'],
            'zeros' => ['workflow_template_id', '000'],
            'negative' => ['workflow_template_id', '-1'],
            'signed' => ['workflow_template_id', '+5'],
            'spaced' => ['workflow_template_id', ' 5'],
            'fraction' => ['workflow_template_id', '1.5'],
            'word' => ['workflow_template_id', 'abc'],
            'beyond an int' => ['workflow_template_id', '99999999999999999999'],
            'list' => ['workflow_template_id', ['5']],
            'zero as an int' => ['workflow_template_id', 0],
            'negative int' => ['workflow_template_id', -3],
            'float' => ['workflow_template_id', 5.0],
            'bool' => ['workflow_template_id', true],
        ];
    }

    /**
     * The API answers an invalid value with 422 and the first error; the
     * web list ignores it and lists every run.
     *
     * @dataProvider invalidFilterData
     */
    public function testAnInvalidValueIsReportedAndOtherwiseIgnored(string $filter, mixed $value): void
    {
        $search = new WorkflowJobSearch();
        $search->$filter = $value;

        $this->assertFalse($search->validate());
        $this->assertSame([$filter => WorkflowJobSearch::errorOf($filter)], $search->getFirstErrors());
        $this->assertSame(['canceled', 'succeeded', 'queued', 'approval', 'paused'], $this->listed($search));
    }

    public function testTheErrorsNameTheValidValues(): void
    {
        $this->assertSame('waiting_for must be one of: approval, resume, runner.', WorkflowJobSearch::errorOf('waiting_for'));
        $this->assertSame('status must be one of: running, succeeded, failed, canceled.', WorkflowJobSearch::errorOf('status'));
        $this->assertSame('workflow_template_id must be a positive integer.', WorkflowJobSearch::errorOf('workflow_template_id'));
        $this->assertSame('%s must be one of: %s.', WorkflowJobSearch::INVALID_FILTER);
    }

    public function testOnlyTheValidFiltersApply(): void
    {
        $search = $this->searchOf(['waiting_for' => 'nonsense', 'status' => 'running', 'workflow_template_id' => 'x']);

        $this->assertFalse($search->validate());
        $this->assertSame(['waiting_for', 'workflow_template_id'], array_keys($search->getErrors()));
        $this->assertNull($search->activeWaitingFor());
        $this->assertNull($search->activeWorkflowTemplateId());
        $this->assertSame('running', $search->activeStatus());
        $this->assertSame(['queued', 'approval', 'paused'], $this->listed($search));
    }

    public function testAnIntegerTemplateIdCountsAsGiven(): void
    {
        $search = new WorkflowJobSearch();
        $search->workflow_template_id = (int)$this->workflow->id;

        $this->assertTrue($search->validate());
        $this->assertSame((int)$this->workflow->id, $search->activeWorkflowTemplateId());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function searchOf(array $params): WorkflowJobSearch
    {
        $search = new WorkflowJobSearch();
        $search->load($params, '');

        return $search;
    }

    /**
     * The fixture runs the search keeps, newest first, by their key.
     *
     * @return list<string>
     */
    private function listed(WorkflowJobSearch $search): array
    {
        $names = array_flip(array_map(static fn (WorkflowJob $run): int => (int)$run->id, $this->runs));
        $ids = $search->apply(WorkflowJob::find()->where(['workflow_job.id' => array_keys($names)]))
            ->orderBy(['workflow_job.id' => SORT_DESC])
            ->select('workflow_job.id')
            ->column();

        return array_map(static fn ($id): string => $names[(int)$id], $ids);
    }
}
