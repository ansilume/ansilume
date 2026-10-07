<?php

declare(strict_types=1);

namespace app\tests\integration\components;

use app\components\JobTemplateWarnings;
use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\tests\integration\DbTestCase;
use yii\db\ActiveQuery;

/**
 * Warnings for job templates saved before Ansilume rejected them: more than
 * one vault password (Ansible only ever gets the first) and a file or dynamic
 * inventory of another project (the runner never reads it). The template
 * page and the REST API show them per template; the template list filters
 * and counts them in SQL, which must agree with the per-template check.
 */
class JobTemplateWarningsTest extends DbTestCase
{
    private int $userId;
    private int $groupId;
    private int $projectId;
    private int $otherProjectId;
    private int $staticInventoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('warnings')->id;
        $this->groupId = (int)$this->createRunnerGroup($this->userId)->id;
        $this->projectId = (int)$this->createProject($this->userId)->id;
        $this->otherProjectId = (int)$this->createProject($this->userId)->id;
        $this->staticInventoryId = (int)$this->inventory(Inventory::TYPE_STATIC, null)->id;
    }

    // -- forTemplate(): no warnings --------------------------------------------

    public function testACleanTemplateHasNoWarnings(): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'only-vault');
        $key = $this->credential(Credential::TYPE_SSH_KEY, 'deploy-key');
        $token = $this->credential(Credential::TYPE_TOKEN, 'api-token');
        $template = $this->template((int)$vault->id, [(int)$key->id, (int)$token->id]);

        $this->assertSame([], JobTemplateWarnings::forTemplate($template));
    }

    public function testTheSameVaultAsPrimaryAndAdditionalCredentialIsOneVault(): void
    {
        $vault = $this->credential(Credential::TYPE_VAULT, 'repeated-vault');
        $template = $this->template((int)$vault->id, [(int)$vault->id]);

        $this->assertSame([], JobTemplateWarnings::forTemplate($template));
    }

    public function testInventoriesTheRunnerReadsAreNoWarning(): void
    {
        $cases = [
            'file inventory of the own project' => $this->inventory(Inventory::TYPE_FILE, $this->projectId),
            'dynamic inventory of the own project' => $this->inventory(Inventory::TYPE_DYNAMIC, $this->projectId),
            'dynamic inventory without project' => $this->inventory(Inventory::TYPE_DYNAMIC, null),
            'static inventory of another project' => $this->inventory(Inventory::TYPE_STATIC, $this->otherProjectId),
        ];
        foreach ($cases as $case => $inventory) {
            $template = $this->template(null, [], (int)$inventory->id);
            $this->assertSame([], JobTemplateWarnings::forTemplate($template), $case);
        }
    }

    // -- forTemplate(): multiple_vault_credentials ------------------------------

    public function testAPrimaryAndAnAdditionalVaultAreWarnedAbout(): void
    {
        // Created first, so it has the lower id: precedence must not follow ids.
        $additional = $this->credential(Credential::TYPE_VAULT, 'staging-vault');
        $primary = $this->credential(Credential::TYPE_VAULT, 'prod-vault');
        $template = $this->template((int)$primary->id, [(int)$additional->id]);

        $this->assertSame([[
            'code' => JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS,
            'message' => 'This template has 2 vault passwords, but Ansible gets only one: '
                . '"prod-vault" takes precedence and "staging-vault" is ignored. '
                . 'Saving the template is rejected until only one is left.',
            'credential_ids' => [(int)$additional->id],
        ]], JobTemplateWarnings::forTemplate($template));
    }

    public function testTwoAdditionalVaultsAreWarnedAboutInSortOrder(): void
    {
        $key = $this->credential(Credential::TYPE_SSH_KEY, 'deploy-key');
        // Lower id, but the higher sort_order: it is the one Ansible ignores.
        $second = $this->credential(Credential::TYPE_VAULT, 'second-vault');
        $first = $this->credential(Credential::TYPE_VAULT, 'first-vault');
        $template = $this->template((int)$key->id, [(int)$first->id, (int)$second->id]);

        $this->assertSame([[
            'code' => JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS,
            'message' => 'This template has 2 vault passwords, but Ansible gets only one: '
                . '"first-vault" takes precedence and "second-vault" is ignored. '
                . 'Saving the template is rejected until only one is left.',
            'credential_ids' => [(int)$second->id],
        ]], JobTemplateWarnings::forTemplate($template));
    }

    public function testEveryIgnoredVaultIsNamed(): void
    {
        $primary = $this->credential(Credential::TYPE_VAULT, 'vault-a');
        $second = $this->credential(Credential::TYPE_VAULT, 'vault-b');
        $third = $this->credential(Credential::TYPE_VAULT, 'vault-c');
        $template = $this->template((int)$primary->id, [(int)$second->id, (int)$third->id]);

        $this->assertSame([[
            'code' => JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS,
            'message' => 'This template has 3 vault passwords, but Ansible gets only one: '
                . '"vault-a" takes precedence and "vault-b" and "vault-c" are ignored. '
                . 'Saving the template is rejected until only one is left.',
            'credential_ids' => [(int)$second->id, (int)$third->id],
        ]], JobTemplateWarnings::forTemplate($template));
    }

    // -- forTemplate(): inventory_other_project --------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function projectBoundTypeProvider(): array
    {
        return [
            'file inventory' => [Inventory::TYPE_FILE],
            'dynamic inventory' => [Inventory::TYPE_DYNAMIC],
        ];
    }

    /**
     * @dataProvider projectBoundTypeProvider
     */
    public function testAnInventoryOfAnotherProjectIsWarnedAbout(string $type): void
    {
        $inventory = $this->inventory($type, $this->otherProjectId);
        $template = $this->template(null, [], (int)$inventory->id);

        $this->assertSame([[
            'code' => JobTemplateWarnings::INVENTORY_OTHER_PROJECT,
            'message' => $this->inventoryWarning($inventory),
            'credential_ids' => [],
        ]], JobTemplateWarnings::forTemplate($template));
    }

    public function testATemplateCanHaveBothWarnings(): void
    {
        $primary = $this->credential(Credential::TYPE_VAULT, 'prod-vault');
        $additional = $this->credential(Credential::TYPE_VAULT, 'staging-vault');
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $template = $this->template((int)$primary->id, [(int)$additional->id], (int)$inventory->id);

        $warnings = JobTemplateWarnings::forTemplate($template);

        $this->assertSame(
            [JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS, JobTemplateWarnings::INVENTORY_OTHER_PROJECT],
            array_column($warnings, 'code')
        );
        $this->assertSame([(int)$additional->id], $warnings[0]['credential_ids']);
        $this->assertSame($this->inventoryWarning($inventory), $warnings[1]['message']);
        $this->assertSame([], $warnings[1]['credential_ids']);
    }

    // -- filter() ----------------------------------------------------------------

    public function testTheVaultFilterFindsExactlyTheTemplatesWithMoreThanOneVault(): void
    {
        $templates = $this->templatesOfEveryKind();
        $query = $this->queryFor($templates);

        $this->assertTrue(JobTemplateWarnings::filter($query, JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS));

        // "repeated vault" (primary plus a pivot row of the same vault) is one
        // vault, not two; the soft-deleted template stays hidden.
        $expected = $this->idsOf($templates, [
            'primary and additional vault',
            'two additional vaults',
            'both warnings',
        ]);
        $this->assertSame($expected, $this->resultIds($query));
        $this->assertSame(count($expected), (int)$query->count());
    }

    public function testTheInventoryFilterFindsExactlyTheTemplatesWithAnInventoryOfAnotherProject(): void
    {
        $templates = $this->templatesOfEveryKind();
        $query = $this->queryFor($templates);

        $this->assertTrue(JobTemplateWarnings::filter($query, JobTemplateWarnings::INVENTORY_OTHER_PROJECT));

        $expected = $this->idsOf($templates, [
            'file inventory of another project',
            'dynamic inventory of another project',
            'both warnings',
            'other project with a file inventory of the first project',
        ]);
        $this->assertSame($expected, $this->resultIds($query));
        $this->assertSame(count($expected), (int)$query->count());
    }

    /**
     * The list filters in SQL, the template page checks in PHP: both must
     * flag the same templates.
     */
    public function testTheFiltersAgreeWithTheWarningsOfEachTemplate(): void
    {
        $templates = $this->templatesOfEveryKind();
        unset($templates['soft-deleted with both warnings']);

        foreach (JobTemplateWarnings::CODES as $code) {
            $flagged = [];
            foreach ($templates as $id) {
                $codes = array_column(JobTemplateWarnings::forTemplate($this->reload($id)), 'code');
                if (in_array($code, $codes, true)) {
                    $flagged[] = $id;
                }
            }
            sort($flagged);
            $query = $this->queryFor($templates);
            JobTemplateWarnings::filter($query, $code);

            $this->assertSame($flagged, $this->resultIds($query), $code);
        }
    }

    public function testAnUnknownCodeIsRejectedAndLeavesTheQueryUnchanged(): void
    {
        $templates = $this->templatesOfEveryKind();
        $visible = $templates;
        unset($visible['soft-deleted with both warnings']);

        foreach (['no_such_warning', ''] as $code) {
            $query = $this->queryFor($templates);
            $sql = $query->createCommand()->getRawSql();

            $this->assertFalse(JobTemplateWarnings::filter($query, $code), "code '{$code}'");

            $this->assertSame($sql, $query->createCommand()->getRawSql(), "code '{$code}'");
            $this->assertSame($this->idsOf($visible, array_keys($visible)), $this->resultIds($query), "code '{$code}'");
        }
    }

    // -- counts() ----------------------------------------------------------------

    public function testCountsReportHowManyTemplatesOfTheQueryHaveEachWarning(): void
    {
        $query = $this->queryFor($this->templatesOfEveryKind());
        $sql = $query->createCommand()->getRawSql();

        $this->assertSame([
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS => 3,
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT => 4,
        ], JobTemplateWarnings::counts($query));
        // counts() filters copies: the query itself still lists every template.
        $this->assertSame($sql, $query->createCommand()->getRawSql());
    }

    public function testCountsFollowTheConditionsOfTheQuery(): void
    {
        $templates = $this->templatesOfEveryKind();
        $clean = $this->queryFor([$templates['clean'], $templates['repeated vault']]);
        // Scoped by project the way the team filter of the controllers does it.
        $otherProject = $this->queryFor($templates)->andWhere(['job_template.project_id' => $this->otherProjectId]);

        $this->assertSame([
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS => 0,
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT => 0,
        ], JobTemplateWarnings::counts($clean));
        $this->assertSame([
            JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS => 0,
            JobTemplateWarnings::INVENTORY_OTHER_PROJECT => 1,
        ], JobTemplateWarnings::counts($otherProject));
    }

    // -- label() -----------------------------------------------------------------

    public function testLabelDescribesEachWarningAndFallsBackToTheCode(): void
    {
        $this->assertSame(
            'more than one vault password',
            JobTemplateWarnings::label(JobTemplateWarnings::MULTIPLE_VAULT_CREDENTIALS)
        );
        $this->assertSame(
            'an inventory of another project',
            JobTemplateWarnings::label(JobTemplateWarnings::INVENTORY_OTHER_PROJECT)
        );
        $this->assertSame('no_such_warning', JobTemplateWarnings::label('no_such_warning'));
    }

    // -- helpers -----------------------------------------------------------------

    /**
     * One template per situation, keyed by what makes it (not) a warning case.
     *
     * @return array<string, int> situation => template id
     */
    private function templatesOfEveryKind(): array
    {
        $vaultA = (int)$this->credential(Credential::TYPE_VAULT, 'vault-a')->id;
        $vaultB = (int)$this->credential(Credential::TYPE_VAULT, 'vault-b')->id;
        $key = (int)$this->credential(Credential::TYPE_SSH_KEY, 'deploy-key')->id;
        $token = (int)$this->credential(Credential::TYPE_TOKEN, 'api-token')->id;
        $otherFile = (int)$this->inventory(Inventory::TYPE_FILE, $this->otherProjectId)->id;
        $otherDynamic = (int)$this->inventory(Inventory::TYPE_DYNAMIC, $this->otherProjectId)->id;

        $templates = [
            'clean' => $this->template($vaultA, [$key, $token]),
            'repeated vault' => $this->template($vaultA, [$vaultA, $key]),
            'primary and additional vault' => $this->template($vaultA, [$vaultB]),
            'two additional vaults' => $this->template($key, [$vaultB, $vaultA]),
            'file inventory of another project' => $this->template(null, [], $otherFile),
            'dynamic inventory of another project' => $this->template(null, [], $otherDynamic),
            'file inventory of the own project' => $this->template(
                null,
                [],
                (int)$this->inventory(Inventory::TYPE_FILE, $this->projectId)->id
            ),
            'dynamic inventory without project' => $this->template(
                null,
                [],
                (int)$this->inventory(Inventory::TYPE_DYNAMIC, null)->id
            ),
            'static inventory of another project' => $this->template(
                null,
                [],
                (int)$this->inventory(Inventory::TYPE_STATIC, $this->otherProjectId)->id
            ),
            'both warnings' => $this->template($vaultA, [$vaultB], $otherFile),
            'soft-deleted with both warnings' => $this->template($vaultA, [$vaultB], $otherFile),
            // The project comparison is per template, not against one project.
            'other project with a file inventory of that project' => $this->template(
                null,
                [],
                $otherFile,
                $this->otherProjectId
            ),
            'other project with a file inventory of the first project' => $this->template(
                null,
                [],
                (int)$this->inventory(Inventory::TYPE_FILE, $this->projectId)->id,
                $this->otherProjectId
            ),
        ];
        $templates['soft-deleted with both warnings']->softDelete();

        return array_map(static fn (JobTemplate $template): int => (int)$template->id, $templates);
    }

    /**
     * A query over the given templates only, so rows of other tests or
     * seeders in the test database never count. Qualified, because the
     * inventory filter joins a second table with an id column.
     *
     * @param array<string, int>|list<int> $templateIds
     */
    private function queryFor(array $templateIds): ActiveQuery
    {
        return JobTemplate::find()->andWhere(['{{%job_template}}.id' => array_values($templateIds)]);
    }

    /**
     * @return list<int>
     */
    private function resultIds(ActiveQuery $query): array
    {
        $ids = array_map(static fn (JobTemplate $template): int => (int)$template->id, $query->all());
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, int> $templates
     * @param list<string> $situations
     * @return list<int>
     */
    private function idsOf(array $templates, array $situations): array
    {
        $ids = array_map(static fn (string $situation): int => $templates[$situation], $situations);
        sort($ids);

        return $ids;
    }

    private function credential(string $type, string $name): Credential
    {
        $credential = $this->createCredential($this->userId, $type);
        $credential->name = $name;
        $credential->save(false);

        return $credential;
    }

    private function inventory(string $type, ?int $projectId): Inventory
    {
        $inventory = $this->createInventory($this->userId);
        $inventory->inventory_type = $type;
        $inventory->project_id = $projectId;
        $inventory->content = $type === Inventory::TYPE_STATIC ? "all:\n  hosts:\n    web1.example.com:\n" : null;
        $inventory->source_path = $type === Inventory::TYPE_STATIC ? null : 'inventories/hosts.yml';
        $inventory->save(false);

        return $inventory;
    }

    /**
     * A template stored without validation, as before vault release 1, with
     * its additional credentials in the given precedence order.
     *
     * @param list<int> $additionalIds
     */
    private function template(
        ?int $primaryId,
        array $additionalIds,
        ?int $inventoryId = null,
        ?int $projectId = null
    ): JobTemplate {
        $template = $this->createJobTemplate(
            $projectId ?? $this->projectId,
            $inventoryId ?? $this->staticInventoryId,
            $this->groupId,
            $this->userId
        );
        $template->credential_id = $primaryId;
        $template->save(false);
        foreach ($additionalIds as $index => $credentialId) {
            \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $credentialId,
                'sort_order' => $index + 1,
            ])->execute();
        }

        return $this->reload((int)$template->id);
    }

    private function reload(int $id): JobTemplate
    {
        $template = JobTemplate::findOne($id);
        $this->assertNotNull($template);

        return $template;
    }

    private function inventoryWarning(Inventory $inventory): string
    {
        return 'The inventory "' . $inventory->name . '" is a file or dynamic inventory of another project. The runner '
            . 'checks out only this template\'s project and looks for the inventory there, so jobs do not use it. '
            . 'Switch to an inventory of this project or a static inventory.';
    }
}
