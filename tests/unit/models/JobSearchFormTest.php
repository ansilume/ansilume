<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Job;
use app\models\JobSearchForm;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JobSearchForm validation.
 * Extends yii\base\Model — no DB required for validation.
 */
class JobSearchFormTest extends TestCase
{
    public function testEmptyFormIsValid(): void
    {
        $form = new JobSearchForm();
        $form->validate();
        $this->assertFalse($form->hasErrors());
    }

    public function testValidStatusPassesValidation(): void
    {
        foreach (Job::statuses() as $status) {
            $form = new JobSearchForm();
            $form->status = $status;
            $form->validate(['status']);
            $this->assertFalse($form->hasErrors('status'), "Status '{$status}' should be valid");
        }
    }

    public function testEmptyStatusPassesValidation(): void
    {
        $form = new JobSearchForm();
        $form->status = '';
        $form->validate(['status']);
        $this->assertFalse($form->hasErrors('status'));
    }

    public function testInvalidStatusFailsValidation(): void
    {
        $form = new JobSearchForm();
        $form->status = 'exploded';
        $form->validate(['status']);
        $this->assertTrue($form->hasErrors('status'));
    }

    public function testValidDateFromPassesValidation(): void
    {
        $form = new JobSearchForm();
        $form->date_from = '2025-01-15';
        $form->validate(['date_from']);
        $this->assertFalse($form->hasErrors('date_from'));
    }

    public function testInvalidDateFromFailsValidation(): void
    {
        $form = new JobSearchForm();
        $form->date_from = 'not-a-date';
        $form->validate(['date_from']);
        $this->assertTrue($form->hasErrors('date_from'));
    }

    public function testValidDateToPassesValidation(): void
    {
        $form = new JobSearchForm();
        $form->date_to = '2025-12-31';
        $form->validate(['date_to']);
        $this->assertFalse($form->hasErrors('date_to'));
    }

    public function testInvalidDateToFailsValidation(): void
    {
        $form = new JobSearchForm();
        $form->date_to = '31/12/2025';
        $form->validate(['date_to']);
        $this->assertTrue($form->hasErrors('date_to'));
    }

    public function testTemplateIdAcceptsInteger(): void
    {
        $form = new JobSearchForm();
        $form->template_id = '42';
        $form->validate(['template_id']);
        $this->assertFalse($form->hasErrors('template_id'));
    }

    public function testLaunchedByAcceptsInteger(): void
    {
        $form = new JobSearchForm();
        $form->launched_by = '7';
        $form->validate(['launched_by']);
        $this->assertFalse($form->hasErrors('launched_by'));
    }

    public function testRunnerGroupIdAcceptsInteger(): void
    {
        $form = new JobSearchForm();
        $form->runner_group_id = '3';
        $form->validate(['runner_group_id']);
        $this->assertFalse($form->hasErrors('runner_group_id'));
    }

    public function testRunnerGroupIdRejectsNonNumeric(): void
    {
        $form = new JobSearchForm();
        $form->runner_group_id = 'abc';
        $form->validate(['runner_group_id']);
        $this->assertTrue($form->hasErrors('runner_group_id'));
    }

    public function testHasChangesAcceptsOneAndZero(): void
    {
        foreach (['1', '0', ''] as $value) {
            $form = new JobSearchForm();
            $form->has_changes = $value;
            $form->validate(['has_changes']);
            $this->assertFalse($form->hasErrors('has_changes'), "has_changes '{$value}' should be valid");
        }
    }

    public function testHasChangesRejectsInvalidValues(): void
    {
        $form = new JobSearchForm();
        $form->has_changes = 'yes';
        $form->validate(['has_changes']);
        $this->assertTrue($form->hasErrors('has_changes'));
    }

    /**
     * recovered takes 1 (the failures a later run recovered), 0 (those none
     * recovered) or nothing; any other value fails the rule, which the
     * search ignores: it is no filter (JobPageRecoveryTest,
     * JobsRecoveryApiTest).
     */
    public function testRecoveredTakesOneZeroOrNothing(): void
    {
        foreach ([JobSearchForm::RECOVERED_YES, JobSearchForm::RECOVERED_NO, '', null] as $value) {
            $form = new JobSearchForm();
            $form->recovered = $value;
            $this->assertTrue($form->validate(['recovered']), (string)json_encode($value));
        }
        $this->assertSame(['1', '0'], [JobSearchForm::RECOVERED_YES, JobSearchForm::RECOVERED_NO]);
        foreach (['yes', '2', ' 1', '1.0', 'true'] as $value) {
            $form = new JobSearchForm();
            $form->recovered = $value;
            $this->assertFalse($form->validate(['recovered']), $value);
        }
    }

