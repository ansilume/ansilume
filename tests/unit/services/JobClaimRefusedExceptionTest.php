<?php

declare(strict_types=1);

namespace app\tests\unit\services;

use app\components\SurveyDefinition;
use app\models\ApprovalRequest;
use app\models\Inventory;
use app\services\JobClaimRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * The claim's refusals (JobClaimRefusedException): each factory's job log
 * line as frozen in the contract, its reason, and the context job.finished
 * records. Every text says that the job did not run and that no credential
 * was decrypted or sent to the runner: the claim's job.started entry exists
 * by then.
 */
class JobClaimRefusedExceptionTest extends TestCase
{
    private const NOT_RUN = ' The job did not run: no credentials were decrypted or sent to the runner.';

    public function testItIsAFinalRuntimeExceptionWithoutAPreviousOne(): void
    {
        $class = new \ReflectionClass(JobClaimRefusedException::class);
        $this->assertTrue($class->isFinal());
        $this->assertTrue($class->isSubclassOf(\RuntimeException::class));
        $this->assertTrue($class->getConstructor()?->isPrivate(), 'only the factories create it');

        $refusal = JobClaimRefusedException::inventoryNotRecorded();
        $this->assertNull($refusal->getPrevious());
        $this->assertSame(0, $refusal->getCode());
    }

    public function testAJobThatItsTemplatesRuleGatesWasNeverApproved(): void
    {
        $refusal = JobClaimRefusedException::notApproved(7, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, 12, null, null);

        $this->assertSame(JobClaimRefusedException::REASON_NOT_APPROVED, $refusal->reason);
        $this->assertSame(
            'Job aborted before execution: its job template #12 requires an approval (approval rule #7), and this job '
            . 'was never approved. Launch it again (Re-launch on the job page): the new job waits for approval.' . self::NOT_RUN,
            $refusal->getMessage()
        );
        $this->assertSame(
            ['claim_refusal' => 'not_approved', 'approval_rule_id' => 7, 'required_by' => 'job_template', 'job_template_id' => 12],
            $refusal->auditContext()
        );
    }

    /**
     * The inventory's ID is recorded only when the inventory's rule requires
     * the approval; the template's own rule comes first.
     */
    public function testAJobThatItsTemplatesRuleGatesNamesNoInventory(): void
    {
        $refusal = JobClaimRefusedException::notApproved(7, ApprovalRequest::REQUIRED_BY_JOB_TEMPLATE, 12, 4, ['id' => 31, 'status' => 'canceled']);

        $this->assertStringStartsWith('Job aborted before execution: its job template #12 requires', $refusal->getMessage());
        $this->assertSame(
            [
                'claim_refusal' => 'not_approved',
                'approval_rule_id' => 7,
                'required_by' => 'job_template',
                'job_template_id' => 12,
                'approval_request_id' => 31,
                'approval_request_status' => 'canceled',
            ],
            $refusal->auditContext()
        );
    }

    public function testAJobThatAnInventorysRuleGatesWasNeverApproved(): void
    {
        $refusal = JobClaimRefusedException::notApproved(7, ApprovalRequest::REQUIRED_BY_INVENTORY, 12, 4, ['id' => 31, 'status' => 'pending']);

        $this->assertSame(
            'Job aborted before execution: inventory #4 requires an approval (approval rule #7), and this job was '
            . 'never approved. Launch it again (Re-launch on the job page): the new job waits for approval.' . self::NOT_RUN,
            $refusal->getMessage()
        );
        $this->assertSame(
            [
                'claim_refusal' => 'not_approved',
                'approval_rule_id' => 7,
                'required_by' => 'inventory',
                'job_template_id' => 12,
                'inventory_id' => 4,
                'approval_request_id' => 31,
                'approval_request_status' => 'pending',
            ],
            $refusal->auditContext()
        );
    }

