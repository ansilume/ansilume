<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\services\ProjectDeletionService;
use app\services\RunnerGroupDeletionService;
use PHPUnit\Framework\TestCase;

/**
 * What the operator docs say about deletes that are refused because
 * something still uses what is deleted is what the code answers: the
 * runner group delete of the web UI and the API, and the project delete
 * that meets a new job template, quoted as the code words their refusals.
 *
 * Regressions from wave 9 of step 3, each test fails on the docs as they
 * were before it: the web UI deleted a runner group that runners belonged
 * to or job templates used, with its runners, and took it off those
 * templates, those with an approval rule included, while the API refused;
 * and a project delete took a job template created at the same moment
 * with it. No doc named either refusal.
 */
class RefusedDeletesDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.9.0 or older: approvals and workflows';

    private const RUNNER_GROUP_ENTRY = '## Deleting a runner group is refused';

    private const PROJECT_ENTRY = '## Deleting a project is refused';

    /**
     * Regression (wave 9 of step 3): the web UI refuses to delete a runner
     * group in use, as the API did; the docs quote both refusals, say what
     * the web UI did before, and what to do.
     */
    public function testTheRefusedRunnerGroupDeleteIsDocumented(): void
    {
        $runners = '`' . sprintf(RunnerGroupDeletionService::RUNNERS_REFUSED, 'eu-west', 2) . '`';
        $templates = '`' . sprintf(RunnerGroupDeletionService::TEMPLATES_REFUSED, 'eu-west', 3) . '`';
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $entry = $this->flat('docs/troubleshooting.md', self::RUNNER_GROUP_ENTRY);
        foreach (['updating.md' => $updating, 'troubleshooting.md' => $entry] as $where => $text) {
            $this->assertMentions($runners . ' or ' . $templates, $text, $where);
        }
        $this->assertMentions(
            '- Deleting a runner group in the web UI is refused while runners belong to it or job templates use it, as `DELETE /api/v1/runner-groups/{id}` refused it already:',
            $updating,
            'The fixes of updating.md'
        );
        $this->assertMentions(
            'The web UI deleted such a group with its runners, and took it off every job template that used it, those with an approval rule included, whose jobs then waited for good, as no runner claims the jobs of a template without a runner group.',
            $updating,
            'The fixes of updating.md'
        );
        $this->assertMentions('[Deleting a runner group is refused](troubleshooting.md#deleting-a-runner-group-is-refused)', $updating, 'updating.md');
        $this->assertMentions('`DELETE /api/v1/runner-groups/{id}` answers `422` with it, and the group stays.', $entry, 'troubleshooting.md');
        $this->assertMentions('Runners belong to the group, or job templates use it; deleted job templates do not count.', $entry, 'troubleshooting.md');
        $this->assertMentions('with **Move** on the group\'s page or `POST /api/v1/runners/{id}/move`, or delete them', $entry, 'troubleshooting.md');
        $this->assertMentions(
            'A runner group is deleted only while no runner belongs to it and no job template uses it; the web UI and `DELETE /api/v1/runner-groups/{id}` refuse it otherwise.',
            $this->flat('docs/runners.md', '## Runner Groups'),
            'runners.md'
        );
    }

    /**
     * Regression (wave 9 of step 3): a job template created in a project
     * while the project was deleted was deleted with it in 2.9.0 (and made
     * the delete fail with a server error since wave 8); the delete is
     * refused now, with the refusal the docs quote.
     */
    public function testTheRefusedProjectDeleteIsDocumented(): void
    {
        $refusal = '`' . sprintf(ProjectDeletionService::REFUSED, 'web', 1) . '`';
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $entry = $this->flat('docs/troubleshooting.md', self::PROJECT_ENTRY);
        $this->assertMentions(
            '- A job template created in a project, or moved into it, while the project was being deleted was deleted together with the project. Now the delete is refused as when the template existed before, with the templates counted again: '
                . $refusal . ' (an error in the web UI, `422` from `DELETE /api/v1/projects/{id}`), and the project and its templates stay',
            $updating,
            'The fixes of updating.md'
        );
        $this->assertMentions('[Deleting a project is refused](troubleshooting.md#deleting-a-project-is-refused)', $updating, 'updating.md');
        $this->assertMentions('**Symptom:** Deleting a project shows ' . $refusal . ' as an error, `DELETE /api/v1/projects/{id}` answers `422` with it, and the project stays.', $entry, 'troubleshooting.md');
        $this->assertMentions('Deleted job templates do not count: the delete removes them with the project, after it canceled their waiting jobs.', $entry, 'troubleshooting.md');
        $this->assertMentions('the database refuses the delete then, and the templates are counted again', $entry, 'troubleshooting.md');
    }
}
