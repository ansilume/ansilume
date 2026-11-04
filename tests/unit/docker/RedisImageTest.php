<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The bundled Redis of the development stack (docker-compose.yml), of the
 * prebuilt stack (docker-compose.prebuilt.yml) and of the deploy role
 * (ansilume_redis_image) is one image, of a Redis line whose image runs on an
 * Alpine that still gets fixes, and every redis service keeps its data in the
 * named volume redis_data at /data.
 *
 * The line decides the base: Redis no longer moves an older line to a newer
 * Alpine, so redis:7.2-alpine stays on Alpine 3.21, whose support ends on
 * 2026-11-01, while redis:8.10-alpine runs on Alpine 3.23, supported until
 * 2027-11-01. Raise OLDEST_LINE with the next move, together with every place
 * this test reads. A Redis cannot load what a newer line wrote (7.2 exits
 * with "Can't handle RDB format version 15" on the data of 8.10), so the line
 * has update notes with a way back in docs/updating.md: a section "## After
 * updating from …" that names its image and, under **Going back.**, removes
 * the volume (`docker volume rm`), and a Rollback that names the line. A move
 * of the line fails here until its notes exist; Redis810UpdateDocsContractTest
 * checks what the notes of the move to 8.10 say.
 *
 * Regression: since 8.6 the redis images declare no VOLUME /data, and the
 * redis services mounted nothing there. Redis kept its data in the anonymous
 * volume of the image's VOLUME, which Compose carries over to a new container
 * only while the new image declares the path. Moved to redis:8.10-alpine as
 * they were, the services would have lost the sessions, the cache and the
 * queue of project syncs at every recreate of the container: every update
 * that pulls a new 8.10.x, every `up --force-recreate`, every run of the
 * deploy role's restart handler.
 */
class RedisImageTest extends TestCase
{
    use RendersDeployRole;

    /** The oldest Redis line whose image runs on an Alpine that still gets fixes. */
    private const OLDEST_LINE = '8.10';

    /** The named volume that keeps the data of Redis. */
    private const VOLUME = 'redis_data';

    /** Where every redis service mounts it: the data directory of the redis images. */
    private const MOUNT = self::VOLUME . ':/data';

    /** The compose files of the repository, each with its one redis service. */
    private const COMPOSE_FILES = ['docker-compose.yml', 'docker-compose.prebuilt.yml'];

    /** The update notes, which say how to go back from every line. */
    private const UPDATE_NOTES = 'docs/updating.md';

    /** What the update notes lack for a line, as missingUpdateNotes() reports it. */
    private const NO_UPDATE_SECTION = 'No section "## After updating from …" of docs/updating.md names %s.';

    private const NO_WAY_BACK = 'No section of docs/updating.md that names %s removes the volume under **Going back.** (`docker volume rm`).';

    private const NO_ROLLBACK = 'The Rollback of docs/updating.md does not name Redis %s.';

    public function testRegressionEveryRedisIsTheSameMaintainedImage(): void
    {
        $images = [];
        foreach (self::COMPOSE_FILES as $file) {
            $images[$file] = (string)($this->compose($file)['services']['redis']['image'] ?? '');
        }
        $images['ansilume_redis_image'] = $this->defaultImage();
        $images['the deploy role'] = (string)($this->renderDeployTemplate(false)['services']['redis']['image'] ?? '');

        $this->assertCount(1, array_unique($images), (string)json_encode($images));
        $line = $this->line();
        $this->assertTrue(version_compare($line, self::OLDEST_LINE, '>='), "Redis {$line} is older than the oldest line whose image runs on a supported Alpine, " . self::OLDEST_LINE);
    }

    public function testTheReadmeNamesTheLine(): void
    {
        $badge = 'badge/Redis-' . $this->line() . '-';

        $this->assertTrue(str_contains($this->read('README.md'), $badge), "README.md has no {$badge}");
    }

    /**
     * A Redis cannot load what a newer line wrote, so the line has update
     * notes with a way back. Review finding: nothing tied the line to its
     * notes, and a move to 8.12 without notes passed every test.
     */
    public function testRegressionEveryLineHasUpdateNotesWithAWayBack(): void
    {
        $this->assertSame([], self::missingUpdateNotes($this->read(self::UPDATE_NOTES), $this->line()));
    }

