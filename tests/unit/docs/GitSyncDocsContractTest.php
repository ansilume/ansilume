<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\GitSyncDiagnostics;
use app\components\RunnerProjectSync;
use app\models\AuditLog;
use app\models\Project;
use app\services\ProjectService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use yii\base\Module;
use yii\console\Controller;
use yii\db\BaseActiveRecord;

/**
 * What the docs say about how a sync hands a project's branch and URL to
 * git is what ProjectService and RunnerProjectSync do: docs/updating.md
 * (the item on a project's branch, the fixes, the known limits and the
 * deployment of the section for the update from 2.10.1), troubleshooting.md,
 * web/openapi.yaml (ProjectInput) and CLAUDE.md. The item on repository
 * URLs is pinned by WorkflowStepsUpdateDocsContractTest;
 * GitSyncBehaviourDocsContractTest runs a real sync of the server against
 * the texts of both, and the query of projects whose branch starts with
 * "-".
 *
 * Regressions (wave 5d of 2.11.0), each failing on the docs from before:
 * nothing said that git read a branch that starts with "-" as one of its
 * options, nor that runners need the update for the fixes of their syncs;
 * the section said runners needed no update.
 *
 * Regressions (wave 5e of 2.11.0), each failing on the docs from before:
 * the docs and the spec said the project form and the API accept a branch
 * that starts with "-", which they refuse now (Project::BRANCH_FORMAT);
 * the entry on a failed fast-forward read as if a force push had worked up
 * to 2.10.1; and CLAUDE.md exempted a POST from asking for JSON.
 *
 * Regression (wave 5f of 2.11.0), failing on the docs from before: they
 * said the form and the API refuse such a branch for every project, while
 * the rule checks the branch of git projects only, as no sync reads that of
 * a manual project, and the query listed no branch with whitespace, which
 * saving refuses too.
 */
class GitSyncDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;
    use ReadsTheSyncCommands;
    use ReadsTheUpdateSection;

    /** The checkout of project 5 on the server and on runners, as the docs' examples name it. */
    private const CHECKOUT = '/var/www/runtime/projects/5';

    /** The URL of the examples, with credentials. */
    private const URL = 'https://deploy:token@git.example.com/org/repo.git';

    /**
     * regression: nothing said that git read a branch that starts with "-"
     * as one of its options. The item says how the branch reached git up to
     * 2.10.1 and how now, quotes the failure as ProjectService::GIT_FAILED
     * words it and the failure of a runner's sync, gives the query of such
     * projects, and steps 11 and 12 of the checklist point to the item and
     * the deployment.
     */
    public function testRegressionTheBranchItemSaysWhatGitDidWithABranchThatStartsWithADash(): void
    {
        $item = $this->item('**Security: a project\'s branch read as an option of git.**');
        $expected = [
            'Up to 2.10.1 the branch of a project went to git after `origin`, in `git fetch … origin <branch>` on the server and `git pull --ff-only origin <branch>` on runners, where git read a branch that starts with `-` as one of its options.',
            'Whoever could change a project, as operators can by default, could so make git run a command of their choice when it fetched: over ssh on the git server, as the account the sync signs in with, where that account may run commands; over https git does not run it.',
            'From 2.11.0 on, git gets the branch after `--`, which ends its options, and takes it for a branch name: a sync with a branch that starts with `-` fails, as git finds no such branch or refuses it, such as `'
                . sprintf(ProjectService::GIT_FAILED, 128, "fatal: couldn't find remote ref -x") . '` on the server, and a runner fails the job with `Git sync failed (exit 128, …)` and the same message of git.',
            'Runners update a checkout with `git fetch` and `git merge --ff-only FETCH_HEAD`, because `git pull` hands the branch on to its fetch without `--`.',
            'Runners older than 2.11.0 still hand the branch to `git pull`: update them (see **Database and deployment**).',
            '-- Git projects whose branch starts with "-" (which git read as one of its options) or contains whitespace',
            'The `' . AuditLog::ACTION_PROJECT_CREATED . '` and `' . AuditLog::ACTION_PROJECT_UPDATED . '` entries of the audit log name who saved a project, not its branch.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The branch item of updating.md');
        }
        $this->assertStringNotContainsString('upload-pack', $item, 'The item names no option that runs a command.');
        $this->assertStringContainsString('"Git sync failed (exit %d, %.1fs):\ncommand: %s\n%s%s\n%s"', $this->source('components/RunnerProjectSync.php'), 'The failure of a runner\'s sync.');
        $contexts = [];
        foreach (['controllers/ProjectController.php', 'controllers/api/v1/ProjectsController.php'] as $controller) {
            preg_match_all('/ACTION_PROJECT_(?:CREATED|UPDATED),\s*\'project\',\s*\$model->id,\s*null,\s*(\[[^\]]*\])/', $this->source($controller), $found);
            array_push($contexts, ...$found[1]);
        }
        $this->assertCount(4, $contexts, 'The audit entries of a saved project, on the pages and in the API.');
        foreach ($contexts as $context) {
            $this->assertStringNotContainsString('scm_branch', $context, 'An audit entry of a saved project records no branch.');
        }
        $this->assertMentions(
            '11. Run the read-only query under **Security: a project\'s branch read as an option of git**, and give each project it lists the branch it should check out: from 2.11.0 on, every save of such a project as a git project that keeps that branch is refused, and every sync of one whose branch starts with `-` fails.',
            $this->checklist(11),
            'Step 11 of the checklist'
        );
        $this->assertMentions(
            '12. Update every runner, also those that run apart from the server (see **Database and deployment**): a runner older than 2.11.0 still fetches from the URL its checkout was cloned with, and still hands a project\'s branch to git where git reads it as an option.',
            $this->checklist(12),
            'Step 12 of the checklist'
        );
    }

    /**
     * regression (wave 5e): the branch item, the known limits and the
     * troubleshooting entry said the project form and the API accept a
     * branch that starts with "-". For git projects they refuse it now, and
     * a branch with whitespace, with Project::BRANCH_FORMAT, also on the
     * next save of a git project that has one; the intro, step 11 and the
     * API contract change say so. The rule refuses what the docs say it
     * refuses.
     */
    public function testRegressionTheDocsSayTheFormAndTheApiRefuseABranchThatStartsWithADash(): void
    {
        $refused = '`' . Project::BRANCH_FORMAT . '`';
        $this->assertSame('The branch cannot start with "-" or contain whitespace.', Project::BRANCH_FORMAT);
        $item = $this->item('**Security: a project\'s branch read as an option of git.**');
        $expected = [
            'From 2.11.0 on, for git projects, the project form and the API refuse such a branch, and any branch with whitespace: the form shows ' . $refused
                . ' under the Branch field, and `POST /api/v1/projects` and `PUT /api/v1/projects/{id}` answer `422` with it; nothing is saved and no sync is queued.',
            'A git project saved with such a branch before keeps it until it is changed: saving it as a git project, in its Edit form or with a `PUT` that sends no other branch, is refused with the same text.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'The branch item of updating.md');
        }
        $this->assertStringNotContainsString('still accept such a branch', $item);
        $section = $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION);
        $this->assertStringNotContainsString('accept a branch that starts with', $this->item('**Known limits.**'), 'The known limit is gone.');
        $this->assertMentions(
            'Git no longer reads a project\'s branch as one of its options, and for git projects the project form and the API refuse a branch that starts with `-` or contains whitespace (see **Security: a project\'s branch read as an option of git**).',
            $section,
            'The intro of updating.md'
        );
        $this->assertMentions(
            '- For git projects, `POST /api/v1/projects` and `PUT /api/v1/projects/{id}` answer `422` ' . $refused
                . ' for a `scm_branch` that starts with `-` or contains whitespace, also for a `PUT` of a project whose stored branch is such a branch and that sends no other branch, and for a `PUT` that makes a manual project with such a branch a git project; nothing is written. They saved such a branch before.',
            $this->between($section, '**API contract change:**', '**Database and deployment.**'),
            'The API contract change of updating.md'
        );
        $entry = $this->flat('docs/troubleshooting.md', '## Project sync fails: the branch starts with "-"');
        $this->assertMentions('Saving the project, in its Edit form or with `PUT /api/v1/projects/{id}`, is refused with ' . $refused, $entry, 'troubleshooting.md, on a branch');
        $this->assertMentions(
            'From 2.11.0 on, for git projects, the project form and the API refuse such a branch, and any branch with whitespace, with ' . $refused
                . '; a git project saved with one before keeps it, and saving it as a git project is refused with the same text until its branch is changed.',
            $entry,
            'troubleshooting.md, on a branch'
        );
        $this->assertStringNotContainsString('form and the API accept such a branch', $entry);

        $rule = $this->branchRule();
        $this->assertSame($this->branchPattern(), $rule['pattern'] ?? null, 'The rule the docs quote.');
        $this->assertSame(Project::BRANCH_FORMAT, $rule['message'] ?? null, 'The rule the docs quote.');
        foreach (['-x', '--upload-pack=touch /tmp/x', 'feature x', ' main', "\tmain", "main\n"] as $branch) {
            $this->assertSame(0, preg_match($this->branchPattern(), $branch), (string)json_encode($branch) . ' is refused, as the docs say.');
        }
        foreach (['main', 'release-1.2', 'feature/x', 'hotfix-'] as $branch) {
            $this->assertSame(1, preg_match($this->branchPattern(), $branch), $branch . ' is a branch the rule takes.');
        }
    }

    /**
     * regression (wave 5f): the docs said the project form and the API
     * refuse such a branch for every project, and the query of the branch
     * item listed no branch with whitespace. The rule checks the branch of
     * git projects only (its 'when', and in the browser its 'whenClient',
     * which reads the form's SCM type), as no sync reads the branch of a
     * manual project: neither the server (ProjectService::sync()) nor a
     * runner (RunnerProjectSync::sync()). The item, the API contract change
     * and the troubleshooting entry say so; GitSyncBehaviourDocsContractTest
     * runs the query.
     */
    public function testRegressionTheDocsSayOnlyTheBranchOfAGitProjectIsChecked(): void
    {
        $this->assertMentions(
            'The branch of a manual project is not checked: no sync reads it, and the form hides the Branch field while Manual (no SCM) is chosen; saving the project as a git project checks it.',
            $this->item('**Security: a project\'s branch read as an option of git.**'),
            'The branch item of updating.md'
        );
        $this->assertMentions(
            'This read-only query lists the git projects whose branch starts with `-` or contains whitespace:',
            $this->item('**Security: a project\'s branch read as an option of git.**'),
            'The branch item of updating.md'
        );
        $api = $this->between($this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), '**API contract change:**', '**Database and deployment.**');
        $this->assertMentions('They saved such a branch before. The branch of a manual project is not checked.', $api, 'The API contract change of updating.md');
        $this->assertMentions(
            'The branch of a manual project is not checked: no sync reads it.',
            $this->flat('docs/troubleshooting.md', '## Project sync fails: the branch starts with "-"'),
            'troubleshooting.md, on a branch'
        );

        $when = $this->branchRule()['when'] ?? null;
        $this->assertInstanceOf(\Closure::class, $when, 'The rule checks the branch of git projects only.');
        $attributes = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        foreach ([Project::SCM_TYPE_GIT => true, Project::SCM_TYPE_MANUAL => false] as $type => $checked) {
            $typed = (new \ReflectionClass(Project::class))->newInstanceWithoutConstructor();
            $attributes->setValue($typed, ['scm_type' => $type]);
            $this->assertSame($checked, $when($typed), 'The branch of a ' . $type . ' project is ' . ($checked ? '' : 'not ') . 'checked.');
        }
        $whenClient = $this->branchRule()['whenClient'] ?? null;
        $this->assertIsString($whenClient);
        $this->assertStringContainsString("document.getElementById('project-scm_type')", $whenClient, 'The browser reads the SCM type of the form.');
        $this->assertStringContainsString("type.value === '" . Project::SCM_TYPE_GIT . "'", $whenClient, 'The browser checks the branch while Git is chosen.');
        $form = $this->source('views/project/form.php');
        $this->assertStringContainsString("Project::SCM_TYPE_MANUAL => 'Manual (no SCM)',", $form, 'The choice the docs name.');
        $this->assertStringContainsString('<div id="git-fields" <?= $model->scm_type !== Project::SCM_TYPE_GIT ? \'style="display:none"\'', $form, 'The form hides the Branch field of a manual project.');
        $this->assertStringContainsString(
            "if (\$project->scm_type !== Project::SCM_TYPE_GIT) {\n            \$this->markSynced(\$project);\n            return;",
            $this->source('services/ProjectService.php'),
            'The server syncs no manual project with git.'
        );

        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $checkout = sys_get_temp_dir() . '/docs-manual-' . uniqid('', true);
        $payload = ['scm_type' => Project::SCM_TYPE_MANUAL, 'scm_url' => self::URL, 'scm_branch' => '-x', 'project_path' => $checkout];
        $this->assertNull((new RunnerProjectSync(new Controller('runner', $app)))->sync($payload), 'A runner syncs no manual project.');
        $this->assertDirectoryDoesNotExist($checkout, 'A runner runs no git for a manual project.');
    }

    /**
     * regression: the section said runners needed no update. Only runners
     * of 2.11.0 point a checkout at the project's current URL and give git
     * the branch after "--", so the deployment says how to update each kind
     * of runner, as the notes of 2.8.1 do.
     */
    public function testRegressionTheDeploymentSaysToUpdateEveryRunner(): void
    {
        $item = $this->item('**Database and deployment.**');
        $this->assertStringNotContainsString('runners need no update', $item);
        $expected = [
            '**Database and deployment.** One migration runs, `m000086_000000_add_user_ldap_sync_disabled`: it adds `user.ldap_sync_disabled` (boolean, default 0) and fills it from the audit log (see **Security: LDAP accounts deactivated in Ansilume**); no setting is new.',
            'so every process runs the new code, the bundled runners included',
            'Runners older than 2.11.0 keep running jobs, but only runners of 2.11.0 point a checkout at the project\'s current repository URL before they fetch, and give git the project\'s branch after `--` (see **Security: repository URLs in syncs and logs** and **Security: a project\'s branch read as an option of git**). Update the other runners too:',
            '- Ansible deploy role: running the role for the new version updates its runners.',
            '- External runners: deploy the new `ghcr.io/ansilume/ansilume-runner` image.',
            '- Runners installed without Docker: `git pull && composer install --no-dev --optimize-autoloader`, then restart the process (`sudo systemctl restart ansilume-runner`).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $item, 'Database and deployment of updating.md');
        }
        $this->assertMentions('and runners need the update too (see **Database and deployment**). What to do:', $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION), 'The intro of updating.md');
        $this->assertMentions('sudo systemctl enable --now ansilume-runner', $this->flat('docs/runners.md'), 'The service the deployment restarts is the one runners.md installs.');
    }

    /**
     * The fixes name the commands a runner runs now (RunnerProjectSync) and
     * the rows the server's sync output gains (ProjectService), with the
     * URL as GitSyncDiagnostics::redactUrl() shows it.
     */
    public function testTheFixesAndTheKnownLimitsNameTheCommandsOfASync(): void
    {
        $section = $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION);
        $fixes = $this->between($section, '**Fixes that change behaviour:**', '**API contract change:**');
        [$setUrl, $fetch, $merge] = $this->runnerCommands(false);
        $this->assertSame(['git', '-C', self::CHECKOUT, 'remote', 'set-url'], array_slice($setUrl, 0, 5));
        $this->assertSame(['git', '-C', self::CHECKOUT, 'fetch'], array_slice($fetch, 0, 4));
        $expected = [
            'A runner updates an existing checkout with `git remote set-url`, `git fetch` and `git merge --ff-only FETCH_HEAD` instead of `git pull --ff-only`, and a failed sync names the command that failed in the job log, such as `command: '
                . implode(' ', $merge) . '`.',
            'Its clone passes the URL after `--` (`' . GitSyncDiagnostics::redactUrl(implode(' ', $this->runnerCommands(true)[0])) . '`).',
            'The server\'s sync output gains the row of `git remote set-url` and shows the fetch as `'
                . rtrim(sprintf(ProjectService::GIT_COMMAND_ROW, 'git -C ' . self::CHECKOUT . ' fetch --progress --depth 1 -- origin main')) . '`.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $fixes, 'The fixes of updating.md');
        }
        $this->assertSame(self::CHECKOUT, (new ProjectService())->workspacePath . '/5', 'The checkout the examples name.');
    }

    /**
     * The troubleshooting entries say what a failed fast-forward of a
     * runner's checkout and a branch that starts with "-" look like, with
     * the command a runner names (RunnerProjectSync), and what to do.
     */
    public function testTheTroubleshootingEntriesSayWhatAFailedSyncLooksLike(): void
    {
        $merge = $this->runnerCommands(false)[2];
        $fastForward = $this->flat('docs/troubleshooting.md', '## A job fails at once: git cannot fast-forward the runner\'s checkout');
        $expected = [
            '`Git sync failed (exit 128, …)` with `command: ' . implode(' ', $merge) . '` and `fatal: refusing to merge unrelated histories` or `fatal: Not possible to fast-forward, aborting.`',
            'From 2.11.0 on, a runner points its checkout at the project\'s current URL before every job, fetches the branch and only fast-forwards; it never discards what the checkout holds.',
            'Remove the project\'s checkout on that runner (`/var/www/runtime/projects/<project ID>`, in its container if it runs in one); its next job clones the repository again.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $fastForward, 'troubleshooting.md, on a failed fast-forward');
        }
        $branch = $this->flat('docs/troubleshooting.md', '## Project sync fails: the branch starts with "-"');
        $this->assertMentions('`' . sprintf(ProjectService::GIT_FAILED, 128, "fatal: couldn't find remote ref -x") . '`', $branch, 'troubleshooting.md, on a branch');
        $this->assertMentions('saving a git project queues a sync.', $branch, 'troubleshooting.md, on a branch');
    }

    /**
     * regression (wave 5e): the entry on a failed fast-forward said "Up to
     * 2.10.1 such a runner kept fetching from the URL its checkout was
     * cloned with", which also reads as covering a force push; a runner of
     * 2.10.1 pulled with --ff-only, so a force push failed its job then too,
     * as updating.md says.
     */
    public function testRegressionTheFastForwardEntrySaysAForcePushFailedUpTo2101Too(): void
    {
        $fastForward = $this->flat('docs/troubleshooting.md', '## A job fails at once: git cannot fast-forward the runner\'s checkout');
        $this->assertMentions(
            'Up to 2.10.1 a runner whose project\'s URL had changed kept fetching from the URL its checkout was cloned with; a force push failed its fast-forward then too.',
            $fastForward,
            'troubleshooting.md, on a failed fast-forward'
        );
        $this->assertStringNotContainsString('Up to 2.10.1 such a runner kept fetching', $fastForward);
        $this->assertMentions(
            'as a force push of the branch did before',
            $this->item('**Security: repository URLs in syncs and logs.**'),
            'The repository URL item of updating.md says the same.'
        );
    }

    /**
     * The spec says what ProjectInput's scm_url and scm_branch do to a sync,
     * and quotes the refusal of a branch (Project::BRANCH_FORMAT;
     * OpenApiProjectsContractTest checks that the API answers it). It gives
     * no pattern: the server's rule takes an empty branch, and its "\s"
     * (PCRE with Unicode properties) and that of a JSON Schema pattern
     * (ECMA-262) differ on a few characters, such as U+FEFF.
     */
    public function testTheSpecSaysWhatASyncDoesWithTheUrlAndTheBranch(): void
    {
        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $properties = $spec['components']['schemas']['ProjectInput']['properties'] ?? null;
        $this->assertIsArray($properties);
        $url = trim((string)preg_replace('/\s+/', ' ', (string)($properties['scm_url']['description'] ?? '')));
        $this->assertStringContainsString(
            'Each sync points the project\'s checkout at the URL as it is then, on the server and on runners, so a changed URL counts from the next sync; a runner older than 2.11.0 keeps the URL its checkout was cloned with until the checkout is removed.',
            $url
        );
        $branch = trim((string)preg_replace('/\s+/', ' ', (string)($properties['scm_branch']['description'] ?? '')));
        $this->assertSame(
            'The branch that syncs of a git project check out; git takes it for a branch name, never for one of its options. For git projects (`scm_type` `git` once the request is applied), a branch that starts with `-` or contains whitespace is refused: `POST` and `PUT` answer 422 `'
                . Project::BRANCH_FORMAT . '` and save nothing. A git project saved with such a branch before 2.11.0 keeps it until it is changed; until then every `PUT` that sends no other branch and leaves it a git project answers that 422.'
                . ' The branch of a manual project is not checked, as no sync reads it; a `PUT` that makes a manual project with such a branch a git project answers that 422. An empty branch is not checked.',
            $branch
        );
        $this->assertIsArray($properties['scm_branch']);
        $this->assertArrayNotHasKey('pattern', $properties['scm_branch'], 'No pattern: it would not be the server\'s rule.');
        $this->assertSame(1, preg_match($this->branchPattern(), "a\u{FEFF}b"), 'The server takes U+FEFF, which an ECMA-262 "\\s" refuses.');
    }

    /**
     * CLAUDE.md's rule 9 says to pass what a user controls to git after
     * "--" and not to pull, and the list of what both deployment paths keep
     * in sync names the sync of a checkout; its rule for scripts names the
     * fetch() guard, which covers every method since wave 5e (regression:
     * it exempted a POST).
     */
    public function testClaudeMdNamesTheRulesOfGitArgumentsAndOfFetch(): void
    {
        $claude = $this->flat('CLAUDE.md');
        $expected = [
            '9. When shell execution is required, escape arguments safely and keep commands auditable. Pass a URL, branch or other value a user controls to git after `--`, where git cannot read it as an option; `git pull` hands its refspecs on to `git fetch` without `--`, so fetch and merge instead (`ProjectService::gitPull()`, `RunnerProjectSync`).',
            '- A sync of an existing checkout points its origin at the project\'s current URL before it fetches, on the server (`ProjectService::gitPull()`) and on runners (`RunnerProjectSync`): keep both paths doing so.',
            '- A script\'s fetch() asks for JSON, whatever its method (`headers: { \'Accept\': \'application/json\' }`, and `credentials: \'same-origin\'` for a GET), so that signed out it is refused with 403 instead of being sent to the login page, which for a GET also keeps the fetched address to return to after the next sign-in; it handles a refusal (401, 403) before it reads the answer as JSON, and treats any other answer that is not OK as a failure.',
            '`tests/unit/views/FetchAsksForJsonTest.php` enforces the header for every fetch( in views/ and web/js/.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $claude, 'CLAUDE.md');
        }
        $this->assertStringNotContainsString('(a POST is exempt)', $claude, 'Every fetch asks for JSON.');
        $this->assertTrue(method_exists(ProjectService::class, 'gitPull'));
        $this->assertFileExists(dirname(__DIR__, 3) . '/tests/unit/views/FetchAsksForJsonTest.php');
        foreach ($this->runnerCommands(false) as $command) {
            $this->assertNotContains('pull', $command, 'A runner does not pull.');
        }
    }

    /**
     * The commands a runner runs for the checkout of project 5 at the
     * example URL and the branch main: a clone, or those that update an
     * existing checkout.
     *
     * @return list<list<string>>
     */
    private function runnerCommands(bool $isClone): array
    {
        return $this->runnerCommandsFor($isClone, self::URL, 'main', self::CHECKOUT);
    }

    private function source(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
    }

    /**
     * Project's rule of the branch format, the only "match" rule of
     * scm_branch.
     *
     * @return array<int|string, mixed>
     */
    private function branchRule(): array
    {
        $project = (new \ReflectionClass(Project::class))->newInstanceWithoutConstructor();
        $rules = array_values(array_filter($project->rules(), static fn (mixed $rule): bool => is_array($rule) && ($rule[0] ?? null) === ['scm_branch'] && ($rule[1] ?? null) === 'match'));
        $this->assertCount(1, $rules, 'The rule the docs quote.');
        $this->assertIsArray($rules[0]);

        return $rules[0];
    }

    /**
     * The pattern of Project's branch rule (Project::BRANCH_PATTERN, private).
     */
    private function branchPattern(): string
    {
        $pattern = (new \ReflectionClass(Project::class))->getConstant('BRANCH_PATTERN');
        $this->assertIsString($pattern);

        return $pattern;
    }
}
