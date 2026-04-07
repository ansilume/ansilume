<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\AnalyticsQuery;
use PHPUnit\Framework\TestCase;

class AnalyticsQueryTest extends TestCase
{
    public function testValidInputPassesValidation(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2026-01-01';
        $q->date_to = '2026-01-31';
        $q->project_id = 1;
        $q->template_id = 2;
        $q->user_id = 3;
        $q->runner_group_id = 4;
        $q->granularity = AnalyticsQuery::GRANULARITY_WEEKLY;
        $this->assertTrue($q->validate());
    }

    public function testInvalidDateFormatFailsValidation(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = 'not-a-date';
        $this->assertFalse($q->validate());
        $this->assertTrue($q->hasErrors('date_from'));
    }

    public function testInvalidGranularityFails(): void
    {
        $q = new AnalyticsQuery();
        $q->granularity = 'hourly';
        $this->assertFalse($q->validate());
        $this->assertTrue($q->hasErrors('granularity'));
    }

    public function testValidateDateRangeToBeforeFrom(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2026-03-15';
        $q->date_to = '2026-03-01';
        $this->assertFalse($q->validate());
        $this->assertTrue($q->hasErrors('date_to'));
    }

    public function testValidateDateRangeExceeds365Days(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2025-01-01';
        $q->date_to = '2026-02-01';
        $this->assertFalse($q->validate());
        $this->assertTrue($q->hasErrors('date_to'));
    }

    public function testValidateDateRangeSkipsWhenNullDates(): void
    {
        $q = new AnalyticsQuery();
        // Both null — validateDateRange should short-circuit
        $this->assertTrue($q->validate());
    }

    public function testValidateDateRangeSkipsWhenInvalidDates(): void
    {
        $q = new AnalyticsQuery();
        // Set dates that pass format validation but would fail strtotime
        // We can't easily make strtotime return false for a valid Y-m-d string,
        // so test the branch where one date is null
        $q->date_from = '2026-01-01';
        $q->date_to = null;
        $this->assertTrue($q->validate());

        $q2 = new AnalyticsQuery();
        $q2->date_from = null;
        $q2->date_to = '2026-01-31';
        $this->assertTrue($q2->validate());
    }

    public function testApplyDefaultsSetsLast30Days(): void
    {
        $q = new AnalyticsQuery();
        $this->assertNull($q->date_from);
        $this->assertNull($q->date_to);

        $q->applyDefaults();

        $this->assertSame(date('Y-m-d', strtotime('-30 days')), $q->date_from);
        $this->assertSame(date('Y-m-d'), $q->date_to);
    }

    public function testApplyDefaultsDoesNotOverwriteExisting(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2026-01-01';
        $q->date_to = '2026-01-15';
        $q->applyDefaults();
        $this->assertSame('2026-01-01', $q->date_from);
        $this->assertSame('2026-01-15', $q->date_to);
    }

    public function testGetDateFromTimestampReturnsUnixTimestamp(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2026-03-01';
        $expected = strtotime('2026-03-01');
        $this->assertSame($expected, $q->dateFromTimestamp);
    }

    public function testGetDateFromTimestampReturnsZeroWhenNull(): void
    {
        $q = new AnalyticsQuery();
        $this->assertSame(0, $q->dateFromTimestamp);
    }

    public function testGetDateToTimestampReturnsEndOfDay(): void
    {
        $q = new AnalyticsQuery();
        $q->date_to = '2026-03-01';
        $expected = strtotime('2026-03-01 23:59:59');
        $this->assertSame($expected, $q->dateToTimestamp);
    }

    public function testGetDateToTimestampReturnsZeroWhenNull(): void
    {
        $q = new AnalyticsQuery();
        $this->assertSame(0, $q->dateToTimestamp);
    }

    /**
     * Regression: empty-string dropdown values ("All") must not cause a
     * TypeError when assigned to the integer filter properties (GitHub #8).
     */
    public function testEmptyStringFilterFieldsNormalisedToNull(): void
    {
        $q = new AnalyticsQuery();
        // Simulate Yii2 setAttributes from GET params with empty "All" selections
        $q->setAttributes([
            'project_id' => '',
            'template_id' => '',
            'user_id' => '',
            'runner_group_id' => '',
        ]);

        $this->assertTrue($q->validate(), 'Validation must pass with empty filter strings');
        $this->assertNull($q->project_id);
        $this->assertNull($q->template_id);
        $this->assertNull($q->user_id);
        $this->assertNull($q->runner_group_id);
    }

    public function testToArray(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2026-01-01';
        $q->date_to = '2026-01-31';
        $q->project_id = 5;
        $q->template_id = 10;
        $q->user_id = 3;
        $q->runner_group_id = 7;
        $q->granularity = AnalyticsQuery::GRANULARITY_WEEKLY;

        $arr = $q->toArray();

        $this->assertSame('2026-01-01', $arr['date_from']);
        $this->assertSame('2026-01-31', $arr['date_to']);
        $this->assertSame(5, $arr['project_id']);
        $this->assertSame(10, $arr['template_id']);
        $this->assertSame(3, $arr['user_id']);
        $this->assertSame(7, $arr['runner_group_id']);
        $this->assertSame('weekly', $arr['granularity']);
        $this->assertCount(7, $arr);
    }
}
