<?php

declare(strict_types=1);

namespace app\tests\integration;

use app\components\WorkflowStepPosition;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\WorkflowStepChangeGuard;
use app\services\WorkflowStepWriteService;
use app\tests\integration\services\WorkflowStepWriteFixtures;

/**
 * Durable guard of the step write path (rule 12, contract C5/C6): every
 * write method of WorkflowStepWriteService refuses an operator on a workflow
 * with an approval step or a gated job step (403), and everyone while a run
 * of the workflow is active (409), and writes nothing then. A write method
 * added later without its case here fails testEveryWriteMethodHasACase(), so
 * no path of the service can skip the guard unnoticed.
 */
class WorkflowStepWritePathsTest extends DbTestCase
{
    use WorkflowStepWriteFixtures;

    /** The service's write methods, each with a case in write(). */
    private const PATHS = ['add', 'move', 'remove', 'update'];

    private User $admin;
    private User $operator;
    private JobTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('write-paths-admin', 'admin');
        $this->operator = $this->createUserWithRole('write-paths-operator', 'operator');
        $this->template = $this->jobTemplate((int)$this->admin->id);
    }

    public function testEveryWriteMethodHasACase(): void
    {
        $declared = array_filter(
            (new \ReflectionClass(WorkflowStepWriteService::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === WorkflowStepWriteService::class
        );
        $names = array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $declared);
        sort($names);

        $this->assertSame(self::PATHS, $names);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lockedPathProvider(): array
    {
        $cases = [];
        foreach (self::PATHS as $path) {
            $cases["{$path}, an approval step"] = [$path, 'approval'];
            $cases["{$path}, a gated job step"] = [$path, 'gated job'];
        }

        return $cases;
    }

    /**
     * @dataProvider lockedPathProvider
     */
    public function testAnOperatorMayNotChangeTheStepsOfAGatedWorkflow(string $path, string $gate): void
    {
        $workflow = $this->workflow();
        if ($gate === 'approval') {
            $this->namedStep((int)$workflow->id, 'Approve', 20, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule((int)$this->admin->id)->id);
        } else {
            $gated = $this->jobTemplate((int)$this->admin->id, (int)$this->createApprovalRule((int)$this->admin->id)->id);
            $this->namedStep((int)$workflow->id, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$gated->id);
        }
        $before = $this->stepRows((int)$workflow->id);

        $refusal = $this->refusalOf(fn () => $this->write($path, $workflow, (int)$this->operator->id));

        $this->assertSame(403, $refusal->status);
        $this->assertSame(
            $gate === 'approval'
                ? ApprovalRuleAssignmentPolicy::APPROVAL_WORKFLOW_STEPS_REFUSED
                : ApprovalRuleAssignmentPolicy::GATED_JOB_WORKFLOW_STEPS_REFUSED,
            $refusal->getMessage()
        );
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pathProvider(): array
    {
        return array_combine(self::PATHS, array_map(static fn (string $path): array => [$path], self::PATHS));
    }

    /**
     * @dataProvider pathProvider
     */
    public function testNobodyChangesTheStepsWhileARunIsActive(string $path): void
    {
        $workflow = $this->workflow();
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$this->admin->id);
        $before = $this->stepRows((int)$workflow->id);

        $refusal = $this->refusalOf(fn () => $this->write($path, $workflow, (int)$this->admin->id));

        $this->assertSame(409, $refusal->status);
        $this->assertSame(WorkflowStepChangeGuard::runsActiveMessage([(int)$run->id]), $refusal->getMessage());
        $this->assertSame(['workflow_job_ids' => [(int)$run->id]], $refusal->details);
        $this->assertSame($before, $this->stepRows((int)$workflow->id));
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
    }

    /**
     * The same calls go through for an admin when no run is active: the
     * refusals above are the guard's, not the cases'.
     *
     * @dataProvider pathProvider
     */
    public function testAnAdminChangesTheStepsOfAGatedWorkflow(string $path): void
    {
        $workflow = $this->workflow();
        $this->namedStep((int)$workflow->id, 'Approve', 20, WorkflowStep::TYPE_APPROVAL, null, (int)$this->createApprovalRule((int)$this->admin->id)->id);

        $this->write($path, $workflow, (int)$this->admin->id);

        $this->assertCount(1, $this->stepAudits((int)$workflow->id));
    }

    /**
     * A workflow with one plain job step, Build.
     */
    private function workflow(): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate((int)$this->admin->id);
        $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->template->id);

        return $workflow;
    }

    /**
     * Call the write method $path on the workflow's step Build (or add a
     * plain job step), as $userId.
     */
    private function write(string $path, WorkflowTemplate $workflow, int $userId): void
    {
        $writer = $this->writer();
        $build = (int)WorkflowStep::find()->select('id')->where(['workflow_template_id' => $workflow->id, 'name' => 'Build'])->scalar();
        match ($path) {
            'add' => $writer->add($workflow, $this->apiCommand(['name' => 'New', 'job_template_id' => (int)$this->template->id]), $userId, 'api'),
            'move' => $writer->move($workflow, $build, WorkflowStepPosition::end(), $userId, 'api'),
            'remove' => $writer->remove($workflow, $build, $userId, 'api'),
            'update' => $writer->update($workflow, $build, $this->apiCommand(['name' => 'Renamed'], false), $userId, 'api'),
        };
    }
}
