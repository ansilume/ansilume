<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\AnalyticsQuery;
use PHPUnit\Framework\TestCase;

class AnalyticsQueryTest extends TestCase
{
    public function testDefaultGranularity(): void
    {
        $q = new AnalyticsQuery();
        $this->assertSame(AnalyticsQuery::GRANULARITY_DAILY, $q->granularity);
    }

    public function testValidGranularityValues(): void
    {
        $q = new AnalyticsQuery();
        $q->granularity = 'daily';
        $this->assertTrue($q->validate(['granularity']));

        $q->granularity = 'weekly';
        $this->assertTrue($q->validate(['granularity']));
    }

    public function testInvalidGranularityRejected(): void
    {
        $q = new AnalyticsQuery();
        $q->granularity = 'hourly';
        $this->assertFalse($q->validate(['granularity']));
    }

    public function testValidDateRange(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2026-01-01';
        $q->date_to = '2026-01-31';
        $this->assertTrue($q->validate());
    }

    public function testDateRangeExceeds365DaysRejected(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2025-01-01';
        $q->date_to = '2026-02-01';
        $this->assertFalse($q->validate());
        $this->assertTrue($q->hasErrors('date_to'));
    }

    public function testEndDateBeforeStartDateRejected(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2026-03-15';
        $q->date_to = '2026-03-01';
        $this->assertFalse($q->validate());
        $this->assertTrue($q->hasErrors('date_to'));
    }

    public function testApplyDefaultsSets30Days(): void
    {
        $q = new AnalyticsQuery();
        $this->assertNull($q->date_from);
        $this->assertNull($q->date_to);

        $q->applyDefaults();
        $this->assertSame(date('Y-m-d', strtotime('-30 days')), $q->date_from);
        $this->assertSame(date('Y-m-d'), $q->date_to);
    }

    public function testApplyDefaultsDoesNotOverrideExisting(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2026-01-01';
        $q->date_to = '2026-01-15';
        $q->applyDefaults();
        $this->assertSame('2026-01-01', $q->date_from);
        $this->assertSame('2026-01-15', $q->date_to);
    }

    public function testDateFromTimestamp(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2026-03-01';
        $this->assertSame(strtotime('2026-03-01'), $q->dateFromTimestamp);
    }

    public function testDateToTimestampIncludesEndOfDay(): void
    {
        $q = new AnalyticsQuery();
        $q->date_to = '2026-03-01';
        $this->assertSame(strtotime('2026-03-01 23:59:59'), $q->dateToTimestamp);
    }

    public function testNullDatesReturnZeroTimestamp(): void
    {
        $q = new AnalyticsQuery();
        $this->assertSame(0, $q->dateFromTimestamp);
        $this->assertSame(0, $q->dateToTimestamp);
    }

    public function testToArrayReturnsAllFields(): void
    {
        $q = new AnalyticsQuery();
        $q->date_from = '2026-01-01';
        $q->date_to = '2026-01-31';
        $q->project_id = 5;
        $q->granularity = 'weekly';

        $arr = $q->toArray();
        $this->assertSame('2026-01-01', $arr['date_from']);
        $this->assertSame('2026-01-31', $arr['date_to']);
        $this->assertSame(5, $arr['project_id']);
        $this->assertSame('weekly', $arr['granularity']);
        $this->assertNull($arr['template_id']);
        $this->assertNull($arr['user_id']);
    }

    public function testIntegerFieldsAcceptNull(): void
    {
        $q = new AnalyticsQuery();
        $q->project_id = null;
        $q->template_id = null;
        $q->user_id = null;
        $q->runner_group_id = null;
        $q->applyDefaults();
        $this->assertTrue($q->validate());
    }

    public function testValidIntegerFields(): void
    {
        $q = new AnalyticsQuery();
        $q->project_id = 1;
        $q->template_id = 2;
        $q->user_id = 3;
        $q->runner_group_id = 4;
        $q->applyDefaults();
        $this->assertTrue($q->validate());
    }

    public function testGranularityConstants(): void
    {
        $this->assertSame('daily', AnalyticsQuery::GRANULARITY_DAILY);
        $this->assertSame('weekly', AnalyticsQuery::GRANULARITY_WEEKLY);
    }

    public function testNullDatesPassValidation(): void
    {
        $q = new AnalyticsQuery();
        // Both null — validateDateRange should short-circuit
        $this->assertTrue($q->validate());
    }
}
