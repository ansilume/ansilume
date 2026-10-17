<?php

declare(strict_types=1);

namespace app\components;

use app\models\Inventory;
use app\models\Job;

/**
 * The inventory a job was launched with, as its runner payload records it
 * (inventory_id, and since 2.12.0 Job::PAYLOAD_INVENTORY_NAME): the claim,
 * the approval requests, the pages and the API name it so, also once the
 * template uses another inventory, or the inventory was renamed or deleted.
 *
 * The name is the one the payload recorded, else the current row's (jobs
 * launched before 2.12.0), else unknown. A job without an inventory in its
 * payload, such as the placeholder of a workflow approval step, has none.
 *
 * The decoded payload carries the launch's extra vars with their survey
 * answers, so every parameter that takes it is marked #[\SensitiveParameter].
 */
final class JobInventory
{
    /** The label of a job without an inventory. */
    public const NONE = '—';

    /** What the pages add to the label of an inventory whose row is gone. */
    public const DELETED = '(deleted)';

    /** The label of an inventory whose name is unknown; sprintf(): its ID. */
    public const UNNAMED = 'Inventory #%d';

    /**
     * @param int|null $id the inventory's ID; null when the job names none
     * @param string|null $name its name at launch, else its current name; null when unknown
     * @param bool $exists whether its row still exists
     */
    private function __construct(
        public readonly ?int $id,
        public readonly ?string $name,
        public readonly bool $exists
    ) {
    }

    /**
     * A job without an inventory.
     */
    public static function none(): self
    {
        return new self(null, null, false);
    }

    /**
     * The inventory ID that $payload (a decoded job.runner_payload) records:
     * a positive int, or a string of digits only; null for anything else
     * (a bool, a float, a list, 0, a negative number, none).
     *
     * @param array<string, mixed> $payload
     */
    public static function idOf(#[\SensitiveParameter] array $payload): ?int
    {
        $value = $payload['inventory_id'] ?? null;
        if (is_string($value) && ctype_digit($value)) {
            $value = (int)$value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * The inventory $job was launched with; none() for no job. At most one
     * query, and none when its payload names no inventory.
     */
    public static function ofJob(?Job $job): self
    {
        if ($job === null) {
            return self::none();
        }
        $key = (int)$job->id;

        return self::ofPayloads([$key => $job->decodedRunnerPayload()])[$key];
    }

    /**
     * The inventory of each job, from its decoded runner payload, keyed as
     * $payloads (by job ID). One query for all of them, and none when no
     * payload names an inventory.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @return array<int, self>
     */
    public static function ofPayloads(#[\SensitiveParameter] array $payloads): array
    {
        $launched = [];
        foreach ($payloads as $jobId => $payload) {
            $launched[$jobId] = ['id' => self::idOf($payload), 'name' => self::launchedName($payload)];
        }
        $current = self::currentNames(array_column($launched, 'id'));
        $inventories = [];
        foreach ($launched as $jobId => $inventory) {
            $id = $inventory['id'];
            $inventories[$jobId] = $id === null
                ? self::none()
                : new self($id, $inventory['name'] ?? $current[$id] ?? null, array_key_exists($id, $current));
        }

        return $inventories;
    }

    /**
     * The inventory's name, else UNNAMED with its ID, else NONE.
     */
    public function label(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        return $this->id === null ? self::NONE : sprintf(self::UNNAMED, $this->id);
    }

    /**
     * The API's fields of the inventory: its ID and name, null when unknown.
     *
     * @return array{inventory_id: int|null, inventory_name: string|null}
     */
    public function apiFields(): array
    {
        return ['inventory_id' => $this->id, 'inventory_name' => $this->name];
    }

    /**
     * The inventory name $payload recorded at launch: a string that is not
     * empty; null for anything else (jobs launched before 2.12.0 have none).
     *
     * @param array<string, mixed> $payload
     */
    private static function launchedName(#[\SensitiveParameter] array $payload): ?string
    {
        $name = $payload[Job::PAYLOAD_INVENTORY_NAME] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The current name of each inventory of $ids that exists, by ID: one
     * query, none without IDs.
     *
     * @param list<int|null> $ids
     * @return array<int, string|null>
     */
    private static function currentNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null)));
        if ($ids === []) {
            return [];
        }
        /** @var list<array{id: int|string, name: string|null}> $rows */
        $rows = Inventory::find()->select(['id', 'name'])->where(['id' => $ids])->asArray()->all();
        $names = [];
        foreach ($rows as $row) {
            $names[(int)$row['id']] = is_string($row['name']) && $row['name'] !== '' ? $row['name'] : null;
        }

        return $names;
    }
}
