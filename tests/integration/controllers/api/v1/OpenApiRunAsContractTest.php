<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\models\JobTemplate;
use app\services\ApprovalSettlement;
use app\services\JobCancellationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What web/openapi.yaml says about the launcher of a job, the user it runs
 * as and who therefore never approves it, and about votes on jobs that can
 * never run, is what the code does. The API answers themselves are compared
 * with the spec in OpenApiRunAsResponseContractTest.
 *
 * Regressions from the last review round of step 3, each test fails on the
 * spec as it was before: it named a schedule's creator as the launcher of
 * its jobs, did not say that the user a trigger runs as never approves its
 * jobs, also the template's creator for an older token, and did not say
 * that a vote on a job of a deleted job template closes its request.
 */
class OpenApiRunAsContractTest extends TestCase
{
    private const SCHEMAS = ['components', 'schemas'];

    /** @var array<mixed>|null */
    private ?array $spec = null;

    /**
     * Regression (decision 4): the launcher of a scheduled job is the user
     * the schedule runs as, which is no longer its creator for good.
     */
    public function testTheLauncherOfAScheduledJobIsTheUserTheScheduleRunsAs(): void
    {
        $launchedBy = $this->textAt([...self::SCHEMAS, 'ApprovalRequest', 'properties', 'launched_by', 'description']);
        $this->assertStringContainsString('The user who launched the job: for a schedule, the user it runs as; for a trigger, the user it runs as;', $launchedBy);
        $this->assertStringNotContainsString('for a schedule, its creator', $launchedBy);
        $this->assertStringContainsString(
            'The caller becomes the user the schedule runs as, returned as `created_by` (**Runs as** in the web UI)',
            $this->textAt(['paths', '/api/v1/schedules', 'post', 'description'])
        );
        $input = $this->textAt([...self::SCHEMAS, 'ScheduleInput', 'description']);
        $this->assertStringContainsString('a schedule runs as the user who created it, or who last changed its job template, cron expression, timezone or extra vars, or enabled it', $input);
        $this->assertStringNotContainsString("a schedule's creator is the user who created it", $input);
        $this->assertStringNotContainsString('The schedule keeps its creator', $this->textAt(['paths', '/api/v1/schedules/{id}', 'put', 'description']));
    }

    /**
     * The user a trigger runs as, also the template's creator for a token
     * generated before Ansilume recorded who generated it, is the launcher
     * of its jobs and never approves them (the code's fallback:
     * JobTemplate::getTriggerUserId()).
     */
    public function testTheUserATriggerRunsAsNeverApprovesItsJobs(): void
    {
        $template = new JobTemplate();
        $template->created_by = 7;
        $template->trigger_token_created_by = null;
        $this->assertSame(7, $template->getTriggerUserId(), 'An older token runs as the creator.');

        foreach (['JobTemplate' => 'trigger\'s jobs', 'WorkflowTemplate' => 'run\'s approval steps and job steps'] as $schema => $what) {
            $this->assertStringContainsString(
                "This user is the launcher of the {$what} and never approves them.",
                $this->textAt([...self::SCHEMAS, $schema, 'properties', 'trigger_user_id', 'description']),
                "{$schema}.trigger_user_id"
            );
        }
        $this->assertStringContainsString(
            "This user is the job's launcher (`launched_by`) and never approves it, so for an older token the template's creator does not approve the jobs of its trigger until the token is generated again.",
            $this->textAt(['paths', '/trigger/fire', 'post', 'description'])
        );
        $this->assertStringContainsString(
            "This user is the launcher of the run's approval steps and job steps and never approves them, so for an older token the workflow's creator does not approve them until the token is generated again.",
            $this->textAt(['paths', '/trigger/fire-workflow', 'post', 'description'])
        );
    }

