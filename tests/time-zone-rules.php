<?php

/**
 * Prints the fixture of TimeZoneRulesTest for the PHP that runs it, the
 * same bytes on every run for the same time zone data; with the names of
 * time zones as arguments, prints their rules instead, a line per offset,
 * to compare two PHPs with diff (TimeZoneRules::REPORT says how).
 *
 * Write the fixture anew, from the root of the repository (TimeZoneRules::WRITE):
 *   docker compose exec -T app php tests/time-zone-rules.php > tests/fixtures/time-zone-rules.json
 *
 * It needs no autoloader, so that any PHP image can run it from a checkout.
 */

declare(strict_types=1);

use app\tests\unit\TimeZoneRules;

require __DIR__ . '/unit/TimeZoneRules.php';

$names = array_values(array_slice($_SERVER['argv'] ?? [], 1));

echo $names === []
    ? TimeZoneRules::json(TimeZoneRules::ofRunningPhp())
    : TimeZoneRules::describe(array_map('strval', $names));
