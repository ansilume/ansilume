<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\commands\HealthController;
use app\components\CsvText;
use app\components\ExceptionLogText;
use app\components\RunnerHttpClient;
use app\components\SubmittedId;
use app\components\SubmittedInteger;
use app\components\SurveyDefinition;
use app\components\WholeNumberValidator;
use app\controllers\api\v1\BaseApiController;
use app\controllers\api\v1\TeamsController;
use app\controllers\api\v1\UsersController;
use app\controllers\JobTemplateController;
use app\controllers\TeamController;
use app\models\ApprovalRule;
use app\models\AnalyticsQuery;
use app\models\Inventory;
use app\models\Job;
use app\models\JobSearchForm;
use app\models\JobTemplate;
use app\models\Project;
use app\models\Schedule;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\ApproverEligibility;
use app\services\JobCompletionService;
use app\services\JobTemplateCredentialService;
use app\services\RunnerReportNumbers;
use app\services\WorkflowStepWriteService;
use app\tests\unit\RequestIntCastScan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use yii\base\DynamicModel;
use yii\base\Model;
use yii\db\Exception as DbException;
use yii\validators\NumberValidator;

/**
 * Contract: the update notes for PHP 8.5 (docs/updating.md), the runner
 * docs (docs/runners.md), troubleshooting.md and docs/ci.md say who must act
 * before the update and where a runner installed without Docker gets PHP
 * 8.5, and quote what Composer, the images, the migration of this release,
 * the CSV exports and the answers to a value that is no whole number write,
 * in the words of the code.
 *
 * The update notes are history: what this release changed stays true when a
 * later one changes the code again. So they are checked against fixed text
 * where later work changes what they quote: the images of this release and
 * of 2.13.1, the headers of the API's CSV export and what 2.13.1 answered.
 * The texts that describe the current code read it: the manual source update
 * names the images of docker/php/Dockerfile (ManualSourceUpdateDocsContractTest),
 * troubleshooting.md and runners.md quote the error that RunnerHttpClient
 * gives a runner on PHP 8.3, and CLAUDE.md names what RequestIntCastScan
 * finds. Review finding: this test read the Dockerfiles for the update
 * notes, so a routine move to composer:2.11 would have failed it, and only
 * a false history would have passed it again.
 *
 * Composer's refusals are built from composer.json and the module API
 * number from the PHP that runs the test, so the quotes follow the floor
 * this release sets. When a later release raises the floor, quote these
 * messages as fixed text, as PhpUpdateDocsContractTest does for PHP 8.3.
 */
