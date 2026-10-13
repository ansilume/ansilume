<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\VaultAssignmentResult;
use app\models\Credential;
use app\models\JobTemplate;
use app\models\User;
use app\services\CredentialWriteService;
use app\services\GatedJobTemplateLock;
use app\services\VaultCredentialAssignmentService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * The vault password assignment saves every template for the user it
 * assigns for (assign()'s $userId), and GatedJobTemplateLock judges that
 * user, whoever is signed in: a template with an approval rule keeps its
 * credentials unless that user holds approval-rule.update.
 *
 * Regression: each save was judged for the signed-in user. Without one (a
 * console run) the lock judged nobody, and an operator's assignment changed
 * the credentials of a gated template; signed in as someone else, the lock
 * judged that other user.
 */
class VaultAssignmentActorTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    private User $admin;
    private User $operator;
    private JobTemplate $gated;
    private JobTemplate $ungated;
    private Credential $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('va-admin', 'admin');
        $this->operator = $this->createUserWithRole('va-operator', 'operator');
        $rule = $this->createApprovalRule((int)$this->admin->id);
        $this->gated = $this->template();
        JobTemplate::updateAll(['approval_rule_id' => $rule->id], ['id' => $this->gated->id]);
        $this->ungated = $this->template();
        $this->vault = $this->usableVault();
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function nobodySignedInProvider(): array
    {
        return [
            'a console run: no user component' => [false],
            'a web request of a guest' => [true],
        ];
    }

    /**
     * Regression: without a signed-in user the vault password assignment
     * of an operator changed a gated template's credentials.
     *
     * @dataProvider nobodySignedInProvider
     */
    public function testRegressionWithNobodySignedInAnOperatorsAssignmentLeavesAGatedTemplateAlone(bool $userComponent): void
    {
        if (!$userComponent) {
            \Yii::$app->set('user', null);
        }

        $items = $this->assignFor($this->operator, [$this->gated, $this->ungated]);

        $this->assertRefused($items, $this->gated);
        $this->assertSame(VaultAssignmentResult::ASSIGNED, $items[(int)$this->ungated->id]['status']);
        $this->assertSame([(int)$this->vault->id], $this->credentialIds($this->ungated));
    }

    /**
     * Regression: signed in as an admin, an assignment made for an operator
     * was judged as the admin's and changed a gated template.
     */
    public function testRegressionAnAssignmentForAnOperatorIsJudgedForTheOperator(): void
    {
        $this->loginAs($this->admin);

        $items = $this->assignFor($this->operator, [$this->gated]);

        $this->assertRefused($items, $this->gated);
    }

    /**
     * And the other way round: an assignment made for an admin changes a
     * gated template, whoever is signed in.
     */
    public function testAnAssignmentForAnAdminChangesAGatedTemplate(): void
    {
        $this->loginAs($this->operator);

        $items = $this->assignFor($this->admin, [$this->gated]);

        $this->assertSame(VaultAssignmentResult::ASSIGNED, $items[(int)$this->gated->id]['status']);
        $this->assertNull($items[(int)$this->gated->id]['error']);
        $this->assertSame([(int)$this->vault->id], $this->credentialIds($this->gated));
    }

    // -- Helpers ----------------------------------------------------------------------

    /**
     * Assign the vault password to $templates for $user.
     *
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
     * $template's item failed with the lock's refusal, and the template
     * kept its credentials (none).
     *
     * @param array<int, array{job_template_id: int, name: string, status: string, replaced: list<array{id: int, name: string}>, error: string|null}> $items
     */
    private function assertRefused(array $items, JobTemplate $template): void
    {
        $item = $items[(int)$template->id] ?? null;
        $this->assertIsArray($item);
        $this->assertSame(VaultAssignmentResult::FAILED, $item['status']);
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED, $item['error']);
        $this->assertSame([], $this->credentialIds($template), 'the gated template keeps its credentials');
    }

    /**
     * @return list<int> $template's credentials as stored, primary first
     */
    private function credentialIds(JobTemplate $template): array
    {
        $stored = JobTemplate::findOne($template->id);
        $this->assertNotNull($stored);

        return array_values(array_column($stored->credentialSnapshot(), 'id'));
    }

    private function template(): JobTemplate
    {
        $ownerId = (int)$this->admin->id;

        return $this->createJobTemplate(
            (int)$this->createProject($ownerId)->id,
            (int)$this->createInventory($ownerId)->id,
            (int)$this->createRunnerGroup($ownerId)->id,
            $ownerId
        );
    }

    private function usableVault(): Credential
    {
        $vault = new Credential();
        $vault->name = 'va-vault-' . uniqid('', true);
        $vault->credential_type = Credential::TYPE_VAULT;
        $vault->created_by = (int)$this->admin->id;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($vault, ['vault_password' => 'va-test-vault-value']), (string)json_encode($vault->errors));

        return $vault;
    }
}
