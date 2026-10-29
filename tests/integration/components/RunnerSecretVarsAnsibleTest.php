<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\OutputMasker;
use app\components\PlaybookEnvironment;
use app\components\RunnerHttpClient;
use app\components\RunnerProcessExecutor;
use app\components\RunnerSecretVars;
use app\helpers\FileHelper;
use app\tests\unit\components\SilentController;
use PHPUnit\Framework\TestCase;

/**
 * Runs real ansible-playbook the way the runner does (PlaybookEnvironment,
 * RunnerSecretVars, RunnerProcessExecutor with the OutputMasker) with a
 * password survey answer.
 *
 * From the file, the play gets the answer, and -vvvv prints only the file's
 * path where it prints the command line; inline, the same run prints the
 * answer. What the playbook itself prints, including templated task names
 * in the callback plugin's records, is masked. The plugin keeps the 0600
 * mode of the task records file the runner creates for it.
 */
class RunnerSecretVarsAnsibleTest extends TestCase
{
    private const ANSWER = 'ansible-answer-marker-7731';

    /** Password answers sent as JSON numbers, which reach the runner as floats. */
    private const FLOAT_ANSWERS = [
        'f1' => 0.12345678901234568,
        'f2' => 3.141592653589793,
        'f3' => 1.2345678901234569e+23,
        'f4' => 1e20,
        'f5' => 1.5e-7,
        'f6' => 0.00001,
        'f7' => 12345678901234.5,
    ];

    /** Parts of the texts in which Python, and so Ansible, writes FLOAT_ANSWERS. */
    private const FLOAT_TEXTS = ['2345678901234', '1415926535', 'e+23', 'e+20', 'e-07', 'e-05'];

    /**
     * How the playbook prints each float answer besides its task name and
     * `debug: var`, and what the log shows for it: the template, and its
     * expression with the masked answer.
     */
    private const FLOAT_FORMS = [
        'str' => ['%s', '<********>'],
        'to_json' => ['%s | to_json', '<********>'],
        'to_nice_json' => ['%s | to_nice_json', '<********>'],
        'to_yaml' => ['[%s] | to_yaml | trim', '<[********]>'],
        'to_nice_yaml' => ['[%s] | to_nice_yaml | trim', '<- ********>'],
    ];

