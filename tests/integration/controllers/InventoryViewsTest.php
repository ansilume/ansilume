<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\InventoryUsage;
use app\components\SurveyDefinition;
use app\controllers\InventoryController;
use app\models\ApprovalRule;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedInventoryLock;
use app\services\InventoryRuleAssignment;
use app\services\InventoryUsageService;
use app\services\JobTemplateRuleAssignment;
use app\tests\integration\CountsQueries;
use app\tests\integration\services\InventoryWriteFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\Response;

/**
 * The inventory pages as each user sees them (C17):
 *
 * - the form of a gated inventory, for a user who may change it but not
 *   assign approval rules: the notice, the host fields disabled in their
 *   fieldset (a browser posts none of them, so a rename is saved), no
 *   editor; the rule select only for rule assigners, the rule as text for
 *   everyone else; the note on where the hosts of file and dynamic
 *   inventories come from;
 * - the inventory's page: the approval rule as a link or as text, the
 *   notice, Edit and Delete as the permissions, the team scope and the lock
 *   allow, the note for gated file and dynamic inventories, and the job
 *   templates that use the inventory;
 * - the list: a badge on gated inventories, at a cost that does not grow
 *   with them.
 *
 * Names with markup come out as text everywhere.
 */
class InventoryViewsTest extends WebControllerTestCase
{
    use CountsQueries;
    use InventoryWriteFixtures;
    use RendersRealViews;
    use TeamScopeFixtures;

    /** A name with markup and both quotes: every page shows it as text. */
    private const MARKUP = '<img src=x onerror=alert(1)> "q" \'a\'';

    /** The inputs of the host fieldset, by ID: type, content, project and source path. */
    private const HOST_INPUTS = ['inventory-type', 'inventory-content', 'inventory-project_id', 'inventory-source_path'];

    /** The fieldset around them. */
    private const HOST_FIELDSET = '//fieldset[@id="inventory-host-fields"]';

    protected function setUp(): void
    {
        parent::setUp();
        \Yii::$app->session->removeAllFlashes();
    }

    protected function tearDown(): void
    {
        $this->detachInventoryHandlers();
        \Yii::$app->session->removeAllFlashes();
        parent::tearDown();
    }

    // -- the form ----------------------------------------------------------------

    /**
     * A user who may change a gated inventory, but not assign rules, gets
     * the notice, the host fields disabled in their fieldset and the plain
     * content field instead of the editor, which would take edits that are
     * not posted; name and description stay open.
     */
    public function testTheLockedFormDisablesTheHostFieldsAndKeepsNameAndDescriptionOpen(): void
    {
        $operator = $this->signedIn('operator');
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $this->rule($operator));

        $x = $this->xpathOf($this->formPage((int)$inventory->id));

        $this->assertSame(GatedInventoryLock::NOTICE, $this->text($x, '//div[@id="inventory-locked-notice"][contains(@class, "alert-info")]'));
        $this->assertSame(1, $this->nodeCount($x, self::HOST_FIELDSET . '[@disabled]'), 'the host fieldset is disabled');
        foreach (self::HOST_INPUTS as $id) {
            $this->assertSame(1, $this->nodeCount($x, self::HOST_FIELDSET . '//*[@id="' . $id . '"]'), "{$id} is in the fieldset");
        }
        $this->assertSame(0, $this->nodeCount($x, '//*[@data-yaml-editor]'), 'no editor on a locked form');
        foreach (['inventory-name', 'inventory-description'] as $id) {
            $this->assertSame(1, $this->nodeCount($x, '//*[@id="' . $id . '"][not(@disabled)][not(ancestor::fieldset)]'), "{$id} stays open");
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}> who, and whether the inventory is gated
     */
    public static function unlockedFormProvider(): array
    {
        return [
            'an operator, an inventory without a rule' => ['operator', false],
            'an admin, a gated inventory' => ['admin', true],
        ];
    }

    /**
     * @dataProvider unlockedFormProvider
     */
    public function testAnUnlockedFormHasNoNoticeAndKeepsTheEditor(string $role, bool $gated): void
    {
        $user = $this->signedIn($role);
        $inventory = $this->staticInventory((int)$user->id);
        if ($gated) {
            $inventory = $this->gate($inventory, $this->rule($user));
        }

        foreach ([$this->formPage((int)$inventory->id), $this->formPage(null)] as $html) {
            $x = $this->xpathOf($html);
            $this->assertSame(0, $this->nodeCount($x, '//*[@id="inventory-locked-notice"]'));
            $this->assertSame(1, $this->nodeCount($x, self::HOST_FIELDSET . '[not(@disabled)]'));
            $this->assertSame('1', $this->attributeOf($x, '//textarea[@id="inventory-content"]', 'data-yaml-editor'));
        }
    }

