<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\tests\unit\bin\ReleaseRepositoryFixture;
use PHPUnit\Framework\TestCase;

/**
 * Every version tag gets a GitHub release, made by
 * .github/workflows/github-release.yml, which release.yml calls once the
 * images are pushed, passed the arm64 smoke test and the scan, and its
 * promote job ran: bin/quickstart installs with the compose file of the
 * latest release. Only that job
 * writes to the repository, it runs only for the pushed tag (so the
 * provenance of the source archive names the tag), and releases are
 * immutable once published (a repository setting), so only a draft left by
 * an interrupted run still takes the archive.
 *
 * The run scripts of the steps run here as on GitHub (shell: bash, that is
 * bash -eo pipefail), with stand-ins for docker and gh.
 *
 * Regressions: the repository had no GitHub releases at all; the review of
 * the first version found that a registry error published empty digests
 * (no pipefail), that an upload to a published release fails on an
 * immutable release, and that a tag such as v1.1.0-rc1 outranked v1.1.0.
 */
class GithubReleaseWorkflowTest extends TestCase
{
    use RunsWorkflowSteps;

    private const RELEASE = '.github/workflows/release.yml';

    private const GITHUB_RELEASE = '.github/workflows/github-release.yml';

    private const JOB_PERMISSIONS = ['contents' => 'write', 'id-token' => 'write', 'attestations' => 'write'];

    private const COMMIT = 'c0ffee0000000000000000000000000000000001';

    public function testReleaseCallsTheGithubReleaseAfterThePromotion(): void
    {
        $job = $this->workflow(self::RELEASE)['jobs']['github-release'] ?? $this->fail('release.yml has no github-release job.');

        $this->assertSame('./' . self::GITHUB_RELEASE, $job['uses'] ?? null);
        $this->assertSame(['promote'], (array)($job['needs'] ?? []), 'The release waits until latest names the images that passed the smoke test and the scan.');
        $this->assertSame("\${{ !contains(github.ref_name, '-') }}", $job['if'] ?? null, 'A release candidate skips the job instead of failing it.');
        $this->assertSame(self::JOB_PERMISSIONS, $job['permissions'] ?? null);
        $this->assertArrayNotHasKey('with', $job, 'The release is made of the pushed tag, which the called workflow reads itself.');
    }

    public function testOnlyTheGithubReleaseJobWritesToTheRepository(): void
    {
        $release = $this->workflow(self::RELEASE);

        $this->assertSame(['contents' => 'read'], $release['permissions'] ?? null);
        foreach ($release['jobs'] as $name => $job) {
            if ($name !== 'github-release') {
                $this->assertNotSame('write', $job['permissions']['contents'] ?? null, "Job {$name} must not write to the repository.");
            }
        }
        $this->assertSame(['contents' => 'read'], $this->workflow(self::GITHUB_RELEASE)['permissions'] ?? null);
        $this->assertSame(self::JOB_PERMISSIONS, $this->job()['permissions'] ?? null);
    }

    /**
     * No manual run from a branch: its provenance would name the branch,
     * not the tag the archive is made of. A failed release is run again
     * with "Re-run failed jobs" of its release.yml run.
     */
    public function testItRunsOnlyWhenReleaseYmlCallsItForThePushedTag(): void
    {
        $workflow = $this->workflow(self::GITHUB_RELEASE);

        $this->assertSame(['workflow_call'], array_keys($workflow['on'] ?? []));
        $this->assertNull($workflow['on']['workflow_call'], 'It takes no inputs.');
        $this->assertSame('${{ github.ref_name }}', $this->job()['env']['TAG'] ?? null);
    }

    public function testEveryStepFailsOnAFailedCommandInAPipe(): void
    {
        $this->assertSame(['run' => ['shell' => 'bash']], $this->job()['defaults'] ?? null, 'shell: bash runs bash -eo pipefail.');
    }

