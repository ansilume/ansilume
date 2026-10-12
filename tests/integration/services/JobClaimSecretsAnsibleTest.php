<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\OutputMasker;
use app\components\PlaybookEnvironment;
use app\components\RunnerHttpClient;
use app\components\RunnerProcessExecutor;
use app\helpers\FileHelper;
use app\models\Inventory;
use app\models\Job;
use app\models\Project;
use app\services\JobClaimService;
use app\tests\integration\DbTestCase;
use app\tests\unit\components\SilentController;

/**
 * An older runner gets the password answers inline and runs the command as
 * sent. ansible-playbook prints its command line above verbosity 3, and its
 * effective verbosity is the -v flags plus the configured default. The claim
 * therefore caps the flags at 3 and pins ANSIBLE_VERBOSITY=0 through env(1).
 *
 * Runs the claimed command with real ansible-playbook in a repository whose
 * ansible.cfg sets `verbosity = 1`, for a job launched with -vvvvv.
 */
class JobClaimSecretsAnsibleTest extends DbTestCase
{
    private string $dir;
    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->hasBinary('ansible-playbook')) {
            $this->markTestSkipped('ansible-playbook is not installed.');
        }
        $this->dir = sys_get_temp_dir() . '/claim_secrets_ansible_' . uniqid('', true);
        mkdir($this->dir . '/project', 0o755, true);
        mkdir($this->dir . '/home', 0o700, true);
        file_put_contents($this->dir . '/project/ansible.cfg', "[defaults]\nverbosity = 1\n");
        file_put_contents(
            $this->dir . '/project/site.yml',
            "---\n- name: Use the answer\n  hosts: all\n  gather_facts: false\n  tasks:\n"
            . "    - name: Show its hash\n      ansible.builtin.debug:\n        msg: \"sha1={{ db_password | hash('sha1') }}\"\n"
        );

        $userId = (int)$this->createUser('claim-ansible')->id;
        $template = $this->createPasswordSurveyTemplate($userId);
        Project::updateAll(['local_path' => $this->dir . '/project'], ['id' => $template->project_id]);
        Inventory::updateAll(['content' => "localhost ansible_connection=local\n"], ['id' => $template->inventory_id]);
        $template->verbosity = 5;
        $template->save(false);
        $this->job = $this->createJobWithSealedSecrets($template, $userId);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            FileHelper::removeDirectory($this->dir);
        }
        parent::tearDown();
    }

    /**
     * Regression: an older runner ran `-vvvvv --extra-vars {...answer...}`,
     * which prints the answer with the command line. Now the play still gets
     * the answer, and the output does not show it, although the repository
     * raises the verbosity.
     */
    public function testTheClaimedCommandForAnOlderRunnerKeepsTheAnswerOutOfTheOutput(): void
    {
        $payload = $this->claimForOlderRunner();

        [$exitCode, $output] = $this->runLikeAnOlderRunner($payload['command'], (string)$payload['inventory_content']);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('sha1=' . sha1(self::SURVEY_PASSWORD_ANSWER), $output, 'the play got the answer');
        $this->assertStringNotContainsString(self::SURVEY_PASSWORD_ANSWER, $output);
    }

    /**
     * Why the env(1) pin: capping only the -v flags at 3 still prints the
     * answer, because the repository's `verbosity = 1` adds to them.
     */
    public function testCappingOnlyTheFlagsStillLeaksWithTheRepositorysVerbosity(): void
    {
        $payload = $this->claimForOlderRunner();
        $this->assertSame(['/usr/bin/env', 'ANSIBLE_VERBOSITY=0'], array_slice($payload['command'], 0, 2));
        $this->assertContains('-vvv', $payload['command']);

        [$exitCode, $output] = $this->runLikeAnOlderRunner(array_slice($payload['command'], 2), (string)$payload['inventory_content']);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString(self::SURVEY_PASSWORD_ANSWER, $output);
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function claimForOlderRunner(): array
    {
        /** @var JobClaimService $claim */
        $claim = \Yii::$app->get('jobClaimService');

        return $claim->buildExecutionPayload($this->job);
    }

    /**
     * What runners 1.0 to 2.8.1 do with `command`: put the static inventory
     * in a file and run it in the project directory, where ansible.cfg
     * applies.
     *
     * @param array<int, string> $command
     * @return array{0: int, 1: string} exit code and the job log without colours
     */
    private function runLikeAnOlderRunner(array $command, string $inventory): array
    {
        file_put_contents($this->dir . '/inventory', $inventory);
        $command = array_map(fn (string $part): string => $part === '__INVENTORY_TMP__' ? $this->dir . '/inventory' : $part, $command);
        $env = array_merge(PlaybookEnvironment::build(getenv() ?: [], $this->dir . '/tasks.ndjson'), [
            'HOME' => $this->dir . '/home',
            'ANSIBLE_HOME' => $this->dir . '/home/.ansible',
            'ANSIBLE_LOCAL_TEMP' => $this->dir . '/home/tmp',
        ]);

        $posts = [];
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('post')->willReturnCallback(static function (string $path, array $body) use (&$posts): array {
            $posts[] = $body;
            return ['ok' => true];
        });
        $executor = new RunnerProcessExecutor($http, new SilentController('runner', \Yii::$app), new OutputMasker([]));
        [$exitCode] = $executor->run(1, array_values($command), ['project_path' => $this->dir . '/project'], $env, 2);
        $output = implode('', array_map(static fn (array $body): string => (string)($body['content'] ?? ''), $posts));

        return [$exitCode, (string)preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $output)];
    }

    private function hasBinary(string $name): bool
    {
        foreach (explode(':', (string)getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable($dir . '/' . $name)) {
                return true;
            }
        }

        return false;
    }
}
