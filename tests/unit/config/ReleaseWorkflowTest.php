<?php

declare(strict_types=1);

namespace app\tests\unit\config;

use app\tests\unit\bin\ReleaseRepositoryFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * .github/workflows/release.yml builds the images of a version tag, tags
 * them with the version (2.12.2), smoke-tests them on arm64 and scans them;
 * only then does it move latest and the floating tags (2.12, 2) to them,
 * each only when the version is the highest release it covers, and only
 * then is the GitHub release made.
 *
 * The run scripts of the steps run here as on GitHub (shell: bash, that is
 * bash -eo pipefail), with a stand-in for docker.
 *
 * Regressions: the merge job moved latest before the smoke test, so a
 * release that failed it was latest, which bin/quickstart and every
 * `docker compose pull` installed, until the next release; no scan stopped
 * a release whose images had a vulnerability with a fix; every job could
 * push packages.
 */
class ReleaseWorkflowTest extends TestCase
{
    use RunsWorkflowSteps;

    private const RELEASE = '.github/workflows/release.yml';

    private const IMAGE = 'ghcr.io/ansilume/ansilume';

    private const DIGEST = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * metadata-action adds latest to a semver tag unless latest=false.
     */
    public function testRegressionTheMergeJobTagsTheVersionOnly(): void
    {
        $metadata = $this->step('merge', 'Extract metadata');

        $this->assertSame('type=semver,pattern={{version}}', trim((string)($metadata['with']['tags'] ?? '')));
        $this->assertSame('latest=false', $metadata['with']['flavor'] ?? null);
    }

    public function testTheJobsRunInOrder(): void
    {
        $jobs = $this->workflow(self::RELEASE)['jobs'];
        $needs = array_map(static fn (array $job): array => (array)($job['needs'] ?? []), $jobs);

        $this->assertSame([
            'build' => [],
            'merge' => ['build'],
            'smoke-test-arm64' => ['merge'],
            'scan' => ['merge'],
            'promote' => ['smoke-test-arm64', 'scan'],
            'github-release' => ['promote'],
        ], $needs);
    }

    /**
     * Regression found in review: the order above holds only while a failed
     * job stops the jobs that need it. `if: always()` on promote,
     * continue-on-error on the scan or the smoke test, or a status function
     * in the condition of the GitHub release would let them run after a
     * failed check, and every other test would stay green.
     */
    public function testAFailedCheckStopsThePromotionAndTheRelease(): void
    {
        $jobs = $this->workflow(self::RELEASE)['jobs'];

        foreach (['smoke-test-arm64', 'scan', 'promote', 'github-release'] as $name) {
            $this->assertArrayNotHasKey('continue-on-error', $jobs[$name], $name);
            $this->assertDoesNotMatchRegularExpression('/\b(?:always|failure|cancelled|success)\s*\(/', (string)($jobs[$name]['if'] ?? ''), "{$name}: a status function replaces the implicit success()");
        }
        foreach (['smoke-test-arm64', 'scan', 'promote'] as $name) {
            foreach ($jobs[$name]['steps'] as $step) {
                $this->assertArrayNotHasKey('continue-on-error', $step, "{$name}: " . ($step['name'] ?? '?'));
            }
        }
        $conditions = array_filter(array_map(static fn (array $job): string => (string)($job['if'] ?? ''), $jobs));
        $this->assertSame(['github-release' => "\${{ !contains(github.ref_name, '-') }}"], $conditions, 'Only the GitHub release has a condition: a release candidate gets none.');
    }