class Php85UpdateDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    public const UPDATE_SECTION = '## After updating from 2.13.1 or older: PHP 8.5, time zone data, CSV exports, whole numbers';

    private const SECTION_ANCHOR = 'after-updating-from-2131-or-older-php-85-time-zone-data-csv-exports-whole-numbers';

    private const UPDATING = 'docs/updating.md';

    private const RUNNERS = 'docs/runners.md';

    private const TROUBLESHOOTING = 'docs/troubleshooting.md';

    /** The images this release builds on and pulls Composer's binary from, as the section names them. */
    private const IMAGES = ['php:8.5-fpm', 'php:8.5-cli', 'composer:2.10'];

    /** The images of 2.13.1 they replace, as the section names them. */
    private const IMAGES_OF_2131 = ['php:8.3-fpm', 'php:8.3-cli', 'composer:2.6'];

    /** The migration of this release: the next runs of schedules, computed anew. */
    private const MIGRATION = 'm000090_000000_recompute_schedule_next_runs_for_time_zone_data';

    /**
     * A line the migration prints (its MOVED message), as the section
     * quotes it: 3 next runs moved, 1 of them to the moment of the update.
     * When MOVED changes, the section and this line change together.
     */
    private const MIGRATION_LINE = 'Computed anew the next runs of the schedules that are not yet due, with time zone data 2026.5:'
        . ' 3 moved, 1 of them to now for a run that passed in the past hour.';

    /** The time zone data of PHP 8.5.12, the PHP of this release's images, as the section names it. */
    private const TIME_ZONE_DATA = '2026.5';

    /**
     * The zones whose rules differ between the time zone data of PHP 8.3.36
     * (2026.1) and of PHP 8.5.12 (2026.5) since 1970, as the migration names
     * them.
     */
    private const CHANGED_ZONES = [
        'Africa/Casablanca', 'Africa/El_Aaiun', 'America/Vancouver', 'Canada/Pacific', 'America/Edmonton',
        'America/Inuvik', 'America/Yellowknife', 'Canada/Mountain', 'America/Winnipeg', 'America/Rainy_River',
        'Canada/Central', 'America/Bogota', 'Asia/Tehran', 'Iran',
    ];

    /** The zones of CHANGED_ZONES whose rules differ only in 1992 and 1979, which no schedule reaches. */
    private const PAST_ONLY_ZONES = ['America/Bogota', 'Asia/Tehran', 'Iran'];

    /**
     * The offsets the section gives the zones on a day after their change:
     * zone, local time, offset. They hold in 2026.5, not in 2026.1.
     */
    private const NEW_OFFSETS = [
        ['Africa/Casablanca', '2026-10-26 12:00', '+00:00'],
        ['Africa/Casablanca', '2027-07-01 12:00', '+00:00'],
        ['America/Vancouver', '2026-11-02 12:00', '-07:00'],
        ['America/Vancouver', '2027-01-15 12:00', '-07:00'],
        ['America/Edmonton', '2026-11-02 12:00', '-06:00'],
        ['America/Winnipeg', '2026-11-02 12:00', '-05:00'],
    ];

    /** The module API of PHP 8.3, under which an extension built for it says it was compiled. */
    private const PHP83_MODULE_API = '20230831';

    /** MySQL's text for error 4031, a connection it closed for inactivity (mysqlnd since PHP 8.4). */
    private const INACTIVITY = 'The client was disconnected by the server because of inactivity.'
        . ' See wait_timeout and interactive_timeout for configuring this behavior.';

    /** The functions of PHP 8.4 that read the headers of the last HTTP answer; PHP 8.3 has neither. */
    private const PHP84_FUNCTIONS = ['http_clear_last_response_headers', 'http_get_last_response_headers'];

    /**
     * What a runner prints that PHP 8.3 starts with the code of this
     * release and the dependencies of 2.13.1, as the section quotes it.
     */
    private const RUNNER_ON_PHP83 = 'Error: Call to undefined function app\components\http_clear_last_response_headers()';

    /** The section's sentence on empty values, which keep their meaning but in the places it names. */
    private const EMPTY_VALUES = 'An empty value (`null`, `""`) keeps its meaning, except a user\'s `status`'
        . ' (`null` and `""` answer `422`, where they deactivated the user), a project\'s `scm_credential_id`'
        . ' (`""` is no credential, where it answered `422`), the `verbosity` of a launch (`""` is the job'
        . ' template\'s, where it was 0; a list or an object, empty or not, as `verbosity` or `limit` answers'
        . ' `422`) and a number of a runner\'s report (`""` answers `422`, where it was 0).';

    /** The section's sentence on the query parameters, which are read as before. */
    private const QUERY_PARAMETERS = 'The query parameters `?page=`, the filters `template_id`, `launched_by` and'
        . ' `runner_group_id` of `GET /api/v1/jobs`, `group_id` of `GET /api/v1/runners` and `user_id` of'
        . ' `GET /api/v1/audit-logs` are text and still read with PHP\'s `(int)`: `?page=2.5` is page 2.';

    private const CONTAINER_ENTRY = '## A container does not start after the update: composer install failed';

    private const RUNNER_ENTRY = '## A runner installed without Docker does not start after the update';

    private const NUMBER_ENTRY = '## An API call or a form refuses a number: it must be a whole number';

    private const REPORT_ENTRY = "## A job stays running, or ends without task results: the server refused the runner's report";

    private const SERVER_ERROR_ENTRY = '## A page or an API call fails with a server error';

    private const UNHEALTHY_ENTRY = '## App or queue-worker container is unhealthy: the log file cannot be written';

    private const LAUNCH_ENTRY = '## Launch refused: the limit or the verbosity';

    /** A JSON number that PHP writes as 3 with its 14 significant digits, and one it writes as 1. */
    private const NEAR_THREE = '2.9999999999999996';

    private const NEAR_ONE = '0.999999999999999';

    private const TIMEOUTS_ENTRY = '## Approval timeouts or the deleted-template sweep keep failing';

    /**
     * The section is the newest, the one of the update to PHP 8.3 follows,
     * and the manual source update and the rollback link to it under the
     * anchor GitHub gives its heading.
     */
    public function testTheSectionIsTheNewest(): void
    {
        $this->assertUpdateSectionBetween(null, self::UPDATE_SECTION, PhpUpdateDocsContractTest::UPDATE_SECTION);
        $this->assertSame(self::SECTION_ANCHOR, self::anchor(self::UPDATE_SECTION), 'The anchor GitHub gives the heading.');

        $update = $this->flat(self::UPDATING, '## Manual update — source (git)');
        $this->assertMentions('(#' . self::SECTION_ANCHOR . ')', $update, 'the manual source update');
        $this->assertMentions('`git checkout v2.13.1`', $update, 'the manual source update');
        $this->assertMentions('(#' . self::SECTION_ANCHOR . ')', $this->flat(self::UPDATING, '## Rollback'), 'the rollback');
    }

    /**
     * Source installations and the deploy role must build right after the
     * update of their code, as a container of 2.13.1 that starts again
     * refuses the new composer.lock on PHP 8.3; a runner installed without
     * Docker needs PHP 8.5 first; the quickstart's installations from source
     * only pull. The section names the images of this release and those of
     * 2.13.1 they replace, as fixed text.
     */
    public function testTheSectionSaysWhoMustAct(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $line = $this->requiredLine();

        foreach (array_merge(self::IMAGES, self::IMAGES_OF_2131) as $image) {
            $this->assertMentions("`{$image}`", $section, 'the update notes');
        }
        foreach (
            [
                "docker compose exec app php -v # PHP {$line}",
                '`[entrypoint] ERROR: composer install failed`',
                'Run steps 2 and 3 right after `git pull`',
                "needs PHP {$line} before you update its code",
                '`ansilume_version: "v2.13.1"`',
                'an installation the quickstart made from source too: it runs `docker-compose.prebuilt.yml`',
                'which delivers no webhooks on PHP 8.3',
                "`ExecStart=/usr/bin/php{$line} yii runner/start`",
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $this->assertStringContainsString('"[entrypoint] ERROR: composer install failed"', $this->read('docker/php/entrypoint.sh'));
        $defaults = $this->read('deploy/roles/ansilume/defaults/main.yaml');
        $this->assertMatchesRegularExpression('/^ansilume_version:/m', $defaults);
        $this->assertMatchesRegularExpression('/^ansilume_use_prebuilt_images:/m', $defaults);
    }

    /**
     * The refusal of composer install on an older PHP and the platform check
     * of a runner started by one, as Composer words them for the floor of
     * composer.json, in the update notes, runners.md and troubleshooting.md.
     */
    public function testTheDocsQuoteComposersRefusals(): void
    {
        $line = $this->requiredLine();
        $refusal = '/`Root composer\.json requires php ' . preg_quote($this->requiredConstraint(), '/')
            . ' but your php version \((\d+\.\d+)\.\d+\) does not satisfy that requirement\.`/';
        $platform = '/`' . preg_quote('Composer detected issues in your platform: Your Composer dependencies require a PHP version ">= ' . $line . '.0". You are running ', '/')
            . '(\d+\.\d+)\.\d+\.`/';
        $texts = [
            'the update notes' => $this->flat(self::UPDATING, self::UPDATE_SECTION),
            'the prerequisites of runners.md' => $this->flat(self::RUNNERS, '### Prerequisites'),
            'the common issues of runners.md' => $this->flat(self::RUNNERS, '### Common issues'),
            self::RUNNER_ENTRY => $this->flat(self::TROUBLESHOOTING, self::RUNNER_ENTRY),
        ];

        foreach ($texts as $where => $text) {
            foreach (['the refusal of composer install' => $refusal, 'the platform check' => $platform] as $what => $pattern) {
                $this->assertGreaterThan(0, preg_match_all($pattern, $text, $versions), "{$where} quotes {$what}.");
                foreach ($versions[1] as $older) {
                    $this->assertTrue(version_compare($older, $line, '<'), "{$where} quotes {$what} for PHP {$older}, which is no older line.");
                }
            }
        }
        $this->assertMatchesRegularExpression($refusal, $this->flat(self::TROUBLESHOOTING, self::CONTAINER_ENTRY), self::CONTAINER_ENTRY);
        // The platform check that composer install writes says so.
        $this->assertStringContainsString(
            'require a PHP version ">= ' . $line . '.0". You are running ',
            $this->read('vendor/composer/platform_check.php')
        );
    }

    /**
     * Regression (review finding): a runner installed without Docker that
     * PHP 8.3 starts with the dependencies of 2.13.1, because composer
     * install refused the new lock or did not run, passes their platform
     * check (PHP 8.3 or newer) and exits at its first request with an error
     * that the docs did not name, so the line in journalctl led nowhere:
     * RunnerHttpClient reads the headers of an answer with functions of PHP
     * 8.4, and PHP names an unqualified call it cannot resolve in the
     * namespace of the caller. troubleshooting.md and runners.md quote the
     * error as the code makes it, the section as this release made it.
     */
    public function testTheDocsQuoteTheErrorOfARunnerThatPhp83Starts(): void
    {
        $error = 'Error: Call to undefined function ' . $this->firstPhp84FunctionOfTheRunnerClient() . '()';
        $entry = $this->flat(self::TROUBLESHOOTING, self::RUNNER_ENTRY);
        $issues = $this->flat(self::RUNNERS, '### Common issues');
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        $this->assertMentions("`{$error}`", $this->between($entry, '**Symptom:**', '**Cause:**'), 'the symptom of ' . self::RUNNER_ENTRY);
        $this->assertMentions(
            'After the third one, or when `composer install` was not run, the dependencies of the release before stay in place,'
            . ' which the new code is not made for: their platform check lets PHP 8.3 start it, and the runner exits at its'
            . ' first request with the second message, as the new code calls a function of PHP 8.4 there.',
            $entry,
            self::RUNNER_ENTRY
        );
        $this->assertMentions("`{$error}`", $issues, 'the common issues of runners.md');
        $this->assertMentions(
            'The second message comes when PHP 8.3 starts the new code with the dependencies of 2.13.1, because'
            . ' `composer install` refused the new lock or was not run | Install PHP 8.5 first',
            $issues,
            'the common issues of runners.md'
        );
        $this->assertMentions(
            'PHP 8.3 starts the new code with them, and the runner exits at its first request with `' . self::RUNNER_ON_PHP83 . '`.',
            $section,
            'the update notes'
        );
    }

    /**
     * Where a runner installed without Docker gets PHP 8.5, and how to check
     * the PHP its service starts, in the section and in runners.md.
     * Regression (review finding): the docs named Alpine 3.23, whose php85
     * installs only /usr/bin/php85, while /usr/bin/php and the composer
     * package are PHP 8.4 there; on Alpine 3.24 they are PHP 8.5.
     */
    public function testRunnersLearnWhereToGetPhp85(): void
    {
        $line = $this->requiredLine();
        $prerequisites = $this->flat(self::RUNNERS, '### Prerequisites');

        foreach (['the update notes' => $this->flat(self::UPDATING, self::UPDATE_SECTION), 'runners.md' => $prerequisites] as $where => $text) {
            foreach (['packages.sury.org', '`ppa:ondrej/php`', "php:remi-{$line}", 'Ubuntu 26.04', 'Fedora 44', 'Alpine 3.24 or later', 'Debian 13 ships PHP 8.4'] as $needle) {
                $this->assertMentions($needle, $text, $where);
            }
            $this->assertStringNotContainsString('Alpine 3.23 or later', $text, "{$where}: Alpine 3.23 has PHP 8.4 as php and composer.");
        }
        foreach (
            [
                "- PHP {$line}+ with extensions: `pcntl`, `posix`, `zip`, `curl`, and `ffi`",
                "`php{$line}-cli`, `php{$line}-curl` and `php{$line}-zip`",
                "`dnf module enable php:remi-{$line}`",
                'Alpine 3.24 or later, where `/usr/bin/php` and the `composer` package are PHP 8.5',
                '`php85`, `php85-pcntl`, `php85-posix`, `php85-zip`, `php85-curl` and `php85-ffi`',
                'On Alpine 3.23 `php85` installs only `/usr/bin/php85`, while `/usr/bin/php` and the `composer` package are PHP 8.4',
                "/usr/bin/php -v # PHP {$line}",
                "/usr/bin/php -m | grep -x -E 'pcntl|posix|zip|curl|FFI' # all five",
            ] as $needle
        ) {
            $this->assertMentions($needle, $prerequisites, 'the prerequisites of runners.md');
        }
        $this->assertMentions(
            'install PHP 8.5 with `pcntl`, `posix`, `zip`, `curl` and `ffi`',
            $this->flat(self::UPDATING, self::UPDATE_SECTION),
            'the update notes'
        );
        $this->assertMentions(
            'Alpine 3.24 or later ship it (on Alpine 3.23 `/usr/bin/php` and `composer` are PHP 8.4',
            $this->flat(self::UPDATING, self::UPDATE_SECTION),
            'the update notes'
        );
    }

    /**
     * Images of one's own: an extension built for PHP 8.3 does not load, as
     * PHP's module API changed, and OPcache is part of PHP 8.5, which the
     * images do not install themselves (OpcacheBuiltInTest). A fatal error
     * of PHP 8.5 carries a stack trace, without arguments in every image.
     * Regression (review finding): the section quoted PHP's third line with
     * one space after "PHP", where PHP prints four, so a search of the log
     * for the quote found nothing; flat() collapses whitespace, so the raw
     * section is read for it.
     */
    public function testImagesOfYourOwnLearnWhatBreaks(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $this->assertSame(1, preg_match('/-(\d{8})$/', basename(PHP_EXTENSION_DIR), $api), 'The extension directory names the module API.');

        $this->assertMentions('`PHP Startup: <extension>: Unable to initialize module`', $section, 'the update notes');
        $this->assertMentions('`Module compiled with module API=' . self::PHP83_MODULE_API . '`', $section, 'the update notes');
        $this->assertStringContainsString(
            "`PHP    compiled with module API={$api[1]}`",
            $this->section(self::UPDATING, self::UPDATE_SECTION),
            'The update notes quote the line as PHP prints it, with four spaces after PHP.'
        );
        foreach (['`docker-php-ext-install opcache`', '`docker-php-ext-enable opcache`', '`zend_extension=opcache`', "`Failed loading Zend extension 'opcache'`"] as $needle) {
            $this->assertMentions($needle, $section, 'the update notes');
        }

        $this->assertSame('1', ini_get('fatal_error_backtraces'), 'PHP writes a stack trace under a fatal error.');
        $this->assertMentions('**Fatal errors carry a stack trace.**', $section, 'the update notes');
        $this->assertMentions('`zend.exception_ignore_args = On`, which every Ansilume image sets', $section, 'the update notes');
        foreach (['docker/php/php.ini', 'docker/runner/php.ini', 'deploy/roles/ansilume/templates/php.ini'] as $ini) {
            $this->assertMatchesRegularExpression('/^zend\.exception_ignore_args = On$/m', $this->read($ini), $ini);
        }
    }

    /**
     * The migration of this release prints what the section quotes, the
     * section names the zones that changed, the rules it states hold in the
     * time zone data of the PHP the tests run on, and the rollback says to
     * revert the migration with this release. Regression (review finding):
     * the section said that schedules in all 14 zones ran an hour off, that
     * a PHP line with security fixes only gets no time zone data, and that
     * western Canada's ran late from November on, where three zones differ
     * only in the past, PHP 8.3.31 brought 2026.1, and 2026.1 has summer
     * time from mid-March.
     */
    public function testTheSectionQuotesTheMigrationAndTheZones(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION . '.php';
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $offOn2131 = count(array_diff(self::CHANGED_ZONES, self::PAST_ONLY_ZONES));

        $this->assertSame(11, $offOn2131, 'The zones whose schedules ran an hour off on 2.13.1.');
        foreach (
            [
                "(see **Schedules in {$offOn2131} time zones run at their time again**)",
                "**Schedules in {$offOn2131} time zones run at their time again.**",
                'a PHP line in security support gets updates of it seldom: PHP 8.3 got 2026.1 with 8.3.31 (2026-05-07)'
                    . ' and none since, so PHP 8.3.36, which 2.13.1 runs on, has 2026.1, while PHP 8.5.12 has ' . self::TIME_ZONE_DATA . '.',
                'Their rules differ in ' . count(self::CHANGED_ZONES) . " zones since 1970, and in {$offOn2131} of them from 2026 on.",
                'where 2026.1 has UTC+1 except in the weeks of Ramadan (2027-02-07 to 2027-03-14, for example):'
                    . ' on 2.13.1 their schedules run an hour early, except in those weeks.',
                'on 2.13.1 their schedules run an hour late each year from early November to mid-March.',
                '`America/Bogota`, `Asia/Tehran` and `Iran` differ only in 1992 and 1979, which no schedule reaches.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        foreach (['gets security fixes only gets no updates of it', 'run an hour late from then on', 'Schedules in 14 time zones'] as $wrong) {
            $this->assertStringNotContainsString($wrong, $section, 'The update notes no longer say so.');
        }
        $this->assertMatchesRegularExpression(
            self::printfPattern(\m000090_000000_recompute_schedule_next_runs_for_time_zone_data::MOVED),
            self::MIGRATION_LINE,
            'The section quotes a line the migration prints: quote its MOVED message anew when it changes.'
        );
        $this->assertSame(
            sprintf(\m000090_000000_recompute_schedule_next_runs_for_time_zone_data::MOVED, self::TIME_ZONE_DATA, 3, 1),
            self::MIGRATION_LINE,
            'The quoted line names the next runs that moved and those that moved to the moment of the update.'
        );
        $this->assertStringNotContainsString(': 3 moved.`', $section, 'The section no longer quotes the line of one count.');
        $this->assertStringContainsString(self::TIME_ZONE_DATA . ':', self::MIGRATION_LINE, 'The quoted line names the time zone data of PHP 8.5.12.');
        $this->assertMentions('`' . self::MIGRATION_LINE . '`', $section, 'the update notes');
        $this->assertMentions('(migration `m000090`)', $section, 'the update notes');
        $this->assertTrue(version_compare(timezone_version_get(), self::TIME_ZONE_DATA, '>='), 'The PHP the tests run on has the time zone data the section names, or newer.');
        $zones = timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC);
        foreach (self::CHANGED_ZONES as $zone) {
            $this->assertContains($zone, $zones);
            $this->assertMentions("`{$zone}`", $section, 'the update notes');
        }
        foreach (self::NEW_OFFSETS as [$zone, $time, $offset]) {
            $this->assertSame($offset, (new \DateTimeImmutable($time, new \DateTimeZone($zone)))->format('P'), "{$zone} on {$time}");
        }
        $this->assertMentions('docker compose exec app php -r \'echo timezone_version_get(), PHP_EOL;\'', $section, 'the update notes');
        $this->assertMentions(
            'To go back further than 2.13.1, first revert the migration of this release with this release, `docker compose exec -u www-data app php yii migrate/down 1 --interactive=0`',
            $section,
            'the update notes'
        );
        $rollback = $this->flat(self::UPDATING, '## Rollback');
        $this->assertMentions('(migration `m000090`, which computes the next runs of schedules anew with the time zone data of PHP 8.5)', $rollback, '## Rollback');
        $this->assertMentions('revert `m000090` first, with the release that has it; `migrate/down 2` there reverts `m000089` as well.', $rollback, '## Rollback');
    }

    /**
     * The CSV exports: the deprecation PHP 8.5 reports for fputcsv() without
     * its escape, the bytes CsvText writes for a field with a backslash and
     * those 2.13.1 wrote, how RFC 4180 reads the old ones, and the headers
     * of the API's answer, which answered 500 up to 2.13.1; the headers and
     * what 2.13.1 answered as fixed text (the API's own tests pin its
     * headers: AnalyticsControllerTest). Regression (review finding): the
     * docs said the exports follow RFC 4180, whose lines end with a carriage
     * return and a line feed; they quote as RFC 4180 does, and each line ends
     * with a line feed, as before.
     */
    public function testTheSectionQuotesTheCsvExports(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        $deprecation = self::deprecationOfFputcsvWithoutEscape();
        $this->assertNotSame('', $deprecation, 'The PHP the tests run on deprecates fputcsv() without its escape.');
        $this->assertMentions("`{$deprecation}`", $section, 'the update notes');
        foreach (['C:\temp\x' => ['C:\temp\x', '"C:\temp\x"'], 'a\"b' => ['"a\""b"', '"a\"b"']] as $field => [$now, $before]) {
            $this->assertSame("name\n{$now}\n", CsvText::of([['name' => $field]]), "The CSV of {$field}.");
            $this->assertMentions("`{$field}`", $section, 'the update notes');
            $this->assertMentions("`{$now}`", $section, 'the update notes');
            $this->assertMentions("`{$before}`", $section, 'the update notes');
        }
        $this->assertSame(['a\b"'], str_getcsv('"a\"b"', ',', '"', ''), 'How RFC 4180 reads what 2.13.1 wrote for a\"b.');
        $this->assertMentions('`a\b"`', $section, 'the update notes');

        foreach (
            [
                '**CSV exports use RFC 4180 quoting.**',
                'They quote fields as RFC 4180 says now, without an escape character: a quote inside a field is doubled,'
                    . ' and a backslash is an ordinary character. Each line still ends with a line feed, where RFC 4180'
                    . ' ends a line with a carriage return and a line feed.',
                '(see **CSV exports use RFC 4180 quoting**)',
                '`Content-Type: text/csv; charset=UTF-8`',
                '`Content-Disposition: attachment; filename="analytics-approvalSummary-2026-10-26.csv"`',
                '`analytics-approval-summary-2026-10-26.csv`',
                '`Response content must not be an array.`',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $this->assertStringNotContainsString('CSV exports follow RFC 4180', $this->read(self::UPDATING));
        $spec = (string)preg_replace('/\s+/', ' ', $this->read('web/openapi.yaml'));
        foreach (
            [
                'the answer is CSV with RFC 4180 quoting (see `AnalyticsResult`)',
                'The CSV quotes fields as RFC 4180 says, without an escape character',
                'each line ending with a line feed, not with the carriage return and line feed of RFC 4180',
                '`analytics-approvalSummary-2026-10-26.csv`',
            ] as $needle
        ) {
            $this->assertMentions($needle, $spec, 'web/openapi.yaml');
        }
        foreach (['CSV as RFC 4180 writes it', 'The CSV is written as RFC 4180 says'] as $wrong) {
            $this->assertStringNotContainsString($wrong, $spec, 'web/openapi.yaml no longer says so.');
        }
    }

    /**
     * The answers to a value that is no whole number, as the models' rules
     * and the endpoints word them, in the section and in the troubleshooting
     * entry the section links to. Regression (review finding): the section
     * and the spec said that empty values keep their meaning, which four
     * changes of this release contradict, and the section said that the API
     * reads every whole number so, where its query parameters are read with
     * (int) as before.
     */
    public function testTheDocsQuoteTheAnswersToAValueThatIsNoWholeNumber(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $entry = $this->flat(self::TROUBLESHOOTING, self::NUMBER_ENTRY);
        $answers = [
            self::integerRefusal(new JobTemplate(), 'forks'),
            self::integerRefusal(new JobTemplate(), 'runner_group_id'),
            self::integerRefusal(new JobTemplate(), 'approval_rule_id'),
            self::integerRefusal(new Inventory(), 'project_id'),
            self::integerRefusal(new Schedule(), 'job_template_id'),
            self::integerRefusal(new Project(), 'scm_credential_id'),
            self::integerRefusal(new Job(), 'verbosity'),
            self::blankRefusal(new Schedule(), 'job_template_id'),
            UsersController::INVALID_STATUS,
            sprintf(TeamsController::USER_NOT_FOUND, 0),
            sprintf(TeamsController::PROJECT_NOT_FOUND, 0),
            TeamController::USER_ID_REQUIRED,
            TeamController::PROJECT_ID_REQUIRED,
            sprintf(WorkflowStepWriteService::STEP_NOT_FOUND, 5, 0),
            sprintf(ApproverEligibility::REFUSAL_JOB, SurveyDefinition::quote('deploy-prod'), 1, 0),
        ];
        $this->assertSame('Runner must be an integer.', $answers[1], 'The job template labels its runner group as Runner.');
        foreach ($answers as $answer) {
            $this->assertMentions("`{$answer}`", $section, 'the update notes');
        }
        $literals = [
            'controllers/api/v1/RunnersController.php' => 'Target runner group not found.',
            'controllers/RunnerController.php' => 'Runner group not found.',
            'controllers/api/v1/JobsController.php' => 'job_template_id is required.',
        ];
        foreach ($literals as $file => $answer) {
            $this->assertStringContainsString("'{$answer}'", $this->read($file), "{$file} answers {$answer}");
            $this->assertMentions("`{$answer}`", $section, 'the update notes');
        }
        $this->assertStringContainsString('"Job template #{$id} not found."', $this->read('controllers/JobTemplateController.php'));
        $this->assertMentions('`Job template #0 not found.`', $section, 'the update notes');
        foreach ([$answers[0], $answers[3], $answers[4], $answers[5], $answers[6], $answers[8], $answers[9], $answers[10], 'Target runner group not found.', 'job_template_id is required.'] as $answer) {
            $this->assertMentions("`{$answer}`", $entry, self::NUMBER_ENTRY);
        }
        $this->assertMentions('(troubleshooting.md#' . self::anchor(self::NUMBER_ENTRY) . ')', $section, 'the update notes');
        $this->assertMentions('(updating.md#' . self::SECTION_ANCHOR . ')', $entry, self::NUMBER_ENTRY);
        foreach (['`1e30`', '`9223372036854775808`', '`1e999`', '`5.5`', '`"abc"`', '`true`'] as $value) {
            $this->assertMentions($value, $section, 'the update notes');
            $this->assertMentions($value, $entry, self::NUMBER_ENTRY);
        }

        $this->assertMentions(
            'The API, the job triggers, the runner API and the web forms posted as JSON read an ID or another whole number of the request body as the integer rule of the model reads it',
            $section,
            'the update notes'
        );
        $this->assertMentions(self::EMPTY_VALUES, $section, 'the update notes');
        $this->assertMentions(self::QUERY_PARAMETERS, $section, 'the update notes');
        $this->assertStringNotContainsString('empty values (`null`, `""`) keep their meaning', $section, 'The update notes name the empty values whose meaning changed.');
        $info = (string)preg_replace('/\s+/', ' ', (string)($this->spec()['info']['description'] ?? ''));
        $this->assertMentions(
            'An empty value (`null`, `""`) keeps its meaning, except a user\'s `status` (`null` and `""` answer 422, where'
            . ' they deactivated the user), a project\'s `scm_credential_id` (`""` is no credential, where it answered 422)'
            . ' and the `verbosity` of a launch (`""` is the job template\'s, where it was 0; a list or an object, empty or'
            . ' not, as `verbosity` or `limit` answers 422).',
            $info,
            'the Whole numbers part of the spec'
        );
        $this->assertMentions('and `""` too, where releases up to 2.13.1 took it for 0; `null` or a missing number is 0.', $info, 'the Whole numbers part of the spec');
        $this->assertStringNotContainsString('keep their meaning', $info, 'The spec names the empty values whose meaning changed.');
    }

    /**
     * Regression (review finding): the docs said that a project_id that is no
     * ID is refused before the access checks, but on an update, and on the
     * web update forms, the access check of the stored project comes first,
     * and on a job template with an approval rule the lock's 403 comes before
     * the 422 of another field for users without approval-rule.update.
     *
     * Regression (verification of the fixes): the docs then said that the
     * lock's 403 always comes first, but the lock compares a field's value
     * after the column's (int) cast, so a value that the cast reads as the
     * stored one, such as 5.5 where 5 is stored, is no change to it and gets
     * the field's 422. JobTemplatesControllerIntRangeTest pins the order
     * (testTheLockTakesAValueThatTheCastReadsAsTheStoredOneForNoChange).
     */
    public function testTheDocsSayWhichChecksComeBeforeTheProjectsError(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $castExceptions = '(`5.5`, `"5abc"` or `" 5"` where 5 is stored, `true` where 1 is, `"abc"`, `false` or `0.5` where 0'
            . ' is): the lock compares the cast values, sees no change, and the field\'s';
        foreach (
            [
                'a `project_id` that is no ID is refused before the access check of the project it names and before the'
                    . ' lock of a job template with an approval rule, where `12.5` was taken for project 12. On `PUT` a caller'
                    . ' who may not change the stored job template gets `403` first, and on a job template with an approval'
                    . ' rule the lock\'s `403` comes before these `422`s for a user without `approval-rule.update`, except'
                    . ' for `project_id`, and except when the body changes the fields the lock guards (all but `name` and'
                    . ' `description`) only to values that PHP\'s `(int)` reads as the stored ones ' . $castExceptions
                    . ' `422` comes. With any other change of such a field the lock\'s `403` comes.',
                'number answers `422` `Project Id must be an integer.` before the access check of the project it names'
                    . ' (on `PUT` a caller who may not change the stored inventory gets `403` first)',
                'such as `Project Id must be an integer.`, before the access check of the project it names (a user who'
                    . ' may not change the stored inventory or job template gets `403` first)',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        foreach (['refused before the access checks and the lock', 'before any access check'] as $wrong) {
            $this->assertStringNotContainsString($wrong, $section, 'The update notes no longer say so.');
        }

        $spec = $this->spec();
        $rejected = (string)preg_replace('/\s+/', ' ', (string)($spec['components']['responses']['JobTemplateRejected']['description'] ?? ''));
        $this->assertMentions(
            'A `project_id` that is no ID is refused before the 403 of the access check of the project it names and of the'
            . ' lock of a template with an approval rule; on PUT a caller who may not change the stored job template gets'
            . ' 403 first. On a template with an approval rule, the lock\'s 403 comes before these 422s for a user without'
            . ' `approval-rule.update`, except for `project_id`, and except when the body changes the fields the lock guards'
            . ' (all but `name` and `description`) only to values that PHP\'s `(int)` reads as the stored ones '
            . $castExceptions . ' 422 comes. With any other change of such a field the lock\'s 403 comes.',
            $rejected,
            'JobTemplateRejected'
        );
        $template = (string)preg_replace('/\s+/', ' ', (string)($spec['components']['schemas']['JobTemplateInput']['description'] ?? ''));
        $this->assertMentions(
            'On a template with an approval rule, a user without `approval-rule.update` gets the lock\'s 403 first, with the'
            . ' exceptions that `JobTemplateRejected` names.',
            $template,
            'JobTemplateInput'
        );
        $input = (string)preg_replace('/\s+/', ' ', (string)($spec['components']['schemas']['InventoryInput']['description'] ?? ''));
        $this->assertMentions(
            '`Project Id must be an integer.` before the access check of the project it names; on PUT a caller who may not'
            . ' change the stored inventory gets 403 first.',
            $input,
            'InventoryInput'
        );
    }

    /**
     * The runner API's refusals of a number it cannot store, in the section,
     * in runners.md and in the troubleshooting entry that says what a refused
     * report leaves. Regression (review finding): "" is refused like any
     * other value that is no whole number, where 2.13.1 took it for 0, so
     * that "exit_code": "" ended a job as succeeded, and the docs said so
     * nowhere.
     */
    public function testTheDocsQuoteTheRefusedReportsOfRunners(): void
    {
        $refusals = [
            RunnerReportNumbers::SEQUENCE_REFUSED,
            RunnerReportNumbers::EXIT_CODE_REFUSED,
            sprintf(RunnerReportNumbers::TASK_NUMBER_REFUSED, '<key>', 'seq'),
            sprintf(RunnerReportNumbers::TASK_NOT_AN_OBJECT, '<key>'),
        ];
        $noHost = (string)strtok(JobCompletionService::NO_HOST_MATCHED, "\n");
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $entry = $this->flat(self::TROUBLESHOOTING, self::REPORT_ENTRY);
        $issues = $this->flat(self::RUNNERS, '### Common issues');

        foreach ($refusals as $refusal) {
            $this->assertMentions("`{$refusal}`", $section, 'the update notes');
            $this->assertMentions("`{$refusal}`", $entry, self::REPORT_ENTRY);
        }
        $this->assertMentions('`{"ok": false, "error": "<message>"}`', $section, 'the update notes');
        $this->assertMentions('`' . $refusals[1] . '`', $issues, 'the common issues of runners.md');
        foreach ([$entry, $issues] as $text) {
            $this->assertMentions("`{$noHost}`", $text, 'troubleshooting.md and runners.md');
            $this->assertMentions('`php yii job/reclaim-stale` takes only the jobs of runners that are offline', $text, 'troubleshooting.md and runners.md');
            $this->assertMentions('`ANSILUME_CALLBACK_FILE`', $text, 'troubleshooting.md and runners.md');
        }
        $this->assertMentions('(troubleshooting.md#' . self::anchor(self::REPORT_ENTRY) . ')', $issues, 'the common issues of runners.md');
        $this->assertStringContainsString("'ANSILUME_CALLBACK_FILE'", $this->read('components/PlaybookEnvironment.php'));

        $this->assertNull(RunnerReportNumbers::sequence(''), 'An empty sequence is refused.');
        $this->assertNull(RunnerReportNumbers::exitCode(''), 'An empty exit code is refused.');
        $this->assertNull(RunnerReportNumbers::taskNumber(''), 'An empty seq or duration_ms is refused.');
        $this->assertSame(sprintf(RunnerReportNumbers::TASK_NUMBER_REFUSED, 0, 'seq'), RunnerReportNumbers::taskListRefusal([['seq' => '']]));
        $this->assertMentions(
            '`""` is refused like any other value that is no whole number, where 2.13.1 took it for 0, so that'
            . ' `"exit_code": ""` ended a job as succeeded; `null` or a missing field is 0, as before.',
            $section,
            'the update notes'
        );
        $this->assertMentions('the report held a number the server cannot store, or `""` where a number belongs, which 2.13.1 took for 0', $issues, 'the common issues of runners.md');
        $this->assertMentions('as it held a number the server cannot store, or `""` where a number belongs, and wrote nothing of the report', $entry, self::REPORT_ENTRY);
        $this->assertMentions('`"exit_code": 1e999` and `"exit_code": ""` ended a job as succeeded', $entry, self::REPORT_ENTRY);
    }

    /**
     * Regression (review of the fixes): the migration moved every next run
     * of the Canadian zones that was not yet due to the first run after the
     * update, so an update in the hour after a run's new time lost that run
     * until the next day, and in Morocco it would have run a run again that
     * 2.13.1 had launched an hour early. The section says which runs the
     * migration keeps, in the zones it names for them (LATE_ZONES,
     * EARLY_ZONES), which are the 11 zones of the section.
     */
    public function testTheSectionSaysWhichRunsTheMigrationKeeps(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION . '.php';
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $early = \m000090_000000_recompute_schedule_next_runs_for_time_zone_data::EARLY_ZONES;
        $late = \m000090_000000_recompute_schedule_next_runs_for_time_zone_data::LATE_ZONES;

        $this->assertEqualsCanonicalizing(
            array_diff(self::CHANGED_ZONES, self::PAST_ONLY_ZONES),
            array_merge($early, $late),
            'The migration keeps the runs of the zones whose schedules ran an hour off on 2.13.1.'
        );
        foreach (
            [
                'when its time came after the schedule\'s last run and its last change, the schedule\'s next run becomes'
                    . ' the moment of the update, so the scheduler launches it as soon as the migrations have run',
                'The same holds for a run that 2.13.1 stored after a run it launched an hour late, as 9:00 of'
                    . ' `0 8,9 * * *` after 8:00.',
                'A change after that time (a save, enabling the schedule) counts as it does without the update: the'
                    . ' schedule runs from the change on.',
                'A disabled schedule launches nothing, and enabling it computes its next run from then on.',
                'In `' . implode('` and `', $early) . '` the run 2.13.1 launched an hour early was that of the hour after it,'
                    . ' so a next run there never moves earlier, and one that is an hour early moves to its time, also'
                    . ' when it is due: the schedule runs once, at its time.',
                'still runs 8:00 once more after 2.13.1 launched it at 7:00, as it would without the update.',
                'An update from 2.12.2 or older runs `m000089` first, which replaces the next run 2.12.2 stored',
                'The migration prints how many next runs moved, and how many of them to the moment of the update',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
    }

    /**
     * Regression (review of the fixes): Yii's integer rule read a float by
     * its text of 14 significant digits, so 2.9999999999999996 (text "3")
     * and 0.999999999999999 (text "1") passed it, and the save cut them to
     * 2 and 0: "status": 0.999999999999999 deactivated a user. The section
     * and the troubleshooting entry say that such a fraction is refused and
     * what 2.13.1 took it for, as PHP writes and casts it and as the rule
     * answers.
     */
    public function testTheDocsQuoteTheAnswersToAFractionCloseToAWholeNumber(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $entry = $this->flat(self::TROUBLESHOOTING, self::NUMBER_ENTRY);
        $nearThree = (float)self::NEAR_THREE;
        $nearOne = (float)self::NEAR_ONE;

        $this->assertSame('14', ini_get('precision'), 'PHP writes a float with 14 significant digits.');
        $this->assertSame(['3', '1'], [(string)$nearThree, (string)$nearOne], 'PHP writes the fractions as whole numbers.');
        $this->assertSame([2, 0], [(int)$nearThree, (int)$nearOne], 'The cast of 2.13.1 cuts them.');
        $this->assertSame(self::integerRefusal(new JobTemplate(), 'forks'), self::refusal(new JobTemplate(), 'forks', $nearThree, 'integer'), 'The integer rule refuses it as it refuses 5.5.');
        $this->assertNull(SubmittedInteger::of($nearOne), 'It is no whole number.');
        foreach (
            [
                'A fraction is refused however close it is to a whole number: `' . self::NEAR_THREE . '` is no 3, although'
                    . ' PHP writes it as `3` with its 14 significant digits.',
                '`' . self::NEAR_THREE . '` for 2',
                '`"abc"`, `false`, `[]`, `{}` and `' . self::NEAR_ONE . '` for 0',
                '`false`, `null`, `""`, `[]`, `{}`, text such as `"abc"` and a fraction below 1, such as `' . self::NEAR_ONE
                    . '`, deactivated the user',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $this->assertMentions(
            'also one as close to a whole number as `' . self::NEAR_THREE . '`, which a computation in a client can give where 3 was meant',
            $entry,
            self::NUMBER_ENTRY
        );
        $this->assertMentions(
            'Round a number your client computes before you send it: `' . self::NEAR_THREE . '` is refused, `3` is taken.',
            $entry,
            self::NUMBER_ENTRY
        );
    }

    /**
     * Regression (review of the fixes): Yii's rules take an empty list for
     * an empty value and skip it, and the request's JSON parser makes a JSON
     * [] and {} one, so the column stored 0, or its foreign key answered
     * 500: an approval rule that needs no approval, a launch at verbosity 0.
     * The section, the troubleshooting entries and the spec say what a list
     * or an object answers now, also an empty one, as the models' rules and
     * the launch form word it.
     */
    public function testTheDocsQuoteTheAnswersToAListOrAnObject(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $verbosity = self::integerRefusal(new Job(), 'verbosity');
        $limit = self::refusal(new Job(), 'limit', ['web'], 'string');
        $blank = self::blankRefusal(new Schedule(), 'job_template_id');
        $approvals = self::integerRefusal(new ApprovalRule(), 'required_approvals');
        $timeout = self::integerRefusal(new ApprovalRule(), 'timeout_minutes');

        $this->assertSame('Limit must be a string.', $limit, 'The job record refuses a list as the limit.');
        $this->assertSame($blank, self::refusal(new Schedule(), 'job_template_id', [], 'required'), 'The required rule takes an empty list for none.');
        foreach (
            [
                "A list or an object as the `verbosity` or the `limit` is refused also when it is empty (`[]` or `{}`), with `{$verbosity}`"
                    . " or `{$limit}` in `error.fields`, where 2.13.1 launched an empty one with the job template's limit and at verbosity 0.",
                "`422` `{\"error\": \"{$verbosity}\"}` or `{\"error\": \"{$limit}\"}`.",
                'A list or an object, also an empty one (`[]` or `{}`), is no whole number either: in these fields but'
                    . ' `approval_rule_id` it answers `422` with the field\'s message as early as a `project_id` that is no ID,'
                    . ' before the lock too',
                'an admin\'s `"approval_rule_id": []` answered `500`.',
                "and `null`, `\"\"`, `[]` or `{}` `422` `{$blank}`",
                'a list or an object among them, also an empty one (`[]` or `{}`), answers `422` `'
                    . self::integerRefusal(new Project(), 'scm_credential_id') . '`, and `""` means no credential, as `null`'
                    . ' does, where `""`, `[]` and `{}` answered `422` `Scm Credential Id is invalid.`',
                "a `required_approvals` or `timeout_minutes` that is a list or an object, also an empty one (`[]` or `{}`),"
                    . " answers `422` `{$approvals}` or `{$timeout}` and writes nothing",
                'Up to 2.13.1 an empty one was stored as 0: a rule that needs no approval, so that any vote, a rejection'
                    . ' too, approved the request, or one whose requests timed out at once.',
                '`SELECT id, name FROM approval_rule WHERE required_approvals < 1 OR timeout_minutes < 1;`',
                'shows the field\'s error, such as `' . self::integerRefusal(new JobTemplate(), 'credential_id') . '`, and the field empty',
                'Sent as the approval rule by a user without `approval-rule.update`, it is a change of the rule (`403`),'
                    . ' and as an inventory\'s project it is no project, as the form\'s empty choice.',
                'The launch form refuses a list as the limit or the verbosity, such as a crafted `overrides[verbosity][]=`, with `'
                    . sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, $verbosity) . '` or `'
                    . sprintf(JobTemplateController::FLASH_LAUNCH_FAILED, $limit) . '`, and launches nothing',
                'Posted as JSON, an empty list or object (`[]`, `{}`) is refused the same way.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }

        $entry = $this->flat(self::TROUBLESHOOTING, self::NUMBER_ENTRY);
        $this->assertMentions("`{$approvals}`", $this->between($entry, '**Symptom:**', '**Cause:**'), self::NUMBER_ENTRY);
        $this->assertMentions('a list or an object, also an empty one (`[]` or `{}`), or a JSON number beyond the 64-bit int range', $entry, self::NUMBER_ENTRY);
        $launch = $this->flat(self::TROUBLESHOOTING, self::LAUNCH_ENTRY);
        foreach (
            [
                'or a list or an object for either, also an empty one (`[]` or `{}`), which the launch form refuses too'
                    . ' (a crafted `overrides[verbosity][]=`).',
                'an empty list or object launched the job with the job template\'s limit and at verbosity 0, and the launch'
                    . ' form ignored a list and launched the job with the job template\'s limit and verbosity.',
                'An empty limit or verbosity (`""`) is the job template\'s.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $launch, self::LAUNCH_ENTRY);
        }
        $this->assertStringNotContainsString('(the launch form drops a list)', $launch, 'The launch form refuses a list.');

        $schemas = $this->spec()['components']['schemas'] ?? [];
        $this->assertIsArray($schemas);
        $schedule = (string)preg_replace('/\s+/', ' ', (string)($schemas['ScheduleInput']['properties']['job_template_id']['description'] ?? ''));
        $this->assertMentions(
            'a list or an object with values, a number beyond the int range) answers 422 `' . self::integerRefusal(new Schedule(), 'job_template_id')
                . "`, and null, an empty string or an empty list or object (`[]`, `{}`) 422 `{$blank}`",
            $schedule,
            'ScheduleInput.job_template_id'
        );
        $status = (string)preg_replace('/\s+/', ' ', (string)($schemas['UserInput']['properties']['status']['description'] ?? ''));
        $this->assertMentions('a list or an object, also an empty one (`[]`, `{}`), and text such as `"abc"` included', $status, 'UserInput.status');
    }

    /**
     * Regression (review of the fixes): the check of who may assign an
     * approval rule, and the audit of a job template, read a rule ID written
     * as "+3", "03", "3\n" or 3.0 as no rule ID, while the save stored it as
     * rule 3: a user without approval-rule.update who sent the stored rule
     * again so was refused, and the audit recorded no rule or a removal.
     * The section quotes the refusal and the spellings as the code reads
     * them (ApprovalRuleAssignmentPolicy::normalizeRuleId()), and says that
     * the page of a team rule without a team says so.
     */
    public function testTheSectionQuotesTheSpellingsOfARuleId(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        foreach (['+3', '03', "3\n", 3.0, 3e0] as $spelling) {
            $this->assertSame(3, ApprovalRuleAssignmentPolicy::normalizeRuleId($spelling), 'Rule 3, as ' . var_export($spelling, true));
        }
        foreach ([' 3', '3.0', '3e0', 3.5] as $noId) {
            $this->assertSame($noId, ApprovalRuleAssignmentPolicy::normalizeRuleId($noId), 'No rule ID: ' . var_export($noId, true));
        }
        $this->assertStringContainsString(
            "echo 'Team: <em class=\"text-muted\">none configured</em>';",
            $this->read('views/approval-rule/view.php'),
            'The page of a team rule without a team says so.'
        );
        foreach (
            [
                'such as `"+3"`, `"03"`, `"3\n"`, `3.0` or `3e0` for rule 3, is that rule wherever it is read',
                'The save always stored it as rule 3, but up to 2.13.1 the check of who may assign a rule, and the audit of'
                    . ' a job template, read it as no rule ID.',
                'was refused with `403` `' . ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED . '`; that is no change now, and the'
                    . ' rename is saved.',
                'These entries record the rule now, as `{"from": ..., "to": {"id": 3, "name": "deploy-prod"}}`, and no'
                    . ' `approval_rule` when the rule stays as it is.',
                'A value that is no whole number, such as `" 3"`, `"3.0"`, `"3e0"` or `3.5`, is refused as before: `403` for'
                    . ' a user without `approval-rule.update`, `422` `' . self::integerRefusal(new JobTemplate(), 'approval_rule_id')
                    . '` for admins.',
                'the rule\'s page shows `Team: none configured` or `Users: none configured`',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $this->assertStringNotContainsString('`Team: #`', $section, 'A team rule without a team shows none configured.');
    }

    /**
     * Regression (review of the fixes): a page past the last of an API list
     * answered the items of the last page again under the requested page,
     * so a client that pages until the data is empty never stopped. The
     * section says what such a page answers, ?page=1e30 as PHP reads it.
     */
    public function testTheSectionSaysWhatAPagePastTheLastAnswers(): void
    {
        $this->assertSame(PHP_INT_MAX, (int)'1e30', 'PHP reads ?page=1e30 as the largest int.');
        $this->assertMentions(
            'answer a page past the last with `200`, an empty `data`, the requested page in `meta.page` and the'
                . ' list\'s `total` and `pages`, so a client that pages until `data` is empty stops. Up to 2.13.1 they'
                . ' answered the items of the last page again, labelled with the requested page, also for `?page=1e30`'
                . ' (`meta.page` ' . PHP_INT_MAX . ').',
            $this->flat(self::UPDATING, self::UPDATE_SECTION),
            'the update notes'
        );
    }

    /**
     * Regression (review of the fixes): each new app container of 2.13.1
     * had an app.log that root created when the migrations ran, and the
     * console commands of the docs ran as root, so php-fpm's workers lost
     * every error of the web UI and the API without a word. The update
     * notes, the troubleshooting entries, ldap.md and installation.md say
     * who writes the log now, how to see that www-data cannot, and how to
     * hand it back, in the words of the health check and of bin/diagnose.
     */
    public function testTheDocsSayWhoWritesTheLog(): void
    {
        $logFile = (string)(new \ReflectionClassConstant(HealthController::class, 'LOG_FILE'))->getValue();
        $line = rtrim(sprintf(HealthController::DIRS_NOT_WRITABLE, sprintf(HealthController::LOG_FILE_NOT_WRITABLE, $logFile, dirname($logFile))), "\n");
        $diagnose = $this->read('bin/diagnose');
        $this->assertSame(1, preg_match('/^\s*log_file=(\S+)$/m', $diagnose, $file), 'bin/diagnose names the log file.');
        $this->assertSame(1, preg_match('/fail "(Log file not writable for www-data: \$\{log_file\}[^"]*)"/', $diagnose, $failure));
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $link = '(troubleshooting.md#' . self::anchor(self::UNHEALTHY_ENTRY) . ')';

        $this->assertSame($logFile, $file[1], 'bin/diagnose checks the log file of the health check.');
        foreach (
            [
                '**The web UI and the API log their errors again after an update.**',
                'The migrations and the health checks of the app and the queue-worker now run as `www-data`, and the'
                    . ' entrypoints hand `runtime/logs` to `www-data` before the command starts.',
                'The health checks also fail now while `www-data` cannot write `runtime/logs/app.log`, and name the file and the fix',
                $link,
                'They need `gosu`, which the app image has since 2.2.12: an installation that pins `ANSILUME_IMAGE_TAG` to an'
                    . ' older release keeps the compose file of that release.',
                '**Console commands run as `www-data`.**',
                'Change your own scripts and cron entries the same way, such as the nightly `ldap/sync` of'
                    . ' [ldap.md](ldap.md#console-commands): `docker compose exec -T -u www-data app php yii ldap/sync`.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $this->assertMentions(
            '`php yii migrate --interactive=0` as `www-data` on every start',
            $this->flat(self::UPDATING, '## What happens during an update'),
            '## What happens during an update'
        );

        $server = $this->flat(self::TROUBLESHOOTING, self::SERVER_ERROR_ENTRY);
        foreach (
            [
                'When the log has no line for the error, and none since the app container started, `www-data`, the user of'
                    . ' php-fpm\'s workers, may not be able to write `app.log`',
                'docker compose exec app ls -l ' . dirname($logFile) . '/',
                '`root root` for `app.log` is that state, in which every new app container of 2.13.1 or older started',
                '(#' . self::anchor(self::UNHEALTHY_ENTRY) . ')',
            ] as $needle
        ) {
            $this->assertMentions($needle, $server, self::SERVER_ERROR_ENTRY);
        }

        $this->assertStringContainsString($line, $this->section(self::TROUBLESHOOTING, self::UNHEALTHY_ENTRY), 'The entry quotes the health check.');
        $entry = $this->flat(self::TROUBLESHOOTING, self::UNHEALTHY_ENTRY);
        foreach (
            [
                '`' . str_replace('${log_file}', $logFile, $failure[1]) . '`',
                '`dependency failed to start: container ansilume_app is unhealthy`',
                'docker compose exec app chown -R www-data:www-data ' . dirname($logFile),
                '`docker compose exec queue-worker chown -R www-data:www-data ' . dirname($logFile) . '`',
                'A root process that rotates a full log (10 MB) also leaves `app.log.1` to `app.log.5` as root\'s copies',
                '(`docker compose exec -u www-data app php yii …`)',
            ] as $needle
        ) {
            $this->assertMentions($needle, $entry, self::UNHEALTHY_ENTRY);
        }
        $this->assertMentions(
            'The entry runs the sync as `www-data`, the user of the web server, as every console command of this guide does.',
            $this->flat('docs/ldap.md', '## Console commands'),
            'docs/ldap.md'
        );
        $this->assertMentions('Console commands run as `www-data` (`-u www-data`), the user of the web server', $this->flat('docs/installation.md'), 'docs/installation.md');
        foreach (['docs/ldap.md' => '## Console commands', 'docs/installation.md' => null] as $file => $heading) {
            $this->assertMentions('(troubleshooting.md#' . self::anchor(self::UNHEALTHY_ENTRY) . ')', $this->flat($file, $heading), $file);
        }
    }

    /**
     * CLAUDE.md names the guards of the fixes after the review of the move:
     * the integer rule of every model, lists before orAsSubmitted(), the
     * rows of an API list, the guard family of whole numbers, the next runs
     * the migration keeps, who writes runtime/logs and who runs Yii, with
     * the classes and tests it names.
     */
    public function testClaudeMdNamesTheGuardsOfTheFixes(): void
    {
        $claude = $this->flat('CLAUDE.md');
        $numbers = (new \ReflectionMethod(\app\tests\integration\controllers\SubmittedNumbersApiTest::class, 'submittedNumbers'))->invoke(null);
        $this->assertIsArray($numbers);
        $numbers = array_map('strval', array_values($numbers));
        $healthcheck = '["CMD", "gosu", "www-data", "php", "/var/www/yii", "health/check"]';
        foreach (['docker-compose.yml', 'docker-compose.prebuilt.yml', 'deploy/roles/ansilume/templates/docker-compose.yaml'] as $compose) {
            $this->assertStringContainsString("test: {$healthcheck}", $this->read($compose), $compose);
        }
        $this->assertTrue(is_subclass_of(WholeNumberValidator::class, NumberValidator::class), 'WholeNumberValidator is an integer rule.');
        $this->assertTrue(method_exists(SubmittedInteger::class, 'refusesLists') && method_exists(SubmittedInteger::class, 'isEmpty'));
        $this->assertTrue(method_exists(BaseApiController::class, 'modelsOfPage'));
        $this->assertTrue(defined(HealthController::class . '::LOG_FILE_NOT_WRITABLE'));
        require_once dirname(__DIR__, 3) . '/migrations/' . self::MIGRATION . '.php';
        foreach (['owesRun', 'timeOfEarlyRun'] as $method) {
            $this->assertTrue(method_exists(\m000090_000000_recompute_schedule_next_runs_for_time_zone_data::class, $method), $method);
        }
        foreach ([\app\tests\integration\controllers\SubmittedNumbersApiTest::class => ['OPERATIONS', 'RUNNER_API'], \app\tests\integration\controllers\SubmittedNumbersWebFormTest::class => ['FORMS'], \app\tests\unit\YiiExecUserTest::class => ['AS_ROOT']] as $class => $constants) {
            foreach ($constants as $constant) {
                $this->assertTrue((new \ReflectionClass($class))->hasConstant($constant), "{$class}::{$constant}");
            }
        }
        foreach (
            [
                'tests/unit/WholeNumberRulesTest.php', 'tests/integration/controllers/ListsForWholeNumbersTest.php',
                'tests/integration/controllers/api/v1/ApiPaginationTest.php', 'tests/integration/controllers/SubmittedNumbersApiTest.php',
                'tests/integration/controllers/SubmittedNumbersWebFormTest.php', 'tests/integration/migrations/RecomputeScheduleNextRunsForTimeZoneDataMigrationTest.php',
                'tests/unit/YiiExecUserTest.php', 'tests/unit/docker/EntrypointLogOwnershipTest.php', 'tests/unit/docker/HealthcheckUserTest.php',
                'tests/unit/config/TestApplicationLogFileTest.php', 'tests/unit/NamedConsoleRoutesExistTest.php',
            ] as $file
        ) {
            $this->assertFileExists($this->root() . '/' . $file);
        }
        $this->assertStringContainsString('public function testEveryListOfTheApiTakesItsRowsFromModelsOfPage(', $this->read('tests/integration/controllers/api/v1/ApiPaginationTest.php'));
        foreach (
            [
                'Every `\'integer\'`, `\'number\'` and `\'double\'` rule is an `' . WholeNumberValidator::class . '`: the `container`'
                    . ' definitions of `config/web.php`, `config/console.php` and `config/test.php` map `' . NumberValidator::class . '` to it',
                '`WholeNumberValidator` refuses every float that is no whole number, and NAN, and answers every other value as its parent does.',
                'Code that needs the rule itself creates a `WholeNumberValidator`, never a `NumberValidator` (`tests/unit/WholeNumberRulesTest.php`).',
                'Refuse a list or an object before `orAsSubmitted()`',
                'a caller refuses it with `SubmittedInteger::refusesLists()`, or the model\'s integer rule takes'
                    . ' `\'isEmpty\' => [SubmittedInteger::class, \'isEmpty\']` (`ListsForWholeNumbersTest`).',
                'An API list takes the rows of that page from `BaseApiController::modelsOfPage()`, never from its data provider\'s'
                    . ' `getModels()`',
                '(`ApiPaginationTest::testEveryListOfTheApiTakesItsRowsFromModelsOfPage`)',
                '`tests/integration/controllers/SubmittedNumbersApiTest.php` sends `' . implode('`, `', array_slice($numbers, 0, -1))
                    . '` and `' . $numbers[count($numbers) - 1] . '` to every whole-number and ID field of every request body that'
                    . ' `web/openapi.yaml` describes (`OpenApiRequestNumbers`)',
                'with nothing written, audited or logged (`DatabaseWrites`, `SendsJsonNumbers::logged()`)',
                'a new endpoint or field gets its case in `SubmittedNumbersApiTest::OPERATIONS` together with its spec entry; a new'
                    . ' web form field goes into `SubmittedNumbersWebFormTest::FORMS` and a runner API field into'
                    . ' `SubmittedNumbersApiTest::RUNNER_API`, where no test notices one that is missing.',
                '(it stores the moment of the update, `m000090`\'s `owesRun()`), and never moves a next run earlier where the old'
                    . ' data ran it early (`timeOfEarlyRun()`)',
                '- Nothing writes `runtime/logs` as root',
                "`{$healthcheck}` in `docker-compose.yml`, `docker-compose.prebuilt.yml` and the deploy role",
                '`php yii health/check` fails while its user cannot append to `runtime/logs/app.log` (`HealthController::LOG_FILE_NOT_WRITABLE`)',
                '(`EntrypointLogOwnershipTest`, `HealthcheckUserTest`, `TestApplicationLogFileTest`)',
                '`tests/unit/YiiExecUserTest.php` finds every such exec in `bin/`, `docs/`, `README.md`, `deploy/`, `.github/`, the'
                    . ' compose files and `web/openapi.yaml`',
                'its `AS_ROOT` lists those that run as root on purpose, with their reason',
                '`tests/unit/NamedConsoleRoutesExistTest.php` asks the console application of `config/console.php`',
            ] as $needle
        ) {
            $this->assertMentions($needle, $claude, 'CLAUDE.md');
        }
    }

    /**
     * A date of only whitespace is an empty one, a NUL byte inside a date
     * is refused on the analytics reports, as the models read them now, and
     * a connection that MySQL closed for inactivity keeps the driver's text
     * in the log.
     */
    public function testTheFixesQuoteTheDateFiltersAndTheLostConnection(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $query = new AnalyticsQuery();
        $query->date_from = "2026-01\x00-15";
        $query->validate(['date_from']);
        $nul = (string)$query->getFirstError('date_from');
        $query = new AnalyticsQuery();
        $query->date_from = 'x';
        $query->validate(['date_from']);
        $format = (string)$query->getFirstError('date_from');
        $search = new JobSearchForm();
        $search->date_from = " \t";
        $search->validate();

        $this->assertSame('Date From is invalid.', $nul);
        $this->assertSame('', $search->date_from, 'A date of only whitespace is an empty one.');
        foreach (["`{$nul}`", "`{$format}`", '`?date_from=%20`'] as $needle) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $this->assertMentions("`{$nul}`", (string)preg_replace('/\s+/', ' ', $this->read('web/openapi.yaml')), 'web/openapi.yaml');

        $logged = ExceptionLogText::brief(new DbException('SQLSTATE[HY000]: General error: 4031 ' . self::INACTIVITY, ['HY000', 4031, self::INACTIVITY]));
        $this->assertSame('yii\db\Exception (SQLSTATE HY000, driver error 4031): ' . self::INACTIVITY, $logged);
        $this->assertMentions("`{$logged}`", $section, 'the update notes');
        $server = $this->flat(self::TROUBLESHOOTING, self::SERVER_ERROR_ENTRY);
        $this->assertMentions("`{$logged}`", $server, self::SERVER_ERROR_ENTRY);
        $this->assertMentions('`HY000` with `2002`, `2006` or `4031`', $server, self::SERVER_ERROR_ENTRY);
        $this->assertMentions('and so is `4031`', $this->flat(self::TROUBLESHOOTING, self::TIMEOUTS_ENTRY), self::TIMEOUTS_ENTRY);
    }

    /**
     * The troubleshooting entries the section links to exist under the
     * anchors it links to, in the form of the file.
     */
    public function testTheSectionLinksToItsTroubleshootingEntries(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        foreach ([self::CONTAINER_ENTRY, self::RUNNER_ENTRY, self::NUMBER_ENTRY, self::SERVER_ERROR_ENTRY, self::UNHEALTHY_ENTRY] as $entry) {
            $this->assertMentions('(troubleshooting.md#' . self::anchor($entry) . ')', $section, 'the update notes');
            $text = $this->flat(self::TROUBLESHOOTING, $entry);
            foreach (['**Symptom:**', '**Cause:**', '**Fix:**'] as $part) {
                $this->assertMentions($part, $text, $entry);
            }
        }
        $this->assertMentions('`[entrypoint] Check disk space and network connectivity.`, does not apply', $this->flat(self::TROUBLESHOOTING, self::CONTAINER_ENTRY), self::CONTAINER_ENTRY);
        $this->assertStringContainsString('"[entrypoint] Check disk space and network connectivity."', $this->read('docker/php/entrypoint.sh'));
    }

    /**
     * docs/ci.md names the image and the release of .scrutinizer.yml, and
     * CLAUDE.md the rules and the roadmap of the move to PHP 8.5, with the
     * classes it names. Regression (review finding): the rule for whole
     * numbers of requests named query parameters too, whose (int) cast never
     * warns and which requestedPage() must cast as yii\data\Pagination does,
     * and said that RequestIntCastsTest finds such casts, where it sees
     * neither query parameters nor model attributes after load(); the
     * roadmap said that a line with security fixes only gets no time zone
     * data, and that schedules in 14 zones ran an hour off.
     */
    public function testCiAndClaudeMdNameWhatTheMoveChanged(): void
    {
        $build = Yaml::parseFile($this->root() . '/.scrutinizer.yml')['build'] ?? [];
        $this->assertIsArray($build);
        $rules = $this->flat('docs/ci.md', '## Rules for `.scrutinizer.yml`');
        $this->assertMentions('`build.image` is `' . (string)($build['image'] ?? '') . '`', $rules, 'docs/ci.md');
        $this->assertMentions('`' . (string)($build['environment']['php']['version'] ?? '') . '` for `' . $this->requiredConstraint() . '`', $rules, 'docs/ci.md');

        $claude = $this->flat('CLAUDE.md');
        foreach ([CsvText::class, \app\components\Percent::class, \app\components\SubmittedInteger::class, \app\components\SubmittedId::class, \app\components\ApproverConfigIds::class] as $class) {
            $this->assertTrue(class_exists($class), "{$class} exists.");
        }
        foreach (['tests/unit/DeprecatedPhpCallsTest.php', 'tests/unit/ProductionClassesLoadTest.php', 'tests/unit/RequestIntCastsTest.php'] as $file) {
            $this->assertFileExists($this->root() . '/' . $file);
            $this->assertMentions("`{$file}`", $claude, 'CLAUDE.md');
        }
        foreach (
            [
                '`app\components\CsvText` (RFC 4180 quoting: a quote doubled, no escape character; each line ends with a line feed)',
                '`app\components\Percent`',
                '`SubmittedInteger::of()` or `SubmittedId::of()`',
                'Read a whole number or an ID that can arrive as a float with `SubmittedInteger::of()` or `SubmittedId::of()`',
                'a value of a request body (`post()`, `bodyParams`, `getBodyParam()`, `getBodyParams()`, `json_decode()` of'
                    . ' `getRawBody()`; `config/web.php` parses a JSON body for the web forms too), an attribute that `load()`'
                    . ' assigned from one, and a value decoded from stored JSON, such as an approval rule\'s approver config'
                    . ' (`ApproverConfigIds`)',
                '`is_numeric()` is no guard',
                'A query parameter (`get()`, `getQueryParam()`) is text or a list, never a float, and its `(int)` cast never'
                    . ' warns: `requestedPage()` keeps its `(int)` of `?page=`, as `yii\data\Pagination` reads it so'
                    . ' (`ApiPaginationTest::testThePageOfMetaIsThePageOfTheRows`).',
                '`tests/unit/RequestIntCastsTest.php` finds an int conversion of a body read or of `$body` or `$overrides`,'
                    . ' and one that only `is_numeric()`, `is_scalar()` or `is_float()` guards; it cannot see a value that'
                    . ' reaches the conversion as a parameter, a property, a model attribute after `load()`, a value decoded'
                    . ' from an attribute, or one that `ArrayHelper::getValue()` returns, so each such site needs a regression'
                    . ' test of its own.',
                'where PHP uses posix_spawn, since 8.3',
                'Keep to PHP 8.3 syntax although the floor is ' . $this->requiredLine(),
                '9. ~~**PHP 8.5**~~',
                '**The next PHP line, before 2029-12-31**',
                'a line in security support gets time zone data seldom: PHP 8.3 got 2026.1 with 8.3.31 (2026-05-07) and'
                    . ' none since, so PHP 8.3.36 kept 2026.1 and schedules in 11 zones ran an hour off until `m000090`.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $claude, 'CLAUDE.md');
        }
        foreach (
            [
                '**PHP 8.5, before 2027-12-31**' => 'The move to PHP 8.5 is no longer next.',
                '(a JSON or form body, a query parameter, an attribute that `load()` assigned)' => 'A query parameter is text, whose cast never warns.',
                '`tests/unit/RequestIntCastsTest.php` finds such casts.' => 'The scan sees neither query parameters nor attributes after load().',
                'gets no time zone data updates' => 'PHP 8.3.31 brought 2026.1.',
            ] as $wrong => $why
        ) {
            $this->assertStringNotContainsString($wrong, $claude, $why);
        }
        // What the rule says of the scan and of ?page= holds in the code.
        $scan = new \ReflectionClass(RequestIntCastScan::class);
        $this->assertSame(['$body', '$overrides'], $scan->getConstant('BODY_VARIABLES'));
        $this->assertSame(['is_numeric', 'is_scalar', 'is_float', 'is_double'], $scan->getConstant('NO_INT_GUARDS'));
        $this->assertStringContainsString('max(1, (int)$page)', $this->read('controllers/api/v1/BaseApiController.php'));
        $this->assertStringContainsString('public function testThePageOfMetaIsThePageOfTheRows(', $this->read('tests/integration/controllers/api/v1/ApiPaginationTest.php'));
    }

    /**
     * Regression (review of the fixes): the save and the lock of a job
     * template read an entry of credential_ids with filter_var(…,
     * FILTER_VALIDATE_INT), so 2.9999999999999996 named credential #3 and
     * true #1, where the section, the troubleshooting entry and the spec
     * said that such values are refused. The section says how an entry is
     * read now, by the save and by the lock, and what it named; the entry
     * quotes the refusal; CLAUDE.md names the rule and its guard. The values
     * are read as the code reads them, and as 2.13.1's filter_var() did.
     */
    public function testTheDocsSayHowAnEntryOfCredentialIdsIsRead(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $nearThree = (float)self::NEAR_THREE;
        $refusal = JobTemplateCredentialService::INVALID_CREDENTIAL_IDS;
        $before = static fn (mixed $value): int|false => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        foreach ([$nearThree, true, ' 5', '5 '] as $value) {
            $this->assertNull(SubmittedId::of($value), var_export($value, true) . ' is no ID.');
        }
        $this->assertSame([5, false], [SubmittedId::of('05'), $before('05')], '"05" is 5, which 2.13.1 refused.');
        $this->assertSame([5, 5], [$before(' 5'), $before('5 ')], '2.13.1 trimmed the spaces.');

        $this->assertMentions(
            'An entry of `credential_ids` is read as an ID too: `' . self::NEAR_THREE . '` and `true` answer `422` `' . $refusal
                . '`, where they named credentials #' . $before($nearThree) . ' and #' . $before(true) . ', `" 5"` and `"5 "` too,'
                . ' where they were credential 5, and `"05"` is credential 5, where it was refused.',
            $section,
            'the update notes'
        );
        $this->assertMentions(
            'The lock of a job template with an approval rule reads an entry the same way: for a user without'
                . ' `approval-rule.update` an entry that is no ID is a change (`403`), and `"05"` for its credential 5 is none.',
            $section,
            'the update notes'
        );
        $this->assertMentions("`{$refusal}`", $this->flat(self::TROUBLESHOOTING, self::NUMBER_ENTRY), self::NUMBER_ENTRY);

        $this->assertFileExists($this->root() . '/tests/unit/IntegerFiltersTest.php');
        $this->assertTrue((new \ReflectionClass(\app\tests\unit\IntegerFiltersTest::class))->hasConstant('ALLOWED'));
        $this->assertMentions(
            'Read an ID with `SubmittedId::of()`, never with `filter_var(…, FILTER_VALIDATE_INT)`, which reads a float by the'
                . ' same text and takes `true` for 1 (`tests/unit/IntegerFiltersTest.php` finds every use of'
                . ' `FILTER_VALIDATE_INT` and `FILTER_SANITIZE_NUMBER_INT`; its `ALLOWED` names those that never read a float or'
                . ' a boolean, with the reason).',
            $this->flat('CLAUDE.md'),
            'CLAUDE.md'
        );
    }

    /**
     * The message the integer rule gives $attribute of $model for a value
     * that is no whole number, with the model's label. A model of no table:
     * the label is all it gives.
     */
    private static function integerRefusal(Model $model, string $attribute): string
    {
        return self::refusal($model, $attribute, 5.5, 'integer');
    }

    /**
     * The message the required rule gives $attribute of $model for an empty
     * value, with the model's label.
     */
    private static function blankRefusal(Model $model, string $attribute): string
    {
        return self::refusal($model, $attribute, null, 'required');
    }

    private static function refusal(Model $model, string $attribute, mixed $value, string $rule): string
    {
        $values = new DynamicModel([$attribute => $value]);
        $values->setAttributeLabels([$attribute => $model->getAttributeLabel($attribute)]);
        $values->addRule($attribute, $rule);
        $values->validate();

        return (string)$values->getFirstError($attribute);
    }

    /**
     * What the running PHP reports for fputcsv() without its escape
     * argument, '' when it reports nothing.
     */
    private static function deprecationOfFputcsvWithoutEscape(): string
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $reported = '';
        set_error_handler(static function (int $level, string $message) use (&$reported): bool {
            $reported = $message;

            return true;
        });
        try {
            fputcsv($stream, ['x']);
        } finally {
            restore_error_handler();
            fclose($stream);
        }

        return $reported;
    }

    /**
     * A pattern that matches what sprintf() makes of $format: its text as it
     * is, any word for %s and any whole number for %d.
     */
    private static function printfPattern(string $format): string
    {
        $pattern = '';
        foreach (preg_split('/(%[sd])/', $format, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            $pattern .= match ($part) {
                '%s' => '\S+',
                '%d' => '-?\d+',
                default => preg_quote($part, '/'),
            };
        }

        return '/^' . $pattern . '$/';
    }

    /**
     * The function that PHP 8.3 cannot call first when RunnerHttpClient
     * sends a request, as PHP names it: the first call of one of
     * PHP84_FUNCTIONS, which comes before file_get_contents() sends the
     * request, in the namespace of the class unless the call names the
     * global function.
     */
    private function firstPhp84FunctionOfTheRunnerClient(): string
    {
        $calls = self::functionCallsIn($this->read('components/RunnerHttpClient.php'));
        $names = array_column($calls, 'name');
        $php84 = array_values(array_intersect($names, self::PHP84_FUNCTIONS));
        $request = array_search('file_get_contents', $names, true);

        $this->assertNotSame([], $php84, 'RunnerHttpClient calls a function of PHP 8.4.');
        $this->assertIsInt($request, 'RunnerHttpClient sends its requests with file_get_contents().');
        $first = (int)array_search($php84[0], $names, true);
        $this->assertLessThan($request, $first, 'It calls the function of PHP 8.4 before it sends a request.');

        return $calls[$first]['qualified']
            ? $php84[0]
            : (new \ReflectionClass(RunnerHttpClient::class))->getNamespaceName() . '\\' . $php84[0];
    }

    /**
     * The function calls of $code in their order: a name and an opening
     * parenthesis, not after ->, ?->, ::, function or new; whether the call
     * names the global function (\name).
     *
     * @return list<array{name: string, qualified: bool}>
     */
    private static function functionCallsIn(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $calls = [];
        foreach ($tokens as $i => $token) {
            $before = $tokens[$i - 1] ?? null;
            $member = is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || ($tokens[$i + 1] ?? null) !== '(' || $member) {
                continue;
            }
            $calls[] = ['name' => strtolower(ltrim($token[1], '\\')), 'qualified' => $token[0] === T_NAME_FULLY_QUALIFIED];
        }

        return $calls;
    }

    /**
     * web/openapi.yaml, parsed.
     *
     * @return array<mixed>
     */
    private function spec(): array
    {
        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);

        return $spec;
    }

    /** The PHP line of composer.json's "php" constraint, such as 8.5 of ">=8.5". */
    private function requiredLine(): string
    {
        $this->assertSame(1, preg_match('/^(?:>=|\^|~)?(\d+\.\d+)(?:\.0)?$/', $this->requiredConstraint(), $match), 'composer.json requires one PHP line');

        return $match[1];
    }

    /** composer.json's "php" constraint, as Composer quotes it. */
    private function requiredConstraint(): string
    {
        $composer = json_decode($this->read('composer.json'), true);
        $this->assertIsArray($composer);

        return (string)($composer['require']['php'] ?? '');
    }

    /**
     * The anchor GitHub gives a heading.
     */
    private static function anchor(string $heading): string
    {
        return str_replace(' ', '-', (string)preg_replace('/[^a-z0-9 -]/', '', strtolower(ltrim($heading, '# '))));
    }
}