    /**
     * The check itself: a move of the line without notes is found, and so is
     * a section whose way back keeps the volume; a heading in a code block
     * and a `docker volume rm` before **Going back.** count for nothing.
     */
    public function testAMoveOfTheLineWithoutUpdateNotesIsFound(): void
    {
        $image = '`redis:8.12-alpine`';
        $section = "## After updating from 2.20.0 or older: Redis 8.12\n\nIt runs {$image}; `docker volume rm <name>` removes the old volume.\n\n**Going back.** Pin the images.\n\n";
        $wayBack = "```bash\ndocker volume rm ansilume_redis_data\n```\n\n";
        $rollback = "## Rollback\n\nTo roll back from the update to Redis 8.12, see above.\n";

        $this->assertSame(
            [sprintf(self::NO_UPDATE_SECTION, $image), sprintf(self::NO_ROLLBACK, '8.12')],
            self::missingUpdateNotes($this->read(self::UPDATE_NOTES), '8.12'),
            'the docs of today after a move to 8.12'
        );
        $this->assertSame([sprintf(self::NO_WAY_BACK, $image)], self::missingUpdateNotes($section . $rollback, '8.12'), 'a way back that keeps the volume');
        $this->assertSame([], self::missingUpdateNotes($section . $wayBack . $rollback, '8.12'), 'notes with a way back');
        $this->assertSame([sprintf(self::NO_UPDATE_SECTION, $image)], self::missingUpdateNotes("```\n" . $section . "```\n\n" . $rollback, '8.12'), 'a heading in a code block');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function composeFileProvider(): array
    {
        return array_combine(self::COMPOSE_FILES, array_map(static fn (string $file): array => [$file], self::COMPOSE_FILES));
    }

    #[DataProvider('composeFileProvider')]
    public function testRegressionTheRedisOfEveryComposeFileKeepsItsDataInTheNamedVolume(string $file): void
    {
        $compose = $this->compose($file);

        $this->assertSame([self::MOUNT], $compose['services']['redis']['volumes'] ?? null, "the redis service of {$file}");
        $this->assertArrayHasKey(self::VOLUME, (array)($compose['volumes'] ?? []), "{$file} declares the volume");
        $this->assertSame([], self::undeclaredVolumes($compose), "{$file} declares every volume a service mounts");
    }

    /**
     * Both image modes, a local and an external database and Redis, with
     * and without runners.
     *
     * @return array<string, array{0: bool, 1: bool, 2: bool, 3: int}>
     */
    public static function deployProvider(): array
    {
        $cases = [];
        foreach ([false, true] as $prebuilt) {
            foreach ([true, false] as $db) {
                foreach ([true, false] as $redis) {
                    foreach ([2, 0] as $runners) {
                        $name = sprintf('%s, %s database, %s redis, %d runners', $prebuilt ? 'prebuilt' : 'build', $db ? 'local' : 'external', $redis ? 'local' : 'external', $runners);
                        $cases[$name] = [$prebuilt, $db, $redis, $runners];
                    }
                }
            }
        }

        return $cases;
    }

    /**
     * Every file the deploy role renders declares each volume its services
     * mount, redis_data whenever it has the local Redis. With the volume in
     * the redis service only, the top-level volumes, rendered only for a
     * local database, prebuilt images or runners, were missing in build mode
     * with an external database, a local Redis and no runners, and Compose
     * refused the file (`service "redis" refers to undefined volume
     * redis_data: invalid compose project`).
     */
    #[DataProvider('deployProvider')]
    public function testRegressionTheDeployRoleKeepsTheDataOfItsRedisInTheNamedVolume(bool $prebuilt, bool $db, bool $redis, int $runners): void
    {
        $compose = $this->renderDeployTemplate($prebuilt, ['ansilume_db_local' => $db, 'ansilume_redis_local' => $redis, 'ansilume_runner_count' => $runners]);
        $volumes = (array)($compose['volumes'] ?? []);

        if ($redis) {
            $this->assertSame([self::MOUNT], $compose['services']['redis']['volumes'] ?? null, 'the redis service');
            $this->assertArrayHasKey(self::VOLUME, $volumes, 'the rendered file declares the volume');
        } else {
            $this->assertArrayNotHasKey('redis', $compose['services'], 'an external Redis has no service');
            $this->assertArrayNotHasKey(self::VOLUME, $volumes, 'nor a volume');
        }
        if (array_key_exists('volumes', $compose)) {
            $this->assertNotEmpty($volumes, 'a top-level volumes key names a volume');
        }
        $this->assertSame([], self::undeclaredVolumes($compose), 'the rendered file declares every volume a service mounts');
    }

    /**
     * The check itself: named volumes in the short and the long syntax
     * count, bind mounts and anonymous volumes do not.
     */
    public function testTheUndeclaredVolumesAreFound(): void
    {
        $compose = [
            'services' => [
                'redis' => ['volumes' => ['redis_data:/data', 'other:/other:ro', './conf:/conf', '/srv/x:/x', '/anonymous']],
                'app' => ['volumes' => [['type' => 'volume', 'source' => 'long', 'target' => '/long'], ['type' => 'bind', 'source' => './y', 'target' => '/y']]],
                'nginx' => ['image' => 'nginx'],
            ],
            'volumes' => ['redis_data' => null],
        ];

        $this->assertSame(['redis: other', 'app: long'], self::undeclaredVolumes($compose));
        $this->assertSame([], self::undeclaredVolumes(['services' => ['redis' => ['volumes' => ['/data']]]]));
    }

    /**
     * The services' named volumes that $compose does not declare, as
     * "<service>: <volume>".
     *
     * @param array<mixed> $compose
     * @return list<string>
     */
    private static function undeclaredVolumes(array $compose): array
    {
        $declared = array_keys((array)($compose['volumes'] ?? []));
        $undeclared = [];
        foreach ((array)($compose['services'] ?? []) as $name => $service) {
            foreach ((array)(is_array($service) ? ($service['volumes'] ?? []) : []) as $volume) {
                $source = self::namedVolumeOf($volume);
                if ($source !== null && !in_array($source, $declared, true)) {
                    $undeclared[] = "{$name}: {$source}";
                }
            }
        }

        return $undeclared;
    }

    /**
     * The named volume a service's volume entry mounts, null for a bind
     * mount or an anonymous volume.
     */
    private static function namedVolumeOf(mixed $volume): ?string
    {
        if (is_array($volume)) {
            return ($volume['type'] ?? null) === 'volume' && is_string($volume['source'] ?? null) ? $volume['source'] : null;
        }
        $parts = explode(':', (string)$volume);
        if (count($parts) < 2 || preg_match('#^[.~/$]#', $parts[0]) === 1) {
            return null;
        }

        return $parts[0];
    }

    /**
     * What $notes, the text of docs/updating.md, lacks to say how to go back
     * from the Redis $line: a section "## After updating from …" that names
     * its image and, from **Going back.** on, removes the volume with
     * `docker volume rm`, and a section "## Rollback" that names the line.
     *
     * @return list<string>
     */
    private static function missingUpdateNotes(string $notes, string $line): array
    {
        $image = "`redis:{$line}-alpine`";
        $sections = self::sectionsOf($notes);
        $naming = array_filter(
            $sections,
            static fn (array $section): bool => str_starts_with($section['heading'], '## After updating from ') && str_contains($section['text'], $image)
        );
        $missing = [];
        if ($naming === []) {
            $missing[] = sprintf(self::NO_UPDATE_SECTION, $image);
        } elseif (array_filter($naming, static fn (array $section): bool => str_contains((string)strstr($section['text'], '**Going back.**'), 'docker volume rm')) === []) {
            $missing[] = sprintf(self::NO_WAY_BACK, $image);
        }
        $rollback = array_filter($sections, static fn (array $section): bool => $section['heading'] === '## Rollback' && str_contains($section['text'], "Redis {$line}"));
        if ($rollback === []) {
            $missing[] = sprintf(self::NO_ROLLBACK, $line);
        }

        return $missing;
    }

    /**
     * The sections of the Markdown $text under its level-two headings, each
     * with its heading line and its text; a line in a code block is no
     * heading.
     *
     * @return list<array{heading: string, text: string}>
     */
    private static function sectionsOf(string $text): array
    {
        $sections = [];
        $inCode = false;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!$inCode && str_starts_with($line, '## ')) {
                $sections[] = ['heading' => $line, 'text' => ''];
                continue;
            }
            // A fence line opens or closes a code block.
            $inCode = $inCode !== (preg_match('/^\s*```/', $line) === 1);
            if ($sections !== []) {
                $sections[array_key_last($sections)]['text'] .= $line . "\n";
            }
        }

        return $sections;
    }

    /**
     * The line of the role default, the image the other places must match.
     */
    private function line(): string
    {
        $this->assertSame(1, preg_match('/^redis:(\d+\.\d+)-alpine$/', $this->defaultImage(), $line), 'An Alpine image of a Redis line: redis:<major>.<minor>-alpine');

        return $line[1];
    }

    private function defaultImage(): string
    {
        $defaults = Yaml::parseFile(dirname(__DIR__, 3) . '/deploy/roles/ansilume/defaults/main.yaml');
        $this->assertIsArray($defaults);

        return (string)($defaults['ansilume_redis_image'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(string $file): array
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . '/' . $file);
        $this->assertIsArray($compose);

        return $compose;
    }

    private function read(string $file): string
    {
        $text = file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        $this->assertIsString($text, "{$file} cannot be read.");

        return $text;
    }
}
