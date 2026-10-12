<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use PHPUnit\Framework\TestCase;

/**
 * What the docs tell a git checkout to run after `git pull` recreates every
 * container. docker-compose.yml mounts two files of the survey password
 * release on their own: docker/php/php.ini, which sets
 * zend.exception_ignore_args = On now, into the PHP containers, and
 * docker/nginx/default.conf, which masks tokens in the access log now, into
 * nginx. Such a mount keeps showing the file the container started with
 * after `git pull` replaces it, and `docker compose up -d --build` alone
 * recreates no container whose image stays the same. A restart brings the
 * new files but keeps each container's own files, which can hold secrets
 * from before the update: the Docker log of nginx, PHP's error log in the
 * PHP containers, and what runners of 2.8.1 left in their /tmp.
 *
 * Regressions, failing on the docs from before each fix:
 * - Fix round 4 (R3V2-1): updating.md offered
 *   `docker compose restart app queue-worker nginx runner-1 runner-2` as
 *   doing the same as `docker compose up -d --build --force-recreate`. The
 *   schedule-runner, whose PHP processes open the sealed answers of
 *   schedules and encrypt the answers of finished jobs every minute, kept
 *   reading the old php.ini. updating.md and troubleshooting.md also told a
 *   git checkout to restart only `app` after fixing APP_SECRET_KEY, and
 *   troubleshooting.md when the migrations had not run.
 * - Fix round 5 (R4V2-2): the restart that replaced it, with the
 *   schedule-runner, still claimed to do the same, while it kept PHP's error
 *   log and the runners' /tmp. The manual update of a git checkout ran
 *   `docker compose up -d --build` only, which recreates nothing, so the
 *   migrations did not run and the long-lived processes kept the old code
 *   and php.ini.
 */
class GitCheckoutRestartDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    private const UPDATE_SECTION = '## After updating from 2.8.1 or older';

    /** What a git checkout runs right after `git pull`; it recreates every container. */
    private const RECREATE = 'docker compose up -d --build --force-recreate';

    public function testTheUpdateOfAGitCheckoutRecreatesEveryContainer(): void
    {
        $bullets = array_values(array_filter(
            $this->units('docs/updating.md', self::UPDATE_SECTION),
            static fn (string $unit): bool => str_starts_with($unit, '- Git checkout: right after `git pull`')
        ));
        $this->assertCount(1, $bullets, 'updating.md tells a git checkout what to run right after `git pull`.');
        $bullet = $bullets[0];
        $where = 'updating.md, on deploying a git checkout,';
        $this->assertMentions('run `' . self::RECREATE . '`.', $bullet, $where);
        $this->assertMentions('the single file `docker/php/php.ini`, which now sets `zend.exception_ignore_args = On`', $bullet, $where);
        $this->assertMentions('such a mount keeps showing the old file after `git pull` replaces it', $bullet, $where);
        $this->assertMentions('`--force-recreate` recreates all of them.', $bullet, $where);
        $this->assertMentions("A restart is not enough: it keeps each container's own files, which recreating drops:", $bullet, $where);
        $this->assertMentions("the Docker log of nginx, PHP's error log `" . self::phpErrorLog() . '` in the PHP containers', $bullet, $where);
        $this->assertMentions('what runners of 2.8.1 left in their `/tmp`', $bullet, $where);
        $this->assertSame([], self::restartCommands($bullet), 'The bullet offers no restart in place of the recreate.');

        $this->assertMentions(
            self::RECREATE,
            $this->flat('docs/updating.md', '## Manual update — source (git)'),
            'updating.md, on the manual update of a git checkout,'
        );
    }

    /**
     * No place that tells a git checkout how to apply this release restarts
     * containers: the places that told it to restart give the recreate, and
     * the purge of old logs counts on it.
     */
    public function testEveryGitCheckoutRestartOfThisReleaseIsARecreate(): void
    {
        $updating = $this->flat('docs/updating.md', self::UPDATE_SECTION);
        $keyFixed = $this->flat('docs/troubleshooting.md', '## App container does not start after the update: APP_SECRET_KEY');
        $notMigrated = $this->flat('docs/troubleshooting.md', '## Launch fails: "The database update has not finished; run the migrations."');
        $places = [
            'updating.md, after updating from 2.8.1,' => $updating,
            'troubleshooting.md, on APP_SECRET_KEY,' => $keyFixed,
            'troubleshooting.md, on migrations that have not run,' => $notMigrated,
        ];
        foreach ($places as $where => $text) {
            $this->assertSame([], self::restartCommands($text), "{$where} restarts containers, which keeps their own files.");
        }

        $keySentences = array_values(array_filter(
            $this->sentences('docs/updating.md', self::UPDATE_SECTION),
            static fn (string $sentence): bool => str_starts_with($sentence, 'Then start the containers again:')
        ));
        $this->assertCount(1, $keySentences, 'updating.md says how to start the containers once APP_SECRET_KEY is fixed.');
        $this->assertMentions('(git checkout: `' . self::RECREATE . '`', $keySentences[0], 'updating.md, once APP_SECRET_KEY is fixed,');
        $this->assertMentions('(git checkout: `' . self::RECREATE . '`)', $keyFixed, 'troubleshooting.md, on APP_SECRET_KEY,');
        $this->assertMentions('run `' . self::RECREATE . '`', $notMigrated, 'troubleshooting.md, on migrations that have not run,');
        $this->assertMentions(
            'A git checkout gets it from `docker/php/php.ini` once its containers are recreated',
            $updating,
            'updating.md, on zend.exception_ignore_args,'
        );
        $this->assertMentions(
            "Recreating the containers (see **Database and deployment**) drops the Docker log of nginx and PHP's error log `"
            . self::phpErrorLog() . '` in the PHP containers',
            $updating,
            'updating.md, on purging old logs,'
        );
    }

    /**
     * Where PHP writes its errors in the PHP containers: the error_log of
     * docker/php/php.ini, the repository's, also when root() points at
     * another copy of the docs.
     */
    private static function phpErrorLog(): string
    {
        $ini = parse_ini_file(dirname(__DIR__, 3) . '/docker/php/php.ini');
        self::assertIsArray($ini);
        $errorLog = $ini['error_log'] ?? null;
        self::assertIsString($errorLog, 'docker/php/php.ini sets error_log.');

        return $errorLog;
    }

    /**
     * The services that each `docker compose restart ...` command in $text
     * names.
     *
     * @return list<list<string>>
     */
    private static function restartCommands(string $text): array
    {
        preg_match_all('/`docker compose restart ([^`]*)`/', $text, $matches);
        $commands = [];
        foreach ($matches[1] as $names) {
            $commands[] = preg_split('/\s+/', trim($names), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return $commands;
    }
}
