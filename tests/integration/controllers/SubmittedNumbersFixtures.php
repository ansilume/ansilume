<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\ApiToken;
use app\models\ApprovalRule;
use app\models\Credential;
use app\models\Job;
use app\models\Runner;
use app\models\Schedule;
use app\models\WorkflowStep;
use app\services\CredentialWriteService;
use app\tests\integration\TeamScopeFixtures;

/**
 * The fixtures of the SubmittedNumbers tests, by name, and the {name}
 * placeholders of their cases: one of each thing a whole-number field can
 * name, so that a body with a whole number at the field is taken. Use in
 * WebControllerTestCase subclasses.
 */
trait SubmittedNumbersFixtures
{
    use TeamScopeFixtures;

    /** @var array<string, int|string> the fixtures, by name */
    private array $world = [];

    /**
     * Builds the fixtures: a superadmin with the admin role (admin) and an
     * API token (apiToken), a manual project, a static inventory of it, a
     * runner group and another one (otherGroup), a job template with a
     * trigger token (token), a token credential, a vault password with a
     * secret (vault), an approval rule, a workflow with two job steps and an
     * approval step, a team, another user, a runner, a schedule, and a
     * runner with a token (runnerToken) that runs a job (job).
     *
     * @return array<string, int|string>
     */
    private function buildWorld(): array
    {
        $admin = $this->createUserWithRole('numbers-guard-admin', 'admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $adminId = (int)$admin->id;
        $project = $this->createProject($adminId);
        $inventory = $this->createInventory($adminId);
        $inventory->project_id = (int)$project->id;
        $inventory->content = "all:\n  hosts:\n    web1.example.com:\n";
        $inventory->save(false);
        $group = $this->createRunnerGroup($adminId);
        $template = $this->createJobTemplate((int)$project->id, (int)$inventory->id, (int)$group->id, $adminId);
        $rule = $this->createApprovalRule($adminId);
        $workflow = (int)$this->createWorkflowTemplate($adminId)->id;
        $this->world = [
            'admin' => $adminId,
            'apiToken' => ApiToken::generate($adminId, 'numbers-guard')['raw'],
            'project' => (int)$project->id,
            'inventory' => (int)$inventory->id,
            'group' => (int)$group->id,
            'otherGroup' => (int)$this->createRunnerGroup($adminId)->id,
            'template' => (int)$template->id,
            'token' => $template->generateTriggerToken($adminId),
            'credential' => (int)$this->createCredential($adminId)->id,
            'vault' => $this->vaultPassword($adminId),
            'rule' => (int)$rule->id,
            'workflow' => $workflow,
            'step1' => (int)$this->createWorkflowStep($workflow, 1, WorkflowStep::TYPE_JOB, (int)$template->id)->id,
            'step2' => (int)$this->createWorkflowStep($workflow, 2, WorkflowStep::TYPE_JOB, (int)$template->id)->id,
            'approvalStep' => (int)$this->createWorkflowStep($workflow, 3, WorkflowStep::TYPE_APPROVAL, null, (int)$rule->id)->id,
            'team' => (int)$this->createTeam($adminId)->id,
            'user' => (int)$this->createUserWithRole('numbers-guard-user', 'operator')->id,
            'runner' => (int)$this->createRunner((int)$group->id, $adminId)->id,
            'schedule' => $this->schedule((int)$template->id, $adminId),
        ];
        $this->reportingRunner((int)$group->id, (int)$template->id, $adminId);

        return $this->world;
    }

    /**
     * $text with each {name} of a fixture replaced by the fixture. A JSON
     * string that is a placeholder alone becomes the fixture's ID, "{project}"
     * the number; inside a longer text, as in "after:{step1}", the
     * placeholder alone is replaced.
     */
    private function resolve(string $text): string
    {
        return (string)preg_replace_callback(
            '/"\{(\w+)\}"|\{(\w+)\}/',
            function (array $m): string {
                $name = $m[1] !== '' ? $m[1] : ($m[2] ?? '');

                return isset($this->world[$name]) ? (string)$this->world[$name] : $m[0];
            },
            $text
        );
    }

    /**
     * Whom the approval rule $ruleId names: "names no approver", or "names
     * approvers #<user ID>, ...".
     */
    private function approversOf(int $ruleId): string
    {
        $rule = ApprovalRule::findOne($ruleId);
        $this->assertNotNull($rule, 'the saved rule');
        $ids = $rule->getApproverUserIds();

        return $ids === [] ? 'names no approver' : 'names approvers #' . implode(', #', $ids);
    }

    private function vaultPassword(int $userId): int
    {
        $credential = new Credential();
        $credential->name = 'numbers-guard-vault-' . uniqid('', true);
        $credential->credential_type = Credential::TYPE_VAULT;
        $credential->created_by = $userId;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($credential, ['vault_password' => 'numbers-guard-fixture']), (string)json_encode($credential->errors));

        return (int)$credential->id;
    }

    private function schedule(int $templateId, int $userId): int
    {
        $schedule = new Schedule();
        $schedule->name = 'numbers-guard-schedule-' . uniqid('', true);
        $schedule->job_template_id = $templateId;
        $schedule->cron_expression = '0 3 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = $userId;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);

        return (int)$schedule->id;
    }

    /**
     * A runner of the group $groupId with a token (runnerToken) that runs a
     * job of $templateId (job).
     */
    private function reportingRunner(int $groupId, int $templateId, int $userId): void
    {
        $runner = $this->createRunner($groupId, $userId);
        $token = Runner::generateToken();
        $runner->token_hash = $token['hash'];
        $runner->save(false);
        $job = $this->createJob($templateId, $userId, Job::STATUS_RUNNING);
        $job->runner_id = (int)$runner->id;
        $job->started_at = time();
        $job->save(false);
        $this->world['runnerToken'] = $token['raw'];
        $this->world['job'] = (int)$job->id;
    }
}