    public function testAJobWithoutATemplateRecordsNoTemplate(): void
    {
        $refusal = JobClaimRefusedException::notApproved(7, ApprovalRequest::REQUIRED_BY_INVENTORY, null, 4, null);

        $this->assertSame(
            ['claim_refusal' => 'not_approved', 'approval_rule_id' => 7, 'required_by' => 'inventory', 'inventory_id' => 4],
            $refusal->auditContext()
        );
    }

    public function testTheInventoryTheJobWasLaunchedWithIsGone(): void
    {
        $refusal = JobClaimRefusedException::inventoryMissing(9);

        $this->assertSame(JobClaimRefusedException::REASON_INVENTORY_MISSING, $refusal->reason);
        $this->assertSame(
            'Job aborted before execution: inventory #9, which the job was launched with, no longer exists, so its '
            . 'hosts are unknown. Launch the job template again.' . self::NOT_RUN,
            $refusal->getMessage()
        );
        $this->assertSame(['claim_refusal' => 'inventory_missing', 'inventory_id' => 9], $refusal->auditContext());
    }

    public function testTheLaunchRecordNamesNoInventory(): void
    {
        $refusal = JobClaimRefusedException::inventoryNotRecorded();

        $this->assertSame(JobClaimRefusedException::REASON_INVENTORY_NOT_RECORDED, $refusal->reason);
        $this->assertSame(
            'Job aborted before execution: its launch record names no inventory, so its hosts are unknown. Launch '
            . 'the job template again.' . self::NOT_RUN,
            $refusal->getMessage()
        );
        $this->assertSame(['claim_refusal' => 'inventory_not_recorded'], $refusal->auditContext());
    }

    public function testAStaticInventoryWithoutContent(): void
    {
        $refusal = JobClaimRefusedException::inventoryEmpty($this->inventory(5, 'web-servers'));

        $this->assertSame(JobClaimRefusedException::REASON_INVENTORY_EMPTY, $refusal->reason);
        $this->assertSame(
            'Job aborted before execution: static inventory #5 "web-servers" has no content, so it names no hosts. '
            . 'Add its hosts, then launch the job template again.' . self::NOT_RUN,
            $refusal->getMessage()
        );
        $this->assertSame(['claim_refusal' => 'inventory_empty', 'inventory_id' => 5], $refusal->auditContext());
    }

    public function testAFileInventoryWithoutASourcePath(): void
    {
        $refusal = JobClaimRefusedException::inventoryWithoutSource($this->inventory(6, 'from-repo'));

        $this->assertSame(JobClaimRefusedException::REASON_INVENTORY_NO_SOURCE, $refusal->reason);
        $this->assertSame(
            'Job aborted before execution: file inventory #6 "from-repo" names no source path, so its hosts are '
            . 'unknown. Set its source path, then launch the job template again.' . self::NOT_RUN,
            $refusal->getMessage()
        );
        $this->assertSame(['claim_refusal' => 'inventory_no_source', 'inventory_id' => 6], $refusal->auditContext());
    }

    /**
     * The name is quoted (SurveyDefinition::quote()): quotes, a line break or
     * markup in it stay inside one quoted line of the job log.
     */
    public function testAnInventoryNameStaysOnOneQuotedLine(): void
    {
        $name = "prod \"eu\"\nnext <b>line</b> \u{00fc}";

        $empty = JobClaimRefusedException::inventoryEmpty($this->inventory(5, $name))->getMessage();
        $noSource = JobClaimRefusedException::inventoryWithoutSource($this->inventory(6, $name))->getMessage();

        $quoted = '"prod \"eu\"\nnext <b>line</b> ' . chr(92) . 'u00fc"';
        $this->assertSame($quoted, SurveyDefinition::quote($name));
        $this->assertStringContainsString('static inventory #5 ' . $quoted . ' has no content', $empty);
        $this->assertStringContainsString('file inventory #6 ' . $quoted . ' names no source path', $noSource);
        $this->assertStringNotContainsString("\n", $empty . $noSource);
    }

    private function inventory(int $id, string $name): Inventory
    {
        $inventory = new Inventory();
        $inventory->id = $id;
        $inventory->name = $name;

        return $inventory;
    }
}
