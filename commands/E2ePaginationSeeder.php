<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Webhook;

/**
 * Seeds a known number of `e2e-pag-NNN` webhook rows so /webhook/index
 * spans multiple paginator pages (pageSize 25) and the matching spec can
 * walk First/Prev/Next/Last + client-side filter against predictable data.
 *
 * Webhooks are the chosen target because no other E2E spec asserts specific
 * page-1 content on /webhook/index (only presence-by-row-text of
 * e2e-crud-webhook, which always lands on page 1 thanks to id DESC
 * ordering). Projects and Inventories were tried first but their indexes
 * are read by resource-isolation and sync-lint specs that *do* expect the
 * seeded fixture rows on page 1.
 *
 * Teardown is handled by the prefix-based E2eTeardownHelper.
 */
class E2ePaginationSeeder
{
    /**
     * Row count chosen so pageSize 25 yields two pages with a comfortable
     * margin over the few other webhooks in the seed set.
     */
    public const ROW_COUNT = 30;

    public const NAME_PREFIX = 'e2e-pag-';

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    public function seed(int $userId): void
    {
        $existingCount = (int)Webhook::find()
            ->where(['like', 'name', self::NAME_PREFIX])
            ->count();
        if ($existingCount >= self::ROW_COUNT) {
            ($this->logger)("  Pagination rows already seeded ({$existingCount} of " . self::ROW_COUNT . ").\n");
            return;
        }

        $created = 0;
        for ($i = 1; $i <= self::ROW_COUNT; $i++) {
            $name = self::NAME_PREFIX . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            if (Webhook::find()->where(['name' => $name])->exists()) {
                continue;
            }
            $webhook = new Webhook();
            $webhook->name = $name;
            $webhook->url = 'https://example.com/e2e-pag-' . $i;
            $webhook->events = 'job.success';
            $webhook->enabled = false;
            $webhook->created_by = $userId;
            $webhook->save(false);
            $created++;
        }

        ($this->logger)("  Seeded {$created} paginator fixture webhook row(s).\n");
    }
}