    /**
     * The tag reaches the scripts only as $TAG, never as an expression
     * inside a script.
     */
    public function testNoExpressionInARunScript(): void
    {
        foreach ($this->job()['steps'] as $step) {
            $this->assertStringNotContainsString('${{', (string)($step['run'] ?? ''), 'Step ' . ($step['name'] ?? '?'));
        }
        $this->assertSame('Check the tag', $this->job()['steps'][0]['name'] ?? null, 'The tag is checked first.');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function tagProvider(): array
    {
        return [
            'a version tag' => ['v2.12.2', 'tag', 0],
            'a release candidate' => ['v2.13.0-rc1', 'tag', 1],
            'a branch' => ['main', 'branch', 1],
            'a version-like branch' => ['v2.12.2', 'branch', 1],
            'shell in the name' => ['v1.2.3;id', 'tag', 1],
        ];
    }

    /**
     * @dataProvider tagProvider
     */
    public function testOnlyAVersionTagIsReleased(string $tag, string $refType, int $exitCode): void
    {
        [$code, , $stderr] = $this->runStep('Check the tag', ['TAG' => $tag, 'GITHUB_REF_TYPE' => $refType]);

        $this->assertSame($exitCode, $code, $stderr);
    }

    /**
     * The release is made of the commit the images were built from: a tag
     * that was moved after the push fails the run.
     */
    public function testTheTagMustStillNameTheCommitOfTheRun(): void
    {
        $repository = $this->repository();
        $repository->commit('feat: first', ['VERSION' => "1.0.0\n"]);
        $built = trim($repository->git(['rev-parse', 'HEAD']));
        $repository->git(['tag', '-a', 'v1.0.0', '-m', 'Release v1.0.0', '-m', 'Notes.']);
        $repository->git(['push', '-q', 'origin', 'main', 'v1.0.0']);
        // actions/checkout can leave the pushed tag as a plain ref to its commit.
        $repository->git(['tag', '-d', 'v1.0.0']);
        $repository->git(['tag', 'v1.0.0', $built]);

        [$code, , $stderr] = $this->runStep('Fetch the annotated tags', ['TAG' => 'v1.0.0', 'GITHUB_SHA' => $built], $repository->work);

        $this->assertSame(0, $code, $stderr);
        $this->assertSame("tag\n", $repository->git(['cat-file', '-t', 'refs/tags/v1.0.0']), 'The notes are in the annotated tag.');

        $repository->commit('fix: later', ['later.txt' => "later\n"]);
        $repository->git(['tag', '-f', '-a', 'v1.0.0', '-m', 'Release v1.0.0']);
        $repository->git(['push', '-q', '--force', 'origin', 'v1.0.0']);

        [$code, $stdout] = $this->runStep('Fetch the annotated tags', ['TAG' => 'v1.0.0', 'GITHUB_SHA' => $built], $repository->work);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("::error::v1.0.0 no longer names {$built}", $stdout);
    }

    public function testTheImagesOfTheTagAreListedWithTheirDigests(): void
    {
        [$code, , $stderr, $runnerTemp] = $this->runImageDigests([]);

        $this->assertSame(0, $code, $stderr);
        $this->assertSame(
            "ghcr.io/ansilume/ansilume:2.12.2 sha256:" . str_repeat('a', 64) . "\n"
            . "ghcr.io/ansilume/ansilume-nginx:2.12.2 sha256:" . str_repeat('a', 64) . "\n"
            . "ghcr.io/ansilume/ansilume-runner:2.12.2 sha256:" . str_repeat('a', 64) . "\n",
            (string)file_get_contents($runnerTemp . '/images.txt')
        );
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function brokenImageProvider(): array
    {
        return [
            'an image is missing' => [['STUB_MISSING' => 'ansilume-nginx'], 'ghcr.io/ansilume/ansilume-nginx:2.12.2'],
            'a platform was built from another commit' => [['STUB_REVISIONS' => self::COMMIT . ' 0000000000000000000000000000000000000002'], 'ghcr.io/ansilume/ansilume:2.12.2'],
            'no platform names a commit' => [['STUB_REVISIONS' => ' '], 'ghcr.io/ansilume/ansilume:2.12.2'],
            'no digest' => [['STUB_DIGEST' => '<no value>'], 'ghcr.io/ansilume/ansilume:2.12.2'],
        ];
    }

    /**
     * Regression: without pipefail a registry error left the digest empty
     * and the release named images it could not find.
     *
     * @param array<string, string> $stub
     * @dataProvider brokenImageProvider
     */
    public function testBrokenImagesFailTheRun(array $stub, string $image): void
    {
        [$code, $stdout, $stderr] = $this->runImageDigests($stub);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString($image, $stdout . $stderr);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: int, 2: string, 3: list<string>}>
     */
    public static function failedInspectProvider(): array
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $revisions = self::COMMIT . ' ' . self::COMMIT . ' ';
        $listed = static fn (string $image): string => "ghcr.io/ansilume/{$image}:2.12.2 {$digest}";
        $error = static function (string $image, string $digestRead, string $revisionsRead): string {
            return "::error::ghcr.io/ansilume/{$image}:2.12.2 has no digest or no platform image"
                . " (digest '{$digestRead}', revisions '{$revisionsRead}')\n";
        };

        return [
            'an image of the tag is missing' => [
                ['STUB_MISSING' => 'ansilume-nginx'],
                1,
                $error('ansilume-nginx', '', ''),
                [$listed('ansilume')],
            ],
            'the first inspect fails after printing the digest' => [
                ['STUB_FAILING' => 'Manifest.Digest', 'STUB_PARTIAL' => '1'],
                1,
                $error('ansilume', '', $revisions),
                [],
            ],
            'the second inspect fails' => [
                ['STUB_FAILING' => 'image.revision'],
                1,
                $error('ansilume', $digest, ''),
                [],
            ],
            'the second inspect fails after printing the revisions' => [
                ['STUB_FAILING' => 'image.revision', 'STUB_PARTIAL' => '1'],
                1,
                $error('ansilume', $digest, ''),
                [],
            ],
            'a good inspect lists every image' => [
                [],
                0,
                '',
                [$listed('ansilume'), $listed('ansilume-nginx'), $listed('ansilume-runner')],
            ],
        ];
    }

    /**
     * Regression: the step kept the digest and the revisions with
     * `digest=$(docker buildx imagetools inspect …)` and `revisions=$(…)`, so
     * a failed inspect (an image of the tag missing, a registry error between
     * the two calls) ended the step at the assignment under set -e: its
     * ::error:: line, which names the image without a digest or a platform
     * image, never appeared, only docker's own error. A failed call now
     * leaves its value empty, whatever it printed before it failed, for that
     * check to report; the step still fails, and lists no image it could not
     * read.
     *
     * @param array<string, string> $stub
     * @param list<string> $listed
     * @dataProvider failedInspectProvider
     */
    public function testRegressionAFailedInspectIsReportedByTheCheck(
        array $stub,
        int $exitCode,
        string $stdout,
        array $listed,
    ): void {
        [$code, $out, $stderr, $runnerTemp] = $this->runImageDigests($stub);

        $this->assertSame([$exitCode, $stdout], [$code, $out], $stderr);
        $file = $runnerTemp . '/images.txt';
        $images = is_file($file) ? explode("\n", trim((string)file_get_contents($file))) : [];
        $this->assertSame($listed, $images, 'The images the release notes name.');
    }

    public function testANewReleaseIsCreatedWithItsNotesAndItsSignedArchive(): void
    {
        [$code, $calls, $stderr, $work] = $this->runPublish('v1.1.0', ['v1.0.0', 'v1.1.0'], 'none');

        $this->assertSame(0, $code, $stderr);
        $this->assertSame([
            'release view v1.1.0 --json isDraft --jq .isDraft',
            'release create v1.1.0 --verify-tag --title Ansilume 1.1.0 --notes-file RUNNER_TEMP/notes.md --latest=true ansilume-1.1.0.tar.gz ansilume-1.1.0.tar.gz.sigstore.json',
        ], $calls);
        $this->assertSame('bundle', (string)file_get_contents($work . '/ansilume-1.1.0.tar.gz.sigstore.json'));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: string}>
     */
    public static function latestProvider(): array
    {
        return [
            'the highest version' => ['v1.1.0', ['v1.0.0', 'v1.1.0'], 'true'],
            'an older version' => ['v1.0.1', ['v1.0.0', 'v1.0.1', 'v1.1.0'], 'false'],
            'v1.10.0 above v1.9.0' => ['v1.10.0', ['v1.9.0', 'v1.10.0'], 'true'],
            'no candidate or other tag above it' => ['v1.1.0', ['v1.0.0', 'v1.1.0', 'v1.2.0-rc1', 'vendor-snapshot'], 'true'],
        ];
    }

    /**
     * @param list<string> $tags
     * @dataProvider latestProvider
     */
    public function testOnlyTheHighestVersionBecomesTheLatestRelease(string $tag, array $tags, string $latest): void
    {
        [$code, $calls, $stderr] = $this->runPublish($tag, $tags, 'none');

        $this->assertSame(0, $code, $stderr);
        $this->assertStringContainsString("--latest={$latest} ", $calls[1] ?? '');
    }

    /**
     * A run that was stopped after gh created the draft leaves the draft
     * behind; running the job again uploads to it and publishes it.
     */
    public function testADraftGetsTheArchiveAndIsPublished(): void
    {
        [$code, $calls, $stderr] = $this->runPublish('v1.1.0', ['v1.0.0', 'v1.1.0'], 'draft');

        $this->assertSame(0, $code, $stderr);
        $this->assertSame([
            'release view v1.1.0 --json isDraft --jq .isDraft',
            'release upload v1.1.0 ansilume-1.1.0.tar.gz ansilume-1.1.0.tar.gz.sigstore.json --clobber',
            'release edit v1.1.0 --draft=false --latest=true',
        ], $calls);
    }

    /**
     * A published release is immutable: GitHub refuses new assets, so the
     * run stops with a clear error and touches nothing.
     */
    public function testAPublishedReleaseIsLeftAlone(): void
    {
        [$code, $calls, , , $stdout] = $this->runPublish('v1.1.0', ['v1.0.0', 'v1.1.0'], 'published');

        $this->assertSame(1, $code);
        $this->assertSame(['release view v1.1.0 --json isDraft --jq .isDraft'], $calls);
        $this->assertStringContainsString('::error::v1.1.0 already has a published release', $stdout);
    }

    public function testTheReleaseCarriesItsNotesAndASignedSourceArchive(): void
    {
        $steps = $this->job()['steps'];

        $this->assertSame(0, $this->step('Checkout')['with']['fetch-depth'] ?? null, 'The notes need every tag and commit.');
        $this->assertStringStartsWith('actions/checkout@', (string)($this->step('Checkout')['uses'] ?? ''));
        $this->assertSame('git archive --format=tar.gz --prefix="ansilume-${TAG#v}/" -o "ansilume-${TAG#v}.tar.gz" "${TAG}"', trim((string)($this->step('Build the source archive')['run'] ?? '')));

        $attest = $this->step('Attest the source archive');
        $this->assertStringStartsWith('actions/attest@', (string)($attest['uses'] ?? ''), 'pinned to a commit, WorkflowActionPinTest');
        $this->assertSame('attest', $attest['id'] ?? null);
        $this->assertSame(['subject-path' => 'ansilume-*.tar.gz'], $attest['with'] ?? null);
        $this->assertSame('${{ steps.attest.outputs.bundle-path }}', $this->step('Create or publish the release')['env']['BUNDLE'] ?? null);
        $this->assertSame(
            ['Check the tag', 'Checkout', 'Fetch the annotated tags', 'Find the image digests', 'Write the release notes', 'Build the source archive', 'Attest the source archive', 'Create or publish the release'],
            array_map(static fn (array $step): string => (string)($step['name'] ?? ''), $steps)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function previousReleaseProvider(): array
    {
        return [
            'the previous version has a release' => ['v1.0.1\nv1.0.0', 'v1.0.2', '## Changes since v1.0.1'],
            'its run failed, so it has none' => ['v1.0.0', 'v1.0.2', "## v1.0.1\n\nv1.0.1 got no release of its own"],
            'a newer line is released already' => ['v1.0.0\nv1.0.1\nv1.1.0', 'v1.0.2', '## Changes since v1.0.1'],
        ];
    }

    /**
     * The text starts from the previous version with a published GitHub
     * release, so a version whose run failed reaches the next release.
     *
     * @dataProvider previousReleaseProvider
     */
    public function testTheNotesStartFromThePreviousRelease(string $released, string $tag, string $expected): void
    {
        $repository = $this->repository();
        $repository->commit('feat: first', ['VERSION' => "1.0.0\n"]);
        $repository->git(['tag', '-a', 'v1.0.0', '-m', 'Release v1.0.0']);
        $repository->commit('fix: one', ['one.txt' => "1\n"]);
        $repository->git(['tag', '-a', 'v1.0.1', '-m', 'Release v1.0.1', '-m', 'Rotate the runner tokens.']);
        $repository->commit('fix: two', ['two.txt' => "2\n"]);
        $repository->git(['tag', '-a', 'v1.0.2', '-m', 'Release v1.0.2']);
        mkdir($repository->work . '/bin');
        copy(dirname(__DIR__, 3) . '/bin/release-notes', $repository->work . '/bin/release-notes');
        chmod($repository->work . '/bin/release-notes', 0o755);
        $runnerTemp = $this->directory();
        file_put_contents($runnerTemp . '/images.txt', "ghcr.io/ansilume/ansilume:1.0.2 sha256:1111\n");

        [$code, , $stderr] = $this->runStep('Write the release notes', [
            'TAG' => $tag,
            'RUNNER_TEMP' => $runnerTemp,
            'STUB_CALLS' => $runnerTemp . '/gh-calls',
            'STUB_RELEASED' => str_replace('\n', "\n", $released) . "\n",
        ], $repository->work);

        $this->assertSame(0, $code, $stderr);
        $this->assertStringContainsString($expected, (string)file_get_contents($runnerTemp . '/notes.md'));
        $this->assertSame("release list --exclude-drafts --limit 1000 --json tagName --jq .[].tagName\n", file_get_contents($runnerTemp . '/gh-calls'));
    }

    /**
     * Runs the publishing step for $tag in a repository with $tags, with a
     * gh that answers "release view" as if the release were none, a draft
     * or published.
     *
     * @param list<string> $tags
     * @return array{0: int, 1: list<string>, 2: string, 3: string, 4: string} exit code, gh calls, stderr, working tree, stdout
     */
    private function runPublish(string $tag, array $tags, string $release): array
    {
        $repository = $this->repository();
        $repository->commit('feat: first', ['VERSION' => "1.0.0\n"]);
        foreach ($tags as $name) {
            $repository->git(['tag', $name]);
        }
        $runnerTemp = $this->directory();
        $bundle = $runnerTemp . '/bundle.json';
        file_put_contents($bundle, 'bundle');
        $calls = $runnerTemp . '/gh-calls';

        [$code, $stdout, $stderr] = $this->runStep('Create or publish the release', [
            'TAG' => $tag,
            'BUNDLE' => $bundle,
            'RUNNER_TEMP' => $runnerTemp,
            'STUB_CALLS' => $calls,
            'STUB_RELEASE' => $release,
        ], $repository->work);

        $logged = is_file($calls) ? explode("\n", trim((string)file_get_contents($calls))) : [];

        return [$code, str_replace($runnerTemp, 'RUNNER_TEMP', $logged), $stderr, $repository->work, $stdout];
    }

    /**
     * @param array<string, string> $stub
     * @return array{0: int, 1: string, 2: string, 3: string} exit code, stdout, stderr, RUNNER_TEMP
     */
    private function runImageDigests(array $stub): array
    {
        $runnerTemp = $this->directory();
        [$code, $stdout, $stderr] = $this->runStep('Find the image digests', $stub + [
            'TAG' => 'v2.12.2',
            'GITHUB_SHA' => self::COMMIT,
            'RUNNER_TEMP' => $runnerTemp,
            'STUB_DIGEST' => 'sha256:' . str_repeat('a', 64),
            'STUB_REVISIONS' => self::COMMIT . ' ' . self::COMMIT . ' ',
        ]);

        return [$code, $stdout, $stderr, $runnerTemp];
    }

    /**
     * Runs the run script of the step named $name with bash -eo pipefail
     * (shell: bash), with stand-ins for docker and gh on the PATH.
     *
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: string}
     */
    private function runStep(string $name, array $env, ?string $cwd = null): array
    {
        $script = (string)($this->step($name)['run'] ?? $this->fail("Step {$name} has no run script."));

        return $this->runScript($script, $env, [
            'docker' => <<<'BASH'
#!/usr/bin/env bash
# docker buildx imagetools inspect <ref> --format <template>
ref="$4"
if [[ "$ref" == *"/${STUB_MISSING:-none}:"* ]]; then
    echo "ERROR: ${ref}: not found" >&2
    exit 1
fi
case "$6" in
    *Manifest.Digest*) output="$STUB_DIGEST" ;;
    *image.revision*) output="$STUB_REVISIONS" ;;
    *) echo "unexpected template: $6" >&2; exit 2 ;;
esac
# A registry error in the call whose template holds STUB_FAILING, after its
# output when STUB_PARTIAL is set.
if [[ -n "${STUB_FAILING:-}" && "$6" == *"${STUB_FAILING}"* ]]; then
    if [[ -n "${STUB_PARTIAL:-}" ]]; then
        echo "$output"
    fi
    echo "ERROR: failed to fetch ${ref}: 502 Bad Gateway" >&2
    exit 1
fi
echo "$output"
BASH,
            'gh' => <<<'BASH'
#!/usr/bin/env bash
echo "$*" >> "$STUB_CALLS"
if [[ "$1 $2" == "release list" ]]; then
    printf '%s' "$STUB_RELEASED"
fi
if [[ "$1 $2" == "release view" ]]; then
    case "$STUB_RELEASE" in
        none) echo "release not found" >&2; exit 1 ;;
        draft) echo true ;;
        published) echo false ;;
    esac
fi
BASH,
        ], $cwd);
    }

    private function repository(): ReleaseRepositoryFixture
    {
        return new ReleaseRepositoryFixture($this->directory() . '/repository');
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $name): array
    {
        foreach ($this->job()['steps'] as $step) {
            if (($step['name'] ?? null) === $name) {
                return $step;
            }
        }
        $this->fail("github-release.yml has no step named {$name}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function job(): array
    {
        $jobs = $this->workflow(self::GITHUB_RELEASE)['jobs'] ?? [];
        $this->assertCount(1, $jobs, 'github-release.yml has one job.');

        return reset($jobs);
    }
}
