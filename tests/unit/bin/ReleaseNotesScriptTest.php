<?php

declare(strict_types=1);

namespace app\tests\unit\bin;

use PHPUnit\Framework\TestCase;

/**
 * bin/release-notes writes the text of the GitHub release of a version tag
 * (.github/workflows/github-release.yml): the notes of the annotated tag
 * (bin/release --notes), the commits since the previous version tag, the
 * images with their digests, where to read about updating and how to verify
 * the source archive. Regression: the repository had no GitHub releases, so
 * nobody could subscribe to them, and bin/quickstart, which asks GitHub for
 * the latest release, always fell back to main.
 *
 * Regression found in review: a version whose release run failed (scan,
 * smoke test) gets no GitHub release, and the next one listed only the
 * changes since that version, so the notes and changes of the failed one,
 * a security fix among them, reached no release at all.
 */
class ReleaseNotesScriptTest extends TestCase
{
    private const NOTES = "Images rebuilt with PHP 8.2.35 (CVE-2026-109618, CVE-2026-109619).\n\nPull the new images.";

    private const IMAGES = "ghcr.io/ansilume/ansilume:1.0.1 sha256:1111\n"
        . "ghcr.io/ansilume/ansilume-nginx:1.0.1 sha256:2222\n"
        . "ghcr.io/ansilume/ansilume-runner:1.0.1 sha256:3333\n";

    private ReleaseRepositoryFixture $repository;

    private string $images;