    /**
     * Regression (decision 4): wherever the spec says approval steps are for
     * admins, it says so of gated job steps too.
     */
    public function testGatedJobStepsAreForAdminsWhereverApprovalStepsAre(): void
    {
        $this->assertStringContainsString(
            'only for admins when an approval step or a gated job step (a job step whose job template has an approval rule) is in the list or in the workflow (403)',
            $this->textAt([...self::SCHEMAS, 'WorkflowTemplateInput', 'properties', 'steps', 'description'])
        );
        $this->assertStringContainsString(
            'A job template with an approval rule makes the step a gated job step, which is for admins as an approval step is: it needs `approval-rule.update` (403 otherwise).',
            $this->textAt([...self::SCHEMAS, 'WorkflowStepInput', 'properties', 'job_template_id', 'description'])
        );
        $this->assertStringContainsString(
            'and so do adding a job step whose job template has a rule (a gated job step) and changing the steps of a workflow that has one.',
            $this->tag('Approval Rules')
        );
        $this->assertStringContainsString(
            'That is the user who created the schedule, or who last changed its job template, cron expression, timezone or extra vars, or enabled it; renaming and disabling keep the user. This user is the launcher of the schedule\'s jobs and never approves them.',
            $this->tag('Schedules')
        );
    }

    /**
     * A vote on a job that can never run, because its workflow step or run
     * ended or its job template was deleted, is refused with 409, closes the
     * request and cancels the job.
     */
    public function testTheVotesOnJobsThatCanNeverRunAreDocumented(): void
    {
        $this->assertSame(JobCancellationService::REASON_TEMPLATE_DELETED, ApprovalSettlement::REASON_TEMPLATE_DELETED);
        $this->assertStringContainsString(
            'also when the job still waits but its workflow run has ended, which cancels the job too, and likewise when its workflow step no longer runs or its job template was deleted;',
            $this->textAt(['paths', '/api/v1/approvals/{id}/approve', 'post', 'description'])
        );
        $this->assertStringContainsString(
            'or still waits but can never run, because its workflow step or run has ended or its job template was deleted (status `canceled`: the vote closed the request, and canceled such a job).',
            $this->textAt(['components', 'responses', 'ApprovalDecisionConflict', 'description'])
        );
        // Regression (wave 8 of step 3): the purge of a project's deleted
        // templates left their waiting jobs without a template, for good.
        $this->assertStringContainsString(
            'Their waiting jobs (`pending`, `queued`, `pending_approval`) are canceled first, as the delete of a job template cancels them: `job.canceled` audit entries with `reason: '
                . JobCancellationService::REASON_TEMPLATE_DELETED . '`',
            $this->textAt(['paths', '/api/v1/projects/{id}', 'delete', 'description'])
        );
    }

    /**
     * The text at $keys, on one line.
     *
     * @param list<string> $keys
     */
    private function textAt(array $keys): string
    {
        $node = $this->spec();
        foreach ($keys as $key) {
            $this->assertIsArray($node, 'web/openapi.yaml has no ' . implode('.', $keys) . '.');
            $this->assertArrayHasKey($key, $node, 'web/openapi.yaml has no ' . implode('.', $keys) . '.');
            $node = $node[$key];
        }
        $this->assertIsString($node, 'web/openapi.yaml: ' . implode('.', $keys) . ' is no text.');

        return trim((string)preg_replace('/\s+/', ' ', $node));
    }

    /**
     * The description of the tag $name, on one line.
     */
    private function tag(string $name): string
    {
        $tags = $this->spec()['tags'] ?? null;
        $this->assertIsArray($tags);
        $descriptions = array_column($tags, 'description', 'name');
        $this->assertArrayHasKey($name, $descriptions, "The tag {$name} has a description.");

        return trim((string)preg_replace('/\s+/', ' ', (string)$descriptions[$name]));
    }

    /** The spec file under test. */
    protected function specPath(): string
    {
        return dirname(__DIR__, 5) . '/web/openapi.yaml';
    }

    /**
     * @return array<mixed>
     */
    private function spec(): array
    {
        if ($this->spec === null) {
            $spec = Yaml::parseFile($this->specPath());
            $this->assertIsArray($spec);
            $this->spec = $spec;
        }

        return $this->spec;
    }
}