    /**
     * finished_since takes a Unix time, a whole number of 0 or more, which
     * finishedSince() reads as an int; any other value fails the rule and
     * is no filter (null), a fraction, a sign alone, whitespace before it,
     * an exponent and a number below 0 included. The dashboard's link to
     * the recovered failures of its last 24 hours passes it.
     */
    public function testFinishedSinceTakesAUnixTime(): void
    {
        foreach (['1793770000' => 1793770000, '0' => 0, '+5' => 5, '007' => 7, "5\n" => 5] as $value => $since) {
            $form = new JobSearchForm();
            $form->finished_since = (string)$value;
            $this->assertTrue($form->validate(['finished_since']), (string)json_encode($value));
            $this->assertSame($since, $form->finishedSince(), (string)json_encode($value));
        }
        foreach ([null, ''] as $value) {
            $form = new JobSearchForm();
            $form->finished_since = $value;
            $this->assertTrue($form->validate(['finished_since']), 'no filter: ' . json_encode($value));
            $this->assertNull($form->finishedSince());
        }
        foreach (['abc', '1.5', '-1', ' 1', '1e3', '0x10', '1 2', '+', "1\0"] as $value) {
            $form = new JobSearchForm();
            $form->finished_since = $value;
            $this->assertFalse($form->validate(['finished_since']), (string)json_encode($value));
            $this->assertNull($form->finishedSince(), (string)json_encode($value));
        }
    }

    /**
     * Dates of only whitespace that trim() strips: strtotime() read " ",
     * "\t", "\n" and "  " as now on PHP 8.3 and as no date on PHP 8.4 and
     * later, and reads a NUL byte as now on every version.
     *
     * @return array<string, array{0: string}>
     */
    public static function whitespaceOnlyDateProvider(): array
    {
        return [
            'a space' => [' '],
            'a tab' => ["\t"],
            'a line break' => ["\n"],
            'a carriage return' => ["\r"],
            'a vertical tab' => ["\v"],
            'a NUL byte' => ["\0"],
            'two spaces' => ['  '],
            'all of them' => [" \t\n\r\v\0"],
        ];
    }

    /**
     * Regression: a date of only whitespace was a filter from now on PHP
     * 8.3 (the job list was empty) and no filter on PHP 8.5, and it failed
     * the date rule; one with a NUL byte answered 500 on every version (see
     * below). The dates are trimmed first: such a date is empty, as an
     * empty one is, on every version.
     *
     * @dataProvider whitespaceOnlyDateProvider
     */
    public function testRegressionAWhitespaceOnlyDateIsEmpty(string $value): void
    {
        $form = new JobSearchForm();
        $form->load(['date_from' => $value, 'date_to' => $value], '');

        $this->assertTrue($form->validate(), 'no error');
        $this->assertSame('', $form->date_from);
        $this->assertSame('', $form->date_to);
    }

    /**
     * Whitespace around a date is trimmed, so the date is valid; a date that
     * is not set stays null and an empty one empty.
     */
    public function testTheDatesAreTrimmed(): void
    {
        $form = new JobSearchForm();
        $form->load(['date_from' => " 2025-01-15\t", 'date_to' => "\n2025-12-31 "], '');
        $unset = new JobSearchForm();
        $unset->date_to = '';

        $this->assertTrue($form->validate());
        $this->assertSame('2025-01-15', $form->date_from);
        $this->assertSame('2025-12-31', $form->date_to);
        $this->assertTrue($unset->validate());
        $this->assertNull($unset->date_from);
        $this->assertSame('', $unset->date_to);
    }

    /**
     * Regression: a date with a NUL byte answered 500 on every PHP version:
     * the date rule's DateTime::createFromFormat() throws a ValueError for
     * it. The trim strips one at either end, and a rule before the date rule
     * refuses one inside, so the date rule skips that date.
     */
    public function testRegressionADateWithANulByteFailsValidationInsteadOfThrowing(): void
    {
        $form = new JobSearchForm();
        $form->load(['date_from' => "2025-01\0-15", 'date_to' => "2025-12-31\0"], '');

        $this->assertFalse($form->validate());
        $this->assertSame(['date_from' => 'Date From is invalid.'], $form->getFirstErrors());
        $this->assertSame('2025-12-31', $form->date_to);
    }

    /**
     * Regression: empty strings from GET params must not cause TypeError on ?string properties.
     */
    public function testEmptyStringParamsDoNotThrow(): void
    {
        $form = new JobSearchForm();
        $form->load([
            'status'          => '',
            'template_id'     => '',
            'launched_by'     => '',
            'runner_group_id' => '',
            'date_from'       => '',
            'date_to'         => '',
            'has_changes'     => '',
            'recovered'       => '',
            'finished_since'  => '',
        ], '');
        $form->validate();
        $this->assertFalse($form->hasErrors());
    }
}
