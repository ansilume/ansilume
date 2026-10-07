<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\VaultAssignmentResult;
use PHPUnit\Framework\TestCase;

/**
 * Outcome of assigning one vault password to several job templates: the
 * counts per status, the summary the web UI shows as flash message, and the
 * shape the REST API returns.
 */
class VaultAssignmentResultTest extends TestCase
{
    private const VAULT_NAME = 'prod-vault';

    public function testCountsHaveEveryStatusAndStartAtZero(): void
    {
        $result = new VaultAssignmentResult(7, self::VAULT_NAME, []);

        $this->assertSame(
            [
                VaultAssignmentResult::ASSIGNED => 0,
                VaultAssignmentResult::REPLACED => 0,
                VaultAssignmentResult::UNCHANGED => 0,
                VaultAssignmentResult::FAILED => 0,
            ],
            $result->counts()
        );
    }

    public function testCountsEachTemplateUnderItsStatus(): void
    {
        $result = new VaultAssignmentResult(7, self::VAULT_NAME, [
            self::item(1, 'web', VaultAssignmentResult::ASSIGNED),
            self::item(2, 'db', VaultAssignmentResult::REPLACED, null, [['id' => 9, 'name' => 'old-vault']]),
            self::item(3, 'cache', VaultAssignmentResult::ASSIGNED),
            self::item(4, 'lb', VaultAssignmentResult::UNCHANGED),
            self::item(5, 'broken', VaultAssignmentResult::FAILED, 'Extra_vars must be valid JSON.'),
            self::item(6, 'mail', VaultAssignmentResult::ASSIGNED),
        ]);

        $this->assertSame(
            [
                VaultAssignmentResult::ASSIGNED => 3,
                VaultAssignmentResult::REPLACED => 1,
                VaultAssignmentResult::UNCHANGED => 1,
                VaultAssignmentResult::FAILED => 1,
            ],
            $result->counts()
        );
    }

    /**
     * Each entry: the templates as [status, name, error], and the summary.
     *
     * @return array<string, array{0: list<array{0: string, 1?: string, 2?: string}>, 1: string}>
     */
    public static function summaryProvider(): array
    {
        return [
            'assigned only' => [
                [[VaultAssignmentResult::ASSIGNED], [VaultAssignmentResult::ASSIGNED]],
                'Vault password "prod-vault": assigned to 2 job template(s).',
            ],
            'replaced only' => [
                [[VaultAssignmentResult::REPLACED]],
                'Vault password "prod-vault": replaced another vault password on 1.',
            ],
            'unchanged only' => [
                [[VaultAssignmentResult::UNCHANGED], [VaultAssignmentResult::UNCHANGED], [VaultAssignmentResult::UNCHANGED]],
                'Vault password "prod-vault": 3 already had it.',
            ],
            'assigned and replaced' => [
                [[VaultAssignmentResult::REPLACED], [VaultAssignmentResult::ASSIGNED], [VaultAssignmentResult::REPLACED]],
                'Vault password "prod-vault": assigned to 1 job template(s), replaced another vault password on 2.',
            ],
            'assigned and unchanged' => [
                [[VaultAssignmentResult::UNCHANGED], [VaultAssignmentResult::ASSIGNED]],
                'Vault password "prod-vault": assigned to 1 job template(s), 1 already had it.',
            ],
            'replaced and unchanged' => [
                [[VaultAssignmentResult::REPLACED], [VaultAssignmentResult::UNCHANGED], [VaultAssignmentResult::UNCHANGED]],
                'Vault password "prod-vault": replaced another vault password on 1, 2 already had it.',
            ],
            'one failure only' => [
                [[VaultAssignmentResult::FAILED, 'web', 'Extra_vars must be valid JSON.']],
                'Vault password "prod-vault": 1 failed: "web" (Extra_vars must be valid JSON.).',
            ],
            'unchanged and a failure' => [
                [[VaultAssignmentResult::UNCHANGED], [VaultAssignmentResult::FAILED, 'db', 'Survey_fields must be valid JSON.']],
                'Vault password "prod-vault": 1 already had it, 1 failed: "db" (Survey_fields must be valid JSON.).',
            ],
            'every status, failures in request order' => [
                [
                    [VaultAssignmentResult::FAILED, 'web', 'Extra_vars must be valid JSON.'],
                    [VaultAssignmentResult::ASSIGNED],
                    [VaultAssignmentResult::UNCHANGED],
                    [VaultAssignmentResult::REPLACED],
                    [VaultAssignmentResult::FAILED, 'db', 'Extra_vars must be valid JSON. Survey_fields must be valid JSON.'],
                    [VaultAssignmentResult::REPLACED],
                ],
                'Vault password "prod-vault": assigned to 1 job template(s), replaced another vault password on 2, 1 already had it, '
                . '2 failed: "web" (Extra_vars must be valid JSON.); "db" (Extra_vars must be valid JSON. Survey_fields must be valid JSON.).',
            ],
        ];
    }

    /**
     * @dataProvider summaryProvider
     * @param list<array{0: string, 1?: string, 2?: string}> $specs
     */
    public function testSummaryNamesEveryStatusThatOccurs(array $specs, string $expected): void
    {
        $items = [];
        foreach ($specs as $index => $spec) {
            $items[] = self::item($index + 1, $spec[1] ?? 'template-' . ($index + 1), $spec[0], $spec[2] ?? null);
        }

        $this->assertSame($expected, (new VaultAssignmentResult(7, self::VAULT_NAME, $items))->summary());
    }

    public function testToArrayCarriesTheCredentialTheResultsInOrderAndTheCounts(): void
    {
        $items = [
            self::item(12, 'web', VaultAssignmentResult::REPLACED, null, [['id' => 9, 'name' => 'old-vault'], ['id' => 10, 'name' => 'older-vault']]),
            self::item(4, 'db', VaultAssignmentResult::ASSIGNED),
            self::item(8, 'broken', VaultAssignmentResult::FAILED, 'Extra_vars must be valid JSON.'),
            self::item(5, 'lb', VaultAssignmentResult::UNCHANGED),
        ];

        $this->assertSame(
            [
                'credential_id' => 7,
                'credential_name' => self::VAULT_NAME,
                'results' => $items,
                'summary' => [
                    VaultAssignmentResult::ASSIGNED => 1,
                    VaultAssignmentResult::REPLACED => 1,
                    VaultAssignmentResult::UNCHANGED => 1,
                    VaultAssignmentResult::FAILED => 1,
                ],
            ],
            (new VaultAssignmentResult(7, self::VAULT_NAME, $items))->toArray()
        );
    }

    /**
     * @param list<array{id: int, name: string}> $replaced
     * @return array{job_template_id: int, name: string, status: string, replaced: list<array{id: int, name: string}>, error: string|null}
     */
    private static function item(int $id, string $name, string $status, ?string $error = null, array $replaced = []): array
    {
        return ['job_template_id' => $id, 'name' => $name, 'status' => $status, 'replaced' => $replaced, 'error' => $error];
    }
}