    /**
     * Regression found in review: a step skipped by a condition, or an image
     * left out of the scan or the promotion, kept every test green while
     * latest moved to images that were not checked.
     */
    public function testEveryCheckRunsForEveryImage(): void
    {
        $jobs = $this->workflow(self::RELEASE)['jobs'];
        $images = static fn (array $job): array => array_column((array)($job['strategy']['matrix']['target'] ?? []), 'image');

        $pushed = $images($jobs['merge']);
        $this->assertSame(['ansilume/ansilume', 'ansilume/ansilume-nginx', 'ansilume/ansilume-runner'], $pushed);
        foreach (['build', 'scan', 'promote'] as $name) {
            $this->assertSame($pushed, $images($jobs[$name]), "{$name} covers every image the merge job pushes");
        }
        foreach (['smoke-test-arm64', 'scan', 'promote'] as $name) {
            foreach ($jobs[$name]['steps'] as $step) {
                if (($step['name'] ?? null) === 'Dump logs on failure') {
                    $this->assertSame('failure()', $step['if'] ?? null);
                    continue;
                }
                $this->assertArrayNotHasKey('if', $step, "{$name}: " . ($step['name'] ?? '?') . ' always runs');
            }
        }
    }

    public function testOnlyTheJobsThatPushImagesWriteToTheRegistry(): void
    {
        $release = $this->workflow(self::RELEASE);

        $this->assertSame(['contents' => 'read'], $release['permissions'] ?? null);
        $writers = [];
        foreach ($release['jobs'] as $name => $job) {
            $this->assertIsArray($job['permissions'] ?? null, "Job {$name} names the permissions it needs.");
            if (($job['permissions']['packages'] ?? null) === 'write') {
                $writers[] = $name;
            }
        }
        $this->assertSame(['build', 'merge', 'promote'], $writers);
    }

    /**
     * The smoke test runs the images of the version: latest does not name
     * them before the promote job. docker-compose.prebuilt.yml tags every
     * Ansilume image with ANSILUME_IMAGE_TAG, latest by default.
     */
    public function testTheSmokeTestRunsTheImagesOfTheVersion(): void
    {
        $env = (string)($this->step('smoke-test-arm64', 'Create .env from production example')['run'] ?? '');
        $verify = (string)($this->step('smoke-test-arm64', 'Verify pulled images are arm64')['run'] ?? '');

        $this->assertStringContainsString('echo "ANSILUME_IMAGE_TAG=${GITHUB_REF_NAME#v}" >> .env', $env);
        $this->assertStringContainsString('ref="${REGISTRY}/ansilume/${image}:${GITHUB_REF_NAME#v}"', $verify);
        $this->assertStringNotContainsString(':latest', $verify);

        $compose = Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.prebuilt.yml');
        $this->assertIsArray($compose);
        $images = [];
        foreach ($compose['services'] as $name => $service) {
            $image = (string)($service['image'] ?? '');
            if (str_starts_with($image, 'ghcr.io/')) {
                $this->assertStringEndsWith(':${ANSILUME_IMAGE_TAG:-latest}', $image, (string)$name);
                $images[] = strstr($image, ':', true);
            }
        }
        $this->assertSame(['ghcr.io/ansilume/ansilume', 'ghcr.io/ansilume/ansilume-nginx', 'ghcr.io/ansilume/ansilume-runner'], array_values(array_unique($images)));
    }

    public function testTheScanUsesAPinnedGrype(): void
    {
        $this->assertMatchesRegularExpression(
            '/^anchore\/grype:v\d+\.\d+\.\d+@sha256:[0-9a-f]{64}$/',
            (string)($this->workflow(self::RELEASE)['env']['GRYPE_IMAGE'] ?? ''),
        );
        $this->assertSame(['run' => ['shell' => 'bash']], $this->job('scan')['defaults'] ?? null);
    }

    public function testTheScanChecksBothPlatformsOfTheVersion(): void
    {
        [$code, $calls] = $this->runScan('');

        $this->assertSame(0, $code);
        $this->assertSame([
            'run --rm -v CWD:/src:ro -w /src GRYPE registry:ghcr.io/ansilume/ansilume:2.12.2 --platform linux/amd64 --only-fixed --fail-on high',
            'run --rm -v CWD:/src:ro -w /src GRYPE registry:ghcr.io/ansilume/ansilume:2.12.2 --platform linux/arm64 --only-fixed --fail-on high',
        ], $calls);
    }

