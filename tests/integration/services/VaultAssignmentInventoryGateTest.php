<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\VaultAssignmentResult;
use app\models\ApprovalRule;
use app\models\Credential;
use app\models\JobTemplate;
use app\models\User;
use app\services\CredentialWriteService;
use app\services\GatedJobTemplateLock;
use app\services\VaultCredentialAssignmentService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The vault password assignment saves templates without the edit form, and
 * the template lock judges each save for the user it assigns for: a
 * template gated through its inventory keeps its credentials unless that
 * user holds approval-rule.update, and is reported as failed with
 * GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY; one with a rule of its own
 * with CHANGE_REFUSED. The other templates are assigned.
 */
class VaultAssignmentInventoryGateTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use TemplateInventoryLockFixtures;

    private User $admin;
    private User $operator;
    private ApprovalRule $rule;
    private JobTemplate $inventoryGated;
    private Credential $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('vig-admin', 'admin');
        $this->operator = $this->createUserWithRole('vig-operator', 'operator');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->inventoryGated = $this->inventoryGated((int)$this->admin->id, $this->rule);
        $this->vault = $this->usableVault();
    }

    /**
     * Regression: an operator's vault password assignment changed the
     * credentials of a job template gated through its inventory.
     */
    public function testRegressionAnOperatorsAssignmentLeavesATemplateGatedThroughItsInventoryAlone(): void
    {
        $ungated = $this->ungatedTemplate((int)$this->admin->id);
        $ownRule = $this->withOwnRule($this->ungatedTemplate((int)$this->admin->id), $this->rule);
        $this->loginAs($this->operator);

        $items = $this->assignFor($this->operator, [$this->inventoryGated, $ungated, $ownRule]);

        $this->assertSame(VaultAssignmentResult::FAILED, $items[(int)$this->inventoryGated->id]['status'], 'the template gated through its inventory is not assigned');
        $this->assertSame([], $this->credentialIds($this->inventoryGated), 'it keeps its credentials');
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, $items[(int)$this->inventoryGated->id]['error']);
        $this->assertSame(VaultAssignmentResult::FAILED, $items[(int)$ownRule->id]['status']);
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED, $items[(int)$ownRule->id]['error'], 'a rule of its own keeps its text');
        $this->assertSame([], $this->credentialIds($ownRule));
        $this->assertSame(VaultAssignmentResult::ASSIGNED, $items[(int)$ungated->id]['status'], 'the other templates are assigned');
        $this->assertSame([(int)$this->vault->id], $this->credentialIds($ungated));
    }

    /**
     * Judged for the user it assigns for, whoever is signed in, also with
     * nobody signed in (a console run).
     */
    public function testAnOperatorsAssignmentIsRefusedWithNobodySignedIn(): void
    {
        \Yii::$app->set('user', null);

        $items = $this->assignFor($this->operator, [$this->inventoryGated]);

        $this->assertSame(VaultAssignmentResult::FAILED, $items[(int)$this->inventoryGated->id]['status']);
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED_INVENTORY, $items[(int)$this->inventoryGated->id]['error']);
    }

    public function testAnAdminsAssignmentChangesATemplateGatedThroughItsInventory(): void
    {
        $this->loginAs($this->operator);

        $items = $this->assignFor($this->admin, [$this->inventoryGated]);

        $this->assertSame(VaultAssignmentResult::ASSIGNED, $items[(int)$this->inventoryGated->id]['status']);
        $this->assertNull($items[(int)$this->inventoryGated->id]['error']);
        $this->assertSame([(int)$this->vault->id], $this->credentialIds($this->inventoryGated));
    }

    // -- helpers ------------------------------------------------------------------------

    /**
     * @param list<JobTemplate> $templates
     * @return array<int, array{job_template_id: int, name: string, status: string, replaced: list<array{id: int, name: string}>, error: string|null}>
     */
    private function assignFor(User $user, array $templates): array
    {
        /** @var VaultCredentialAssignmentService $assignment */
        $assignment = \Yii::$app->get('vaultCredentialAssignmentService');
        $ids = array_map(static fn (JobTemplate $template): int => (int)$template->id, $templates);

        return array_column($assignment->assign($this->vault, $ids, (int)$user->id)->items, null, 'job_template_id');
    }

    /**
     * @return list<int> $template's credentials as stored, primary first
     */
    private function credentialIds(JobTemplate $template): array
    {
        return array_values(array_column($this->reloaded($template)->credentialSnapshot(), 'id'));
    }

    private function usableVault(): Credential
    {
        $vault = new Credential();
        $vault->name = 'vig-vault-' . uniqid('', true);
        $vault->credential_type = Credential::TYPE_VAULT;
        $vault->created_by = (int)$this->admin->id;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($vault, ['vault_password' => 'vig-test-vault-value']), (string)json_encode($vault->errors));

        return $vault;
    }
}
