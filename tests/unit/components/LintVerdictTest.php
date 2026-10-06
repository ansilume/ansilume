<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\LintVerdict;
use PHPUnit\Framework\TestCase;

class LintVerdictTest extends TestCase
{
    /**
     * @return array<string, array{0: int|null, 1: string|null, 2: string}>
     */
    public static function resultProvider(): array
    {
        return [
            'never ran' => [null, null, LintVerdict::NOT_RUN],
            'clean' => [0, 'Passed: 0 failure(s)', LintVerdict::CLEAN],
            'vault vars_files' => [2, 'internal-error: Decryption failed (no vault secrets were found that could decrypt) on /p/vault.yml', LintVerdict::VAULT_SKIPPED],
            'findings' => [2, 'name[missing]: All tasks should be named.', LintVerdict::ISSUES],
            'failure without output' => [2, null, LintVerdict::ISSUES],
        ];
    }

    /**
     * @dataProvider resultProvider
     */
    public function testStoredResultsAreClassified(?int $exitCode, ?string $output, string $expected): void
    {
        $this->assertSame($expected, LintVerdict::of($exitCode, $output));
    }

    public function testLabelsAndBadges(): void
    {
        $this->assertSame(['not run', 'text-bg-secondary'], [LintVerdict::label(LintVerdict::NOT_RUN), LintVerdict::badgeClass(LintVerdict::NOT_RUN)]);
        $this->assertSame(['clean', 'text-bg-success'], [LintVerdict::label(LintVerdict::CLEAN), LintVerdict::badgeClass(LintVerdict::CLEAN)]);
        $this->assertSame(['issues found', 'text-bg-warning'], [LintVerdict::label(LintVerdict::ISSUES), LintVerdict::badgeClass(LintVerdict::ISSUES)]);
        $this->assertSame(
            ['not lint-checked: vault-encrypted vars_files', 'text-bg-secondary'],
            [LintVerdict::label(LintVerdict::VAULT_SKIPPED), LintVerdict::badgeClass(LintVerdict::VAULT_SKIPPED)]
        );
    }
}