    /**
     * grype --fail-on high exits 2 on a fixable vulnerability of high or
     * critical severity (1 on an error); the other platform is scanned all
     * the same, so its findings are in the log too.
     */
    public function testAFindingOnOnePlatformFailsTheScan(): void
    {
        [$code, $calls, $stdout] = $this->runScan('linux/amd64');

        $this->assertSame(1, $code);
        $this->assertCount(2, $calls, 'Both platforms are scanned.');
        $this->assertStringContainsString('::error::The scan of ghcr.io/ansilume/ansilume:2.12.2 (linux/amd64) failed', $stdout);
        $this->assertStringNotContainsString('(linux/arm64) failed', $stdout);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function floatingTagProvider(): array
    {
        return [
            'the newest release' => ['v2.12.2', ['v2.11.1', 'v2.12.1', 'v2.12.2'], ['latest', '2', '2.12']],
            'the first of a new minor version' => ['v2.13.0', ['v2.12.2', 'v2.13.0'], ['latest', '2', '2.13']],
            'a re-run of an older release' => ['v2.12.1', ['v2.12.1', 'v2.12.2'], []],
            'a fix of an older minor version' => ['v2.11.2', ['v2.11.1', 'v2.11.2', 'v2.12.2'], ['2.11']],
            'a fix of an older major version' => ['v1.9.3', ['v1.9.2', 'v1.9.3', 'v2.12.2'], ['1', '1.9']],
            'v1.10.0 is above v1.9.0' => ['v1.10.0', ['v1.9.0', 'v1.10.0'], ['latest', '1', '1.10']],
            'a release candidate moves none' => ['v2.13.0-rc1', ['v2.12.2', 'v2.13.0-rc1'], []],
            'candidates do not outrank a release' => ['v2.13.0', ['v2.12.2', 'v2.13.0-rc1', 'v2.13.0', 'v2.13.1-rc1'], ['latest', '2', '2.13']],
            'v2.1.0 does not cover v2.10.0' => ['v2.1.1', ['v2.1.0', 'v2.1.1', 'v2.10.0'], ['2.1']],
            'v1 does not cover v10' => ['v1.9.3', ['v1.9.2', 'v1.9.3', 'v10.0.0'], ['1', '1.9']],
        ];
    }

    /**
     * @dataProvider floatingTagProvider
     * @param list<string> $tags
     * @param list<string> $moved
     */
    public function testRegressionLatestAndTheFloatingTagsMoveOnlyToTheHighestRelease(string $tag, array $tags, array $moved): void
    {
        [$code, $calls, $stdout, $stderr] = $this->runPromote($tag, $tags, '');

        $this->assertSame(0, $code, $stderr);
        if ($moved === []) {
            $this->assertSame([], $calls, 'No tag moves.');
            $this->assertStringContainsString("{$tag} is not the highest release of its line", $stdout);

            return;
        }
        $version = substr($tag, 1);
        $targets = implode(' ', array_map(static fn (string $name): string => '-t ' . self::IMAGE . ':' . $name, $moved));
        $this->assertSame('buildx imagetools create ' . $targets . ' ' . self::IMAGE . ':' . $version, $calls[0]);
        $this->assertSame('buildx imagetools inspect ' . self::IMAGE . ':' . $version, $calls[1]);
        $this->assertCount(2 + count($moved), $calls, 'Every moved tag is checked.');
        foreach ($moved as $name) {
            $this->assertStringContainsString(self::IMAGE . ':' . $name . ' -> ' . self::DIGEST, $stdout);
        }
    }

    public function testAMovedTagThatNamesOtherImagesFailsThePromotion(): void
    {
        [$code, , $stdout] = $this->runPromote('v2.12.2', ['v2.12.1', 'v2.12.2'], '2.12');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('::error::' . self::IMAGE . ':2.12 names sha256:stale, not ' . self::DIGEST . ', the images of ' . self::IMAGE . ':2.12.2', $stdout);
    }

    /**
     * The tag reaches the new run scripts only as an environment variable.
     */
    public function testNoExpressionInTheRunScriptsOfScanAndPromote(): void
    {
        foreach (['scan', 'promote'] as $job) {
            foreach ($this->job($job)['steps'] as $step) {
                $this->assertStringNotContainsString('${{', (string)($step['run'] ?? ''), "{$job}: " . ($step['name'] ?? '?'));
            }
        }
        $this->assertSame(['run' => ['shell' => 'bash']], $this->job('promote')['defaults'] ?? null);
        $this->assertSame(0, $this->step('promote', 'Checkout')['with']['fetch-depth'] ?? null, 'The version tags.');
    }

    /**
     * @return array{0: int, 1: list<string>, 2: string, 3: string} exit code, docker calls, stdout, stderr
     */
    private function runScan(string $failing): array
    {
        $directory = $this->directory();
        $calls = $directory . '/docker-calls';
        [$code, $stdout, $stderr] = $this->runScript((string)$this->step('scan', 'Scan both platforms')['run'], [
            'GITHUB_REF_NAME' => 'v2.12.2',
            'IMAGE' => self::IMAGE,
            'GRYPE_IMAGE' => 'GRYPE',
            'STUB_CALLS' => $calls,
            'STUB_FAILING' => $failing,
        ], [
            'docker' => <<<'BASH'
#!/usr/bin/env bash
echo "$*" >> "$STUB_CALLS"
# grype --fail-on: exit 2 for the platform with a finding
if [[ " $* " == *" --platform ${STUB_FAILING:-none} "* ]]; then
    exit 2
fi
BASH,
        ], $directory);

        return [$code, $this->calls($calls, [$directory => 'CWD']), $stdout, $stderr];
    }

    /**
     * Runs the promote step for $tag in a repository with $tags, with a
     * docker that names the same index for every tag but $stale.
     *
     * @param list<string> $tags
     * @return array{0: int, 1: list<string>, 2: string, 3: string} exit code, docker calls, stdout, stderr
     */
    private function runPromote(string $tag, array $tags, string $stale): array
    {
        $repository = new ReleaseRepositoryFixture($this->directory() . '/repository');
        $repository->commit('feat: first', ['VERSION' => "1.0.0\n"]);
        foreach ($tags as $name) {
            $repository->git(['tag', $name]);
        }
        $calls = $this->directory() . '/docker-calls';
        [$code, $stdout, $stderr] = $this->runScript((string)$this->step('promote', 'Move latest and the floating tags')['run'], [
            'TAG' => $tag,
            'IMAGE' => self::IMAGE,
            'STUB_CALLS' => $calls,
            'STUB_DIGEST' => self::DIGEST,
            'STUB_STALE' => $stale,
        ], [
            'docker' => <<<'BASH'
#!/usr/bin/env bash
# docker buildx imagetools create -t <ref>... <source>
# docker buildx imagetools inspect <ref> --format '{{.Manifest.Digest}}'
if [[ "$3" == inspect ]]; then
    echo "buildx imagetools inspect $4" >> "$STUB_CALLS"
    if [[ -n "$STUB_STALE" && "$4" == *":${STUB_STALE}" ]]; then
        echo sha256:stale
    else
        echo "$STUB_DIGEST"
    fi
else
    echo "$*" >> "$STUB_CALLS"
fi
BASH,
        ], $repository->work);

        return [$code, $this->calls($calls, []), $stdout, $stderr];
    }

    /**
     * @param array<string, string> $replace
     * @return list<string>
     */
    private function calls(string $file, array $replace): array
    {
        if (!is_file($file)) {
            return [];
        }

        return explode("\n", strtr(trim((string)file_get_contents($file)), $replace));
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $job, string $name): array
    {
        foreach ($this->job($job)['steps'] as $step) {
            if (($step['name'] ?? null) === $name) {
                return $step;
            }
        }
        $this->fail("Job {$job} of release.yml has no step named {$name}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function job(string $name): array
    {
        return $this->workflow(self::RELEASE)['jobs'][$name] ?? $this->fail("release.yml has no job {$name}.");
    }
}