    private string $dir;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->hasBinary('ansible-playbook')) {
            $this->markTestSkipped('ansible-playbook is not installed.');
        }
        $this->dir = sys_get_temp_dir() . '/runner_secret_vars_ansible_' . uniqid('', true);
        $this->project = $this->dir . '/project';
        mkdir($this->project, 0o755, true);
        mkdir($this->dir . '/home', 0o700, true);
        mkdir($this->dir . '/secrets', 0o700, true);
        file_put_contents(
            $this->project . '/hash.yml',
            "---\n- name: Use the answer\n  hosts: localhost\n  gather_facts: false\n  tasks:\n"
            . "    - name: Show its hash\n      ansible.builtin.debug:\n        msg: \"sha1={{ db_password | hash('sha1') }}\"\n"
        );
        file_put_contents(
            $this->project . '/print.yml',
            "---\n- name: Print the answer\n  hosts: localhost\n  gather_facts: false\n  tasks:\n"
            . "    - name: Use {{ db_password }} here\n      ansible.builtin.debug:\n        var: db_password\n"
        );
        file_put_contents(
            $this->project . '/mode.yml',
            "---\n- name: Look at the task records file\n  hosts: localhost\n  gather_facts: false\n  tasks:\n"
            . "    - name: Stat it\n      ansible.builtin.stat:\n        path: \"{{ lookup('env', 'ANSILUME_CALLBACK_FILE') }}\"\n      register: records\n"
            . "    - name: Show its mode\n      ansible.builtin.debug:\n        msg: \"records-mode={{ records.stat.mode }}\"\n"
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            FileHelper::removeDirectory($this->dir);
        }
        parent::tearDown();
    }

    public function testThePlayReadsTheAnswerFromTheFileAndVerboseOutputShowsOnlyThePath(): void
    {
        $vars = new RunnerSecretVars($this->dir . '/secrets');
        $args = $vars->prepare(['secret_extra_vars' => ['db_password' => self::ANSWER]]);
        try {
            [$exitCode, $output] = $this->runPlaybook('hash.yml', ['-vvvv', ...$args], new OutputMasker([]));
        } finally {
            $vars->cleanup();
        }

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('sha1=' . sha1(self::ANSWER), $output, 'the play got the answer');
        $this->assertStringContainsString("extra_vars: ('" . $args[1] . "',)", $output, '-vvvv shows the file');
        $this->assertStringNotContainsString(self::ANSWER, $output);
        $this->assertSame([], glob($this->dir . '/secrets/*') ?: []);
    }

    /**
     * Why the file: inline, -vvvv prints the answer with the command line.
     */
    public function testInlineTheSameRunPrintsTheAnswer(): void
    {
        [$exitCode, $output] = $this->runPlaybook(
            'hash.yml',
            ['-vvvv', '--extra-vars', (string)json_encode(['db_password' => self::ANSWER])],
            new OutputMasker([])
        );

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('sha1=' . sha1(self::ANSWER), $output);
        $this->assertStringContainsString(self::ANSWER, $output);
    }

    /**
     * A playbook that prints the answer, and a task name templated with it:
     * the log and the task records the runner posts carry the mask instead.
     */
    public function testWhatThePlaybookPrintsIsMasked(): void
    {
        $answers = ['db_password' => self::ANSWER];
        $vars = new RunnerSecretVars($this->dir . '/secrets');
        $masker = new OutputMasker($answers);
        try {
            [$exitCode, $output] = $this->runPlaybook('print.yml', ['-v', ...$vars->prepare(['secret_extra_vars' => $answers])], $masker);
        } finally {
            $vars->cleanup();
        }

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringNotContainsString(self::ANSWER, $output);
        $this->assertStringContainsString('TASK [Use ' . OutputMasker::MASK . ' here]', $output);
        $this->assertStringContainsString('"db_password": "' . OutputMasker::MASK . '"', $output);

        $records = array_map(
            static fn (string $line): mixed => json_decode($line, true),
            file($this->dir . '/tasks.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
        );
        $this->assertCount(1, $records);
        $this->assertIsArray($records[0]);
        $this->assertSame('Use ' . self::ANSWER . ' here', $records[0]['name'], 'the callback plugin records the templated name');
        $masked = $masker->maskValues($records[0], ['name', 'host']);
        $this->assertSame('Use ' . OutputMasker::MASK . ' here', $masked['name']);
        $this->assertSame('localhost', $masked['host']);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function resultFormatProvider(): array
    {
        return [
            'default (JSON) result format' => [[], '"%s": %s'],
            'YAML result format' => [['ANSIBLE_CALLBACK_RESULT_FORMAT' => 'yaml'], '%s: %s'],
        ];
    }

    /**
     * Regression (CLS-1): the masker wrote a float answer as PHP's text, with
     * 14 significant digits (1.0E+20), but Ansible prints it as Python writes
     * it: all its digits, an exponent with a sign and two digits (1e+20,
     * 1.5e-07), and 1.0e+20 in YAML. These answers appeared in clear text in
     * the log and in task names, and 12345678901234.5 as ********.5. They
     * arrive as the claim payload brings them, decoded from JSON.
     *
     * @dataProvider resultFormatProvider
     * @param array<string, string> $env
     */
    public function testFloatAnswersAreMaskedInEveryFormAnsiblePrintsThem(array $env, string $varLine): void
    {
        $answers = json_decode((string)json_encode(self::FLOAT_ANSWERS), true);
        $this->assertIsArray($answers);
        $this->assertSame(array_keys(self::FLOAT_ANSWERS), array_keys(array_filter($answers, 'is_float')), 'every answer arrives as a float');
        file_put_contents($this->project . '/floats.yml', $this->floatPlaybook(array_keys($answers)));
        $vars = new RunnerSecretVars($this->dir . '/secrets');
        try {
            [$exitCode, $output] = $this->runPlaybook(
                'floats.yml',
                ['-v', ...$vars->prepare(['secret_extra_vars' => $answers])],
                new OutputMasker($answers),
                $env
            );
        } finally {
            $vars->cleanup();
        }

        $this->assertSame(0, $exitCode, $output);
        foreach (self::FLOAT_TEXTS as $text) {
            $this->assertStringNotContainsString($text, $output);
        }
        foreach (array_keys($answers) as $name) {
            $this->assertStringContainsString("TASK [Use {$name}=" . OutputMasker::MASK . ' here]', $output);
            $this->assertStringContainsString(sprintf($varLine, $name, OutputMasker::MASK) . "\n", $output);
            foreach (self::FLOAT_FORMS as $form => [, $masked]) {
                $this->assertStringContainsString("{$name} {$form}={$masked}", $output);
            }
        }
    }

    /**
     * Review SEC-EXP-3: the callback plugin opens its task records file with
     * open(path, 'w'). On its own it creates the file with the process umask
     * (0644 under the runner image's 0022), where other users of a shared
     * temp directory can read the templated task names. The runner now
     * creates the file 0600 first; open(path, 'w') truncates it and keeps
     * that mode, so the plugin needs no change.
     */
    public function testTheCallbackPluginKeepsTheModeOfTheTaskRecordsFileTheRunnerCreated(): void
    {
        $records = $this->dir . '/tasks.ndjson';
        $umask = umask(0o022);
        try {
            [$exitCode, $output] = $this->runPlaybook('mode.yml', [], new OutputMasker([]));
            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('records-mode=0644', $output, 'without the runner: readable by everyone');
            unlink($records);

            $this->assertTrue(RunnerSecretVars::writePrivateFile($records, ''));
            [$exitCode, $output] = $this->runPlaybook('mode.yml', [], new OutputMasker([]));
        } finally {
            umask($umask);
        }

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('records-mode=0600', $output, 'while the play runs');
        $this->assertSame(0o600, fileperms($records) & 0o777);
        $this->assertCount(2, file($records, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], 'the plugin wrote its records into it');
    }

    // -- Helpers ------------------------------------------------------------------

    /**
     * A play that prints each of the answers $names in its task name, with
     * `debug: var` and in the forms of FLOAT_FORMS, each on a line of its
     * own: the YAML result format folds lines longer than 80 characters.
     *
     * @param list<string> $names
     */
    private function floatPlaybook(array $names): string
    {
        $tasks = '';
        foreach ($names as $name) {
            $tasks .= "    - name: Use {$name}={{ {$name} }} here\n      ansible.builtin.debug:\n        var: {$name}\n";
            foreach (self::FLOAT_FORMS as $form => [$expression]) {
                $tasks .= "    - ansible.builtin.debug:\n        msg: \"{$name} {$form}=<{{ " . sprintf($expression, $name) . " }}>\"\n";
            }
        }

        return "---\n- name: Print the float answers\n  hosts: localhost\n  gather_facts: false\n  tasks:\n" . $tasks;
    }

    /**
     * @param list<string> $args after the playbook
     * @param array<string, string> $extraEnv on top of the runner's environment
     * @return array{0: int, 1: string} exit code and the job log without colours
     */
    private function runPlaybook(string $playbook, array $args, OutputMasker $masker, array $extraEnv = []): array
    {
        $env = PlaybookEnvironment::build(getenv() ?: [], $this->dir . '/tasks.ndjson');
        // Keep Ansible's own files inside the test directory: the default
        // HOME is the runtime directory the dev runners share.
        $env = array_merge($env, [
            'HOME' => $this->dir . '/home',
            'ANSIBLE_HOME' => $this->dir . '/home/.ansible',
            'ANSIBLE_LOCAL_TEMP' => $this->dir . '/home/tmp',
        ], $extraEnv);
        $cmd = ['ansible-playbook', '-i', 'localhost,', '-c', 'local', $playbook, ...$args];

        $posts = [];
        $http = $this->createMock(RunnerHttpClient::class);
        $http->method('post')->willReturnCallback(static function (string $path, array $body) use (&$posts): array {
            $posts[] = $body;
            return ['ok' => true];
        });
        $app = \Yii::$app;
        $this->assertNotNull($app);
        $executor = new RunnerProcessExecutor($http, new SilentController('runner', $app), $masker);
        [$exitCode] = $executor->run(1, $cmd, ['project_path' => $this->project], $env, 2);
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
