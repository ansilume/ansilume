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
        ], '');
        $form->validate();
        $this->assertFalse($form->hasErrors());
    }
}