    /**
     * Rule assigners choose the rule in a select that posts
     * Inventory[approval_rule_id] ('' for none), with the form's hint;
     * everyone else sees the rule as text, why they may not change it, and
     * posts nothing for it.
     */
    public function testOnlyRuleAssignersGetTheRuleSelect(): void
    {
        $admin = $this->signedIn('admin');
        $rule = $this->rule($admin, self::MARKUP);
        $inventory = $this->gate($this->staticInventory((int)$admin->id), $rule);

        $html = $this->formPage((int)$inventory->id);
        $x = $this->xpathOf($html);
        $select = '//select[@id="inventory-approval_rule_id"][@name="Inventory[approval_rule_id]"]';
        $this->assertSame('— None —', $this->text($x, $select . '/option[1][@value=""]'));
        $this->assertSame(JobTemplateRuleAssignment::label($rule), $this->text($x, $select . '/option[@value="' . $rule->id . '"][@selected]'));
        $this->assertSame(
            InventoryRuleAssignment::FORM_HINT,
            $this->text($x, '//div[contains(@class, "field-inventory-approval_rule_id")]//*[contains(@class, "hint-block")]')
        );
        $this->assertSame('Approval rule', $this->text($x, '//label[@for="inventory-approval_rule_id"]'));
        $this->assertSame(0, $this->nodeCount($x, '//*[@id="inventory-approval-rule-readonly"]'));
        $this->assertStringContainsString('>' . Html::encode(JobTemplateRuleAssignment::label($rule)) . '</option>', $html);
        $this->assertStringNotContainsString(self::MARKUP, $html);

        $this->signedIn('operator');
        foreach ([(int)$inventory->id => JobTemplateRuleAssignment::label($rule), 0 => 'None'] as $id => $shown) {
            $html = $this->formPage($id === 0 ? null : $id);
            $x = $this->xpathOf($html);
            $this->assertSame(0, $this->nodeCount($x, '//*[@name="Inventory[approval_rule_id]"]'), 'nothing posts a rule');
            $readonly = '//div[@id="inventory-approval-rule-readonly"]';
            $this->assertSame($shown, $this->text($x, $readonly . '/*[contains(@class, "form-control-plaintext")]'));
            $this->assertSame(ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED, $this->text($x, $readonly . '/*[contains(@class, "form-text")]'));
            $this->assertStringNotContainsString(self::MARKUP, $html);
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}> who, whether the inventory is gated, whether the note shows
     */
    public static function formNoteProvider(): array
    {
        return [
            'an admin, an inventory without a rule' => ['admin', false, true],
            'an admin, a gated inventory' => ['admin', true, true],
            'an operator, an inventory without a rule' => ['operator', false, false],
            'an operator, a gated inventory' => ['operator', true, true],
        ];
    }

    /**
     * The note on where the hosts of a file or dynamic inventory come from
     * is the last element of #field-project, which the form shows for those
     * types only: for rule assigners always, for everyone else on a gated
     * inventory.
     *
     * @dataProvider formNoteProvider
     */
    public function testTheFormShowsTheRepositoryHostsNoteToThoseItConcerns(string $role, bool $gated, bool $shown): void
    {
        $user = $this->signedIn($role);
        $inventory = $this->projectBoundInventory($user, Inventory::TYPE_FILE);
        if ($gated) {
            $inventory = $this->gate($inventory, $this->rule($user));
        }

        $x = $this->xpathOf($this->formPage((int)$inventory->id));

        $note = '//div[@id="field-project"]/*[last()][self::p][@id="inventory-repository-hosts-note"][contains(@class, "form-text")]';
        $this->assertSame($shown ? 1 : 0, $this->nodeCount($x, $note));
        $this->assertSame($shown ? 1 : 0, $this->nodeCount($x, '//*[@id="inventory-repository-hosts-note"]'), 'nowhere else');
        if ($shown) {
            $this->assertSame(GatedInventoryLock::REPOSITORY_HOSTS_NOTE, $this->text($x, $note));
        }
    }

    /**
     * New Inventory: an admin may give the new inventory a rule, so the
     * note shows; everyone else creates an inventory without one.
     */
    public function testTheCreateFormShowsTheNoteToRuleAssignersOnly(): void
    {
        $this->signedIn('admin');
        $this->assertSame(1, $this->nodeCount($this->xpathOf($this->formPage(null)), '//div[@id="field-project"]/p[@id="inventory-repository-hosts-note"]'));

        $this->signedIn('operator');
        $this->assertSame(0, $this->nodeCount($this->xpathOf($this->formPage(null)), '//*[@id="inventory-repository-hosts-note"]'));
    }

    /**
     * The form of a gated inventory, as a browser posts it for a user the
     * lock applies to, saves a new name: the disabled host fields are not
     * posted, so content that a browser would send back with CRLF line
     * breaks cannot count as a change of the hosts (CHANGE_REFUSED).
     */
    public function testALockedRenameThroughTheFormAsABrowserPostsItIsSaved(): void
    {
        $operator = $this->signedIn('operator');
        $inventory = $this->gate($this->staticInventory((int)$operator->id), $this->rule($operator));
        $hosts = $this->hostColumns((int)$inventory->id);
        $body = $this->browserPost($this->formPage((int)$inventory->id), 'inventory-form');
        $this->assertIsArray($body['Inventory'] ?? null);
        $this->assertSame(['name', 'description'], array_keys($body['Inventory']), 'only the open fields are posted');
        $body['Inventory']['name'] = 'inv-views-renamed';
        $this->setPost($body);
        $ctrl = $this->capturingController();

        $ctrl->actionUpdate((int)$inventory->id);

        $this->assertSame(['view', 'id' => (int)$inventory->id], $ctrl->capturedRedirect, 'saved');
        $this->assertSame('inv-views-renamed', $this->reloaded((int)$inventory->id)->name);
        $this->assertSame($hosts, $this->hostColumns((int)$inventory->id), 'the hosts and the rule stay');
        $this->assertSame(['success' => sprintf(InventoryController::FLASH_UPDATED, 'inv-views-renamed')], \Yii::$app->session->getAllFlashes());
    }

    /**
     * An admin's form posts the rule select: the stored rule is no change,
     * and None ('') removes the rule.
     */
    public function testTheRuleSelectPostsTheStoredRuleAndNoneRemovesIt(): void
    {
        $admin = $this->signedIn('admin');
        $rule = $this->rule($admin);
        $inventory = $this->gate($this->staticInventory((int)$admin->id), $rule);
        $body = $this->browserPost($this->formPage((int)$inventory->id), 'inventory-form');
        $this->assertIsArray($body['Inventory'] ?? null);
        $this->assertSame((string)$rule->id, $body['Inventory']['approval_rule_id'] ?? null);
        $body['Inventory']['name'] = 'inv-views-admin-renamed';
        $ctrl = $this->capturingController();

        $this->setPost($body);
        $ctrl->actionUpdate((int)$inventory->id);

        $this->assertSame(['view', 'id' => (int)$inventory->id], $ctrl->capturedRedirect);
        $this->assertSame((int)$rule->id, $this->reloaded((int)$inventory->id)->getAttribute('approval_rule_id'), 'the rule stays');

        $body['Inventory']['approval_rule_id'] = '';
        $this->setPost($body);
        $ctrl->actionUpdate((int)$inventory->id);

        $this->assertNull($this->reloaded((int)$inventory->id)->getAttribute('approval_rule_id'), 'None removes the rule');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fieldErrorProvider(): array
    {
        return [
            'a rule that a job template on the inventory cannot share' => ['rules-differ'],
            'a dynamic inventory with a rule and no source path' => ['no-source'],
        ];
    }

    /**
     * An admin's save that the rules refuse shows the form again with the
     * error under its field: RULES_DIFFER under the rule select,
     * SOURCE_PATH_REQUIRED_WHEN_GATED under the source path; nothing is
     * saved.
     *
     * @dataProvider fieldErrorProvider
     */
    public function testARefusedRuleShowsItsErrorUnderItsField(string $case): void
    {
        $admin = $this->signedIn('admin');
        $rule = $this->rule($admin);
        if ($case === 'rules-differ') {
            $inventory = $this->staticInventory((int)$admin->id);
            $this->templateOn((int)$inventory->id, (int)$admin->id, $this->rule($admin), 'inv-views-own-rule');
            $fields = [InventoryRuleAssignment::FIELD => (string)$rule->id];
            $field = InventoryRuleAssignment::FIELD;
            $expected = sprintf(InventoryRuleAssignment::RULES_DIFFER, SurveyDefinition::quote('inv-views-own-rule'));
        } else {
            $inventory = $this->projectBoundInventory($admin, Inventory::TYPE_DYNAMIC);
            $fields = [InventoryRuleAssignment::FIELD => (string)$rule->id, 'source_path' => ''];
            $field = 'source_path';
            $expected = Inventory::SOURCE_PATH_REQUIRED_WHEN_GATED;
        }
        $this->setPost(['Inventory' => $fields]);
        $ctrl = $this->realViewController();

        $html = $this->withRealViews($ctrl, '/inventory/update?id=' . $inventory->id, static fn (): Response|string => $ctrl->actionUpdate((int)$inventory->id));

        $this->assertIsString($html, 'the form is shown again');
        $this->assertNull($this->reloaded((int)$inventory->id)->getAttribute('approval_rule_id'), 'nothing is saved');
        $this->assertSame($expected, $this->text($this->xpathOf($html), '//div[contains(@class, "field-inventory-' . $field . '")]//*[contains(@class, "help-block")]'));
    }

    // -- the inventory's page ----------------------------------------------------

    /**
     * Everyone sees the inventory's approval rule: as a link for users who
     * may open rules (approval-rule.view), else as text; None without one.
     */
    public function testThePageNamesTheRuleAsALinkOrAsText(): void
    {
        $admin = $this->signedIn('admin');
        $rule = $this->rule($admin, self::MARKUP);
        $inventory = $this->gate($this->staticInventory((int)$admin->id), $rule);
        $row = '//dd[@id="inventory-approval-rule"]';

        $html = $this->viewPage((int)$inventory->id);
        $x = $this->xpathOf($html);
        $this->assertSame('Approval rule', $this->text($x, $row . '/preceding-sibling::dt[1]'));
        $this->assertSame(JobTemplateRuleAssignment::label($rule), $this->text($x, $row . '/a[@href="/approval-rule/view?id=' . $rule->id . '"]'));
        $this->assertStringNotContainsString(self::MARKUP, $html);

        $this->loginAs($this->ruleBlind());
        $html = $this->viewPage((int)$inventory->id);
        $x = $this->xpathOf($html);
        $this->assertSame(0, $this->nodeCount($x, $row . '//a'), 'no link without approval-rule.view');
        $this->assertSame(JobTemplateRuleAssignment::label($rule), $this->text($x, $row));
        $this->assertStringNotContainsString(self::MARKUP, $html);

        $this->assertSame('None', $this->text($this->xpathOf($this->viewPage((int)$this->staticInventory((int)$admin->id)->id)), $row));
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool, 4: bool}>
     *     who, whether the inventory is gated, then whether Edit, Delete and the notice show
     */
    public static function controlsProvider(): array
    {
        return [
            'an admin, a gated inventory' => ['admin', true, true, true, false],
            'an operator, an inventory without a rule' => ['operator', false, true, false, false],
            'an operator, a gated inventory' => ['operator', true, true, false, true],
            'a deleter without approval-rule.update, an inventory without a rule' => ['deleter', false, true, true, false],
            'a deleter without approval-rule.update, a gated inventory' => ['deleter', true, true, false, true],
            'a viewer, a gated inventory' => ['viewer', true, false, false, false],
            'a superadmin without roles, a gated inventory' => ['superadmin', true, true, true, false],
            'an operator whose team only views the project, a gated inventory' => ['team-viewer', true, false, false, false],
        ];
    }

    /**
     * Edit needs inventory.update and operator access to the inventory's
     * project; Delete inventory.delete, that access, and the lock not
     * applying; superadmins pass both permission checks. The notice is
     * for users who may change the inventory, but not its hosts.
     *
     * @dataProvider controlsProvider
     */
    public function testEditDeleteAndTheNoticeFollowThePermissionsTheProjectAndTheLock(
        string $who,
        bool $gated,
        bool $edit,
        bool $delete,
        bool $notice
    ): void {
        $inventory = $this->inventoryFor($who, $gated);

        $x = $this->xpathOf($this->viewPage((int)$inventory->id));

        $this->assertSame($edit ? 1 : 0, $this->nodeCount($x, '//a[@href="/inventory/update?id=' . $inventory->id . '"][normalize-space()="Edit"]'), 'Edit');
        $this->assertSame(
            $delete ? 1 : 0,
            $this->nodeCount($x, '//form[@method="post"][@action="/inventory/delete?id=' . $inventory->id . '"][contains(@onsubmit, "Delete")]//button[@type="submit"]'),
            'Delete'
        );
        $this->assertSame($notice ? [GatedInventoryLock::NOTICE] : [], $this->texts($x, '//div[@id="inventory-locked-notice"][contains(@class, "alert-info")]'), 'the notice');
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}> the type, whether it is gated, whether the note shows
     */
    public static function pageNoteProvider(): array
    {
        return [
            'a gated file inventory' => [Inventory::TYPE_FILE, true, true],
            'a gated dynamic inventory' => [Inventory::TYPE_DYNAMIC, true, true],
            'a gated static inventory' => [Inventory::TYPE_STATIC, true, false],
            'a file inventory without a rule' => [Inventory::TYPE_FILE, false, false],
            'a dynamic inventory without a rule' => [Inventory::TYPE_DYNAMIC, false, false],
        ];
    }

    /**
     * The page of a gated file or dynamic inventory says where its hosts
     * come from, at the end of the Details card; no other page does.
     *
     * @dataProvider pageNoteProvider
     */
    public function testThePageShowsTheRepositoryHostsNoteForGatedFileAndDynamicInventoriesOnly(string $type, bool $gated, bool $shown): void
    {
        $admin = $this->signedIn('admin');
        $inventory = $type === Inventory::TYPE_STATIC ? $this->staticInventory((int)$admin->id) : $this->projectBoundInventory($admin, $type);
        if ($gated) {
            $inventory = $this->gate($inventory, $this->rule($admin));
        }

        $x = $this->xpathOf($this->viewPage((int)$inventory->id));

        $note = '//div[contains(@class, "card-body")][.//dd[@id="inventory-approval-rule"]]/*[last()][self::p][@id="inventory-repository-hosts-note"]';
        $this->assertSame($shown ? [GatedInventoryLock::REPOSITORY_HOSTS_NOTE] : [], $this->texts($x, $note . '[contains(@class, "small")][contains(@class, "text-muted")]'));
        $this->assertSame($shown ? 1 : 0, $this->nodeCount($x, '//*[@id="inventory-repository-hosts-note"]'), 'nowhere else');
    }

    /**
     * The Job templates card lists the live job templates the viewer may
     * see, each with its project and its own approval rule ('—' for none),
     * and counts those in projects the viewer cannot see and the deleted
     * ones that still refer to the inventory.
     */
    public function testTheJobTemplatesCardListsWhatTheViewerMaySeeAndCountsTheRest(): void
    {
        $scope = $this->teamScope();
        $rule = $this->rule($scope['admin'], self::MARKUP);
        $own = $scope['own'];
        $own->name = self::MARKUP;
        $own->approval_rule_id = (int)$rule->id;
        $own->save(false);
        $this->assertSame(1, Project::updateAll(['name' => self::MARKUP], ['id' => $own->project_id]));
        $scope['open']->deleted_at = time();
        $scope['open']->save(false);
        $this->loginAs($scope['member']);

        $html = $this->viewPage((int)$own->inventory_id);
        $x = $this->xpathOf($html);

        $rows = '//div[@id="inventory-job-templates"]//table[@id="inventory-job-templates-list"]/tbody/tr';
        $this->assertSame([(string)$own->id, (string)$scope['viewed']->id], $this->attributesOf($x, $rows, 'data-job-template-id'), 'own and viewed, by name');
        $ownRow = $rows . '[@data-job-template-id="' . $own->id . '"]';
        $this->assertSame(self::MARKUP, $this->text($x, $ownRow . '/td[1]/a[@href="/job-template/view?id=' . $own->id . '"]'));
        $this->assertSame(self::MARKUP, $this->text($x, $ownRow . '/td[2]'));
        $this->assertSame(self::MARKUP, $this->text($x, $ownRow . '/td[@data-template-rule]'));
        $viewedRow = $rows . '[@data-job-template-id="' . $scope['viewed']->id . '"]';
        $this->assertSame((string)$scope['viewed']->project?->name, $this->text($x, $viewedRow . '/td[2]'));
        $this->assertSame('—', $this->text($x, $viewedRow . '/td[@data-template-rule]'));
        $this->assertSame(sprintf(InventoryUsage::CARD_HIDDEN, 1), $this->text($x, '//p[@id="inventory-job-templates-hidden"]'));
        $this->assertSame(sprintf(InventoryUsage::CARD_DELETED, 1), $this->text($x, '//p[@id="inventory-job-templates-deleted"]'));
        $this->assertSame(0, $this->nodeCount($x, '//*[@id="inventory-job-templates-empty"]'));
        $this->assertStringNotContainsString(self::MARKUP, $html);
        $this->assertStringNotContainsString(Html::encode((string)$scope['foreign']->name), $html, 'the hidden template is not named');
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}> what uses the inventory, the card's lines by ID
     */
    public static function cardLinesProvider(): array
    {
        return [
            'nothing' => ['nothing', ['inventory-job-templates-empty']],
            'only a job template the viewer cannot see' => ['hidden', ['inventory-job-templates-hidden']],
            'only a deleted job template' => ['deleted', ['inventory-job-templates-empty', 'inventory-job-templates-deleted']],
        ];
    }

    /**
     * Without a template the viewer may see there is no list: the card says
     * that no job template uses the inventory, unless a hidden one does.
     *
     * @dataProvider cardLinesProvider
     * @param list<string> $lines
     */
    public function testTheJobTemplatesCardSaysWhatItCannotList(string $usedBy, array $lines): void
    {
        $scope = $this->teamScope();
        $inventory = $this->staticInventory((int)$scope['admin']->id);
        if ($usedBy !== 'nothing') {
            $template = $this->templateIn((int)$scope[$usedBy === 'hidden' ? 'foreign' : 'own']->project_id, (int)$inventory->id, (int)$scope['admin']->id);
            if ($usedBy === 'deleted') {
                $template->deleted_at = time();
                $template->save(false);
            }
        }
        $this->loginAs($scope['member']);

        $x = $this->xpathOf($this->viewPage((int)$inventory->id));

        $this->assertSame(0, $this->nodeCount($x, '//*[@id="inventory-job-templates-list"]'), 'no list');
        $this->assertSame($lines, $this->attributesOf($x, '//div[@id="inventory-job-templates"]//p[starts-with(@id, "inventory-job-templates-")]', 'id'));
        $expected = [
            'inventory-job-templates-empty' => InventoryUsage::CARD_EMPTY,
            'inventory-job-templates-hidden' => sprintf(InventoryUsage::CARD_HIDDEN, 1),
            'inventory-job-templates-deleted' => sprintf(InventoryUsage::CARD_DELETED, 1),
        ];
        foreach ($lines as $line) {
            $this->assertSame($expected[$line], $this->text($x, '//p[@id="' . $line . '"]'));
        }
    }

    // -- the list ----------------------------------------------------------------

    /**
     * Gated inventories carry the badge in their Type cell, after the type,
     * with the rule in its title; never in the Name cell.
     */
    public function testTheListShowsTheBadgeInTheTypeCellOfGatedInventoriesOnly(): void
    {
        $admin = $this->signedIn('admin');
        $gated = $this->gate($this->staticInventory((int)$admin->id), $this->rule($admin, self::MARKUP));
        $open = $this->staticInventory((int)$admin->id);

        $html = $this->indexPage();
        $x = $this->xpathOf($html);

        $badge = $this->listRow((int)$gated->id) . '/td[3]/span[@data-inventory-gated-badge]';
        $this->assertSame(InventoryRuleAssignment::GATED_BADGE, $this->text($x, $badge));
        $this->assertSame(sprintf(InventoryRuleAssignment::GATED_TITLE, self::MARKUP), $this->attributeOf($x, $badge, 'title'));
        $this->assertSame('badge text-bg-warning ms-1', $this->attributeOf($x, $badge, 'class'));
        $this->assertSame(1, $this->nodeCount($x, $badge . '/preceding-sibling::span[contains(@class, "text-bg-secondary")]'), 'after the type');
        $this->assertSame(1, $this->nodeCount($x, $this->listRow((int)$gated->id) . '//span[@data-inventory-gated-badge]'), 'not in the Name cell');
        $this->assertSame(0, $this->nodeCount($x, $this->listRow((int)$open->id) . '//span[@data-inventory-gated-badge]'));
        $this->assertStringNotContainsString(self::MARKUP, $html);
    }

    /**
     * The list loads the rules of its inventories with them: twelve gated
     * inventories, each with its own rule, project and creator, cost as
     * much as two.
     */
    public function testTheListCostsAsMuchWithTwelveGatedInventoriesAsWithTwo(): void
    {
        $this->signedIn('admin');
        $ids = $this->gatedInventories(2);
        $this->indexPage();
        $few = $this->queriesOf(fn (): string => $this->indexPage());

        $ids = array_merge($ids, $this->gatedInventories(10));
        $this->indexPage();

        $this->assertSame($few, $this->queriesOf(fn (): string => $this->indexPage()));
        $x = $this->xpathOf($this->indexPage());
        foreach ($ids as $id) {
            $this->assertSame(1, $this->nodeCount($x, $this->listRow($id) . '/td[3]/span[@data-inventory-gated-badge]'), "inventory #{$id}");
        }
    }

    // -- a schema cached from before m000088 -------------------------------------

    /**
     * Code that runs with a schema cached from before m000088 has no rule
     * column: the form shows no Approval section and the page no rule, and
     * neither fails.
     */
    public function testAModelWithoutTheRuleColumnShowsThePagesWithoutTheRule(): void
    {
        $admin = $this->signedIn('admin');
        $inventory = $this->staleModel((int)$this->staticInventory((int)$admin->id)->id);
        $ctrl = $this->realViewController();

        $form = $this->withRealViews($ctrl, '/inventory/update?id=' . $inventory->id, static fn (): string => $ctrl->render('form', [
            'model' => $inventory,
            'projects' => [],
            'canAssignRule' => true,
            'approvalRuleOptions' => [],
            'changeLocked' => false,
            'repositoryHostsNote' => GatedInventoryLock::REPOSITORY_HOSTS_NOTE,
        ]));
        $x = $this->xpathOf($form);
        $this->assertSame(0, $this->nodeCount($x, '//h5[normalize-space()="Approval"]'));
        $this->assertSame(0, $this->nodeCount($x, '//*[@id="inventory-approval_rule_id" or @id="inventory-approval-rule-readonly"]'));
        $this->assertSame(1, $this->nodeCount($x, '//input[@id="inventory-name"]'));

        $usage = (new InventoryUsageService())->forInventory($inventory, (int)$admin->id);
        $page = $this->withRealViews($ctrl, '/inventory/view?id=' . $inventory->id, static fn (): string => $ctrl->render('view', [
            'model' => $inventory,
            'canChange' => true,
            'canDelete' => true,
            'changeLocked' => false,
            'canViewRules' => true,
            'usage' => $usage,
        ]));
        $this->assertSame('None', $this->text($this->xpathOf($page), '//dd[@id="inventory-approval-rule"]'));
    }

    // -- helpers -----------------------------------------------------------------

    /**
     * A new user of $role, signed in: admin, operator or viewer.
     */
    private function signedIn(string $role): User
    {
        $user = $this->createUserWithRole('inv-views-' . $role, $role);
        $this->loginAs($user);

        return $user;
    }

    /**
     * A user whose only role holds inventory.view, but not
     * approval-rule.view: they see rules as text.
     */
    private function ruleBlind(): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('inv-views-rule-blind-' . bin2hex(random_bytes(4)));
        $auth->add($role);
        $permission = $auth->getPermission('inventory.view');
        $this->assertNotNull($permission);
        $auth->addChild($role, $permission);
        $user = $this->createUser('inv-views-rule-blind');
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    /**
     * Signs in the user $who and returns the inventory they look at, gated
     * when $gated: without a project, except for 'team-viewer', a team
     * operator whose team only views the inventory's project.
     */
    private function inventoryFor(string $who, bool $gated): Inventory
    {
        $projectId = null;
        if ($who === 'team-viewer') {
            $scope = $this->teamScope();
            $user = $scope['member'];
            $projectId = (int)$scope['viewed']->project_id;
        } elseif ($who === 'deleter') {
            $user = $this->inventoryDeleter();
        } elseif ($who === 'superadmin') {
            $user = $this->createUser('inv-views-superadmin');
            $user->is_superadmin = true;
            $this->assertTrue($user->save(false));
        } else {
            $user = $this->createUserWithRole('inv-views-' . $who, $who);
        }
        $this->loginAs($user);
        $inventory = $this->staticInventory((int)$user->id, $projectId);

        return $gated ? $this->gate($inventory, $this->rule($user)) : $inventory;
    }

    private function rule(User $creator, string $name = ''): ApprovalRule
    {
        $rule = $this->createApprovalRule((int)$creator->id);
        if ($name !== '') {
            $rule->name = $name;
            $this->assertTrue($rule->save(false));
        }

        return $rule;
    }

    /**
     * A $type inventory (file or dynamic) of a new project, with a source path.
     */
    private function projectBoundInventory(User $creator, string $type): Inventory
    {
        $inventory = $this->createInventory((int)$creator->id);
        $inventory->inventory_type = $type;
        $inventory->content = null;
        $inventory->source_path = 'inventories/hosts.yml';
        $inventory->project_id = (int)$this->createProject((int)$creator->id)->id;
        $this->assertTrue($inventory->save(false));

        return $this->reloaded((int)$inventory->id);
    }

    /**
     * $count static inventories, each gated by its own rule, in its own
     * project and by its own creator.
     *
     * @return list<int> their IDs
     */
    private function gatedInventories(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $creator = $this->createUser('inv-views-creator');
            $inventory = $this->staticInventory((int)$creator->id, (int)$this->createProject((int)$creator->id)->id);
            $ids[] = (int)$this->gate($inventory, $this->rule($creator))->id;
        }

        return $ids;
    }

    private function templateIn(int $projectId, int $inventoryId, int $createdBy): JobTemplate
    {
        return $this->createJobTemplate($projectId, $inventoryId, (int)$this->createRunnerGroup($createdBy)->id, $createdBy);
    }

    /**
     * Inventory #$id as code with a schema cached from before m000088 loads
     * it: a model without approval_rule_id.
     */
    private function staleModel(int $id): Inventory
    {
        $legacy = new class () extends Inventory {
            public function attributes(): array
            {
                return array_values(array_diff(parent::attributes(), ['approval_rule_id']));
            }

            /** The form's field names, as of Inventory (an anonymous class has none of its own). */
            public function formName(): string
            {
                return 'Inventory';
            }
        };
        $inventory = $legacy::findOne($id);
        $this->assertInstanceOf(Inventory::class, $inventory);
        $this->assertFalse($inventory->hasAttribute('approval_rule_id'));

        return $inventory;
    }

    /**
     * The columns of inventory #$id that say where its jobs run, and its rule.
     *
     * @return array<string, mixed>
     */
    private function hostColumns(int $id): array
    {
        $row = $this->storedRow($id);

        return array_intersect_key($row, array_flip(['inventory_type', 'content', 'source_path', 'project_id', 'approval_rule_id']));
    }

    /**
     * The XPath of the list row of inventory #$id.
     */
    private function listRow(int $id): string
    {
        return '//table[@id="inventory-table"]/tbody/tr[td[1][normalize-space()="' . $id . '"]]';
    }

    private function viewPage(int $id): string
    {
        $ctrl = $this->realViewController();

        return $this->page($ctrl, '/inventory/view?id=' . $id, static fn (): string => $ctrl->actionView($id));
    }

    /**
     * The edit form of inventory #$id, or New Inventory for null.
     */
    private function formPage(?int $id): string
    {
        $ctrl = $this->realViewController();

        return $id === null
            ? $this->page($ctrl, '/inventory/create', static fn (): Response|string => $ctrl->actionCreate())
            : $this->page($ctrl, '/inventory/update?id=' . $id, static fn (): Response|string => $ctrl->actionUpdate($id));
    }

    private function indexPage(): string
    {
        $ctrl = $this->realViewController();

        return $this->page($ctrl, '/inventory/index', static fn (): string => $ctrl->actionIndex());
    }

    /**
     * The page that $action renders, without the layout, as the answer to a
     * GET of $url.
     */
    private function page(InventoryController $ctrl, string $url, callable $action): string
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = \Yii::$app->request;
        $this->assertInstanceOf(\yii\web\Request::class, $request);
        $request->setBodyParams([]);
        $html = $this->withRealViews($ctrl, $url, $action);
        $this->assertIsString($html, 'the action renders the page');

        return $html;
    }

