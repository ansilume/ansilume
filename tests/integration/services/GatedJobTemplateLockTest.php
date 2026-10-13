<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\VaultAssignmentResult;
use app\models\ApprovalRule;
use app\models\AuditLog;
use app\models\Credential;
use app\models\JobTemplate;
use app\models\User;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\CredentialWriteService;
use app\services\GatedJobTemplateLock;
use app\services\JobTemplateCredentialService;
use app\services\VaultCredentialAssignmentService;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Model;
use yii\log\Logger;

/**
 * What a gated job template runs is for admins (GatedJobTemplateLock): for
 * users without approval-rule.update every column but name and description,
 * and the ordered credential list, is locked; a value sent as stored is no
 * change; the decision is taken on the template row, locked as the first
 * statement of the save's transaction.
 */
class GatedJobTemplateLockTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /** A survey of one text field, as the template form saves it. */
    private const SURVEY = '[{"name":"env","label":"Env","type":"text","required":true,"default":"prod","options":[],"hint":""}]';

    private User $admin;
    private User $operator;
    private ApprovalRule $rule;
    private JobTemplate $template;

    /** @var array<string, Credential> credentials by symbol */
    private array $credentials = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('lock-admin', 'admin');
        $this->operator = $this->createUserWithRole('lock-operator', 'operator');
        $this->rule = $this->createApprovalRule((int)$this->admin->id);
        $this->template = $this->gate($this->template());
    }

    // -- Who and what the lock applies to ------------------------------------------

    public function testTheLockAppliesToUsersWhoMayNotAssignRulesOnAGatedTemplate(): void
    {
        $lock = new GatedJobTemplateLock();

        $this->assertTrue($lock->appliesTo((int)$this->operator->id, $this->template));
        $this->assertFalse($lock->appliesTo((int)$this->admin->id, $this->template), 'admins change what it runs');
        $this->assertFalse($lock->appliesTo((int)$this->superadmin()->id, $this->template), 'superadmins too');
        $this->assertFalse($lock->appliesTo((int)$this->operator->id, $this->template()), 'a template without a rule');
        $new = new JobTemplate();
        $new->setAttribute('approval_rule_id', (int)$this->rule->id);
        $this->assertFalse($lock->appliesTo((int)$this->operator->id, $new), 'a template not saved yet');
    }

    public function testARuleIdThatNamesNoRuleGatesNothing(): void
    {
        $template = $this->template();
        $template->setOldAttribute('approval_rule_id', 987654321);

        $this->assertFalse((new GatedJobTemplateLock())->appliesTo((int)$this->operator->id, $template));
    }

    public function testNameAndDescriptionStayOpen(): void
    {
        $this->template->setAttribute('name', 'renamed-' . uniqid('', true));
        $this->template->setAttribute('description', 'Another description.');

        $this->assertFalse($this->vetoes($this->template, null));
        $this->assertFalse($this->vetoes($this->template, null, null, true), 'under the row lock too');
        $this->assertFalse(GatedJobTemplateLock::refused($this->template));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lockedColumnProvider(): array
    {
        return [
            'project' => ['project_id'],
            'inventory' => ['inventory_id'],
            'primary credential' => ['credential_id'],
            'playbook' => ['playbook'],
            'extra vars' => ['extra_vars'],
            'verbosity' => ['verbosity'],
            'forks' => ['forks'],
            'become' => ['become'],
            'become method' => ['become_method'],
            'become user' => ['become_user'],
            'limit' => ['limit'],
            'tags' => ['tags'],
            'skip tags' => ['skip_tags'],
            'timeout' => ['timeout_minutes'],
            'runner group' => ['runner_group_id'],
            'survey' => ['survey_fields'],
        ];
    }

    /**
     * @dataProvider lockedColumnProvider
     */
    public function testEveryOtherColumnIsForAdmins(string $column): void
    {
        $this->template->setAttribute($column, $this->changedValue($column));

        $this->assertTrue($this->vetoes($this->template, null));
        $this->assertSame([GatedJobTemplateLock::CHANGE_REFUSED], $this->template->getErrors(GatedJobTemplateLock::ERROR_KEY));
        $this->assertTrue(GatedJobTemplateLock::refused($this->template));
        $this->template->clearErrors();
        $this->assertTrue($this->vetoes($this->template, null, null, true), 'under the row lock too');
        $this->assertFalse($this->vetoes($this->template, null, (int)$this->admin->id), 'an admin may');
    }

    /**
     * Deny by default: every column of the table but name and description
     * is locked, also one added later.
     */
    public function testEveryColumnButNameAndDescriptionIsLocked(): void
    {
        $columns = array_diff(array_keys(JobTemplate::getTableSchema()->columns), GatedJobTemplateLock::OPEN_ATTRIBUTES);
        $named = array_map(static fn (array $case): string => $case[0], self::lockedColumnProvider());
        $this->assertSame([], array_values(array_diff($named, $columns)), 'every column the contract names is locked');
        foreach ($columns as $column) {
            $template = $this->fresh($this->template);
            $stored = $template->getAttribute($column);
            $phpType = JobTemplate::getTableSchema()->getColumn($column)?->phpType;
            $template->setAttribute($column, $phpType === 'integer' ? (is_numeric($stored) ? (int)$stored : 0) + 1 : $stored . 'x');

            $this->assertTrue($this->vetoes($template, null), $column);
        }
    }

    public function testATemplateWithoutARuleIsNotLocked(): void
    {
        $template = $this->template();
        $template->setAttribute('playbook', 'other.yml');

        $this->assertFalse($this->vetoes($template, ['no-id']));
    }

    public function testANewTemplateIsNotLocked(): void
    {
        $template = new JobTemplate();
        $template->setAttribute('approval_rule_id', (int)$this->rule->id);
        $template->setAttribute('playbook', 'new.yml');

        $this->assertFalse($this->vetoes($template, []));
    }

    public function testEveryoneWhoMayAssignRulesChangesEverything(): void
    {
        $credential = $this->createCredential((int)$this->admin->id);
        foreach (['admin' => $this->admin, 'superadmin' => $this->superadmin(), 'custom role' => $this->customAssigner()] as $kind => $user) {
            $template = $this->fresh($this->template);
            $template->setAttribute('playbook', 'admin.yml');

            $this->assertFalse($this->vetoes($template, [(int)$credential->id], (int)$user->id), $kind);
            $this->assertFalse($this->vetoes($template, [(int)$credential->id], (int)$user->id, true), $kind . ', under the row lock');
        }
    }

    // -- A value sent as stored is no change ----------------------------------------

    public function testAValueSentAsStoredIsNoChange(): void
    {
        $template = $this->stored($this->template, ['extra_vars' => '{"env":"prod","count":1}', 'survey_fields' => self::SURVEY]);
        $template->setAttribute('project_id', (string)$template->project_id);
        $template->setAttribute('runner_group_id', (string)$template->runner_group_id);
        $template->setAttribute('approval_rule_id', (string)$this->rule->id);
        $template->setAttribute('forks', '5');
        $template->setAttribute('become', false);
        $template->setAttribute('credential_id', '');
        $template->setAttribute('extra_vars', "{\n    \"env\": \"prod\",\n    \"count\": 1\n}");
        $template->setAttribute('survey_fields', (string)json_encode(json_decode(self::SURVEY), JSON_PRETTY_PRINT));

        $this->assertCount(8, $template->getDirtyAttributes(), 'each value is sent, in another type or spelling');
        $this->assertFalse($this->vetoes($template, null));
        $this->assertFalse($this->vetoes($template, null, null, true));
    }

    /**
     * @return array<string, array{0: string|null, 1: string, 2: bool}>
     */
    public static function extraVarsProvider(): array
    {
        return [
            'spaces and line breaks' => ['{"a":1,"b":[1,2]}', "{ \"a\" : 1,\n  \"b\" : [ 1, 2 ] }", false],
            'escaped characters' => ['{"a":"é/x"}', '{"a":"é\/x"}', false],
            'empty text for none' => [null, '', false],
            'the same integer beyond the int range' => ['{"a":12345678901234567890}', '{"a": 12345678901234567890}', false],
            'another value' => ['{"a":1}', '{"a":2}', true],
            'another order of keys' => ['{"a":1,"b":2}', '{"b":2,"a":1}', true],
            'a whole float for an integer' => ['{"a":1}', '{"a":1.0}', true],
            'an empty list for an empty object' => ['{"a":{}}', '{"a":[]}', true],
            'a string for a number' => ['{"a":1}', '{"a":"1"}', true],
            'another integer beyond the int range' => ['{"a":12345678901234567890}', '{"a":12345678901234567891}', true],
            'the digits of such an integer as a string' => ['{"a":12345678901234567890}', '{"a":"12345678901234567890"}', true],
            'JSON for none' => [null, '{}', true],
            'text that is no JSON' => ['{"a":1}', '{"a":1', true],
            'a number too large for JSON, written otherwise' => ['{"a":1e999}', '{"a": 1e999}', true],
        ];
    }

    /**
     * Extra vars and the survey compare as JSON values, not as text.
     *
     * @dataProvider extraVarsProvider
     */
    public function testExtraVarsCompareAsJsonValues(?string $stored, string $sent, bool $changes): void
    {
        foreach (['extra_vars', 'survey_fields'] as $column) {
            $template = $this->stored($this->template, [$column => $stored]);
            $template->setAttribute($column, $sent);

            $this->assertSame($changes, $this->vetoes($template, null), $column);
        }
    }

    // -- The credential list -------------------------------------------------------

    /**
     * @return array<string, array{0: list<string>|null, 1: bool}>
     */
    public static function credentialListProvider(): array
    {
        return [
            'not sent: kept' => [null, false],
            'the same list' => [['A', 'B'], false],
            'the same list as text' => [['a', 'b'], false],
            'with the primary again' => [['P', 'A', 'B'], false],
            'with a duplicate' => [['A', 'B', 'A'], false],
            'another order' => [['B', 'A'], true],
            'one removed' => [['A'], true],
            'one added' => [['A', 'B', 'C'], true],
            'none' => [[], true],
            'a value that is no credential ID' => [['A', 'B', '?'], true],
        ];
    }

    /**
     * The credentials compare as the list the save stores: the primary
     * first, then the additional ones in their order.
     *
     * @param list<string>|null $symbols
     * @dataProvider credentialListProvider
     */
    public function testTheCredentialListIsForAdmins(?array $symbols, bool $changes): void
    {
        $template = $this->withCredentials($this->template, 'P', ['A', 'B']);
        $submitted = $symbols === null ? null : $this->credentialIds($symbols);

        $this->assertSame($changes, $this->vetoes($template, $submitted));
        $template->clearErrors();
        $this->assertSame($changes, $this->vetoes($template, $submitted, null, true), 'under the row lock too');
        $this->assertFalse($this->vetoes($template, $submitted, (int)$this->admin->id), 'an admin may');
    }

    // -- The stored row decides -----------------------------------------------------

    /**
     * Regression: a rule assigned after the template was loaded must lock
     * it. The lock reads the row, as the save's transaction locks it.
     */
    public function testARuleAssignedAfterTheTemplateWasLoadedLocksIt(): void
    {
        $template = $this->template();
        JobTemplate::updateAll(['approval_rule_id' => $this->rule->id], ['id' => $template->id]);
        $template->setAttribute('playbook', 'chosen.yml');

        $this->assertTrue($this->vetoes($template, null, null, true));
    }

    public function testARuleRemovedAfterTheTemplateWasLoadedUnlocksIt(): void
    {
        $template = $this->fresh($this->template);
        JobTemplate::updateAll(['approval_rule_id' => null], ['id' => $template->id]);
        $template->setAttribute('playbook', 'chosen.yml');

        $this->assertFalse($this->vetoes($template, null, null, true));
    }

    /**
     * Only what the save writes counts: another save that changed the
     * playbook meanwhile is not the user's change, and sending the value it
     * stored is no change either.
     */
    public function testWhatAnotherSaveChangedMeanwhileIsNotTheUsersChange(): void
    {
        $template = $this->fresh($this->template);
        JobTemplate::updateAll(['playbook' => 'admin.yml'], ['id' => $template->id]);
        $template->setAttribute('name', 'renamed-' . uniqid('', true));
        $this->assertFalse($this->vetoes($template, null, null, true), 'the save writes the name only');

        $template->setAttribute('playbook', 'admin.yml');
        $this->assertFalse($this->vetoes($template, null, null, true), 'the stored value again');

        $template->setAttribute('playbook', 'operator.yml');
        $this->assertTrue($this->vetoes($template, null, null, true));
    }

    public function testATemplateGoneMeanwhileHasNothingToLock(): void
    {
        $template = $this->fresh($this->template);
        $template->setAttribute('id', 987654321);
        $template->setOldAttribute('id', 987654321);
        $template->setAttribute('playbook', 'gone.yml');

        $this->assertFalse($this->vetoes($template, null, null, true));
    }

    // -- Whose save it is ------------------------------------------------------------

    public function testTheSignedInUserSavesUnlessAnotherUserIsGiven(): void
    {
        $this->template->setAttribute('playbook', 'chosen.yml');
        $lock = new GatedJobTemplateLock();

        $this->assertFalse($lock->vetoes($this->template, null, null, false), 'a guest: nobody the lock concerns');
        $this->loginAs($this->operator);
        $this->assertTrue($lock->vetoes($this->template, null, null, false), 'the signed-in operator');
        $this->template->clearErrors();
        $this->assertFalse($lock->vetoes($this->template, null, (int)$this->admin->id, false), 'the user given');
    }

    public function testAConsoleRunHasNoUserTheLockConcerns(): void
    {
        $this->template->setAttribute('playbook', 'chosen.yml');
        $user = \Yii::$app->getComponents(true)['user'] ?? null;
        \Yii::$app->set('user', null);
        try {
            $this->assertFalse((new GatedJobTemplateLock())->vetoes($this->template, null, null, false));
        } finally {
            \Yii::$app->set('user', $user);
        }
    }

    // -- The save (JobTemplateCredentialService) -----------------------------------

    public function testARefusedSaveWritesNothingAndSaysWhy(): void
    {
        $this->loginAs($this->operator);
        $template = $this->fresh($this->template);
        $template->setAttribute('playbook', 'chosen.yml');
        $audits = $this->auditCount();

        $this->assertFalse($this->credentialService()->saveWithCredentials($template, null));

        $this->assertSame([GatedJobTemplateLock::ERROR_KEY => GatedJobTemplateLock::CHANGE_REFUSED], $template->getFirstErrors());
        $this->assertSame('site.yml', $this->fresh($this->template)->playbook);
        $this->assertSame($audits, $this->auditCount(), 'no audit entry');
    }

    /**
     * The save's transaction locks the template row as its first statement
     * and decides on it: a rule assigned while the save runs (here: during
     * its validation) is seen, and nothing is written.
     */
    public function testARuleAssignedWhileTheSaveRunsIsSeenUnderTheRowLock(): void
    {
        $this->loginAs($this->operator);
        $template = $this->template();
        $template->setAttribute('playbook', 'chosen.yml');
        $template->on(Model::EVENT_AFTER_VALIDATE, function () use ($template): void {
            JobTemplate::updateAll(['approval_rule_id' => $this->rule->id], ['id' => $template->id]);
        });
        $audits = $this->auditCount();

        $saved = true;
        $statements = $this->statementsOf(function () use ($template, &$saved): void {
            $saved = $this->credentialService()->saveWithCredentials($template, []);
        });

        $this->assertFalse($saved);
        $this->assertTrue(GatedJobTemplateLock::refused($template));
        $this->assertSame('site.yml', $this->fresh($template)->playbook);
        $this->assertSame($audits, $this->auditCount());
        $savepoint = $this->indexOf($statements, '~^SAVEPOINT LEVEL\d+$~');
        $lock = $this->indexOf($statements, '~^SELECT \* FROM `job_template` WHERE `id` = ' . $template->id . ' FOR UPDATE$~');
        $this->assertSame($savepoint + 1, $lock, "the row lock is the first statement of the save's transaction:\n" . implode("\n", $statements));
        $this->assertSame(
            [],
            preg_grep('~^(UPDATE|INSERT INTO|DELETE FROM) `job_template~', array_slice($statements, $savepoint)),
            "the save's transaction writes nothing:\n" . implode("\n", $statements)
        );
    }

    public function testAnAllowedSaveLocksTheRowBeforeItWrites(): void
    {
        $this->loginAs($this->operator);
        $template = $this->fresh($this->template);
        $template->setAttribute('name', 'renamed-' . uniqid('', true));

        $saved = false;
        $statements = $this->statementsOf(function () use ($template, &$saved): void {
            $saved = $this->credentialService()->saveWithCredentials($template, null);
        });

        $this->assertTrue($saved);
        $savepoint = $this->indexOf($statements, '~^SAVEPOINT LEVEL\d+$~');
        $lock = $this->indexOf($statements, '~^SELECT \* FROM `job_template` WHERE `id` = ' . $template->id . ' FOR UPDATE$~');
        $update = $this->indexOf($statements, '~^UPDATE `job_template` SET .* WHERE `id`=' . $template->id . '$~s');
        $this->assertSame($savepoint + 1, $lock, implode("\n", $statements));
        $this->assertGreaterThan($lock, $update);
        $this->assertSame($template->name, $this->fresh($template)->name);
    }

    /**
     * Regression: an operator could change the credentials of a gated job
     * template through the vault password assignment of the credential page
     * (web and API), which saves templates without the edit form.
     */
    public function testRegressionTheVaultPasswordAssignmentLeavesAGatedTemplateToAdmins(): void
    {
        $vault = $this->usableVault();
        $ungated = $this->template();
        /** @var VaultCredentialAssignmentService $assignment */
        $assignment = \Yii::$app->get('vaultCredentialAssignmentService');

        $this->loginAs($this->operator);
        $items = $this->itemsById($assignment->assign($vault, [(int)$this->template->id, (int)$ungated->id], (int)$this->operator->id));

        $this->assertSame(VaultAssignmentResult::FAILED, $items[(int)$this->template->id]['status']);
        $this->assertSame(GatedJobTemplateLock::CHANGE_REFUSED, $items[(int)$this->template->id]['error']);
        $this->assertSame([], $this->fresh($this->template)->credentialSnapshot(), 'the gated template keeps its credentials');
        $this->assertSame(VaultAssignmentResult::ASSIGNED, $items[(int)$ungated->id]['status']);

        $this->loginAs($this->admin);
        $items = $this->itemsById($assignment->assign($vault, [(int)$this->template->id], (int)$this->admin->id));
        $this->assertSame(VaultAssignmentResult::ASSIGNED, $items[(int)$this->template->id]['status']);
        $this->assertSame([(int)$vault->id], array_column($this->fresh($this->template)->credentialSnapshot(), 'id'));
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * @param list<mixed>|null $additionalIds
     */
    private function vetoes(JobTemplate $template, ?array $additionalIds, ?int $actorId = null, bool $forUpdate = false): bool
    {
        return (new GatedJobTemplateLock())->vetoes($template, $additionalIds, $actorId ?? (int)$this->operator->id, $forUpdate);
    }

    private function changedValue(string $column): mixed
    {
        $ownerId = (int)$this->admin->id;

        return match ($column) {
            'project_id' => (int)$this->createProject($ownerId)->id,
            'inventory_id' => (int)$this->createInventory($ownerId)->id,
            'credential_id' => (int)$this->createCredential($ownerId)->id,
            'runner_group_id' => (int)$this->createRunnerGroup($ownerId)->id,
            'playbook' => 'other.yml',
            'extra_vars' => '{"chosen_by": "operator"}',
            'verbosity' => 4,
            'forks' => 50,
            'become' => true,
            'become_method' => 'su',
            'become_user' => 'deploy',
            'limit' => 'web*',
            'tags' => 'deploy',
            'skip_tags' => 'slow',
            'timeout_minutes' => 5,
            'survey_fields' => self::SURVEY,
            default => $this->fail("No changed value for {$column}"),
        };
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

    private function gate(JobTemplate $template): JobTemplate
    {
        JobTemplate::updateAll(['approval_rule_id' => $this->rule->id], ['id' => $template->id]);

        return $this->fresh($template);
    }

    /**
     * $template as stored after writing $values into its row.
     *
     * @param array<string, mixed> $values
     */
    private function stored(JobTemplate $template, array $values): JobTemplate
    {
        JobTemplate::updateAll($values, ['id' => $template->id]);

        return $this->fresh($template);
    }

    /**
     * $template with the primary credential $primary and the additional ones
     * $additional, stored as the save stores them (primary first).
     *
     * @param list<string> $additional symbols
     */
    private function withCredentials(JobTemplate $template, string $primary, array $additional): JobTemplate
    {
        $db = \Yii::$app->db;
        JobTemplate::updateAll(['credential_id' => $this->credential($primary)->id], ['id' => $template->id]);
        $db->createCommand()->delete('{{%job_template_credential}}', ['job_template_id' => $template->id])->execute();
        foreach (array_merge([$primary], $additional) as $order => $symbol) {
            $db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $this->credential($symbol)->id,
                'sort_order' => $order,
            ])->execute();
        }

        return $this->fresh($template);
    }

    private function credential(string $symbol): Credential
    {
        return $this->credentials[$symbol] ??= $this->createCredential((int)$this->admin->id);
    }

    /**
     * Upper case: the credential's ID; lower case: its ID as text; '?': no ID.
     *
     * @param list<string> $symbols
     * @return list<mixed>
     */
    private function credentialIds(array $symbols): array
    {
        return array_map(fn (string $symbol): mixed => match (true) {
            $symbol === '?' => 'no-id',
            ctype_lower($symbol) => (string)$this->credential(strtoupper($symbol))->id,
            default => (int)$this->credential($symbol)->id,
        }, $symbols);
    }

    private function fresh(JobTemplate $template): JobTemplate
    {
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function superadmin(): User
    {
        $user = $this->createUser('lock-superadmin');
        $user->is_superadmin = true;
        $user->save(false);

        return $user;
    }

    private function customAssigner(): User
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->createRole('lock-assigners-' . uniqid());
        $auth->add($role);
        $permission = $auth->getPermission(ApprovalRuleAssignmentPolicy::PERMISSION);
        $this->assertNotNull($permission);
        $auth->addChild($role, $permission);
        $user = $this->createUserWithRole('lock-custom', 'operator');
        $auth->assign($role, (string)$user->id);

        return $user;
    }

    private function usableVault(): Credential
    {
        $vault = new Credential();
        $vault->name = 'lock-vault-' . uniqid('', true);
        $vault->credential_type = Credential::TYPE_VAULT;
        $vault->created_by = (int)$this->admin->id;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($vault, ['vault_password' => 'lock-test-vault-value']), (string)json_encode($vault->errors));

        return $vault;
    }

    /**
     * @return array<int, array{job_template_id: int, name: string, status: string, replaced: list<array{id: int, name: string}>, error: string|null}>
     */
    private function itemsById(VaultAssignmentResult $result): array
    {
        return array_column($result->items, null, 'job_template_id');
    }

    private function credentialService(): JobTemplateCredentialService
    {
        /** @var JobTemplateCredentialService $service */
        $service = \Yii::$app->get('jobTemplateCredentialService');

        return $service;
    }

    private function auditCount(): int
    {
        return (int)AuditLog::find()->where(['object_type' => 'job_template'])->count();
    }

    /**
     * The SQL statements $action runs, in order.
     *
     * @return list<string>
     */
    private function statementsOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $statements = [];
        foreach ($logger->messages as $message) {
            if (
                in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true)
                && $message[1] === Logger::LEVEL_INFO
                && is_string($message[0])
            ) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }

    /**
     * @param list<string> $statements
     */
    private function indexOf(array $statements, string $pattern): int
    {
        foreach ($statements as $index => $statement) {
            if (preg_match($pattern, $statement) === 1) {
                return $index;
            }
        }
        $this->fail("No statement matches {$pattern}:\n" . implode("\n", $statements));
    }
}
