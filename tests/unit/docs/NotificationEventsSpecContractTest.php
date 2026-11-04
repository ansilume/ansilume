<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\models\NotificationTemplate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The API spec of a notification template lists every event a template may
 * listen to (NotificationTemplate::eventLabels()), and says how many there
 * are.
 *
 * Regression (workflow notifications plan, Task 8): the list said "(15)"
 * while it named 18 events, and nothing noticed when an event was added.
 */
class NotificationEventsSpecContractTest extends TestCase
{
    use ReadsOperatorDocs;

    public function testTheSpecListsEveryEventATemplateMayListenTo(): void
    {
        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $description = $spec['components']['schemas']['NotificationTemplate']['description'] ?? null;
        $this->assertIsString($description, 'web/openapi.yaml describes the NotificationTemplate schema');
        $flat = trim((string)preg_replace('/\s+/', ' ', $description));

        $this->assertSame(1, preg_match('/Supported events \((\d+)\): ([a-z_., ]+)\.$/', $flat, $match), 'the list ends the description');
        $listed = array_map('trim', explode(',', $match[2]));
        $events = array_keys(NotificationTemplate::eventLabels());
        $this->assertSame(count($events), (int)$match[1], 'the count the spec says');
        $this->assertSame(count($listed), (int)$match[1], 'the count of the events the spec names');
        $this->assertEqualsCanonicalizing($events, $listed);
    }
}
