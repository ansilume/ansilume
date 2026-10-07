<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\Inventory;
use app\models\JobTemplate;
use app\tests\integration\DbTestCase;

/**
 * File and dynamic inventories must come from the job template's project:
 * the runner checks out only that project and looks for the inventory there,
 * so an inventory of another project was silently never read.
 *
 * Both sides of the rule are covered: the job template (new templates and
 * changes of project or inventory are validated) and the inventory (changing
 * its type or project must not break the templates that already use it).
 * Records saved before the rule existed ("legacy", written with save(false))
 * keep validating as long as those attributes stay the same.
 */
class JobTemplateInventoryRuleTest extends DbTestCase
{
    private const OWN_PROJECT = 'own';
    private const OTHER_PROJECT = 'other';
    private const NO_PROJECT = 'none';
    private const STATIC_CONTENT = "all:\n  hosts:\n    web1.example.com:\n";

    private int $userId;
    private int $groupId;
    private int $projectId;
    private int $otherProjectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('invrule')->id;
        $this->groupId = (int)$this->createRunnerGroup($this->userId)->id;
        $this->projectId = (int)$this->createProject($this->userId)->id;
        $this->otherProjectId = (int)$this->createProject($this->userId)->id;
    }

    // -- data providers --------------------------------------------------------

    /**
     * Inventories the runner of a template in the own project never reads.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function crossProjectInventoryProvider(): array
    {
        return [
            'file inventory of another project' => [Inventory::TYPE_FILE, self::OTHER_PROJECT],
            'dynamic inventory of another project' => [Inventory::TYPE_DYNAMIC, self::OTHER_PROJECT],
        ];
    }

    /**
     * Inventories a template in the own project can use.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function usableInventoryProvider(): array
    {
        return [
            'file inventory of the own project' => [Inventory::TYPE_FILE, self::OWN_PROJECT],
            'dynamic inventory of the own project' => [Inventory::TYPE_DYNAMIC, self::OWN_PROJECT],
            'dynamic inventory without project' => [Inventory::TYPE_DYNAMIC, self::NO_PROJECT],
            'static inventory of another project' => [Inventory::TYPE_STATIC, self::OTHER_PROJECT],
            'static inventory without project' => [Inventory::TYPE_STATIC, self::NO_PROJECT],
        ];
    }

    /**
     * Both lists above, with whether the inventory belongs to another project.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function inventoryProvider(): array
    {
        $cases = [];
        foreach (self::crossProjectInventoryProvider() as $name => [$type, $owner]) {
            $cases[$name] = [$type, $owner, true];
        }
        foreach (self::usableInventoryProvider() as $name => [$type, $owner]) {
            $cases[$name] = [$type, $owner, false];
        }

        return $cases;
    }

    // -- JobTemplate: new templates --------------------------------------------

    /**
     * @dataProvider crossProjectInventoryProvider
     */
    public function testANewTemplateRejectsAProjectBoundInventoryOfAnotherProject(string $type, string $owner): void
    {
        $inventory = $this->inventory($type, $this->projectIdFor($owner));
        $template = $this->newTemplate($this->projectId, (int)$inventory->id);

        $this->assertFalse($template->validate());
        $this->assertSame(['inventory_id' => [$this->crossProjectError($inventory)]], $template->getErrors());
    }

    /**
     * @dataProvider usableInventoryProvider
     */
    public function testANewTemplateAcceptsAnInventoryItsRunnerCanRead(string $type, string $owner): void
    {
        $inventory = $this->inventory($type, $this->projectIdFor($owner));
        $template = $this->newTemplate($this->projectId, (int)$inventory->id);

        $this->assertTrue($template->validate(), (string)json_encode($template->getErrors()));
    }

    /**
     * Regression: an unknown inventory_id passed validation and failed on the
     * foreign key with a database exception instead of a validation error.
     */
    public function testAnUnknownInventoryIsAValidationError(): void
    {
        $template = $this->newTemplate($this->projectId, 999_999_999);

        $this->assertFalse($template->save());
        // The project check is skipped: one clear message, nothing else.
        $this->assertSame(['inventory_id' => ['The selected inventory does not exist.']], $template->getErrors());
        $this->assertTrue($template->isNewRecord);
    }

    // -- JobTemplate: legacy and existing templates ----------------------------

    public function testALegacyCrossProjectTemplateStillSavesWhenOnlyItsNameChanges(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $template = $this->legacyTemplate($this->projectId, (int)$inventory->id);
        $template->name = 'renamed-legacy-template';

        $this->assertTrue($template->save(), (string)json_encode($template->getErrors()));
        $stored = $this->reloadTemplate((int)$template->id);
        $this->assertSame('renamed-legacy-template', $stored->name);
        // It keeps running and keeps its warning until someone fixes it.
        $this->assertTrue($stored->hasCrossProjectInventory());
    }

    /**
     * Forms submit ids as strings. "5" for a stored 5 is no change, so it must
     * not subject a legacy template to the rule.
     */
    public function testStringIdsFromAFormAreNoChangeForALegacyTemplate(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_DYNAMIC, $this->otherProjectId);
        $template = $this->legacyTemplate($this->projectId, (int)$inventory->id);

        $template->load([
            'name' => 'renamed-through-the-form',
            'project_id' => (string)$this->projectId,
            'inventory_id' => (string)$inventory->id,
        ], '');

        $this->assertSame((string)$inventory->id, $template->inventory_id);
        $this->assertSame((string)$this->projectId, $template->project_id);
        $this->assertTrue($template->validate(), (string)json_encode($template->getErrors()));
    }

    public function testALegacyTemplateCannotSwitchToAnotherCrossProjectInventory(): void
    {
        $legacy = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $replacement = $this->inventory(Inventory::TYPE_DYNAMIC, $this->otherProjectId);
        $template = $this->legacyTemplate($this->projectId, (int)$legacy->id);
        $template->inventory_id = (int)$replacement->id;

        $this->assertFalse($template->validate());
        $this->assertSame([$this->crossProjectError($replacement)], $template->getErrors('inventory_id'));
    }

    public function testAnExistingTemplateCannotSwitchToACrossProjectInventory(): void
    {
        $static = $this->inventory(Inventory::TYPE_STATIC, null);
        $file = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $template = $this->legacyTemplate($this->projectId, (int)$static->id);
        $template->inventory_id = (int)$file->id;

        $this->assertFalse($template->save());
        $this->assertSame([$this->crossProjectError($file)], $template->getErrors('inventory_id'));
        $this->assertSame((int)$static->id, $this->reloadTemplate((int)$template->id)->inventory_id);
    }

    public function testMovingALegacyCrossProjectTemplateToAThirdProjectIsRejected(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $template = $this->legacyTemplate($this->projectId, (int)$inventory->id);
        $template->project_id = (int)$this->createProject($this->userId)->id;

        $this->assertFalse($template->validate());
        $this->assertSame([$this->crossProjectError($inventory)], $template->getErrors('inventory_id'));
    }

    public function testMovingATemplateAwayFromTheProjectOfItsFileInventoryIsRejected(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->projectId);
        $template = $this->legacyTemplate($this->projectId, (int)$inventory->id);
        $template->project_id = $this->otherProjectId;

        $this->assertFalse($template->save());
        $this->assertSame([$this->crossProjectError($inventory)], $template->getErrors('inventory_id'));
        $this->assertSame($this->projectId, $this->reloadTemplate((int)$template->id)->project_id);
    }

    public function testALegacyTemplateIsFixedByMovingItToTheProjectOfItsInventory(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $template = $this->legacyTemplate($this->projectId, (int)$inventory->id);
        $template->project_id = $this->otherProjectId;

        $this->assertTrue($template->save(), (string)json_encode($template->getErrors()));
        $this->assertFalse($this->reloadTemplate((int)$template->id)->hasCrossProjectInventory());
    }

    public function testALegacyTemplateIsFixedBySwitchingToAStaticInventory(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $static = $this->inventory(Inventory::TYPE_STATIC, null);
        $template = $this->legacyTemplate($this->projectId, (int)$inventory->id);
        $template->inventory_id = (int)$static->id;

        $this->assertTrue($template->save(), (string)json_encode($template->getErrors()));
        $this->assertFalse($this->reloadTemplate((int)$template->id)->hasCrossProjectInventory());
    }

    // -- JobTemplate::hasCrossProjectInventory ---------------------------------

    /**
     * The warning flags exactly the combinations the rule rejects.
     *
     * @dataProvider inventoryProvider
     */
    public function testHasCrossProjectInventoryFlagsWhatTheRuleRejects(
        string $type,
        string $owner,
        bool $expected
    ): void {
        $inventory = $this->inventory($type, $this->projectIdFor($owner));
        $template = $this->legacyTemplate($this->projectId, (int)$inventory->id);

        $this->assertSame($expected, $template->hasCrossProjectInventory());
    }

    public function testATemplateWithoutInventoryHasNoCrossProjectInventory(): void
    {
        $template = new JobTemplate();
        $template->project_id = $this->projectId;

        $this->assertFalse($template->hasCrossProjectInventory());
    }

    // -- Inventory::isProjectBound / belongsToOtherProjectThan -----------------

    public function testOnlyFileAndDynamicInventoriesAreProjectBound(): void
    {
        $expected = [
            Inventory::TYPE_STATIC => false,
            Inventory::TYPE_FILE => true,
            Inventory::TYPE_DYNAMIC => true,
        ];
        foreach ($expected as $type => $bound) {
            $inventory = new Inventory();
            $inventory->inventory_type = $type;
            $this->assertSame($bound, $inventory->isProjectBound(), $type);
        }
    }

    /**
     * @dataProvider inventoryProvider
     */
    public function testBelongsToOtherProjectThan(string $type, string $owner, bool $expected): void
    {
        $inventory = $this->inventory($type, $this->projectIdFor($owner));

        $this->assertSame($expected, $inventory->belongsToOtherProjectThan($this->projectId));
    }

    public function testAProjectIdFromAFormIsComparedByValue(): void
    {
        $inventory = new Inventory();
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->project_id = (string)$this->projectId;

        $this->assertFalse($inventory->belongsToOtherProjectThan($this->projectId));
        $this->assertTrue($inventory->belongsToOtherProjectThan($this->otherProjectId));
    }

    // -- Inventory::validateTemplateProjects -----------------------------------

    public function testTurningAStaticInventoryIntoAFileInventoryOfAnotherProjectIsRejected(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_STATIC, null);
        $this->legacyTemplate($this->projectId, (int)$inventory->id);
        $this->legacyTemplate($this->projectId, (int)$inventory->id);
        // A template of the inventory's new project keeps working: not counted.
        $this->legacyTemplate($this->otherProjectId, (int)$inventory->id);

        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->source_path = 'inventories/hosts.yml';
        $inventory->project_id = $this->otherProjectId;

        $this->assertFalse($inventory->save());
        $this->assertSame(['project_id' => [$this->templatesOfOtherProjectsError(2)]], $inventory->getErrors());
        $this->assertSame(Inventory::TYPE_STATIC, $this->reloadInventory((int)$inventory->id)->inventory_type);
    }

    public function testTurningAStaticInventoryIntoAFileInventoryOfItsTemplatesProjectIsAllowed(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_STATIC, null);
        $this->legacyTemplate($this->projectId, (int)$inventory->id);

        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->source_path = 'inventories/hosts.yml';
        $inventory->project_id = $this->projectId;

        $this->assertTrue($inventory->save(), (string)json_encode($inventory->getErrors()));
    }

    public function testMovingAFileInventoryAwayFromTheProjectOfItsTemplatesIsRejected(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->projectId);
        $this->legacyTemplate($this->projectId, (int)$inventory->id);

        $inventory->project_id = $this->otherProjectId;

        $this->assertFalse($inventory->save());
        $this->assertSame(['project_id' => [$this->templatesOfOtherProjectsError(1)]], $inventory->getErrors());
        $this->assertSame($this->projectId, $this->reloadInventory((int)$inventory->id)->project_id);
    }

    public function testChangingOnlyTheTypeOfALegacyCrossProjectInventoryIsChecked(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $this->legacyTemplate($this->projectId, (int)$inventory->id);

        $inventory->inventory_type = Inventory::TYPE_DYNAMIC;

        $this->assertFalse($inventory->validate());
        $this->assertSame([$this->templatesOfOtherProjectsError(1)], $inventory->getErrors('project_id'));
    }

    public function testAnUnchangedLegacyCrossProjectInventoryStillSaves(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $this->legacyTemplate($this->projectId, (int)$inventory->id);

        $this->assertTrue($inventory->validate(), (string)json_encode($inventory->getErrors()));

        // Renamed through the form: the unchanged type and project arrive as
        // strings, which is no change either.
        $inventory->load([
            'name' => 'renamed-legacy-inventory',
            'inventory_type' => Inventory::TYPE_FILE,
            'project_id' => (string)$this->otherProjectId,
        ], '');

        $this->assertTrue($inventory->save(), (string)json_encode($inventory->getErrors()));
        $this->assertSame('renamed-legacy-inventory', $this->reloadInventory((int)$inventory->id)->name);
    }

    public function testALegacyCrossProjectInventoryCanBeMovedToTheProjectOfItsTemplates(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $template = $this->legacyTemplate($this->projectId, (int)$inventory->id);

        $inventory->project_id = $this->projectId;

        $this->assertTrue($inventory->save(), (string)json_encode($inventory->getErrors()));
        $this->assertFalse($this->reloadTemplate((int)$template->id)->hasCrossProjectInventory());
    }

    public function testALegacyCrossProjectInventoryCanBeTurnedIntoAStaticOne(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->otherProjectId);
        $template = $this->legacyTemplate($this->projectId, (int)$inventory->id);

        $inventory->inventory_type = Inventory::TYPE_STATIC;
        $inventory->content = self::STATIC_CONTENT;
        $inventory->source_path = null;

        $this->assertTrue($inventory->save(), (string)json_encode($inventory->getErrors()));
        $this->assertFalse($this->reloadTemplate((int)$template->id)->hasCrossProjectInventory());
    }

    /**
     * A dynamic inventory without project counts as each template's own, so
     * templates of any project may keep using it.
     */
    public function testTurningAnInventoryIntoADynamicOneWithoutProjectIsAllowed(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_STATIC, null);
        $this->legacyTemplate($this->projectId, (int)$inventory->id);
        $this->legacyTemplate($this->otherProjectId, (int)$inventory->id);

        $inventory->inventory_type = Inventory::TYPE_DYNAMIC;
        $inventory->source_path = 'inventories/aws_ec2.yml';
        $inventory->project_id = null;

        $this->assertTrue($inventory->save(), (string)json_encode($inventory->getErrors()));
    }

    /**
     * Regression: the inventory form posts '' for "no project". Yii counts
     * null -> '' as a change and (int)'' is 0, so every template using a
     * dynamic inventory without project counted as one of another project,
     * and the inventory could not even be renamed.
     */
    public function testRenamingAUsedDynamicInventoryWithoutProjectInTheFormIsAllowed(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_DYNAMIC, null);
        $this->legacyTemplate($this->projectId, (int)$inventory->id);
        $this->legacyTemplate($this->otherProjectId, (int)$inventory->id);

        $this->assertTrue($inventory->load(['Inventory' => [
            'name' => 'renamed-dynamic',
            'inventory_type' => Inventory::TYPE_DYNAMIC,
            'project_id' => '',
            'source_path' => 'inventories/aws_ec2.yml',
        ]]));

        $this->assertTrue($inventory->save(), (string)json_encode($inventory->getErrors()));
        $this->assertNull($this->reloadInventory((int)$inventory->id)->project_id);
    }

    /**
     * Regression: the same empty form value blocked turning a used inventory
     * into a dynamic one without project and clearing the project of a used
     * dynamic inventory.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function clearedProjectProvider(): array
    {
        return [
            'static to dynamic without project' => [Inventory::TYPE_STATIC, self::NO_PROJECT],
            'dynamic with project to none' => [Inventory::TYPE_DYNAMIC, self::OWN_PROJECT],
        ];
    }

    /**
     * @dataProvider clearedProjectProvider
     */
    public function testAnEmptyProjectFromTheFormIsNoProject(string $type, string $owner): void
    {
        $inventory = $this->inventory($type, $this->projectIdFor($owner));
        $this->legacyTemplate($this->otherProjectId, (int)$inventory->id);

        $inventory->load(['Inventory' => [
            'inventory_type' => Inventory::TYPE_DYNAMIC,
            'project_id' => '',
            'source_path' => 'inventories/aws_ec2.yml',
        ]]);

        $this->assertTrue($inventory->save(), (string)json_encode($inventory->getErrors()));
        $this->assertNull($this->reloadInventory((int)$inventory->id)->project_id);
    }

    public function testAnEmptyProjectIdNeverBelongsToAnotherProject(): void
    {
        $inventory = new Inventory();
        $inventory->inventory_type = Inventory::TYPE_DYNAMIC;
        $inventory->project_id = '';

        $this->assertFalse($inventory->belongsToOtherProjectThan($this->projectId));
    }

    /**
     * Regression: the guard looked the templates up with
     * JobTemplate::find()->where(...), which drops the scope that hides
     * deleted templates. A deleted template, which cannot be restored or
     * edited, blocked moving the inventory for good.
     */
    public function testDeletedTemplatesDoNotBlockMovingAnInventory(): void
    {
        $inventory = $this->inventory(Inventory::TYPE_FILE, $this->projectId);
        $deleted = $this->legacyTemplate($this->projectId, (int)$inventory->id);
        $this->assertTrue($deleted->softDelete());

        $inventory->project_id = $this->otherProjectId;

        $this->assertTrue($inventory->save(), (string)json_encode($inventory->getErrors()));
    }

    public function testANewInventoryIsNeverChecked(): void
    {
        $used = $this->inventory(Inventory::TYPE_STATIC, null);
        $this->legacyTemplate($this->projectId, (int)$used->id);

        $inventory = new Inventory();
        $inventory->name = 'new-file-inventory';
        $inventory->inventory_type = Inventory::TYPE_FILE;
        $inventory->source_path = 'inventories/hosts.yml';
        $inventory->project_id = $this->otherProjectId;
        $inventory->created_by = $this->userId;

        $this->assertTrue($inventory->validate(), (string)json_encode($inventory->getErrors()));

        // Nothing can use an inventory before it exists, so a new record is
        // not looked up even when it carries the id of a used inventory.
        $inventory->id = $used->id;
        $this->assertTrue($inventory->validate(), (string)json_encode($inventory->getErrors()));
    }

    // -- helpers ---------------------------------------------------------------

    private function projectIdFor(string $owner): ?int
    {
        return match ($owner) {
            self::OWN_PROJECT => $this->projectId,
            self::OTHER_PROJECT => $this->otherProjectId,
            default => null,
        };
    }

    /**
     * An inventory stored without validation, read back from the database.
     */
    private function inventory(string $type, ?int $projectId): Inventory
    {
        $inventory = $this->createInventory($this->userId);
        $inventory->inventory_type = $type;
        $inventory->project_id = $projectId;
        $inventory->content = $type === Inventory::TYPE_STATIC ? self::STATIC_CONTENT : null;
        $inventory->source_path = $type === Inventory::TYPE_STATIC ? null : 'inventories/hosts.yml';
        $inventory->save(false);

        return $this->reloadInventory((int)$inventory->id);
    }

    private function newTemplate(int $projectId, int $inventoryId): JobTemplate
    {
        $template = new JobTemplate();
        $template->name = 'inventory-rule-template';
        $template->project_id = $projectId;
        $template->inventory_id = $inventoryId;
        $template->runner_group_id = $this->groupId;
        $template->playbook = 'site.yml';
        $template->verbosity = 0;
        $template->forks = 5;
        $template->become = false;
        $template->become_method = 'sudo';
        $template->become_user = 'root';
        $template->timeout_minutes = 120;
        $template->created_by = $this->userId;

        return $template;
    }

    /**
     * A template stored without validation, as before the rule existed, read
     * back from the database the way a request loads it.
     */
    private function legacyTemplate(int $projectId, int $inventoryId): JobTemplate
    {
        $template = $this->createJobTemplate($projectId, $inventoryId, $this->groupId, $this->userId);

        return $this->reloadTemplate((int)$template->id);
    }

    private function reloadTemplate(int $id): JobTemplate
    {
        $template = JobTemplate::findOne($id);
        $this->assertNotNull($template);

        return $template;
    }

    private function reloadInventory(int $id): Inventory
    {
        $inventory = Inventory::findOne($id);
        $this->assertNotNull($inventory);

        return $inventory;
    }

    private function crossProjectError(Inventory $inventory): string
    {
        return 'File and dynamic inventories must belong to the job template\'s project, but "' . $inventory->name
            . '" belongs to another project. Choose an inventory of this project or a static inventory.';
    }

    private function templatesOfOtherProjectsError(int $count): string
    {
        return $count . ' job template(s) of other projects use this inventory. File and dynamic inventories can only '
            . 'be used by job templates of their own project; switch those templates to another inventory first.';
    }
}
