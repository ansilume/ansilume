<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\SurveySecrets;
use app\models\AuditLog;
use app\models\Job;
use app\models\JobTemplate;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\services\WorkflowRunVars;
use app\services\WorkflowStepDispatcher;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The launch extra vars of a run reach every job step of it: over the job
 * template's extra vars, under the step's own mapping of the previous step's
 * output. Password answers among them stay sealed in every step's job, also
 * in a step whose template has no such password field.
 */
class WorkflowExecutionRunVarsTest extends DbTestCase
{
    use TeamScopeFixtures;
    use WorkflowEngineFixtures;

    /**
     * Regression: the launch extra vars (of a trigger, for one) reached the
     * start step only; every later job step ran without them.
     */
    public function testRegressionTheLaunchExtraVarsReachEveryJobStep(): void
    {
        $user = $this->operator('wf-vars-every');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $first = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$user->id)->id);
        $second = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$user->id)->id);

        $run = $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => ['env' => 'prod']], 'trigger');
        $this->finishJob($this->jobOfStep($run, $first));

        $this->assertSame(['env' => 'prod'], $this->extraVarsOf($this->jobOfStep($run, $first)));
        $this->assertSame(['env' => 'prod'], $this->extraVarsOf($this->jobOfStep($run, $second)), 'the second step gets them too');
    }

    /**
     * An approval or pause step at the start dropped the launch extra vars;
     * the job step after it gets them now.
     */
    public function testAPauseAtTheStartPassesTheVarsOnToTheJobStepAfterIt(): void
    {
        $user = $this->operator('wf-vars-pause');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $job = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$user->id)->id);

        $run = $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => '{"env": "prod"}']);
        $this->engine()->resume($run, (int)$user->id);

        $this->assertSame(['env' => 'prod'], $this->extraVarsOf($this->jobOfStep($run, $job)));
    }

    /**
     * Precedence, lowest to highest: the job template's extra vars, the run's
     * launch extra vars, the step's mapping of the previous step's output.
     */
    public function testTheStepMappingWinsOverTheRunVarsWhichWinOverTheTemplate(): void
    {
        $user = $this->operator('wf-vars-precedence');
        $template = $this->openTemplate((int)$user->id);
        JobTemplate::updateAll(['extra_vars' => '{"a": "template", "b": "template", "c": "template"}'], ['id' => $template->id]);
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $first = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$template->id);
        $second = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$template->id);
        WorkflowStep::updateAll(['extra_vars_template' => '{"c": "version", "d": "missing"}'], ['id' => $second->id]);

        $run = $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => ['b' => 'run', 'c' => 'run']]);
        WorkflowJobStep::updateAll(['output_vars' => '{"version": "2.0.1"}'], ['id' => $this->executionOfStep($run, $first)->id]);
        $this->finishJob($this->jobOfStep($run, $first));

        $this->assertSame(['a' => 'template', 'b' => 'run', 'c' => 'run'], $this->extraVarsOf($this->jobOfStep($run, $first)));
        $this->assertSame(['a' => 'template', 'b' => 'run', 'c' => '2.0.1'], $this->extraVarsOf($this->jobOfStep($run, $second)));
    }

    /**
     * The run's password answers stay sealed in the job of every step: the
     * template that asks for the answer, and one that has no such field,
     * which would otherwise store it in clear in its extra vars and runner
     * payload. The job.launched entries name the run and the carried keys,
     * never the answers.
     */
    public function testThePasswordAnswersStaySealedInEveryStepsJob(): void
    {
        $user = $this->operator('wf-vars-secret');
        $asking = $this->createPasswordSurveyTemplate((int)$user->id);
        $other = $this->openTemplate((int)$user->id);
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $first = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$asking->id);
        $second = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$other->id);

        $run = $this->engine()->launch($workflow, (int)$user->id, ['extra_vars' => [
            self::SURVEY_TEXT_FIELD => 'prod',
            self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER,
        ]]);
        $this->finishJob($this->jobOfStep($run, $first));

        foreach ([$first, $second] as $step) {
            $job = $this->jobOfStep($run, $step);
            $this->assertSame(
                [self::SURVEY_TEXT_FIELD => 'prod', self::SURVEY_PASSWORD_FIELD => SurveySecrets::PLACEHOLDER],
                $this->extraVarsOf($job),
                $step->name
            );
            $this->assertSame([self::SURVEY_PASSWORD_FIELD => self::SURVEY_PASSWORD_ANSWER], SurveySecrets::open($job->getSealedSecretExtraVars()));
            $this->assertStringNotContainsString(self::SURVEY_PASSWORD_ANSWER, (string)$job->runner_payload);

            $launched = $this->auditEntries(AuditLog::ACTION_JOB_LAUNCHED, 'job', (int)$job->id);
            $this->assertCount(1, $launched);
            $this->assertSame(
                ['workflow_job_id' => (int)$run->id, 'carried_secret_keys' => [self::SURVEY_PASSWORD_FIELD]],
                array_intersect_key($this->metadataOf($launched[0]), ['workflow_job_id' => 0, 'carried_secret_keys' => 0])
            );
            $this->assertStringNotContainsString(self::SURVEY_PASSWORD_ANSWER, (string)$launched[0]->metadata);
        }
    }

    /**
     * Without launch extra vars, a job step's job.launched entry still names
     * its run, with no carried keys.
     */
    public function testAJobStepOfARunWithoutVarsNamesItsRun(): void
    {
        $user = $this->operator('wf-vars-none');
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $step = $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_JOB, (int)$this->openTemplate((int)$user->id)->id);

        $run = $this->engine()->launch($workflow, (int)$user->id);

        $job = $this->jobOfStep($run, $step);
        $this->assertNull($job->extra_vars);
        $launched = $this->auditEntries(AuditLog::ACTION_JOB_LAUNCHED, 'job', (int)$job->id);
        $this->assertCount(1, $launched);
        $this->assertSame((int)$run->id, $this->metadataOf($launched[0])['workflow_job_id'] ?? null);
        $this->assertSame([], $this->metadataOf($launched[0])['carried_secret_keys'] ?? null);
    }

    /**
     * Sealed answers the server can no longer open (APP_SECRET_KEY was
     * changed) fail the job step, without its job, with the generic reason;
     * the log has why.
     */
    public function testAnswersThatCannotBeOpenedFailTheStep(): void
    {
        $user = $this->operator('wf-vars-broken');
        $template = $this->openTemplate((int)$user->id);
        $workflow = $this->createWorkflowTemplate((int)$user->id);
        $this->createWorkflowStep((int)$workflow->id, 0, WorkflowStep::TYPE_PAUSE);
        $step = $this->createWorkflowStep((int)$workflow->id, 1, WorkflowStep::TYPE_JOB, (int)$template->id);
        $run = $this->engine()->launch($workflow, (int)$user->id);
        WorkflowJob::updateAll(['secret_extra_vars' => 'not-a-sealed-value'], ['id' => $run->id]);

        $this->engine()->resume($run, (int)$user->id);

        $execution = $this->executionOfStep($run, $step);
        $this->assertSame(WorkflowJobStep::STATUS_FAILED, $execution->status);
        $this->assertSame(WorkflowStepDispatcher::LAUNCH_FAILED, $execution->error_message);
        $this->assertNull($execution->job_id);
        $this->assertSame(0, (int)Job::find()->where(['job_template_id' => $template->id])->count());
        $run->refresh();
        $this->assertSame(WorkflowJob::STATUS_FAILED, $run->status);
    }

    /**
     * The mapping takes only text source keys, and only output values that
     * are there and not null.
     */
    public function testTheStepMappingSkipsWhatTheOutputLacks(): void
    {
        $previous = new WorkflowJobStep();
        $previous->output_vars = '{"hosts": "web1", "empty": null, "count": 3}';
        $next = new WorkflowStep();
        $next->extra_vars_template = '{"target": "hosts", "nothing": "empty", "absent": "gone", "numbered": 7, "count": "count"}';

        /** @var WorkflowRunVars $runVars */
        $runVars = \Yii::$app->get('workflowRunVars');
        $this->assertSame(['target' => 'web1', 'count' => 3], $runVars->stepMapping($previous, $next));

        $next->extra_vars_template = null;
        $this->assertSame([], $runVars->stepMapping($previous, $next));
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function extraVarsOf(Job $job): ?array
    {
        $vars = json_decode((string)$job->extra_vars, true);

        return is_array($vars) ? $vars : null;
    }
}
