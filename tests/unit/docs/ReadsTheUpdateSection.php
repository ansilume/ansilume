<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

/**
 * Reads the section of docs/updating.md for the update from 2.10.1
 * (WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION) for the doc
 * contract tests: an item under its bold title and a step of its checklist,
 * each on one line. Use together with ReadsOperatorDocs.
 */
trait ReadsTheUpdateSection
{
    /**
     * The item of the section that starts with the bold title $title: its
     * paragraph and the list items, paragraphs and code blocks after it, up
     * to the next bold title, on one line.
     */
    private function item(string $title): string
    {
        $item = [];
        foreach ($this->units('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION) as $unit) {
            if ($item === [] && !str_starts_with($unit, $title)) {
                continue;
            }
            if ($item !== [] && str_starts_with($unit, '**')) {
                break;
            }
            $item[] = $unit;
        }
        $this->assertNotEmpty($item, "updating.md has no item {$title}");

        return implode(' ', $item);
    }

    /**
     * Step $number of the section's checklist ("What to do"), on one line.
     */
    private function checklist(int $number): string
    {
        foreach ($this->units('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION) as $unit) {
            if (str_starts_with($unit, $number . '. ')) {
                return $unit;
            }
        }
        $this->fail("The checklist of updating.md has no step {$number}.");
    }
}
