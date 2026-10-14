<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guard: every container image the docs name is one that
 * .github/workflows/release.yml publishes, with a tag that it publishes.
 * The release tags its images with docker/metadata-action:
 * type=semver,pattern={{version}} (2.10.1), {{major}}.{{minor}} (2.10),
 * {{major}} (2) and type=raw,value=latest, without the leading v of the
 * git tag (v2.10.1).
 *
 * The docs are docs/*.md, README.md and CLAUDE.md. An image is named as
 * ghcr.io/ansilume/<name>[:<tag>]; a tag is also named on its own, in
 * backticks, such as "change `:latest` to `:2.10.1`", where it pins the
 * images of docker-compose.yml.
 *
 * Regression (wave 5, W5-OPS-5): the Rollback steps of docs/updating.md
 * said to change `:latest` to `:v2.1.0`, a tag the release never pushed, so
 * a rollback stopped at its first `docker compose pull` with "manifest
 * unknown". Fails on the docs from before.
 */
class DocsImageTagsTest extends TestCase
{
    use ReadsOperatorDocs;

    private const RELEASE_WORKFLOW = '.github/workflows/release.yml';

    /** The registry and owner of the images the docs name. */
    private const IMAGE = '#ghcr\.io/(ansilume/[a-z0-9._-]+)(?::([^\s`"\'),\]]+))?#';

    /**
     * A tag written on its own, in backticks: `:latest`, `:2.10.1`,
     * `:v2.1.0`; a port such as `:8088` is none.
     */
    private const BARE_TAG = '/`:((?:latest|v?\d+\.\d+)[^`\s]*)`/';

    /** What each pattern of the semver tags of metadata-action gives. */
    private const SEMVER_PATTERNS = [
        '{{version}}' => '\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?',
        '{{major}}.{{minor}}' => '\d+\.\d+',
        '{{major}}' => '\d+',
    ];

    /**
     * regression: `:v2.1.0` in the Rollback steps of updating.md.
     */
    public function testRegressionEveryImageTagTheDocsNameIsOneTheReleasePublishes(): void
    {
        $tags = $this->releaseTagPatterns();
        $wrong = [];
        $checked = 0;
        foreach ($this->docs() as $file) {
            foreach ($this->namedTags($file) as $tag) {
                $checked++;
                if (!self::matchesAny($tag, $tags)) {
                    $wrong[] = "{$file}: :{$tag}";
                }
            }
        }

        $this->assertGreaterThan(5, $checked, 'The docs name image tags.');
        $this->assertSame([], $wrong, 'Image tags that release.yml does not publish (its tags have no leading v): ' . implode(', ', $wrong));
    }

    public function testEveryImageTheDocsNameIsOneTheReleasePublishes(): void
    {
        $images = $this->releaseImages();
        $wrong = [];
        foreach ($this->docs() as $file) {
            preg_match_all(self::IMAGE, $this->read($file), $matches);
            foreach (array_unique($matches[1]) as $image) {
                if (!in_array($image, $images, true)) {
                    $wrong[] = "{$file}: ghcr.io/{$image}";
                }
            }
        }

        $this->assertSame([], $wrong, 'Images that release.yml does not publish: ' . implode(', ', $wrong));
    }

    /**
     * The check itself: the tags the release publishes pass, other
     * spellings fail.
     */
    public function testTheCheckTellsTheReleaseTagsFromOthers(): void
    {
        $tags = $this->releaseTagPatterns();
        foreach (['latest', '2.10.1', '2.10', '2', '2.11.0-rc.1'] as $published) {
            $this->assertTrue(self::matchesAny($published, $tags), $published);
        }
        foreach (['v2.10.1', 'v2.1.0', '2.10.1.1', 'Latest', 'main'] as $other) {
            $this->assertFalse(self::matchesAny($other, $tags), $other);
        }
        $this->assertSame(['v2.1.0', 'latest', '2.10.1'], self::tagsIn('change `:v2.1.0` to `:latest` or `ghcr.io/ansilume/ansilume:2.10.1`, on `:8088`'));
    }

    /**
     * The tags release.yml gives the images, as regular expressions.
     *
     * @return list<string>
     */
    private function releaseTagPatterns(): array
    {
        $patterns = [];
        foreach (preg_split('/\R/', trim((string)($this->metadataStep()['with']['tags'] ?? ''))) ?: [] as $line) {
            $options = [];
            foreach (explode(',', trim($line)) as $option) {
                [$key, $value] = array_pad(explode('=', $option, 2), 2, '');
                $options[$key] = $value;
            }
            $patterns[] = match ($options['type'] ?? '') {
                'semver' => self::SEMVER_PATTERNS[$options['pattern'] ?? ''] ?? $this->fail("release.yml has the semver pattern {$line}, which this test does not know."),
                'raw' => preg_quote($options['value'] ?? '', '/'),
                default => $this->fail("release.yml tags with {$line}, which this test does not know."),
            };
        }
        $this->assertCount(4, $patterns, 'release.yml gives four tags.');

        return $patterns;
    }

    /**
     * The images release.yml publishes, such as ansilume/ansilume-nginx.
     *
     * @return list<string>
     */
    private function releaseImages(): array
    {
        $images = [];
        foreach ($this->release()['jobs'] ?? [] as $job) {
            foreach ($job['strategy']['matrix']['target'] ?? [] as $target) {
                $images[] = (string)($target['image'] ?? '');
            }
        }
        $images = array_values(array_unique($images));
        $this->assertSame(['ansilume/ansilume', 'ansilume/ansilume-nginx', 'ansilume/ansilume-runner'], $images);

        return $images;
    }

    /**
     * The metadata-action step that tags the merged images.
     *
     * @return array<string, mixed>
     */
    private function metadataStep(): array
    {
        foreach ($this->release()['jobs'] ?? [] as $job) {
            foreach ($job['steps'] ?? [] as $step) {
                if (str_starts_with((string)($step['uses'] ?? ''), 'docker/metadata-action@') && isset($step['with']['tags'])) {
                    return $step;
                }
            }
        }
        $this->fail('release.yml has no metadata-action step with tags.');
    }

    /**
     * @return array<string, mixed>
     */
    private function release(): array
    {
        $release = Yaml::parseFile(dirname(__DIR__, 3) . '/' . self::RELEASE_WORKFLOW);
        $this->assertIsArray($release);

        return $release;
    }

    /**
     * The docs: docs/*.md, README.md and CLAUDE.md.
     *
     * @return list<string>
     */
    private function docs(): array
    {
        $docs = array_map(static fn (string $path): string => 'docs/' . basename($path), glob($this->root() . '/docs/*.md') ?: []);
        $this->assertNotEmpty($docs);

        return array_merge($docs, ['README.md', 'CLAUDE.md']);
    }

    /**
     * The image tags $file names, in its order.
     *
     * @return list<string>
     */
    private function namedTags(string $file): array
    {
        return self::tagsIn($this->read($file));
    }

    /**
     * The tags of the images $text names after ghcr.io/ansilume/ and the
     * tags it names on their own, in backticks, in this order.
     *
     * @return list<string>
     */
    private static function tagsIn(string $text): array
    {
        preg_match_all(self::BARE_TAG, $text, $bare);
        preg_match_all(self::IMAGE, $text, $images);

        return array_values(array_merge($bare[1], array_filter($images[2], static fn (string $tag): bool => $tag !== '')));
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchesAny(string $tag, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match('/^(?:' . $pattern . ')$/', $tag) === 1) {
                return true;
            }
        }

        return false;
    }
}
