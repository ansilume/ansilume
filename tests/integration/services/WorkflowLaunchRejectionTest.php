<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\JobLaunchRejectedException;
use app\services\JobLaunchService;
use app\services\WorkflowExecutionService;
use app\tests\integration\DbTestCase;

/**
 * A workflow job step whose launch is refused records the reason as the
 * step's error, so operators see what to fix without the application log.
 */
class WorkflowLaunchRejectionTest extends DbTestCase
{
    /** @var list<array{0: string, 1: mixed}> */
    private array $swapped = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->swapped) as [$id, $original]) {
            \Yii::$app->set($id, $original);
        }
        $this->swapped = [];
        parent::tearDown();
    }

    public function testARefusedJobStepFailsWithTheReason(): void
    {
        $admin = $this->superadmin();
        $template = $this->createPasswordSurveyTemplate((int)$admin->id);
        $template->survey_fields = '[{"name":"db[pw]","type":"password"}]';
        $template->save(false);

        $run = $this->launchWorkflowWith($template, (int)$admin->id);

        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
        $step = $this->firstStep($run);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $step->status);
        $this->assertSame(
            'Survey field "db[pw]" has a name that cannot be used for a password answer; rename it in the survey before launching.',
            $step->error_message
        );
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
    }

    public function testAnyRefusalOfTheLaunchServiceBecomesTheStepError(): void
    {
        $admin = $this->superadmin();
        $template = $this->createPasswordSurveyTemplate((int)$admin->id);
        $this->swap('jobLaunchService', new class () extends JobLaunchService {
            public function launch(JobTemplate $template, int $launchedBy, array $overrides = []): Job
            {
                throw new JobLaunchRejectedException('The value of "db_password" is the placeholder $encrypted$; send the real value.');
            }
        });

        $run = $this->launchWorkflowWith($template, (int)$admin->id);

        $this->assertSame(
            'The value of "db_password" is the placeholder $encrypted$; send the real value.',
            $this->firstStep($run)->error_message
        );
    }

    private function launchWorkflowWith(JobTemplate $template, int $userId): WorkflowJob
    {
        $workflow = $this->createWorkflowTemplate($userId);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        /** @var WorkflowExecutionService $service */
        $service = \Yii::$app->get('workflowExecutionService');

        return $service->launch($workflow, $userId);
    }

    private function firstStep(WorkflowJob $run): WorkflowJobStep
    {
        /** @var WorkflowJobStep|null $step */
        $step = WorkflowJobStep::find()->where(['workflow_job_id' => $run->id])->orderBy(['id' => SORT_ASC])->one();
        $this->assertNotNull($step);

        return $step;
    }

    private function superadmin(): User
    {
        $user = $this->createUser('workflow-rejection');
        $user->is_superadmin = true;
        $user->save(false);

        return $user;
    }

    private function swap(string $id, object $replacement): void
    {
        $components = \Yii::$app->getComponents(true);
        $this->swapped[] = [$id, $components[$id] ?? null];
        \Yii::$app->set($id, $replacement);
    }
}
