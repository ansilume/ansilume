<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\controllers\api\v1\ProjectsController;
use app\controllers\ProjectController;
use app\models\LoginForm;
use app\models\Project;
use app\services\MaintenanceService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract: the update notes for Redis 8.10 (docs/updating.md), the
 * troubleshooting entry for a Redis that cannot load its data, and the
 * backup notes of deployment.md say what the move does to the data of Redis
 * and how to go back, with the commands and the messages of the hands-on
 * test of the move: Redis 8.10 starts empty on the new volume redis_data,
 * keeps its data across new containers, and Redis 7.2 exits on what 8.10
 * wrote, whether the compose file of 2.14.0 hands it the volume or only the
 * image goes back, until the container and the volume are removed.
 *
 * The images and the messages of Redis, Docker Compose and git are history
 * and fixed text here; what Ansilume does with a lost sync (the stale-sync
 * sweep, and where Sync queues it again), the sign-in by the Remember me
 * cookie, how the quickstart runs an installation it made from source,
 * where the compose files publish Redis, and the names the commands use
 * (the redis service and container, the volume, the deploy role's project,
 * the services only the development stack has) are read from the code.
 * RedisImageTest checks the images and the volume of the compose files and
 * the deploy role, and that every line has update notes with a way back.
 */
class Redis810UpdateDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    public const UPDATE_SECTION = '## After updating from 2.14.0 or older: Redis 8.10, outbound HTTP, workflow notifications, Mattermost, lint in the background, recovered failures';

    private const SECTION_ANCHOR = 'after-updating-from-2140-or-older-redis-810-outbound-http-workflow-notifications-mattermost-lint-in-the-background-recovered-failures';

    private const UPDATING = 'docs/updating.md';

    private const TROUBLESHOOTING = 'docs/troubleshooting.md';

    private const MANUAL_SOURCE_UPDATE = '## Manual update — source (git)';

    private const RDB_ENTRY = '## Redis does not start after going back: "Can\'t handle RDB format version 15"';

    private const RDB_ANCHOR = 'redis-does-not-start-after-going-back-cant-handle-rdb-format-version-15';

    /** The image of this release and the one of 2.14.0 it replaces, as the section names them. */
    private const IMAGES = ['redis:8.10-alpine', 'redis:7.2-alpine'];

    /** What Redis 7.2 logs before it exits on the data of Redis 8.10 (RDB format 15). */
    private const REFUSAL = "```\n# Can't handle RDB format version 15\n# Fatal error loading the DB, check server logs. Exiting.\n```";

    /** What `docker compose up -d` ends with while the redis container cannot start. */
    private const DEPENDENCY_FAILED = '`dependency failed to start: container ansilume_redis is unhealthy`';

    /**
     * The way back, as run against the compose file of 2.14.0 and against
     * the image alone set back: Redis 7.2 then starts empty.
     */
    private const WAY_BACK = "```bash\ndocker compose rm -sf redis\ndocker volume rm ansilume_redis_data\ndocker compose up -d\n```";

    /**
     * The same way back in an installation the quickstart made from source,
     * whose .env names no compose file: a plain `docker compose` there reads
     * docker-compose.yml, the development stack.
     */
    private const WAY_BACK_QUICKSTART_SOURCE = "```bash\ndocker compose -f docker-compose.prebuilt.yml rm -sf redis\ndocker volume rm ansilume_redis_data\ndocker compose -f docker-compose.prebuilt.yml up -d\n```";

    /** Why such an installation needs the -f form. */
    private const PREBUILT_FILE_NEEDED = 'In an installation the quickstart made from source, which runs `docker-compose.prebuilt.yml` and whose `.env` names no compose file, add `-f docker-compose.prebuilt.yml` to each `docker compose` command: there a plain `docker compose up -d` builds and starts the development stack of `docker-compose.yml` in place of your containers, and its runners mount the install directory, `.env` included.';

    /** How whoever ran the plain command there gets the containers of the prebuilt file back, as run in a throwaway project. */
    private const REMOVE_ORPHANS = '`docker compose -f docker-compose.prebuilt.yml up -d --remove-orphans` creates the containers of `docker-compose.prebuilt.yml` anew and removes those that only the development stack has: %s.';

    /**
     * The services that only docker-compose.yml has and that a plain
     * `docker compose up -d` starts with the profile of the quickstart's
     * .env, as the docs name them.
     */
    private const DEVELOPMENT_ONLY = ['adminer' => 'Adminer', 'mailhog' => 'mailhog', 'swagger' => 'swagger-ui', 'runner-2' => 'the second runner'];

    /** The profile the .env of the quickstart activates for the bundled database. */
    private const QUICKSTART_PROFILE = 'internal-db';

    /**
     * How a source installation started from docker-compose.yml keeps Redis
     * 8.10 on the code of 2.14.0, as run in a clone: the file checked out
     * from main is staged, so `git checkout main` works again later.
     */
    private const KEEP_REDIS_810_FROM_SOURCE = "```bash\ngit checkout v2.14.0\ngit checkout main -- docker-compose.yml\ndocker compose up -d --build --force-recreate\n```";

    /** What git answers when docker-compose.yml was edited by hand on the tag, as run in a clone. */
    private const CHECKOUT_REFUSED = '`Your local changes to the following files would be overwritten by checkout`';

    /** How many seconds the example of the sweep's error says the sync waited. */
    private const EXAMPLE_SECONDS = 930;

    /**
     * The section is the newest, the one of the update to PHP 8.5 follows,
     * and the rollback and the troubleshooting entry link to it under the
     * anchor GitHub gives its heading. A failed build of the manual source
     * update goes back to the release it updated from, 2.14.0 first, which
     * `git describe` names after a `git pull` (checked in a clone).
     * Review finding: it named only the release before this one, and an
     * update from 2.13.1 that went there served 2.14.0's code on the
     * containers of 2.13.1.
     */
    public function testTheSectionIsTheNewest(): void
    {
        $this->assertUpdateSectionBetween(null, self::UPDATE_SECTION, Php85UpdateDocsContractTest::UPDATE_SECTION);
        $this->assertSame(self::SECTION_ANCHOR, self::anchor(self::UPDATE_SECTION), 'The anchor GitHub gives the heading.');
        $this->assertSame(self::RDB_ANCHOR, self::anchor(self::RDB_ENTRY), 'The anchor GitHub gives the entry.');

        $this->assertMentions('(#' . self::SECTION_ANCHOR . ')', $this->flat(self::UPDATING, '## Rollback'), 'the rollback');
        $this->assertMentions('(updating.md#' . self::SECTION_ANCHOR . ')', $this->flat(self::TROUBLESHOOTING, self::RDB_ENTRY), self::RDB_ENTRY);
        $this->assertMentions('(troubleshooting.md#' . self::RDB_ANCHOR . ')', $this->flat(self::UPDATING, self::UPDATE_SECTION), 'the update notes');

        $update = $this->flat(self::UPDATING, self::MANUAL_SOURCE_UPDATE);
        $this->assertMentions('go back to the release you updated from until a build gets through (`git checkout v2.14.0` from 2.14.0,', $update, 'the manual source update');
        $this->assertMentions('right after `git pull`, `git describe --tags ORIG_HEAD` names it, and a commit after a release as `v2.13.1-1-gc017f1c`, which `git checkout` takes too', $update, 'the manual source update');
    }

    /**
     * The images, why the line moves, and the volume, in the names the
     * compose files and the deploy role use.
     */
    public function testTheSectionNamesTheImagesAndTheVolume(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        foreach (self::IMAGES as $image) {
            $this->assertMentions("`{$image}`", $section, 'the update notes');
        }
        foreach (
            [
                'its image stays on Alpine 3.21, whose support ends on 2026-11-01',
                'on Alpine 3.23, which gets fixes until 2027-11-01',
                'The images of Redis 8.6 and later declare none',
                'mounts the named volume `redis_data` at `/data` now, in all three',
                '`ansilume_redis_data` for the project `ansilume`',
                '`ansilume_redis_image`',
                '`ansilume_redis_local: false` runs none',
                '**An external Redis** (`REDIS_HOST` names a Redis of your own) is not changed by the update',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        foreach (['docker-compose.yml', 'docker-compose.prebuilt.yml'] as $file) {
            $redis = $this->compose($file)['services']['redis'] ?? [];
            $this->assertSame('ansilume_redis', $redis['container_name'] ?? null, "the redis container of {$file}, which the commands name");
            $this->assertSame(['redis_data:/data'], $redis['volumes'] ?? null, "the volume of {$file}");
        }
        $defaults = Yaml::parse($this->read('deploy/roles/ansilume/defaults/main.yaml'));
        $this->assertIsArray($defaults);
        $this->assertSame('ansilume', $defaults['ansilume_compose_project'] ?? null, 'the deploy role names its volume ansilume_redis_data');
    }

    /**
     * What the empty Redis means, as the code does it: the session goes,
     * the Remember me cookie signs in again, and a lost sync stays Syncing
     * until the stale-sync sweep marks it Error.
     */
    public function testTheSectionSaysWhatTheEmptyRedisMeans(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $threshold = (new MaintenanceService())->staleSyncThresholdSeconds;
        $sweep = MaintenanceService::STALE_SYNC_RECOVERED;

        $this->assertSame(900, $threshold, 'the section says 15 minutes');
        $this->assertGreaterThan($threshold, self::EXAMPLE_SECONDS, 'the example has waited longer than the threshold');
        $this->assertStringContainsString("'enableAutoLogin' => true", $this->read('config/web.php'), 'the Remember me cookie signs in again');
        foreach (
            [
                'Everyone is signed out. A user who signed in with **' . (new LoginForm())->getAttributeLabel('rememberMe') . '** is signed in again by its cookie',
                'The project stays **' . Project::statusLabel(Project::STATUS_SYNCING) . '** until, once 15 minutes have passed since the sync was queued,',
                'marks it **' . Project::statusLabel(Project::STATUS_ERROR) . '**, with the sync error `' . sprintf($sweep, self::EXAMPLE_SECONDS, $threshold) . '`',
                'The database, with the jobs, their logs, the schedules and the workflow runs, is not touched.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
    }

    /**
     * An empty Redis signs everyone out, and a Remember me cookie signs its
     * user in again, in the update notes, their way back, the troubleshooting
     * entry and the backup notes alike. Review finding: the troubleshooting
     * entry, the backup notes and the way back said that everyone signs in
     * again.
     */
    public function testTheDocsSayThatTheRememberMeCookieSignsInAgain(): void
    {
        $cookie = 'who signed in with **' . (new LoginForm())->getAttributeLabel('rememberMe') . '** is signed in again by its cookie';
        $places = [
            'the update notes' => $this->flat(self::UPDATING, self::UPDATE_SECTION),
            self::RDB_ENTRY => $this->flat(self::TROUBLESHOOTING, self::RDB_ENTRY),
            'the backup notes of deployment.md' => $this->flat('docs/deployment.md', '## Backup'),
        ];

        foreach ($places as $where => $text) {
            $this->assertMentions($cookie, $text, $where);
            $this->assertFalse(str_contains($text, 'everyone signs in again'), "{$where}: \"everyone signs in again\" leaves out the Remember me cookie");
        }
        $this->assertMentions('Redis 7.2 starts empty, and everyone is signed out once more, as at the update', $places['the update notes'], 'the way back of the update notes');
        $this->assertMentions('everyone is signed out (a user ' . $cookie . ', everyone else signs in again)', $places[self::RDB_ENTRY], self::RDB_ENTRY);
        $this->assertMentions('Without them everyone is signed out: a user ' . $cookie . ', everyone else signs in again.', $places['the backup notes of deployment.md'], 'the backup notes of deployment.md');
    }

    /**
     * Where a lost sync is started again, as the code offers it: the
     * project's page and the API queue a sync whatever the project's status,
     * while the Projects list disables its Sync for a project that shows
     * Syncing. Review finding: the notes said that Sync works at once while
     * the project shows Syncing, which the Projects list does not offer.
     */
    public function testTheSectionSaysWhereALostSyncIsStartedAgain(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $syncing = Project::statusLabel(Project::STATUS_SYNCING);
        $error = Project::statusLabel(Project::STATUS_ERROR);

        foreach (
            [
                "Sync it again on the project's page or with `POST /api/v1/projects/{id}/sync`: both queue a sync at once, also while the project shows **{$syncing}**.",
                "The **Sync** of the Projects list stays disabled while the project shows **{$syncing}**.",
                "Sync the projects that show **{$syncing}**, on their pages or with `POST /api/v1/projects/{id}/sync`; once they show **{$error}**",
                'queued, the **Sync** of the Projects list works too.',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }

        $this->assertMatchesRegularExpression('/Project::STATUS_SYNCING \? \'disabled\' : \'\'[^\n]*>Sync<\/button>/', $this->read('views/project/index.php'), 'the Projects list disables Sync while the project shows Syncing');
        $this->assertSame(1, preg_match_all('#<button[^>]*>Sync</button>#', $this->read('views/project/view.php'), $buttons), "the project's page has one Sync button");
        $this->assertStringNotContainsString('disabled', $buttons[0][0], "the Sync of the project's page is never disabled");
        foreach ([ProjectController::class, ProjectsController::class] as $controller) {
            $action = $this->sourceOf($controller, 'actionSync');
            $this->assertStringContainsString('->queueSync($model);', $action, "{$controller}::actionSync queues the sync");
            $this->assertStringNotContainsString('->status', $action, "{$controller}::actionSync queues it whatever the status");
        }
        $this->assertStringContainsString("['pattern' => 'api/v1/projects/<id:\\d+>/sync', 'route' => 'api/v1/projects/sync', 'verb' => 'POST']", $this->read('config/web.php'), 'the API route the notes name');
        $this->assertMatchesRegularExpression('#^  /api/v1/projects/\{id\}/sync:\n    post:#m', $this->read('web/openapi.yaml'), 'the spec describes the route');
    }

    /**
     * The old anonymous volume is found by the container it belonged to and
     * removed by its name; `docker volume prune` is named only as what not
     * to do, as it removes the unused volumes of every project on the host.
     */
    public function testTheOldVolumeIsRemovedByItsNameOnly(): void
    {
        $raw = $this->section(self::UPDATING, self::UPDATE_SECTION);

        $this->assertStringContainsString("docker inspect --format '{{range .Mounts}}{{.Name}}{{end}}' ansilume_redis", $raw);
        $this->assertStringContainsString('`docker volume rm <name>`', $raw);
        $prune = array_values(array_filter($this->units(self::UPDATING), static fn (string $unit): bool => str_contains($unit, 'docker volume prune')));
        $this->assertNotEmpty($prune, 'updating.md warns against docker volume prune');
        foreach ($prune as $unit) {
            $this->assertStringContainsString('Do not use `docker volume prune`', $unit, 'docker volume prune is named only as what not to do');
        }
    }

    /**
     * Going back keeps Redis 8.10 with the images pinned; a compose file of
     * 2.14.0, or the image alone set back, makes Redis 7.2 exit until the
     * container and the volume are removed, the way the hands-on test went
     * back, in this order. The troubleshooting entry gives the same.
     */
    public function testTheWayBackIsTheOneThatWasRun(): void
    {
        $raw = $this->section(self::UPDATING, self::UPDATE_SECTION);
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $entry = $this->section(self::TROUBLESHOOTING, self::RDB_ENTRY);

        foreach (
            [
                'pin the images of 2.14.0 (`ANSILUME_IMAGE_TAG=2.14.0`, see [Rollback](#rollback)): 2.14.0 runs on Redis 8.10 too',
                '`ansilume_version: "v2.14.0"`',
                '`git checkout v2.14.0`',
                'Docker Compose gives it the volume `redis_data` of the container before, as its image declares `/data` a volume',
                'The same happens when only the image goes back to `redis:7.2-alpine`',
                'Removing only the container is not enough: the next update would load `redis_data` again',
                '`docker compose up -d --renew-anon-volumes` does not help either',
                self::DEPENDENCY_FAILED,
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        foreach (['the update notes' => $raw, self::RDB_ENTRY => $entry] as $where => $text) {
            $this->assertStringContainsString(self::REFUSAL, $text, "{$where} quotes the log of Redis 7.2");
            $this->assertStringContainsString(self::WAY_BACK, $text, "{$where} gives the way back");
        }
        $this->assertMentions(self::DEPENDENCY_FAILED, $this->flat(self::TROUBLESHOOTING, self::RDB_ENTRY), self::RDB_ENTRY);
        $this->assertMentions('A Redis older than 8.10, such as the `redis:7.2-alpine` of 2.14.0 and older', $this->flat(self::TROUBLESHOOTING, self::RDB_ENTRY), self::RDB_ENTRY);
    }

    /**
     * An installation the quickstart made from source is a git checkout that
     * runs docker-compose.prebuilt.yml through -f, and the .env the
     * quickstart writes names no compose file: there the way back and the
     * fix of the troubleshooting entry run every `docker compose` command
     * with `-f docker-compose.prebuilt.yml`, as a plain `docker compose up -d`
     * starts the development stack of docker-compose.yml (checked in a
     * throwaway project), and going back keeps the checkout and pins the
     * images. Review finding: both gave only the plain commands, and the
     * section left out the warning of the earlier ones.
     */
    public function testAnInstallationTheQuickstartMadeFromSourceGoesBackWithThePrebuiltFile(): void
    {
        $removeOrphans = sprintf(self::REMOVE_ORPHANS, self::namedInOrder(self::DEVELOPMENT_ONLY));
        foreach (
            [
                'the update notes' => [$this->section(self::UPDATING, self::UPDATE_SECTION), $this->flat(self::UPDATING, self::UPDATE_SECTION)],
                self::RDB_ENTRY => [$this->section(self::TROUBLESHOOTING, self::RDB_ENTRY), $this->flat(self::TROUBLESHOOTING, self::RDB_ENTRY)],
            ] as $where => [$raw, $flat]
        ) {
            $this->assertMentions(self::WAY_BACK_QUICKSTART_SOURCE, $raw, "{$where}, on an installation the quickstart made from source,");
            $this->assertMentions(self::PREBUILT_FILE_NEEDED, $flat, $where);
            $this->assertMentions('If you ran the plain `docker compose up -d` there already, ' . $removeOrphans, $flat, $where);
        }
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $this->assertMentions('This holds for an installation the quickstart made from source too: it runs `docker-compose.prebuilt.yml`. Do not run the steps of the manual source update there, as they start the development stack of `docker-compose.yml`.', $section, 'the update notes');
        $this->assertMentions('An installation the quickstart made from source keeps its checkout of this release, which holds that compose file, sets the pin in `.env`, and starts with `docker compose -f docker-compose.prebuilt.yml -f docker-compose.override.yml up -d`, after `docker compose -f docker-compose.prebuilt.yml -f docker-compose.override.yml rm -sf notification-worker`: a command that names its compose files with `-f` reads no other.', $section, 'the update notes');
        $this->assertMentions('An installation the quickstart made from source restarts with `docker compose -f docker-compose.prebuilt.yml up -d`', $this->flat(self::UPDATING, '## Rollback'), 'the rollback');

        $quickstart = $this->read('bin/quickstart');
        $this->assertStringContainsString('COMPOSE_FILE="docker-compose.prebuilt.yml"', $this->between($quickstart, 'git clone https://github.com/ansilume/ansilume.git .', 'cat > .env <<EOF'), 'the quickstart runs the prebuilt file from its clone');
        $this->assertStringContainsString('COMPOSE_ARGS="-f ${COMPOSE_FILE}"', $quickstart, 'through -f');
        $this->assertStringNotContainsString('COMPOSE_FILE', $this->between($quickstart, 'cat > .env <<EOF', "\nEOF\n"), 'the .env the quickstart writes names no compose file');
        $this->assertStringNotContainsString('COMPOSE_FILE', $this->between($quickstart, 'merge_env() {', "\n}\n"), 'nor does its update add one');
        $this->assertSame(array_keys(self::DEVELOPMENT_ONLY), $this->servicesOnlyTheDevelopmentStackStarts(), 'the services the notes say --remove-orphans removes');
    }

    /**
     * A source installation started from docker-compose.yml has no images to
     * pin: it keeps Redis 8.10 on the code of 2.14.0 with the compose file
     * of this release, checked out from main, which `git checkout main`
     * takes back without a conflict, while an edit by hand makes it refuse
     * (both run in a clone). Review finding: going back named only the pin
     * of the images, and `git checkout v2.14.0` brought Redis 7.2 back.
     */
    public function testASourceInstallationKeepsRedis810WithTheComposeFileOfThisRelease(): void
    {
        $this->assertMentions(self::KEEP_REDIS_810_FROM_SOURCE, $this->section(self::UPDATING, self::UPDATE_SECTION), 'the update notes');
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        foreach (
            [
                'A source installation started from `docker-compose.yml` builds its images from the checkout and has none to pin, and `git checkout v2.14.0` brings back the compose file of 2.14.0, with Redis 7.2.',
                '`git checkout main` before the next update then works without a conflict.',
                'Do not edit `docker-compose.yml` by hand on the tag instead: `git checkout main` then refuses with ' . self::CHECKOUT_REFUSED . ' until you discard the edit (`git checkout -- docker-compose.yml`).',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $this->assertMentions('(a source installation started from `docker-compose.yml`, which builds its images: `git checkout v2.14.0`, then `git checkout main -- docker-compose.yml`)', $this->flat(self::UPDATING, '## Rollback'), 'the rollback');
        $this->assertMentions('a source installation started from `docker-compose.yml`, which builds its images, keeps that compose file after the `git checkout` of the older release with `git checkout main -- docker-compose.yml`', $this->flat(self::TROUBLESHOOTING, self::RDB_ENTRY), self::RDB_ENTRY);
        foreach (['app', 'queue-worker', 'schedule-runner', 'runner-1', 'runner-2'] as $service) {
            $this->assertArrayHasKey('build', $this->compose('docker-compose.yml')['services'][$service] ?? [], "docker-compose.yml builds {$service}: no image to pin");
        }
        $this->assertFalse(str_contains($this->read('docker-compose.yml'), 'ANSILUME_IMAGE_TAG'), 'docker-compose.yml has no image tag to pin');
    }

    /**
     * The modules the Redis 8 images load, who reaches Redis in which
     * stack, the new warning without a password, and the license, which
     * some operators must review. Review finding: the notes said that Redis
     * stays on the network of the server's containers, while
     * docker-compose.yml publishes it on the host.
     */
    public function testTheSectionNamesTheModulesAndTheLicense(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        foreach (
            [
                'RediSearch, RedisJSON, RedisTimeSeries and RedisBloom',
                'Ansilume uses none of them',
                'With `docker-compose.prebuilt.yml` and the deploy role, Redis stays on the network of the server\'s containers, which the runners do not join; `docker-compose.yml` also publishes it on the host, at `DEV_BIND_ADDRESS` (`127.0.0.1` by default) and `REDIS_PORT` (6379)',
                'with `DEV_BIND_ADDRESS=0.0.0.0` every machine that reaches the host',
                'whoever reaches Redis calls every command without a password, those of the modules too',
                '`# WARNING: Redis does not require authentication and is not protected by network restrictions.',
                'Redis 8 is available under the RSALv2, the SSPLv1 or the AGPLv3, at your choice, where Redis 7.2 was under the 3-clause BSD license',
            ] as $needle
        ) {
            $this->assertMentions($needle, $section, 'the update notes');
        }
        $this->assertSame(['${DEV_BIND_ADDRESS:-127.0.0.1}:${REDIS_PORT:-6379}:6379'], $this->compose('docker-compose.yml')['services']['redis']['ports'] ?? null, 'docker-compose.yml publishes Redis where the notes say');
        $this->assertArrayNotHasKey('ports', $this->compose('docker-compose.prebuilt.yml')['services']['redis'] ?? [], 'docker-compose.prebuilt.yml publishes no Redis port');
        $template = $this->read('deploy/roles/ansilume/templates/docker-compose.yaml');
        $this->assertStringNotContainsString('ports:', $this->between($template, "{% if ansilume_redis_local %}\n  redis:", '{% endif %}'), 'the deploy role publishes no Redis port');
    }

    public function testTheBackupNotesSayWhyRedisNeedsNone(): void
    {
        $this->assertMentions(
            'Redis needs no backup: its volume `redis_data` holds the sessions, the cache and the queue of project syncs, nothing that must be kept.',
            $this->flat('docs/deployment.md', '## Backup'),
            'the backup notes of deployment.md'
        );
    }

    /**
     * The services of docker-compose.yml that docker-compose.prebuilt.yml
     * does not have and that a plain `docker compose up -d` starts with the
     * profile of the quickstart's .env, in the order of the file.
     *
     * @return list<string>
     */
    private function servicesOnlyTheDevelopmentStackStarts(): array
    {
        $prebuilt = (array)($this->compose('docker-compose.prebuilt.yml')['services'] ?? []);
        $started = [];
        foreach ((array)($this->compose('docker-compose.yml')['services'] ?? []) as $name => $service) {
            $profiles = (array)(is_array($service) ? ($service['profiles'] ?? []) : []);
            if (!array_key_exists($name, $prebuilt) && ($profiles === [] || in_array(self::QUICKSTART_PROFILE, $profiles, true))) {
                $started[] = (string)$name;
            }
        }

        return $started;
    }

    /**
     * The names of $named as a sentence lists them: "a, b, c and d".
     *
     * @param array<string, string> $named
     */
    private static function namedInOrder(array $named): string
    {
        $names = array_values($named);
        $last = (string)array_pop($names);

        return $names === [] ? $last : implode(', ', $names) . ' and ' . $last;
    }

    /**
     * The source of the method $method of $class, from its first line to its
     * last.
     *
     * @param class-string $class
     */
    private function sourceOf(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        $this->assertIsString($file, "{$class}::{$method} has a file");
        $lines = file($file);
        $this->assertIsArray($lines, "{$file} can be read");

        return implode('', array_slice($lines, (int)$reflection->getStartLine() - 1, (int)$reflection->getEndLine() - (int)$reflection->getStartLine() + 1));
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(string $file): array
    {
        $compose = Yaml::parse($this->read($file));
        $this->assertIsArray($compose);

        return $compose;
    }

    /**
     * The anchor GitHub gives a heading.
     */
    private static function anchor(string $heading): string
    {
        return str_replace(' ', '-', (string)preg_replace('/[^a-z0-9 -]/', '', strtolower(ltrim($heading, '# '))));
    }
}