    protected function setUp(): void
    {
        $this->repository = new ReleaseRepositoryFixture(sys_get_temp_dir() . '/release-notes-' . uniqid('', true));
        $this->images = $this->repository->dir . '/images.txt';
        file_put_contents($this->images, self::IMAGES);
        $this->repository->commit('feat: first', ['VERSION' => "1.0.0\n"]);
        $this->repository->git(['tag', '-a', 'v1.0.0', '-m', 'Release v1.0.0']);
        $this->repository->commit('feat(api): add the jobs endpoint', ['api.txt' => "jobs\n"]);
        $this->repository->commit('fix(ui): keep the filter', ['ui.txt' => "filter\n"]);
        $this->repository->commit('chore: release v1.0.1', ['VERSION' => "1.0.1\n"]);
        $this->repository->git(['tag', '-a', 'v1.0.1', '-m', 'Release v1.0.1', '-m', self::NOTES]);
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    public function testTheReleaseTextOfAnAnnotatedTag(): void
    {
        [$exitCode, $stdout, $stderr] = $this->repository->runScript('bin/release-notes', ['v1.0.1', $this->images]);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame(
            self::NOTES . "\n\n"
            . "## Changes since v1.0.0\n\n"
            . "- feat(api): add the jobs endpoint\n"
            . "- fix(ui): keep the filter\n\n"
            . "## Images\n\n"
            . "- `ghcr.io/ansilume/ansilume:1.0.1` (`sha256:1111`)\n"
            . "- `ghcr.io/ansilume/ansilume-nginx:1.0.1` (`sha256:2222`)\n"
            . "- `ghcr.io/ansilume/ansilume-runner:1.0.1` (`sha256:3333`)\n\n"
            . "## Updating\n\n"
            . "How to update, and what changes after an update: [docs/updating.md](https://github.com/ansilume/ansilume/blob/v1.0.1/docs/updating.md)\n\n"
            . "## Verifying\n\n"
            . "`ansilume-1.0.1.tar.gz` is the source of this release. Check that the release workflow built it from the tag, and that it belongs to this release:\n\n"
            . "    gh attestation verify ansilume-1.0.1.tar.gz --repo ansilume/ansilume --source-ref refs/tags/v1.0.1 --signer-workflow ansilume/ansilume/.github/workflows/github-release.yml\n"
            . "    gh release verify-asset v1.0.1 ansilume-1.0.1.tar.gz --repo ansilume/ansilume\n",
            $stdout
        );
    }

    /**
     * A lightweight tag has no notes: the message of its commit is no
     * release text.
     */
    public function testALightweightTagHasNoNotes(): void
    {
        $this->repository->commit("chore: release v1.0.2\n\nA commit body that is no release text.", ['VERSION' => "1.0.2\n"]);
        $this->repository->git(['tag', 'v1.0.2']);

        [$exitCode, $stdout, $stderr] = $this->repository->runScript('bin/release-notes', ['v1.0.2']);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertStringStartsWith("## Changes since v1.0.1\n\nNothing but the version number.\n\n", $stdout);
        $this->assertStringNotContainsString('no release text', $stdout);
    }

    /**
     * The previous release is the highest version tag the release is built
     * on: a merged branch counts with its commits but without the merge
     * commit; a tag that is no version (an E2E baseline, a release
     * candidate) is no previous release; only the release's own version
     * bump is left out, not other commits that start alike.
     */
    public function testChangesSinceThePreviousVersionTag(): void
    {
        $this->repository->git(['checkout', '-q', '-b', 'topic']);
        $this->repository->commit('feat: on a branch', ['topic.txt' => "topic\n"]);
        $this->repository->git(['checkout', '-q', 'main']);
        $this->repository->commit('fix: on main', ['main.txt' => "main\n"]);
        $this->repository->git(['tag', 'e2e-baseline']);
        $this->repository->git(['merge', '-q', '--no-ff', '-m', "Merge branch 'topic'", 'topic']);
        $this->repository->commit('chore: release v2 pipeline', ['pipeline.txt' => "v2\n"]);
        $this->repository->git(['tag', '-a', 'v1.1.0-rc1', '-m', 'Release candidate']);
        $this->repository->commit('fix: after the candidate', ['rc.txt' => "rc\n"]);
        $this->repository->commit('chore: release v1.1.0', ['VERSION' => "1.1.0\n"]);
        $this->repository->git(['tag', '-a', 'v1.1.0', '-m', 'Release v1.1.0']);

        [$exitCode, $stdout, $stderr] = $this->repository->runScript('bin/release-notes', ['v1.1.0']);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertStringStartsWith(
            "## Changes since v1.0.1\n\n"
            . "- feat: on a branch\n"
            . "- fix: on main\n"
            . "- chore: release v2 pipeline\n"
            . "- fix: after the candidate\n\n"
            . "## Updating\n",
            $stdout
        );
    }

    /**
     * An images file written by hand may lack the newline after its last
     * line; that image is listed too.
     */
    public function testTheLastImageWithoutANewlineIsListed(): void
    {
        file_put_contents($this->images, rtrim(self::IMAGES, "\n"));

        [$exitCode, $stdout, $stderr] = $this->repository->runScript('bin/release-notes', ['v1.0.1', $this->images]);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertStringContainsString("- `ghcr.io/ansilume/ansilume-runner:1.0.1` (`sha256:3333`)\n\n## Updating\n", $stdout);
    }

    /**
     * A release that only rebuilds the images, as v2.12.1 did, says that
     * nothing but the version number changed.
     */
    public function testAReleaseWithoutOtherCommitsSaysSo(): void
    {
        $this->repository->commit('chore: release v1.0.2', ['VERSION' => "1.0.2\n"]);
        $this->repository->git(['tag', '-a', 'v1.0.2', '-m', 'Release v1.0.2']);

        [$exitCode, $stdout, $stderr] = $this->repository->runScript('bin/release-notes', ['v1.0.2']);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertStringStartsWith("## Changes since v1.0.1\n\nNothing but the version number.\n\n## Updating\n", $stdout);
    }

    /**
     * The release after a version whose run failed carries that version's
     * notes and changes: the changes since the previous release, given as
     * the third argument, without the version bumps of either.
     */
    public function testAVersionWithoutAReleaseIsPartOfTheNext(): void
    {
        $this->repository->commit('fix(security): redact the tokens', ['token.txt' => "redacted\n"]);
        $this->repository->commit('chore: release v1.0.2', ['VERSION' => "1.0.2\n"]);
        $this->repository->git(['tag', '-a', 'v1.0.2', '-m', 'Release v1.0.2', '-m', 'Rotate the runner tokens after the update.']);
        $this->repository->commit('fix(images): newer base image', ['base.txt' => "new\n"]);
        $this->repository->commit('chore: release v1.0.3', ['VERSION' => "1.0.3\n"]);
        $this->repository->git(['tag', '-a', 'v1.0.3', '-m', 'Release v1.0.3', '-m', 'Images rebuilt.']);

        [$exitCode, $stdout, $stderr] = $this->repository->runScript('bin/release-notes', ['v1.0.3', '', 'v1.0.1']);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertStringStartsWith(
            "Images rebuilt.\n\n"
            . "## v1.0.2\n\n"
            . "v1.0.2 got no release of its own: its changes are part of this one.\n\n"
            . "Rotate the runner tokens after the update.\n\n"
            . "## Changes since v1.0.1\n\n"
            . "- fix(security): redact the tokens\n"
            . "- fix(images): newer base image\n\n"
            . "## Updating\n",
            $stdout
        );

        [, $unreleased] = $this->repository->runScript('bin/release-notes', ['v1.0.3']);
        $this->assertStringStartsWith("Images rebuilt.\n\n## Changes since v1.0.2\n\n- fix(images): newer base image\n\n", $unreleased, 'Without a previous release, the previous version tag.');
    }

    /**
     * Regression found in review: a fix of an older line, released from a
     * maintenance branch and merged forward, was called a version without a
     * release of its own, with its notes repeated.
     */
    public function testAReleasedFixOfAnOlderLineMergedForwardHasItsOwnRelease(): void
    {
        $this->repository->commit('chore: release v1.1.0', ['VERSION' => "1.1.0\n"]);
        $this->repository->git(['tag', '-a', 'v1.1.0', '-m', 'Release v1.1.0']);
        $this->repository->git(['checkout', '-q', '-b', 'release-1.0', 'v1.0.1']);
        $this->repository->commit('fix(security): on the old line', ['old.txt' => "fix\n"]);
        $this->repository->commit('chore: release v1.0.2', ['VERSION' => "1.0.2\n"]);
        $this->repository->git(['tag', '-a', 'v1.0.2', '-m', 'Release v1.0.2', '-m', 'Rotate the runner tokens.']);
        $this->repository->git(['checkout', '-q', 'main']);
        $this->repository->git(['merge', '-q', '--no-ff', '-X', 'ours', '-m', "Merge branch 'release-1.0'", 'release-1.0']);
        $this->repository->commit('chore: release v1.1.1', ['VERSION' => "1.1.1\n"]);
        $this->repository->git(['tag', '-a', 'v1.1.1', '-m', 'Release v1.1.1']);

        [$exitCode, $stdout, $stderr] = $this->repository->runScript('bin/release-notes', ['v1.1.1', '', 'v1.1.0']);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertStringStartsWith("## Changes since v1.1.0\n\n- fix(security): on the old line\n\n## Updating\n", $stdout);
        $this->assertStringNotContainsString('got no release', $stdout);
        $this->assertStringNotContainsString('Rotate the runner tokens.', $stdout);
    }

    public function testTheFirstReleaseHasNoChangesSection(): void
    {
        [$exitCode, $stdout, $stderr] = $this->repository->runScript('bin/release-notes', ['v1.0.0']);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertStringStartsWith("## Updating\n", $stdout);
        $this->assertStringNotContainsString('## Changes', $stdout);
        $this->assertStringNotContainsString('## Images', $stdout);
    }

    public function testLinksFollowTheRepositoryTheWorkflowRunsIn(): void
    {
        [$exitCode, $stdout, $stderr] = $this->repository->runScript('bin/release-notes', ['v1.0.1'], [
            'GITHUB_SERVER_URL' => 'https://github.example.com',
            'GITHUB_REPOSITORY' => 'acme/ansilume',
        ]);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertStringContainsString('(https://github.example.com/acme/ansilume/blob/v1.0.1/docs/updating.md)', $stdout);
        $this->assertStringContainsString('gh attestation verify ansilume-1.0.1.tar.gz --repo acme/ansilume --source-ref refs/tags/v1.0.1 --signer-workflow acme/ansilume/.github/workflows/github-release.yml', $stdout);
        $this->assertStringContainsString('gh release verify-asset v1.0.1 ansilume-1.0.1.tar.gz --repo acme/ansilume', $stdout);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function refusedArgumentsProvider(): array
    {
        return [
            'no tag' => [[], 'Usage:'],
            'a tag that does not exist' => [['v9.9.9'], 'v9.9.9 is no tag'],
            'a branch' => [['main'], 'main is no tag'],
            'an images file that does not exist' => [['v1.0.1', '/nonexistent/images.txt'], '/nonexistent/images.txt is no file'],
            'a previous release that does not exist' => [['v1.0.1', '', 'v0.9.9'], 'v0.9.9 is no tag'],
            'a fourth argument' => [['v1.0.1', '', 'v1.0.0', 'v0.9.9'], 'Usage:'],
        ];
    }

    /**
     * @param list<string> $args
     * @dataProvider refusedArgumentsProvider
     */
    public function testRefusedArgumentsWriteNoText(array $args, string $error): void
    {
        [$exitCode, $stdout, $stderr] = $this->repository->runScript('bin/release-notes', $args);

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString($error, $stderr);
    }
}
