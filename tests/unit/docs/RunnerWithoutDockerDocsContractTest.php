<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\components\GitEnvBuilder;
use app\components\PlaybookEnvironment;
use app\components\RunnerHttpClient;
use app\components\RunnerTokenResolver;
use app\tests\unit\components\SilentController;
use PHPUnit\Framework\TestCase;
use yii\console\ExitCode;

/**
 * Contract: the installation of a runner without Docker in docs/runners.md
 * ("Running Without Docker") works as written with the runner's code, and
 * the runner bullet of the update notes, the troubleshooting entry and the
 * row of runners.md's common issues say what the move to PHP 8.5 does to
 * such a runner.
 *
 * Regressions (findings of the final verification of the PHP 8.5 move, in
 * brackets), each test fails on the docs as they were before the fix:
 * - [DEP-1] the runner reads its settings from $_ENV, which the php.ini of
 *   the distributions' PHP packages leaves empty (variables_order GPCS):
 *   the documented `export` and the `Environment=` lines of the systemd
 *   unit never reached it, and it exited with "API_URL environment
 *   variable is required". `yii` loads .env of its directory into $_ENV
 *   whatever php.ini says; the docs put the settings there now.
 * - [DEP-2] the runner and the server's claims use paths under /var/www
 *   (the token cache, the homes of git and Ansible, the checkouts of git
 *   projects, the Selftest project); the docs installed it in
 *   /opt/ansilume-runner, where it could not start.
 * - [DEP-4] the composer package of Debian 12 stops on the PHP 8.5 of
 *   packages.sury.org; the docs named no Composer.
 * - [DOCS-6] on PHP 8.4 such a runner runs the new code with the
 *   dependencies of 2.13.1 without a message; the docs named only PHP 8.3.
 * Found while proving the procedure on Debian 12 (fix round 4):
 * - Ansible keeps the temp files of the modules it runs on the runner's
 *   host in the home of the runner's user (~user/.ansible/tmp, from the
 *   passwd entry, not $HOME): the Selftest failed "UNREACHABLE! ... Failed
 *   to create temporary directory" for a system user without a home.
 * - `composer install --optimize-autoloader` reads every directory of the
 *   project root (composer.json maps app\ to it), so an update stopped at
 *   runtime/, which only the runner's user may enter.
 */
class RunnerWithoutDockerDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const RUNNERS = 'docs/runners.md';

    private const SECTION = '## Running Without Docker';

    private const SETUP = '### Setup';

    private const UPDATING = 'docs/updating.md';

    private const TROUBLESHOOTING = 'docs/troubleshooting.md';

    private const RUNNER_ENTRY = '## A runner installed without Docker does not start after the update';

    /** What RunnerController::actionStart() prints when $_ENV has no API_URL. */
    private const NO_API_URL = 'ERROR: API_URL environment variable is required.';

    /** What it prints, past API_URL, when it has no token and may not register. */
    private const NO_TOKEN = 'ERROR: No runner token available.';

    /** What Composer printed on Debian 12 for --optimize-autoloader once runtime/ existed. */
    private const OPTIMIZE_REFUSED = 'RecursiveDirectoryIterator::__construct(/var/www/runtime): Failed to open directory: Permission denied';

    /** What the composer package of Debian 12 printed on the PHP 8.5 of packages.sury.org. */
    private const DEBIAN_COMPOSER_FAILED = 'PHP Fatal error: Uncaught Error: Class "Normalizer" not found';

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $directory) {
            self::removeTree($directory);
        }
        $this->scratch = [];
    }

    /**
     * [DEP-2] The runner lives in /var/www: the docs name the paths that
     * the server's claims and the runner's code use there, and create the
     * directories the runner image creates.
     */
    public function testTheRunnerLivesWhereTheServerAndTheRunnerLookForIt(): void
    {
        $setup = $this->flat(self::RUNNERS, self::SETUP);
        foreach (
            [
                'The runner lives in `/var/www`, as in the runner image',
                '(`/var/www/runtime/projects/<id>`)',
                '(`/var/www/ansible/selftest`)',
                '`/var/www` must be missing or empty',
                'git clone https://github.com/ansilume/ansilume.git /var/www',
                'cd /var/www',
                'only `runtime/` belongs to the runner\'s user',
            ] as $needle
        ) {
            $this->assertMentions($needle, $setup, 'The setup of runners.md');
        }
        $section = $this->section(self::RUNNERS, self::SECTION);
        $this->assertStringNotContainsString('/opt/ansilume-runner', $section, 'The runner cannot start outside /var/www.');
        $this->assertStringNotContainsString('mkdir -p runtime/projects', $section, 'Directories the runner never used.');

        // What the docs say, from the code: the server's claims name its own
        // checkouts (the app image is installed in /var/www) and the seeded
        // Selftest project; the runner keeps its token and homes there.
        $this->assertStringContainsString('COPY . /var/www/', $this->read('docker/php/Dockerfile.prod'));
        $this->assertMatchesRegularExpression("/'workspacePath' => '@runtime\\/projects'/", $this->read('config/services.php'));
        $this->assertStringContainsString("'local_path' => '/var/www/ansible/selftest'", $this->read('migrations/m000039_000000_move_selftest_to_ansible_dir.php'));
        $this->assertSame('/var/www/runtime/git-home', GitEnvBuilder::GIT_HOME);
        $this->assertSame('/var/www/runtime/ansible-home', PlaybookEnvironment::ANSIBLE_HOME);
        $this->assertSame('/var/www/runtime/runner-bare-runner-1.token', $this->tokenCacheFile('bare-runner-1'));

        // The setup creates what the entrypoint of the runner image creates.
        preg_match('/^mkdir -p (.+)$/m', $this->read('docker/runner/entrypoint.sh'), $mkdir);
        $this->assertNotEmpty($mkdir, 'The runner image creates its directories.');
        $inImage = array_map(static fn (string $path): string => substr($path, strlen('/var/www/')), preg_split('/\s+/', trim($mkdir[1])) ?: []);
        $created = $this->runtimeDirectoriesOfTheSetup();
        $this->assertContains('runtime', $created);
        sort($inImage);
        $created = array_values(array_diff($created, ['runtime']));
        sort($created);
        $this->assertSame($inImage, $created, 'The setup creates the runtime directories of the runner image.');
    }

    /**
     * [DEP-1] The settings go in .env, and the systemd unit sets none of
     * them; the docs say why, in the words of the runner.
     */
    public function testTheSettingsGoInDotEnvAndNotInTheUnit(): void
    {
        $setup = $this->flat(self::RUNNERS, self::SETUP);
        foreach (
            [
                'sudo install -o root -g ansilume -m 0640 /dev/null .env',
                "sudo tee .env > /dev/null <<'EOF' API_URL=https://ansilume.example.com RUNNER_NAME=bare-runner-1 RUNNER_BOOTSTRAP_SECRET=your-bootstrap-secret-here EOF",
                'sudo -u ansilume php yii runner/start',
                'PHP fills `$_ENV` from the environment only when `variables_order` in its php.ini contains `E`',
                '`php.ini-production`, sets `GPCS`',
                'settings given with `export` or with `Environment=` in a systemd unit do not reach the runner, which exits with `' . self::NO_API_URL . '`',
                '`yii` keeps a variable that the environment already has, even an empty one, and PHP hides that one as well',
                'set `RUNNER_TOKEN` there instead',
                'The unit names none of the runner\'s settings: they are in `.env`.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $setup, 'The setup of runners.md');
        }
        $this->assertStringNotContainsString('export API_URL', $setup, 'An exported setting does not reach the runner.');

        $service = $this->serviceOfTheUnit();
        foreach (['User=ansilume', 'WorkingDirectory=/var/www', 'ExecStart=/usr/bin/php yii runner/start'] as $line) {
            $this->assertContains($line, $service, 'The unit of runners.md');
        }
        foreach ($service as $line) {
            $this->assertDoesNotMatchRegularExpression('/^Environment=(API_URL|RUNNER_)/', $line, 'A setting in the unit hides the one of .env.');
        }

        // The words of the runner, and the loader the docs describe.
        $this->assertStringContainsString('"' . self::NO_API_URL . '\n"', $this->read('commands/RunnerController.php'));
        $this->assertStringContainsString("\$_ENV['API_URL']", $this->read('commands/RunnerController.php'));
        $yii = $this->read('yii');
        $this->assertStringContainsString('\Dotenv\Dotenv::createUnsafeImmutable(__DIR__)', $yii);
        $this->assertStringContainsString('->safeLoad()', $yii);
    }

    /**
     * [DEP-1] What the docs say about the settings is what the runner does
     * on the php.ini of the distributions: started from a copy of `yii`
     * under variables_order GPCS, it finds API_URL in .env of its directory,
     * not in its environment, nor when both have it; EGPCS, the default of
     * PHP without a php.ini and so of the images, hands it the environment.
     */
    public function testTheRunnerFindsItsSettingsAsTheDocsSay(): void
    {
        $url = 'http://127.0.0.1:9';
        $cases = [
            'GPCS, .env only' => ['GPCS', $url, null, self::NO_TOKEN],
            'GPCS, environment only' => ['GPCS', null, $url, self::NO_API_URL],
            'GPCS, .env and environment' => ['GPCS', $url, $url, self::NO_API_URL],
            'GPCS, .env and an empty variable of the environment' => ['GPCS', $url, '', self::NO_API_URL],
            'EGPCS, environment only' => ['EGPCS', null, $url, self::NO_TOKEN],
        ];
        foreach ($cases as $case => [$order, $inDotEnv, $inEnvironment, $expected]) {
            [$exitCode, $output] = $this->startTheRunner($order, $inDotEnv, $inEnvironment);
            $this->assertSame(ExitCode::CONFIG, $exitCode, "{$case}: {$output}");
            $this->assertStringContainsString($expected, $output, $case);
        }
    }

    /**
     * [DEP-4] Composer runs on PHP 8.5: the prerequisites name the one of
     * getcomposer.org and say why not the package of Debian 12.
     */
    public function testComposerComesFromGetcomposerOrg(): void
    {
        $prerequisites = $this->flat(self::RUNNERS, '### Prerequisites');
        foreach (
            [
                '- Composer 2, run with that PHP (see below)',
                'Run Composer with PHP 8.5 as well. Take it from getcomposer.org',
                'the `composer` package of Alpine 3.24 or later works too',
                'The `composer` package of Debian 12 does not: it installs its PHP extensions for PHP 8.4, the default of packages.sury.org',
                '`' . self::DEBIAN_COMPOSER_FAILED . '`',
                'sudo apt-get install php8.5-cli php8.5-curl php8.5-zip git ansible openssh-client',
                'curl -fsSLO https://getcomposer.org/download/latest-2.x/composer.phar',
                'curl -fsSLO https://getcomposer.org/download/latest-2.x/composer.phar.sha256sum',
                'sha256sum -c composer.phar.sha256sum',
                'sudo install -m 0755 composer.phar /usr/local/bin/composer',
            ] as $needle
        ) {
            $this->assertMentions($needle, $prerequisites, 'The prerequisites of runners.md');
        }
    }

    /**
     * Found on Debian 12: the update stopped at runtime/, as
     * --optimize-autoloader reads the whole project root. Without it,
     * Composer reads nothing of the root, as the app's autoload is PSR-4
     * only; the docs install and update with `composer install --no-dev`.
     */
    public function testComposerDoesNotOptimizeTheAutoloader(): void
    {
        preg_match_all('/^composer install.*$/m', $this->section(self::RUNNERS, self::SECTION), $installs);
        $this->assertSame(['composer install --no-dev'], $installs[0], 'The setup installs the dependencies without optimizing the autoloader.');
        $setup = $this->flat(self::RUNNERS, self::SETUP);
        $this->assertMentions('Run `composer install` without `--optimize-autoloader`, now and at every update', $setup, 'The setup of runners.md');
        $this->assertMentions('`' . self::OPTIMIZE_REFUSED . '`', $setup, 'The setup of runners.md');
        $this->assertMentions('To update the runner, run `git pull` and `composer install --no-dev` in `/var/www`', $setup, 'The setup of runners.md');

        $texts = [
            'the update notes' => $this->runnerBullet(),
            self::RUNNER_ENTRY => $this->flat(self::TROUBLESHOOTING, self::RUNNER_ENTRY),
            'the common issues of runners.md' => $this->phpRow(),
        ];
        foreach ($texts as $where => $text) {
            $this->assertMentions('`composer install --no-dev` with', $text, $where);
            $this->assertStringNotContainsString('`composer install --no-dev --optimize-autoloader`', $text, "{$where} still optimizes the autoloader.");
        }

        $composer = json_decode($this->read('composer.json'), true);
        $this->assertIsArray($composer);
        $this->assertSame(['psr-4' => ['app\\' => '']], $composer['autoload'] ?? null, 'The app\'s autoload maps app\ to the project root and has no classmap.');
    }

    /**
     * Found on Debian 12: Ansible runs the modules of a play on the
     * runner's host (connection: local, the Selftest) in the home of the
     * runner's user, which `useradd --system` does not create.
     */
    public function testTheRunnersUserHasAHome(): void
    {
        $setup = $this->flat(self::RUNNERS, self::SETUP);
        $this->assertMentions('sudo useradd --system --user-group --create-home --shell /usr/sbin/nologin ansilume', $setup, 'The setup of runners.md');
        $this->assertMentions('Ansible keeps the temp files of the modules it runs on this host, as the Selftest does, in ~/.ansible/tmp', $setup, 'The setup of runners.md');
        $this->assertMentions('connection: local', $this->read('ansible/selftest/selftest.yaml'), 'The Selftest');
    }

    /**
     * [DOCS-6] On PHP 8.4 the runner runs the new code with the
     * dependencies of 2.13.1 without a message; and PHP 8.5's own php.ini
     * hides the environment from a runner that relied on EGPCS.
     */
    public function testTheDocsSayWhatPhp84AndThePhpIniOfPhp85Do(): void
    {
        $entry = $this->flat(self::TROUBLESHOOTING, self::RUNNER_ENTRY);
        $this->assertMentions(
            'PHP 8.4 runs the new code with them without a message: the runner starts and runs jobs on the dependencies of the release before until a later release needs PHP 8.5.',
            $this->between($entry, '**Cause:**', '**Fix:**'),
            self::RUNNER_ENTRY
        );
        $this->assertMentions('Or the runner exits with `' . self::NO_API_URL . '` although its unit sets `API_URL`.', $this->between($entry, '**Symptom:**', '**Cause:**'), self::RUNNER_ENTRY);
        $this->assertMentions('PHP 8.5 brings a php.ini of its own, which sets `GPCS`', $entry, self::RUNNER_ENTRY);
        $this->assertMentions('put the runner\'s settings in `.env` of its directory and remove them from the unit (see [runners.md](runners.md#setup))', $entry, self::RUNNER_ENTRY);

        $bullet = $this->runnerBullet();
        $this->assertMentions('PHP 8.4 runs the new code with them without a message: the runner starts and runs jobs on the dependencies of 2.13.1 until a later release needs PHP 8.5.', $bullet, 'the update notes');
        $this->assertMentions('PHP 8.5 brings a php.ini of its own, which sets `variables_order` to `GPCS`', $bullet, 'the update notes');
        $this->assertMentions('because the php.ini of its old PHP set `EGPCS`, exits with `' . self::NO_API_URL . '`', $bullet, 'the update notes');
        $this->assertMentions('It needs `/var/www`, and its settings in `.env` (see [runners.md](runners.md#setup)).', $bullet, 'the update notes');

        $this->assertMentions(
            'When `composer install` refused the new lock or was not run, PHP 8.4 runs the new code with the dependencies of 2.13.1 without a message: the runner starts and runs jobs on them until a later release needs PHP 8.5.',
            $this->phpRow(),
            'the common issues of runners.md'
        );
        $this->assertSame('setup', self::headingAnchor(self::SETUP), 'The anchor the links use.');
    }

    /**
     * The runner bullet of the newest section of the update notes.
     */
    private function runnerBullet(): string
    {
        $units = $this->units(self::UPDATING, Php85UpdateDocsContractTest::UPDATE_SECTION);
        $bullets = array_values(array_filter($units, static fn (string $unit): bool => str_starts_with($unit, '- **Runners:**')));
        $this->assertCount(1, $bullets, 'The newest section has one runner bullet.');

        return $bullets[0];
    }

    /**
     * The row of runners.md's common issues about the PHP of the runner.
     */
    private function phpRow(): string
    {
        $rows = array_values(array_filter(
            $this->units(self::RUNNERS, '### Common issues'),
            static fn (string $unit): bool => str_starts_with($unit, '| The runner exits at start with `Composer detected issues')
        ));
        $this->assertCount(1, $rows, 'runners.md has one row about the PHP of the runner.');

        return $rows[0];
    }

    /**
     * The directories that the setup's `install -d` gives the runner's user,
     * relative to /var/www.
     *
     * @return list<string>
     */
    private function runtimeDirectoriesOfTheSetup(): array
    {
        $commands = (string)preg_replace('/\\\\\R\s*/', ' ', $this->section(self::RUNNERS, self::SETUP));
        preg_match('/^sudo install -d -o ansilume -g ansilume -m 0750 (.+)$/m', $commands, $install);
        $this->assertNotEmpty($install, 'The setup gives the runtime directories to the runner\'s user.');

        return preg_split('/\s+/', trim($install[1])) ?: [];
    }

    /**
     * The lines of the [Service] section of the systemd unit in runners.md.
     *
     * @return list<string>
     */
    private function serviceOfTheUnit(): array
    {
        preg_match_all('/```ini\R(.*?)```/s', $this->section(self::RUNNERS, self::SECTION), $blocks);
        $units = array_values(array_filter($blocks[1], static fn (string $block): bool => str_contains($block, 'yii runner/start')));
        $this->assertCount(1, $units, 'runners.md has one systemd unit for the runner.');
        $section = null;
        $service = [];
        foreach (preg_split('/\R/', $units[0]) ?: [] as $line) {
            if (preg_match('/^\[(\w+)\]$/', trim($line), $match) === 1) {
                $section = $match[1];
            } elseif ($section === 'Service' && trim($line) !== '') {
                $service[] = trim($line);
            }
        }

        return $service;
    }

    /**
     * Where the runner caches the token of $name.
     */
    private function tokenCacheFile(string $name): string
    {
        $app = \Yii::$app;
        if ($app === null) {
            $this->fail('The tests run in a Yii application.');
        }
        $resolver = new RunnerTokenResolver(new RunnerHttpClient('http://127.0.0.1:9', ''), new SilentController('test', $app));
        $path = (new \ReflectionMethod($resolver, 'tokenCacheFile'))->invoke($resolver, $name);
        $this->assertIsString($path);

        return $path;
    }

    /**
     * Starts `yii runner/start` from a directory of its own, with a copy of
     * `yii` and of config/, so that .env and runtime/ are the test's, and
     * the repository's vendor/. Its environment holds PATH, TMPDIR (an empty
     * directory of the test, which the runner's sweep at start reads) and
     * API_URL when $inEnvironment is not null, set by env(1), as proc_open()
     * leaves out a variable whose value is empty; .env holds API_URL when
     * $inDotEnv is not null.
     *
     * @return array{0: int, 1: string} exit code, and what it printed
     */
    private function startTheRunner(string $variablesOrder, ?string $inDotEnv, ?string $inEnvironment): array
    {
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/ansilume-runner-env-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($directory . '/config', 0o700, true));
        $this->assertTrue(mkdir($directory . '/tmp', 0o700));
        $this->scratch[] = $directory;
        $this->assertTrue(copy($root . '/yii', $directory . '/yii'));
        foreach (glob($root . '/config/*.php') ?: [] as $file) {
            $this->assertTrue(copy($file, $directory . '/config/' . basename($file)));
        }
        $this->assertTrue(symlink($root . '/vendor', $directory . '/vendor'));
        if ($inDotEnv !== null) {
            file_put_contents($directory . '/.env', "API_URL={$inDotEnv}\n");
        }
        $command = [PHP_BINARY, '-d', 'variables_order=' . $variablesOrder, $directory . '/yii', 'runner/start'];
        if ($inEnvironment !== null) {
            $command = ['env', 'API_URL=' . $inEnvironment, ...$command];
        }

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
            ['PATH' => (string)getenv('PATH'), 'TMPDIR' => $directory . '/tmp']
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * The anchor GitHub gives a heading: lower case, without punctuation,
     * spaces as hyphens.
     */
    private static function headingAnchor(string $heading): string
    {
        $text = strtolower(trim((string)preg_replace('/^#+\s*/', '', $heading)));

        return str_replace(' ', '-', (string)preg_replace('/[^a-z0-9 _-]/', '', $text));
    }

    /**
     * Removes the test's own directory tree; a link is removed, not followed.
     */
    private static function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (is_link($path) || file_exists($path)) {
                unlink($path);
            }

            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
