<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * bin/release --notes puts a text into the annotated tag, below its subject
 * "Release vX.Y.Z", and the GitHub release of the tag starts with it
 * (bin/release-notes, .github/workflows/github-release.yml). Regression: a
 * release said nowhere why it was made; v2.12.1 rebuilt the images with
 * PHP 8.2.35 for CVE-2026-109618 and CVE-2026-109619, and its tag, its
 * commit and the docs did not say so.
 */
class ReleaseTagNotesTest extends TestCase
{
    /**
     * With a Markdown heading: git tag drops every line that starts with #
     * unless it is told to keep them (--cleanup=whitespace).
     */
    private const NOTES = "Images rebuilt with PHP 8.2.35 (CVE-2026-109618, CVE-2026-109619).\n\n## Who must update\n\nEvery installation: pull the new images.";

    private ReleaseRepositoryFixture $repository;

    protected function setUp(): void
    {
        $this->repository = new ReleaseRepositoryFixture(sys_get_temp_dir() . '/release-tag-notes-' . uniqid('', true));
        $this->repository->commit('feat: first', [
            'VERSION' => "1.4.2\n",
            'web/openapi.yaml' => "openapi: 3.1.0\ninfo:\n  title: Ansilume API\n  version: \"1.4.2\"\npaths: {}\n",
        ]);
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    public function testNotesBecomeTheBodyOfTheAnnotatedTag(): void
    {
        [$exitCode, , $stderr] = $this->repository->runScript('bin/release', ['patch', '--notes', self::NOTES]);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame("1.4.3\n", (string)file_get_contents($this->repository->work . '/VERSION'));
        foreach ([$this->repository->work, $this->repository->origin] as $repository) {
            $this->assertSame("tag\n", $this->repository->git(['cat-file', '-t', 'refs/tags/v1.4.3'], $repository), 'An annotated tag, in ' . $repository);
            $this->assertSame("Release v1.4.3\n", $this->repository->git(['for-each-ref', '--format=%(contents:subject)', 'refs/tags/v1.4.3'], $repository));
            $this->assertSame(self::NOTES, rtrim($this->repository->git(['for-each-ref', '--format=%(contents:body)', 'refs/tags/v1.4.3'], $repository)));
        }
    }

    public function testWithoutNotesTheTagHasOnlyItsSubject(): void
    {
        [$exitCode, , $stderr] = $this->repository->runScript('bin/release', ['patch']);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame("Release v1.4.3\n", $this->repository->git(['for-each-ref', '--format=%(contents:subject)', 'refs/tags/v1.4.3']));
        $this->assertSame('', trim($this->repository->git(['for-each-ref', '--format=%(contents:body)', 'refs/tags/v1.4.3'])));
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function refusedArgumentsProvider(): array
    {
        return [
            'misspelt option' => [['patch', '--note', self::NOTES]],
            'notes without a text' => [['patch', '--notes']],
            'empty notes' => [['patch', '--notes', '']],
            'notes before the bump' => [['--notes', self::NOTES, 'patch']],
            'a second text' => [['patch', '--notes', self::NOTES, 'more']],
        ];
    }

    /**
     * A mistake in the arguments must stop the release before it bumps,
     * commits, tags or pushes anything.
     *
     * @param list<string> $args
     * @dataProvider refusedArgumentsProvider
     */
    public function testRefusedArgumentsChangeNothing(array $args): void
    {
        $head = $this->repository->git(['rev-parse', 'HEAD']);

        [$exitCode, , $stderr] = $this->repository->runScript('bin/release', $args);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Usage:', $stderr);
        $this->assertSame("1.4.2\n", (string)file_get_contents($this->repository->work . '/VERSION'));
        $this->assertSame($head, $this->repository->git(['rev-parse', 'HEAD']));
        $this->assertSame('', $this->repository->git(['tag', '--list']));
        $this->assertSame('', $this->repository->git(['ls-remote', '--tags', 'origin']));
    }
}