    /**
     * The directory of the inventory views. A check of these tests against
     * changed copies of the views overrides it.
     */
    protected function viewPath(): string
    {
        return '@app/views/inventory';
    }

    private function realViewController(): InventoryController
    {
        $ctrl = new InventoryController('inventory', \Yii::$app);
        $ctrl->layout = false;
        $ctrl->setViewPath($this->viewPath());

        return $ctrl;
    }

    /**
     * @return InventoryController&object{capturedRedirect: mixed}
     */
    private function capturingController(): InventoryController
    {
        return new class ('inventory', \Yii::$app) extends InventoryController {
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function render($view, $params = []): string
            {
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;

                return new Response();
            }
        };
    }

    /**
     * What a browser posts for the form #$formId of $html: the name and
     * value of every control that is not disabled, itself or by a disabled
     * fieldset, with a textarea's line breaks as CRLF and without the
     * buttons (HTML's form submission), as Yii reads them from the body.
     *
     * @return array<array-key, mixed>
     */
    private function browserPost(string $html, string $formId): array
    {
        $xpath = $this->xpathOf($html);
        $controls = $xpath->query('//form[@id="' . $formId . '"]//*[self::input or self::textarea or self::select][@name]');
        $this->assertInstanceOf(\DOMNodeList::class, $controls);
        $pairs = [];
        foreach ($controls as $control) {
            $this->assertInstanceOf(\DOMElement::class, $control);
            if ($xpath->evaluate('boolean(ancestor-or-self::*[@disabled])', $control) === true) {
                continue;
            }
            foreach ($this->postedValues($xpath, $control) as $value) {
                $pairs[] = rawurlencode($control->getAttribute('name')) . '=' . rawurlencode($value);
            }
        }
        parse_str(implode('&', $pairs), $body);

        return $body;
    }

