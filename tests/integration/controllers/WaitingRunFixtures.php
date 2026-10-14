<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\ApprovalRequest;
use app\models\ApprovalRule;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowJob;
use app\models\WorkflowJobStep;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;

/**
 * Fixtures of the approval list and dashboard tests: workflow runs written
 * as the engine leaves them while a step waits, and pages parsed for their
 * cells. Use in WebControllerTestCase subclasses together with
 * TeamScopeFixtures and RunStateFixtures.
 */
trait WaitingRunFixtures
{
    /**
     * A run of a new workflow $workflowName whose first step $stepName waits
     * for an approval by $rule: an approval step (without $gatedTemplate),
     * whose placeholder job waits, or a job step on $gatedTemplate, whose job
     * waits. The step's execution runs; the request is pending. Job steps on
     * $laterTemplates follow it (the workflow belongs to their projects too).
     *
     * @param list<JobTemplate> $laterTemplates
     * @return array{workflow: WorkflowTemplate, step: WorkflowStep, run: WorkflowJob, execution: WorkflowJobStep, job: Job, request: ApprovalRequest}
     */
    protected function approvalWait(
        User $launcher,
        string $workflowName,
        string $stepName,
        ApprovalRule $rule,
        ?JobTemplate $gatedTemplate = null,
        array $laterTemplates = []
    ): array {
        $workflow = $this->namedWorkflow((int)$launcher->id, $workflowName);
        $step = $gatedTemplate === null
            ? $this->namedStep($workflow, 0, $stepName, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id)
            : $this->namedStep($workflow, 0, $stepName, WorkflowStep::TYPE_JOB, (int)$gatedTemplate->id);
        if ($gatedTemplate !== null) {
            $gatedTemplate->approval_rule_id = (int)$rule->id;
            $gatedTemplate->save(false);
        }
        foreach ($laterTemplates as $i => $template) {
            $this->namedStep($workflow, $i + 1, 'later-' . $i, WorkflowStep::TYPE_JOB, (int)$template->id);
        }
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$launcher->id);
        $job = $this->jobOf($gatedTemplate === null ? null : (int)$gatedTemplate->id, (int)$launcher->id, Job::STATUS_PENDING_APPROVAL);
        $execution = $this->executionOf($run, $step, WorkflowJobStep::STATUS_RUNNING, $job);
        $request = $this->requestOf($job, $rule, ApprovalRequest::STATUS_PENDING, time() - 60);

        return ['workflow' => $workflow, 'step' => $step, 'run' => $run, 'execution' => $execution, 'job' => $job, 'request' => $request];
    }

    /**
     * A run of a new workflow $workflowName at its first step $stepName of
     * $type: a pause step (without a job), or a job step on $template whose
     * job has $jobStatus.
     *
     * @return array{workflow: WorkflowTemplate, step: WorkflowStep, run: WorkflowJob, execution: WorkflowJobStep, job: Job|null}
     */
    protected function runAtStep(
        User $launcher,
        string $workflowName,
        string $stepName,
        ?JobTemplate $template = null,
        string $jobStatus = Job::STATUS_RUNNING
    ): array {
        $workflow = $this->namedWorkflow((int)$launcher->id, $workflowName);
        $step = $template === null
            ? $this->namedStep($workflow, 0, $stepName, WorkflowStep::TYPE_PAUSE)
            : $this->namedStep($workflow, 0, $stepName, WorkflowStep::TYPE_JOB, (int)$template->id);
        $run = $this->createWorkflowJob((int)$workflow->id, (int)$launcher->id);
        $job = $template === null ? null : $this->jobOf((int)$template->id, (int)$launcher->id, $jobStatus);
        $execution = $this->executionOf($run, $step, WorkflowJobStep::STATUS_RUNNING, $job);

        return ['workflow' => $workflow, 'step' => $step, 'run' => $run, 'execution' => $execution, 'job' => $job];
    }

    protected function namedWorkflow(int $createdBy, string $name): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate($createdBy);
        $workflow->name = $name;
        $workflow->save(false);

        return $workflow;
    }

    /**
     * A user whose only role grants exactly $permissions.
     *
     * @param list<string> $permissions
     */
    protected function userWithPermissions(string $suffix, array $permissions): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('w2a-' . $suffix . '-' . substr(md5(uniqid('', true)), 0, 8));
        $auth->add($role);
        foreach ($permissions as $name) {
            $permission = $auth->getPermission($name);
            $this->assertNotNull($permission, $name);
            $auth->addChild($role, $permission);
        }
        $user = $this->createUser($suffix);
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    protected function xpathOf(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    /**
     * The text of $node as a reader sees it: whitespace runs as one space.
     */
    protected static function textOf(?\DOMNode $node): string
    {
        return $node === null ? '' : trim((string)preg_replace('/\s+/u', ' ', (string)$node->textContent));
    }

    /**
     * The markup inside $node.
     */
    protected static function innerHtmlOf(?\DOMNode $node): string
    {
        $html = '';
        foreach ($node?->childNodes ?? [] as $child) {
            $html .= (string)$child->ownerDocument?->saveHTML($child);
        }

        return $html;
    }

    /**
     * The first table row whose first cell links to $href (exactly), or
     * null: the row of that run, job, request or schedule, not one that
     * mentions it.
     */
    protected static function rowLinkingTo(\DOMXPath $xpath, string $href): ?\DOMElement
    {
        $rows = $xpath->query(sprintf('//tbody/tr[td[1]//a[@href="%s"]]', $href));
        $row = $rows === false ? null : $rows->item(0);

        return $row instanceof \DOMElement ? $row : null;
    }

    /**
     * The cell texts of $row, in order.
     *
     * @return list<string>
     */
    protected static function cellsOf(?\DOMElement $row): array
    {
        $cells = [];
        foreach ($row?->getElementsByTagName('td') ?? [] as $cell) {
            $cells[] = self::textOf($cell);
        }

        return $cells;
    }
}
