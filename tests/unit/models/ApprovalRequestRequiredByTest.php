<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\ApprovalRequest;
use PHPUnit\Framework\TestCase;

/**
 * What required an approval request (approval_request.required_by,
 * m000088): read with requiredBy(), recorded with recordRequiredBy(),
 * validated against the three values, and named by requiredByLabel().
 * Code running before m000088, or with a schema cached from before it, has
 * no such column: it reads null, records nothing, and validates as before.
 *
 * The models list their columns themselves, so this reads no database.
 */
class ApprovalRequestRequiredByTest extends TestCase
{
    public function testTheValuesAndTheirLabels(): void
    {
        $this->assertSame('job_template', ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE);
        $this->assertSame('inventory', ApprovalRequest::REQUIRED_BY_INVENTORY);
        $this->assertSame('approval_step', ApprovalRequest::REQUIRED_BY_APPROVAL_STEP);
        $this->assertSame(['job_template', 'inventory', 'approval_step'], ApprovalRequest::REQUIRED_BY_VALUES);
        $this->assertSame(
            ['job_template' => 'Job template', 'inventory' => 'Inventory', 'approval_step' => 'Workflow approval step'],
            ApprovalRequest::REQUIRED_BY_LABELS
        );
    }

    public function testRecordedValuesAreReadBack(): void
    {
        foreach (ApprovalRequest::REQUIRED_BY_VALUES as $value) {
            $request = $this->request();
            $request->recordRequiredBy($value);

            $this->assertSame($value, $request->requiredBy());
            $this->assertSame($value, $request->getAttribute('required_by'));
        }
    }

    public function testANewRequestThatRecordedNothingReadsNull(): void
    {
        $this->assertNull($this->request()->requiredBy());
    }

    public function testAValueThatIsNoStringReadsNull(): void
    {
        $request = $this->request();
        $request->setAttribute('required_by', 7);

        $this->assertNull($request->requiredBy());
    }

    public function testOnlyTheThreeValuesAreValidExactlyAsWritten(): void
    {
        foreach (ApprovalRequest::REQUIRED_BY_VALUES as $value) {
            $request = $this->request();
            $request->recordRequiredBy($value);
            $this->assertTrue($request->validate(['required_by']), $value);
        }
        foreach (['JOB_TEMPLATE', 'Inventory', 'approval step', 'workflow', ' inventory'] as $value) {
            $request = $this->request();
            $request->recordRequiredBy($value);
            $this->assertFalse($request->validate(['required_by']), $value);
            $this->assertSame(['Required By is invalid.'], $request->getErrors('required_by'));
        }
        $notAString = $this->request();
        $notAString->setAttribute('required_by', 1);
        $this->assertFalse($notAString->validate(['required_by']), 'compared strictly');
    }

    /**
     * Regression: the rule skipped an empty value, so a request that
     * recorded '' was saved with it. Only a request that recorded nothing
     * (null) is left to the column's default, job_template.
     */
    public function testRegressionAnEmptyValueWasAccepted(): void
    {
        $empty = $this->request();
        $empty->recordRequiredBy('');

        $this->assertFalse($empty->validate(['required_by']));
        $this->assertSame(['Required By is invalid.'], $empty->getErrors('required_by'));
        $this->assertTrue($this->request()->validate(['required_by']), 'nothing recorded: the column default applies');
    }

    /**
     * Before m000088: no column, no value, no rule, and recording is a no-op
     * (the column's default, job_template, applies once it is added).
     */
    public function testWithoutTheColumnNothingIsReadOrRecorded(): void
    {
        $request = new class () extends ApprovalRequest {
            public function attributes(): array
            {
                return ['id', 'job_id', 'approval_rule_id', 'status', 'requested_at', 'resolved_at', 'expires_at'];
            }
        };

        $request->recordRequiredBy(ApprovalRequest::REQUIRED_BY_INVENTORY);

        $this->assertNull($request->requiredBy());
        $this->assertFalse($request->hasAttribute('required_by'));
        $this->assertSame([], $request->getActiveValidators('required_by'));
        $request->setAttributes(['job_id' => 4, 'approval_rule_id' => 2, 'status' => ApprovalRequest::STATUS_PENDING], false);
        $this->assertTrue($request->validate(), implode(' ', $request->getFirstErrors()));
    }

    public function testTheLabelNamesWhatRequiredTheRequest(): void
    {
        $this->assertSame('Job template', ApprovalRequest::requiredByLabel('job_template'));
        $this->assertSame('Inventory', ApprovalRequest::requiredByLabel('inventory'));
        $this->assertSame('Workflow approval step', ApprovalRequest::requiredByLabel('approval_step'));
        $this->assertSame('—', ApprovalRequest::requiredByLabel(null), 'unknown before m000088');
        $this->assertSame('something-new', ApprovalRequest::requiredByLabel('something-new'), 'an unknown value as stored');
    }

    // -- Helpers ----------------------------------------------------------------

    /**
     * A new request on a schema with m000088.
     */
    private function request(): ApprovalRequest
    {
        return new class () extends ApprovalRequest {
            public function attributes(): array
            {
                return ['id', 'job_id', 'approval_rule_id', 'required_by', 'status', 'requested_at', 'resolved_at', 'expires_at'];
            }
        };
    }
}