    /**
     * @return list<string> what a browser posts for the enabled control $control
     */
    private function postedValues(\DOMXPath $xpath, \DOMElement $control): array
    {
        if ($control->tagName === 'textarea') {
            return [(string)preg_replace('/\r\n|\r|\n/', "\r\n", $control->textContent)];
        }
        if ($control->tagName === 'select') {
            $options = $this->attributesOf($xpath, './/option[@selected]', 'value', $control);
            $first = $this->attributesOf($xpath, './/option[1]', 'value', $control);

            return $options === [] && !$control->hasAttribute('multiple') ? $first : $options;
        }
        $type = strtolower($control->getAttribute('type'));
        if (in_array($type, ['submit', 'button', 'reset', 'image'], true)) {
            return [];
        }
        if (in_array($type, ['checkbox', 'radio'], true) && !$control->hasAttribute('checked')) {
            return [];
        }

        return [$control->getAttribute('value')];
    }

    private function xpathOf(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
        }
        $this->assertTrue($loaded, 'the page parses as HTML');

        return new \DOMXPath($document);
    }

    private function nodeCount(\DOMXPath $xpath, string $query): int
    {
        $nodes = $xpath->query($query);
        $this->assertInstanceOf(\DOMNodeList::class, $nodes, $query);

        return $nodes->length;
    }

    /**
     * The text of every node $query finds, its white space collapsed.
     *
     * @return list<string>
     */
    private function texts(\DOMXPath $xpath, string $query): array
    {
        $nodes = $xpath->query($query);
        $this->assertInstanceOf(\DOMNodeList::class, $nodes, $query);
        $texts = [];
        foreach ($nodes as $node) {
            $texts[] = trim((string)preg_replace('/\s+/u', ' ', $node->textContent));
        }

        return $texts;
    }

    /**
     * The text of the one node $query finds.
     */
    private function text(\DOMXPath $xpath, string $query): string
    {
        $texts = $this->texts($xpath, $query);
        $this->assertCount(1, $texts, "one node: {$query}");

        return $texts[0];
    }

    /**
     * The attribute $name of every element $query finds (below $context).
     *
     * @return list<string>
     */
    private function attributesOf(\DOMXPath $xpath, string $query, string $name, ?\DOMNode $context = null): array
    {
        $nodes = $xpath->query($query, $context);
        $this->assertInstanceOf(\DOMNodeList::class, $nodes, $query);
        $values = [];
        foreach ($nodes as $node) {
            $this->assertInstanceOf(\DOMElement::class, $node);
            $values[] = $node->getAttribute($name);
        }

        return $values;
    }

    /**
     * The attribute $name of the one element $query finds, which has it.
     */
    private function attributeOf(\DOMXPath $xpath, string $query, string $name): string
    {
        $nodes = $xpath->query($query);
        $this->assertInstanceOf(\DOMNodeList::class, $nodes, $query);
        $this->assertSame(1, $nodes->length, "one element: {$query}");
        $node = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);
        $this->assertTrue($node->hasAttribute($name), "{$query} has {$name}");

        return $node->getAttribute($name);
    }
}
