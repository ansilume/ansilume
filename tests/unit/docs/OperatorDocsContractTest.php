<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\commands\MaintenanceController;
use app\commands\RunnerController;
use app\components\RunnerSecretVars;
use app\components\SecretCipher;
use app\components\SurveyDefinition;
use app\components\SurveySecrets;
use app\services\JobLaunchSecrets;
use app\services\MaintenanceService;
use app\services\ScheduleLaunchSecrets;
use PHPUnit\Framework\TestCase;
use yii\helpers\Inflector;

/**
 * The operator docs (docs/, README.md, the .env examples, the deploy role's
 * defaults) say what the code does: the messages as the code words them,
 * commands that exist, the limits the code enforces, and what changed
 * against 2.8.1. A change on one side without the other fails here. The
 * runner's docs are checked in RunnerDocsContractTest, the Extra Vars docs
 * in ExtraVarsDocsContractTest.
 *
 * Regressions from the review of the survey password release (findings in
 * brackets), each test fails on the docs as they were before the fix:
 * - [DOC-8] installation.md and the .env examples asked for at least 32
 *   characters of APP_SECRET_KEY, while the cipher takes 16, so an operator
 *   could replace a working key and lose every stored secret. [V2-DOC8] The
 *   deploy role's defaults still did, and warned about credentials only.
 * - [DOC-4] no doc named the command that makes the background encryption
 *   start over after a database restore.
 * - [DOC-1] updating.md said refused trigger launches answered 500 before,
 *   that 400 without a token was new, and that refused workflow steps had
 *   shown the generic error; 2.8.1 launched them.
 * - [DOC-2] updating.md said every job on an old runner gets the env prefix.
 * - [DOC-3] the schedule paragraph described an API round trip without the
 *   API returning the extra vars.
 * - [DOC-5] the deprecated path form was listed next to workflows.
 * - [DOC-7] the check for end-to-end test users came last.
 * - [C1, C3, SEC-EXP-1] the new refusals were missing from the docs.
 *   [V2-C1U] The docs named whitespace and control characters as what makes
 *   a password field name unusable, not the characters outside ASCII.
 * - [G open issue 3] .env.example said APP_ENV controls error verbosity,
 *   the debug toolbar and caching; nothing reads it.
 */
class OperatorDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.8.1 or older';

    /** The files that say how long APP_SECRET_KEY has to be. */
    private const KEY_FILES = [
        'docs/installation.md',
        '.env.example',
        '.env.prod.example',
        'deploy/roles/ansilume/defaults/main.yaml',
    ];

    public function testTheSecretKeyMinimumIsTheOneTheCipherEnforces(): void
    {
        $minimum = (new \ReflectionClassConstant(SecretCipher::class, 'MIN_KEY_LENGTH'))->getValue();
        $this->assertIsInt($minimum);

        foreach (self::KEY_FILES as $file) {
            $statements = $this->keyLengthStatements($file);
            $this->assertNotEmpty($statements, "{$file} must say how long APP_SECRET_KEY has to be.");
            foreach ($statements as $statement) {
                $this->assertMatchesRegularExpression('/32 random characters/i', $statement, "{$file}: recommend 32 random characters.");
                $this->assertMatchesRegularExpression('/never change it/i', $statement, "{$file}: warn against changing the key.");
                foreach (['credentials', 'TOTP secrets', 'survey answers'] as $secret) {
                    $this->assertStringContainsString($secret, $statement, "{$file}: the key protects {$secret}.");
                }
            }
        }
        foreach ([...self::KEY_FILES, 'docs/updating.md', 'docs/credentials.md'] as $file) {
            foreach ($this->keyLengthStatements($file) as $statement) {
                preg_match_all('/(?:at least|minimum(?: of)?) (\d+) characters/i', $statement, $lengths);
                foreach ($lengths[1] as $length) {
                    $this->assertSame((string)$minimum, $length, "{$file} states another minimum than the cipher's: {$statement}");
                }
            }
        }
    }

    public function testTheRestoreProcedureMakesTheBackgroundEncryptionStartOver(): void
    {
        $action = 'actionResetSurveySecretScrub';
        $this->assertTrue(method_exists(MaintenanceController::class, $action));
        $command = 'php yii maintenance/' . Inflector::camel2id(substr($action, strlen('action')));

        $updating = $this->flat('docs/updating.md');
        $this->assertMentions($command, $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md');
        $this->assertMentions('reset-survey-secret-scrub', $this->flat('docs/updating.md', '## Rollback'), 'The rollback steps of updating.md');
        $this->assertMentions($command, $this->flat('docs/troubleshooting.md'), 'troubleshooting.md');
        $this->assertMentions($this->resetCommandOutput(), $updating, 'updating.md, quoting what the command prints,');

        // Flushing the cache instead would log everyone out and drop queued work.
        $cacheFlush = array_filter($this->units('docs/updating.md'), static fn (string $unit): bool => str_contains($unit, 'cache/flush'));
        $this->assertNotEmpty($cacheFlush, 'updating.md warns against flushing the cache.');
        foreach ($cacheFlush as $unit) {
            $this->assertStringContainsString('Do not', $unit, 'cache/flush is named only as what not to do.');
        }
    }

    public function testEveryDocumentedConsoleCommandExists(): void
    {
        /** @var \yii\console\Application $app */
        $app = \Yii::$app;
        $core = $app->coreCommands();
        $files = array_merge(glob($this->root() . '/docs/*.md') ?: [], [$this->root() . '/README.md', $this->root() . '/web/openapi.yaml']);
        $found = 0;
        foreach ($files as $path) {
            preg_match_all('~php yii ([a-z0-9-]+)(?:/([a-z0-9-]+))?~', (string)file_get_contents($path), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $found++;
                $class = is_string($core[$match[1]] ?? null) ? $core[$match[1]] : 'app\\commands\\' . Inflector::id2camel($match[1]) . 'Controller';
                $this->assertTrue(class_exists($class), basename($path) . ": no console command {$match[0]}");
                if (isset($match[2]) && $match[2] !== '') {
                    $this->assertTrue(method_exists($class, 'action' . Inflector::id2camel($match[2])), basename($path) . ": no console command {$match[0]}");
                }
            }
        }
        $this->assertGreaterThan(0, $found);
    }

    public function testTheMessagesOfTheNewRefusalsAreQuotedAsTheCodeWordsThem(): void
    {
        $troubleshooting = $this->flat('docs/troubleshooting.md');
        foreach ([JobLaunchSecrets::EXTRA_VARS, JobLaunchSecrets::PASSWORD_ANSWERS] as $subject) {
            $this->assertMentions(SurveySecrets::unencodable($subject, JSON_ERROR_INF_OR_NAN), $troubleshooting, 'troubleshooting.md');
        }
        $utf8 = SurveySecrets::unencodable(JobLaunchSecrets::EXTRA_VARS, JSON_ERROR_UTF8);
        $this->assertMentions(substr($utf8, (int)strpos($utf8, 'they contain')), $troubleshooting, 'troubleshooting.md');

        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        foreach ([ScheduleLaunchSecrets::LAUNCH_SCHEDULE_CHANGED, ScheduleLaunchSecrets::LAUNCH_UNDECRYPTABLE] as $message) {
            $this->assertMentions($message, $troubleshooting, 'troubleshooting.md');
            $this->assertMentions($message, $updating, 'updating.md');
        }
        foreach (['docs/troubleshooting.md', 'docs/runners.md'] as $file) {
            $this->assertMentions(RunnerController::JOB_FILES_FAILED, $this->flat($file), $file);
            $this->assertMentions(RunnerSecretVars::WRITE_FAILED, $this->flat($file), $file);
        }
    }

    /**
     * Every kind of name the docs say cannot carry an answer is one the code
     * refuses, and the docs name every kind the code refuses, with the
     * escaped form its messages show.
     */
    public function testTheDocsNameEveryKindOfPasswordFieldNameThatCannotCarryAnAnswer(): void
    {
        $kinds = [
            'empty' => '',
            'a number' => '1',
            '`[`' => 'db[pw]',
            'a space at its start or end' => 'db_password ',
            'control character' => "db\x01pw",
            'a line break' => "db_password\n",
            'a tab' => "db\tpassword",
            'outside ASCII' => "db_p\u{00E4}ssword",
            'non-breaking space' => "db_password\u{00A0}",
            'byte order mark' => "\u{FEFF}db_password",
            'zero-width space' => "db_password\u{200B}",
        ];
        foreach ($kinds as $kind => $name) {
            $this->assertFalse(SurveyDefinition::isUsablePasswordName($name), "A name with {$kind} cannot carry an answer.");
            $fields = SurveyDefinition::passwordFields((string)json_encode([['name' => $name, 'type' => 'password']]));
            $this->assertSame([], $fields['usable'], "A name with {$kind} cannot carry an answer.");
        }
        $this->assertTrue(SurveyDefinition::isUsablePasswordName('db password'), 'Inner spaces and other printable ASCII are fine.');
        $docs = [
            'troubleshooting.md' => $this->between($this->flat('docs/troubleshooting.md'), '## Launch refused: a password field needs another name', '**Fix:**'),
            'updating.md' => $this->between($this->flat('docs/updating.md', self::UPDATE_SECTION), '**A password field whose name cannot carry an answer**', '**Extra Vars'),
        ];
        foreach ($docs as $file => $text) {
            foreach (array_keys($kinds) as $kind) {
                $this->assertMentions($kind, $text, "{$file}, on names that cannot carry an answer,");
            }
            $this->assertMentions('`' . SurveyDefinition::quote("db_password\u{00A0}") . '`', $text, "{$file}, showing a name as the messages do,");
        }
    }

    /**
     * What the docs say the survey editor does to the whitespace around a
     * name is what String.prototype.trim() does: it removes spaces, line
     * breaks, tabs, non-breaking spaces and byte order marks, not a
     * zero-width space or anything inside the name.
     */
    public function testTheSurveyEditorTrimsNamesAsTheDocsSay(): void
    {
        $editor = (string)file_get_contents(dirname(__DIR__, 3) . '/views/job-template/_survey_editor.php');
        $this->assertMatchesRegularExpression("~querySelector\\('\\.sf-name'\\)\\.value\\.trim\\(\\)~", $editor, 'The survey editor trims names with String.prototype.trim().');

        $removed = 'removes spaces, line breaks, tabs, non-breaking spaces and byte order marks around a name';
        $this->assertMentions($removed, $this->flat('docs/troubleshooting.md'), 'troubleshooting.md');
        $this->assertMentions($removed, $this->flat('docs/updating.md', self::UPDATE_SECTION), 'updating.md');
    }

    public function testTheChecklistStartsWithTheCheckForEndToEndUsers(): void
    {
        $section = $this->section('docs/updating.md', self::UPDATE_SECTION);
        $this->assertSame(1, preg_match('/^1\. (.*?)\R(?:\S|\R)/ms', $section, $first));
        $this->assertStringContainsString('End-to-end test', (string)preg_replace('/\s+/', ' ', $first[1] ?? ''), 'Seeded users with public passwords are checked first.');
    }

    /**
     * 2.8.1 launched what is refused now: it never answered 500 for these
     * calls, nor showed the generic error for such a workflow step. A call
     * without a token already answered 400 then.
     */
    public function testTheUpdateNotesSayWhatTheRefusedCallsDidIn281(): void
    {
        foreach ($this->sentences('docs/updating.md', self::UPDATE_SECTION) as $sentence) {
            if (str_contains($sentence, '`422`') && (str_contains($sentence, 'before') || str_contains($sentence, '2.8.1'))) {
                $this->assertStringNotContainsString('`500', $sentence);
            }
            $this->assertStringNotContainsString('`400` without a token', $sentence);
            $this->assertFalse(
                str_contains($sentence, 'instead of') && str_contains($sentence, 'The job could not be launched'),
                "A refused workflow step launched its job in 2.8.1: {$sentence}"
            );
        }
    }

    /** The env prefix and the cap apply to jobs with password answers only. */
    public function testTheEnvPrefixIsDescribedForJobsWithPasswordAnswers(): void
    {
        $checked = 0;
        foreach ($this->sentences('docs/updating.md', self::UPDATE_SECTION) as $sentence) {
            if (str_contains($sentence, 'starts with `/usr/bin/env ANSIBLE_VERBOSITY=0`')) {
                $checked++;
                $this->assertStringContainsString('answers', $sentence);
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function testTheScheduleParagraphDescribesTheRoundTripOfTheApi(): void
    {
        $schedules = array_values(array_filter(
            $this->units('docs/updating.md', self::UPDATE_SECTION),
            static fn (string $unit): bool => str_starts_with($unit, '**Schedules keep their answers encrypted.**')
        ));
        $this->assertCount(1, $schedules);
        $this->assertMentions('schedule response of the API now has `extra_vars`', $schedules[0], 'The schedule paragraph of updating.md');
        $this->assertMentions('`PUT`', $schedules[0], 'The schedule paragraph of updating.md');
    }

    /** /trigger/<token> routes to job templates only. */
    public function testThePathFormIsNeverListedAsWorkingForWorkflows(): void
    {
        foreach (['docs/updating.md', 'docs/troubleshooting.md'] as $file) {
            foreach ($this->sentences($file) as $sentence) {
                $pathForm = str_contains($sentence, '/trigger/<token>') || str_contains($sentence, '/trigger/{token}');
                if ($pathForm && str_contains($sentence, 'fire-workflow') && str_contains($sentence, 'deprecated')) {
                    $this->assertStringContainsString('job templates only', $sentence, $file);
                }
            }
        }
    }

    /**
     * APP_ENV is passed to the containers and shown by bin/diagnose, but no
     * code reads it: YII_ENV and YII_DEBUG decide how Ansilume runs. Every
     * statement about APP_ENV says so, and the guard below fails once code
     * starts to read it, so that the docs follow.
     */
    public function testAppEnvIsDescribedAsReadByNothing(): void
    {
        $readersOf = fn (string $variable): array => array_values(array_filter(
            $this->codeFiles(),
            static fn (string $path): bool => str_contains((string)file_get_contents($path), $variable)
        ));
        $this->assertNotEmpty($readersOf("\$_ENV['YII_ENV']"), 'The scan sees the entry scripts, which read YII_ENV.');
        $this->assertSame([], $readersOf('APP_ENV'), 'Code reads APP_ENV now: say in the docs and the .env examples what it does.');

        $files = ['.env.example', '.env.prod.example', 'deploy/roles/ansilume/defaults/main.yaml', 'docs/installation.md'];
        foreach ($files as $file) {
            $statements = array_values(array_filter($this->units($file), static fn (string $unit): bool => str_contains($unit, 'APP_ENV')));
            $this->assertNotEmpty($statements, "{$file} says what APP_ENV is.");
            foreach ($statements as $statement) {
                $this->assertStringContainsString('Ansilume does not read it', $statement, "{$file}: {$statement}");
            }
        }
    }

    /**
     * Guard: every link to a section of the docs (docs/*.md and README.md)
     * leads to a heading that exists, with the anchor GitHub gives it.
     */
    public function testEveryLinkToASectionOfTheDocsResolves(): void
    {
        $files = array_merge(
            array_map(static fn (string $path): string => 'docs/' . basename($path), glob($this->root() . '/docs/*.md') ?: []),
            ['README.md']
        );
        $anchors = [];
        foreach ($files as $file) {
            $anchors[$file] = $this->anchorsOf($file);
        }
        $checked = 0;
        foreach ($files as $file) {
            foreach ($this->sectionLinks($file) as [$target, $anchor]) {
                $resolved = $target === '' ? $file : ltrim(dirname($file) . '/' . $target, './');
                if (!isset($anchors[$resolved])) {
                    continue;
                }
                $checked++;
                $this->assertContains($anchor, $anchors[$resolved], "{$file} links to {$target}#{$anchor}, which has no such section.");
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    /**
     * What maintenance/reset-survey-secret-scrub prints, without touching
     * the cache.
     */
    private function resetCommandOutput(): string
    {
        $app = \Yii::$app;
        assert($app instanceof \yii\base\Module);
        $controller = new class ('maintenance', $app) extends MaintenanceController {
            public string $printed = '';

            public function stdout($string): int
            {
                $this->printed .= (string)$string;

                return 0;
            }
        };
        $previous = $app->getComponents()['maintenanceService'] ?? null;
        $app->set('maintenanceService', new class () extends MaintenanceService {
            public function resetSurveySecretScrub(): void
            {
            }
        });
        try {
            $this->assertSame(0, $controller->actionResetSurveySecretScrub());
        } finally {
            $app->set('maintenanceService', $previous);
        }

        return trim($controller->printed);
    }

    /**
     * The statements of $file about the length of APP_SECRET_KEY.
     *
     * @return list<string>
     */
    private function keyLengthStatements(string $file): array
    {
        return array_values(array_filter(
            $this->units($file),
            static fn (string $unit): bool => str_contains($unit, 'APP_SECRET_KEY') && preg_match('/\d+ characters/i', $unit) === 1
        ));
    }

    /**
     * The PHP code and the container scripts of the repository (not the
     * docs under test): what could read an environment variable.
     *
     * @return list<string>
     */
    private function codeFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [$root . '/yii'];
        foreach (['commands', 'components', 'config', 'controllers', 'helpers', 'jobs', 'migrations', 'models', 'services', 'views', 'web'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php' && !str_contains($file->getPathname(), '/web/assets/')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return array_merge($files, glob($root . '/docker/*/entrypoint*.sh') ?: [], glob($root . '/docker/*/Dockerfile*') ?: []);
    }

    /**
     * The anchors of the headings of $file, as GitHub makes them: lower
     * case, punctuation removed, each space a hyphen, and -1, -2, ... for a
     * heading that repeats an earlier one. Lines in code blocks are no
     * headings.
     *
     * @return list<string>
     */
    private function anchorsOf(string $file): array
    {
        $anchors = [];
        $seen = [];
        $inCode = false;
        foreach (preg_split('/\R/', $this->read($file)) ?: [] as $line) {
            $inCode = $inCode !== str_starts_with($line, '```');
            if ($inCode || preg_match('/^#{1,6} +(.*?)\s*#*\s*$/', $line, $match) !== 1) {
                continue;
            }
            $text = (string)preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', mb_strtolower($match[1]));
            $anchor = str_replace(' ', '-', (string)preg_replace('/[^\p{L}\p{M}\p{N} _-]/u', '', $text));
            $count = $seen[$anchor] ?? 0;
            $seen[$anchor] = $count + 1;
            $anchors[] = $count === 0 ? $anchor : "{$anchor}-{$count}";
        }

        return $anchors;
    }

    /**
     * The links of $file to a section of a markdown file, outside code
     * blocks: the linked file ('' for $file itself) and the anchor.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function sectionLinks(string $file): array
    {
        $text = (string)preg_replace('/^```.*?^```/ms', '', $this->read($file));
        preg_match_all('/\]\(([^)\s#]*)#([^)\s]+)\)/', $text, $matches, PREG_SET_ORDER);
        $links = [];
        foreach ($matches as $match) {
            if ($match[1] === '' || str_ends_with($match[1], '.md')) {
                $links[] = [$match[1], $match[2]];
            }
        }

        return $links;
    }
}
