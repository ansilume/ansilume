<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\components\VaultAssignmentResult;
use app\models\AuditLog;
use app\models\Credential;
use app\models\JobTemplate;
use app\models\Project;
use app\models\TeamProject;
use app\services\CredentialService;
use app\services\CredentialWriteService;
use app\services\VaultAssignmentException;
use app\services\VaultCredentialAssignmentService;
use app\tests\integration\DbTestCase;
use yii\db\Query;

/**
 * Assigning one vault password to several job templates at once: which
 * templates are offered, how each template's credentials change, the audit
 * trail, and the pre-checks that reject a request before anything is
 * written.
 *
 * Credentials are named by symbols in the layouts below: V is the vault
 * password being assigned (with a usable secret), O, O1 and O2 are other
 * vault passwords, S is an SSH key, T and T2 are tokens.
 */
class VaultCredentialAssignmentServiceTest extends DbTestCase
{
    /** updated_at of fixture templates: a save by the service changes it. */
    private const UNTOUCHED_AT = 1_000_000;

    /** Stands for the fixture template's id in the invalid id lists. */
    private const TEMPLATE_ID = 'template-id';

    private VaultCredentialAssignmentService $service;
    private int $adminId;
    private Credential $vault;
    private int $openProjectId;
    private int $inventoryId;
    private int $runnerGroupId;

    /** @var array<string, Credential> fixture credentials by symbol, without V */
    private array $credentials = [];

    protected function setUp(): void
    {
        parent::setUp();
        /** @var VaultCredentialAssignmentService $service */
        $service = \Yii::$app->get('vaultCredentialAssignmentService');
        $this->service = $service;
        $this->adminId = $this->userWithRole('admin');
        $this->vault = $this->storedCredential(Credential::TYPE_VAULT, ['vault_password' => 'vca-assigned-secret'], 'vca-assigned-vault');
        $this->openProjectId = (int)$this->createProject($this->adminId)->id;
        $this->inventoryId = (int)$this->createInventory($this->adminId)->id;
        $this->runnerGroupId = (int)$this->createRunnerGroup($this->adminId)->id;
    }

    // -------------------------------------------------------------------------
    // candidates()
    // -------------------------------------------------------------------------

    public function testCandidatesOfferOnlyTemplatesTheUserMayChange(): void
    {
        [$operatorId, $projects] = $this->teamFixture();
        $templates = array_map(fn (int $projectId): int => (int)$this->template('', $projectId)->id, $projects);
        $deleted = $this->template('', $projects['open']);
        $deleted->softDelete();
        $fixtureIds = array_merge(array_values($templates), [(int)$deleted->id]);

        $this->assertSame(
            $this->sorted([$templates['open'], $templates['operator']]),
            $this->candidateIds($operatorId, $fixtureIds),
            'an operator team member is offered open projects and projects where their team operates'
        );
        $this->assertSame(
            $this->sorted([$templates['open'], $templates['operator'], $templates['viewer'], $templates['foreign']]),
            $this->candidateIds($this->adminId, $fixtureIds),
            'an admin is offered every template that is not deleted'
        );
    }

    public function testCandidatesReportTheVaultStateOfEachTemplateSortedByName(): void
    {
        $prefix = 'vca-' . uniqid('', true);
        $multipleWithThis = $this->withCredentials($this->template($prefix . '-f'), null, ['V', 'O']);
        $none = $this->withCredentials($this->template($prefix . '-a'), 'S', ['T']);
        $other = $this->withCredentials($this->template($prefix . '-d'), null, ['T', 'O']);
        $thisAdditional = $this->withCredentials($this->template($prefix . '-b'), 'S', ['V']);
        $multipleOthers = $this->withCredentials($this->template($prefix . '-e'), 'O2', ['T', 'O1']);
        $thisPrimary = $this->withCredentials($this->template($prefix . '-c'), 'V', []);
        $fixtureIds = array_map(
            static fn (JobTemplate $template): int => (int)$template->id,
            [$multipleWithThis, $none, $other, $thisAdditional, $multipleOthers, $thisPrimary]
        );

        $rows = array_values(array_filter(
            $this->service->candidates($this->vault, $this->adminId),
            static fn (array $row): bool => in_array($row['id'], $fixtureIds, true)
        ));

        $this->assertSame([
            $this->candidateRow($none, VaultCredentialAssignmentService::STATE_NONE, []),
            $this->candidateRow($thisAdditional, VaultCredentialAssignmentService::STATE_THIS, ['V']),
            $this->candidateRow($thisPrimary, VaultCredentialAssignmentService::STATE_THIS, ['V']),
            $this->candidateRow($other, VaultCredentialAssignmentService::STATE_OTHER, ['O']),
            // vault passwords in precedence order: the primary first
            $this->candidateRow($multipleOthers, VaultCredentialAssignmentService::STATE_MULTIPLE, ['O2', 'O1']),
            $this->candidateRow($multipleWithThis, VaultCredentialAssignmentService::STATE_MULTIPLE, ['V', 'O']),
        ], $rows);
    }

    /**
     * Without a user no template is offered.
     *
     * Regression: ProjectAccessChecker returned its deny-all filter as
     * ['0=1'], which Yii's query builder rejects as an operator without
     * operands, so candidates() threw.
     */
    public function testCandidatesForNoUserAreEmpty(): void
    {
        $this->withCredentials($this->template(), null, ['O']);

        $this->assertSame([], $this->service->candidates($this->vault, null));
    }

    // -------------------------------------------------------------------------
    // assign(): outcome per template
    // -------------------------------------------------------------------------

    /**
     * Each entry: the stored primary and additional credentials; the
     * primary and all credentials in precedence order afterwards; the
     * status; the replaced vault passwords; the credential diff in the
     * audit entry (primary as [from, to], or null when it stays).
     *
     * @return array<string, array{0: string|null, 1: list<string>, 2: string|null, 3: list<string>, 4: string, 5: list<string>, 6: array{attached: list<string>, detached: list<string>, primary: array{0: string|null, 1: string|null}|null}}>
     */
    public static function layoutProvider(): array
    {
        return [
            'no credentials: the vault is appended' => [
                null, [],
                null, ['V'],
                VaultAssignmentResult::ASSIGNED, [],
                ['attached' => ['V'], 'detached' => [], 'primary' => null],
            ],
            'no vault: the vault is appended as last additional credential' => [
                'S', ['T'],
                'S', ['S', 'T', 'V'],
                VaultAssignmentResult::ASSIGNED, [],
                ['attached' => ['V'], 'detached' => [], 'primary' => null],
            ],
            'another vault as additional: replaced in the same position' => [
                'S', ['T', 'O', 'T2'],
                'S', ['S', 'T', 'V', 'T2'],
                VaultAssignmentResult::REPLACED, ['O'],
                ['attached' => ['V'], 'detached' => ['O'], 'primary' => null],
            ],
            'another vault as primary: the new vault becomes primary' => [
                'O', ['T'],
                'V', ['V', 'T'],
                VaultAssignmentResult::REPLACED, ['O'],
                ['attached' => ['V'], 'detached' => ['O'], 'primary' => ['O', 'V']],
            ],
            'legacy, two other vaults: the first slot gets the vault, both are replaced' => [
                'S', ['O1', 'T', 'O2'],
                'S', ['S', 'V', 'T'],
                VaultAssignmentResult::REPLACED, ['O1', 'O2'],
                ['attached' => ['V'], 'detached' => ['O1', 'O2'], 'primary' => null],
            ],
            'legacy, this vault first: only the other one is replaced' => [
                'S', ['V', 'O'],
                'S', ['S', 'V'],
                VaultAssignmentResult::REPLACED, ['O'],
                ['attached' => [], 'detached' => ['O'], 'primary' => null],
            ],
            'legacy, this vault after another: it moves into the first vault slot' => [
                'S', ['O', 'T', 'V'],
                'S', ['S', 'V', 'T'],
                VaultAssignmentResult::REPLACED, ['O'],
                ['attached' => [], 'detached' => ['O'], 'primary' => null],
            ],
            'legacy, vault primary and another vault: the new vault becomes primary' => [
                'O1', ['O2', 'T'],
                'V', ['V', 'T'],
                VaultAssignmentResult::REPLACED, ['O1', 'O2'],
                ['attached' => ['V'], 'detached' => ['O1', 'O2'], 'primary' => ['O1', 'V']],
            ],
            'legacy, this vault primary and another vault: the other one is detached' => [
                'V', ['O'],
                'V', ['V'],
                VaultAssignmentResult::REPLACED, ['O'],
                ['attached' => [], 'detached' => ['O'], 'primary' => null],
            ],
            'legacy, another vault primary and this vault additional: this one becomes primary' => [
                'O', ['T', 'V'],
                'V', ['V', 'T'],
                VaultAssignmentResult::REPLACED, ['O'],
                ['attached' => [], 'detached' => ['O'], 'primary' => ['O', 'V']],
            ],
        ];
    }

    /**
     * @dataProvider layoutProvider
     * @param list<string> $additional
     * @param list<string> $expectedOrder
     * @param list<string> $replaced
     * @param array{attached: list<string>, detached: list<string>, primary: array{0: string|null, 1: string|null}|null} $diff
     */
    public function testEachTemplateEndsWithExactlyThisVaultAndOneAuditEntry(
        ?string $primary,
        array $additional,
        ?string $expectedPrimary,
        array $expectedOrder,
        string $status,
        array $replaced,
        array $diff
    ): void {
        $template = $this->withCredentials($this->template(), $primary, $additional);

        $result = $this->service->assign($this->vault, [(int)$template->id], $this->adminId, ['source' => 'test']);

        $this->assertSame([$this->expectedItem($template, $status, $replaced)], $this->normalisedItems($result));
        $this->assertSame(['primary' => $expectedPrimary, 'ordered' => $expectedOrder], $this->storedLayout($template));
        $audits = $this->updateAudits($template);
        $this->assertCount(1, $audits);
        $this->assertSame($template->name, $audits[0]['name']);
        $this->assertSame('test', $audits[0]['source']);
        $this->assertSame(['credential_id' => (int)$this->vault->id, 'replaced' => $this->ids($replaced)], $audits[0]['vault_assignment']);
        $this->assertSame($this->expectedDiff($diff), $audits[0]['credentials']);
    }

    public function testATemplateThatAlreadyHasExactlyThisVaultIsNotSaved(): void
    {
        $asAdditional = $this->withCredentials($this->template(), 'S', ['V', 'T']);
        $asPrimary = $this->withCredentials($this->template(), 'V', ['T']);
        $before = $this->snapshot([$asAdditional, $asPrimary]);

        $result = $this->service->assign($this->vault, [(int)$asAdditional->id, (int)$asPrimary->id], $this->adminId, ['source' => 'test']);

        $this->assertSame([
            $this->expectedItem($asAdditional, VaultAssignmentResult::UNCHANGED),
            $this->expectedItem($asPrimary, VaultAssignmentResult::UNCHANGED),
        ], $this->normalisedItems($result));
        $this->assertSame($before, $this->snapshot([$asAdditional, $asPrimary]), 'no row, pivot row or audit entry was written');
    }

    public function testTheResultKeepsTheRequestOrderAndIgnoresDuplicates(): void
    {
        $withoutVault = $this->withCredentials($this->template(), 'S', []);
        $withOther = $this->withCredentials($this->template(), null, ['O']);
        $withThis = $this->withCredentials($this->template(), null, ['V']);
        $bystander = $this->withCredentials($this->template(), null, ['O']);
        $bystanderBefore = $this->snapshot([$bystander]);
        $ids = [(int)$withThis->id, (string)$withoutVault->id, (int)$withOther->id, (int)$withoutVault->id, (string)$withThis->id, (string)$withOther->id];

        $result = $this->service->assign($this->vault, $ids, $this->adminId, ['source' => 'test']);

        $this->assertSame((int)$this->vault->id, $result->credentialId);
        $this->assertSame($this->vault->name, $result->credentialName);
        $this->assertSame([
            $this->expectedItem($withThis, VaultAssignmentResult::UNCHANGED),
            $this->expectedItem($withoutVault, VaultAssignmentResult::ASSIGNED),
            $this->expectedItem($withOther, VaultAssignmentResult::REPLACED, ['O']),
        ], $this->normalisedItems($result));
        $this->assertSame(
            [VaultAssignmentResult::ASSIGNED => 1, VaultAssignmentResult::REPLACED => 1, VaultAssignmentResult::UNCHANGED => 1, VaultAssignmentResult::FAILED => 0],
            $result->counts()
        );
        $this->assertCount(1, $this->updateAudits($withoutVault), 'a duplicate id must not save the template twice');
        $this->assertCount(1, $this->updateAudits($withOther), 'a duplicate id must not save the template twice');
        $this->assertCount(0, $this->updateAudits($withThis));
        $this->assertSame(['primary' => 'S', 'ordered' => ['S', 'V']], $this->storedLayout($withoutVault));
        $this->assertSame(['primary' => null, 'ordered' => ['V']], $this->storedLayout($withOther));
        $this->assertSame($bystanderBefore['templates'], $this->snapshot([$bystander])['templates'], 'templates outside the request stay as they are');
        $this->assertCount(0, $this->updateAudits($bystander));
    }

    public function testAnOperatorTeamMemberAssignsToOpenAndOperatorProjects(): void
    {
        [$operatorId, $projects] = $this->teamFixture();
        $open = $this->withCredentials($this->template('', $projects['open']), 'S', []);
        $operated = $this->withCredentials($this->template('', $projects['operator']), null, ['O']);

        $result = $this->service->assign($this->vault, [(int)$open->id, (int)$operated->id], $operatorId, ['source' => 'test']);

        $this->assertSame([
            $this->expectedItem($open, VaultAssignmentResult::ASSIGNED),
            $this->expectedItem($operated, VaultAssignmentResult::REPLACED, ['O']),
        ], $this->normalisedItems($result));
        $this->assertSame(['primary' => 'S', 'ordered' => ['S', 'V']], $this->storedLayout($open));
        $this->assertSame(['primary' => null, 'ordered' => ['V']], $this->storedLayout($operated));
    }

    public function testATemplateThatFailsItsOwnValidationIsReportedWhileTheOthersAreAssigned(): void
    {
        $first = $this->withCredentials($this->template(), 'S', []);
        $broken = $this->brokenTemplate(['extra_vars' => '{"region": eu-west'], 'S', ['O']);
        $last = $this->withCredentials($this->template(), null, ['O1']);
        $brokenBefore = $this->snapshot([$broken])['templates'];

        $result = $this->service->assign($this->vault, [(int)$first->id, (int)$broken->id, (int)$last->id], $this->adminId, ['source' => 'test']);

        $this->assertSame([
            $this->expectedItem($first, VaultAssignmentResult::ASSIGNED),
            $this->expectedItem($broken, VaultAssignmentResult::FAILED, [], 'Extra_vars must be valid JSON.'),
            $this->expectedItem($last, VaultAssignmentResult::REPLACED, ['O1']),
        ], $this->normalisedItems($result));
        $this->assertSame($brokenBefore, $this->snapshot([$broken])['templates'], 'the failed template keeps its credentials');
        $this->assertCount(0, $this->updateAudits($broken));
        $this->assertSame(['primary' => 'S', 'ordered' => ['S', 'V']], $this->storedLayout($first));
        $this->assertSame(['primary' => null, 'ordered' => ['V']], $this->storedLayout($last));
        $this->assertCount(1, $this->updateAudits($first));
        $this->assertCount(1, $this->updateAudits($last));
        $this->assertSame(1, $result->counts()[VaultAssignmentResult::FAILED]);
        $this->assertStringContainsString('1 failed: "' . $broken->name . '" (Extra_vars must be valid JSON.)', $result->summary());
    }

    public function testEveryValidationErrorOfAFailedTemplateIsReported(): void
    {
        $broken = $this->brokenTemplate(['extra_vars' => 'not json', 'survey_fields' => '[{'], null, []);

        $result = $this->service->assign($this->vault, [(int)$broken->id], $this->adminId);

        $this->assertSame(
            [$this->expectedItem($broken, VaultAssignmentResult::FAILED, [], 'Extra_vars must be valid JSON. Survey_fields must be valid JSON.')],
            $this->normalisedItems($result)
        );
        $this->assertSame(['primary' => null, 'ordered' => []], $this->storedLayout($broken));
        $this->assertCount(0, $this->updateAudits($broken));
    }

    // -------------------------------------------------------------------------
    // assign(): pre-checks, nothing is written when one fails
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonVaultTypeProvider(): array
    {
        return [
            'ssh key' => [Credential::TYPE_SSH_KEY],
            'username and password' => [Credential::TYPE_USERNAME_PASSWORD],
            'token' => [Credential::TYPE_TOKEN],
        ];
    }

    /**
     * @dataProvider nonVaultTypeProvider
     */
    public function testOnlyAVaultPasswordCanBeAssigned(string $type): void
    {
        $credential = $this->createCredential($this->adminId, $type);
        $template = $this->withCredentials($this->template(), 'S', ['O']);
        $before = $this->snapshot([$template]);

        $e = $this->rejection($credential, [(int)$template->id], $this->adminId);

        $this->assertSame(422, $e->status);
        $this->assertSame('Only vault passwords can be assigned to several job templates at once.', $e->getMessage());
        $this->assertSame([], $e->templateIds);
        $this->assertSame($before, $this->snapshot([$template]));
    }

    /**
     * @return array<string, array{0: array<string, string>|string|null, 1: string}>
     */
    public static function unusableSecretProvider(): array
    {
        return [
            'no secret stored' => [null, CredentialService::SECRET_STATUS_INCOMPLETE],
            'blank vault password' => [['vault_password' => '   '], CredentialService::SECRET_STATUS_INCOMPLETE],
            'only the secret of another type' => [['token' => 'vca-token'], CredentialService::SECRET_STATUS_INCOMPLETE],
            'undecryptable secret' => ['not-a-ciphertext', CredentialService::SECRET_STATUS_UNDECRYPTABLE],
        ];
    }

    /**
     * @dataProvider unusableSecretProvider
     * @param array<string, string>|string|null $secret encrypted when an array, stored as it is otherwise
     */
    public function testAVaultPasswordWithoutUsableSecretIsRejected(array|string|null $secret, string $secretStatus): void
    {
        $vault = $this->createCredential($this->adminId, Credential::TYPE_VAULT);
        $vault->secret_data = is_array($secret) ? $this->credentialService()->encryptSecrets($secret) : $secret;
        $vault->save(false);
        $this->assertSame($secretStatus, $this->credentialService()->secretStatus($vault));
        $template = $this->withCredentials($this->template(), 'S', ['O']);
        $before = $this->snapshot([$template]);

        $e = $this->rejection($vault, [(int)$template->id], $this->adminId);

        $this->assertSame(422, $e->status);
        $this->assertSame('The vault password has no usable secret, so jobs would fail. Enter it on the credential page first.', $e->getMessage());
        $this->assertSame([], $e->templateIds);
        $this->assertSame($before, $this->snapshot([$template]));
    }

    /**
     * TEMPLATE_ID stands for the id of a template the admin may change, so
     * one bad entry rejects the whole request.
     *
     * @return array<string, array{0: array<array-key, mixed>}>
     */
    public static function invalidIdListProvider(): array
    {
        return [
            'empty list' => [[]],
            'associative array' => [['job_template' => self::TEMPLATE_ID]],
            'keys not counting from zero' => [[1 => self::TEMPLATE_ID]],
            'non-numeric id' => [[self::TEMPLATE_ID, 'abc']],
            'zero' => [[self::TEMPLATE_ID, 0]],
            'negative id' => [[self::TEMPLATE_ID, -3]],
            'fraction' => [[self::TEMPLATE_ID, '1.5']],
            'empty string' => [[self::TEMPLATE_ID, '']],
            'null' => [[self::TEMPLATE_ID, null]],
            'nested list' => [[[self::TEMPLATE_ID]]],
            // Regression: filter_var() took true and 1.0 for template #1.
            'boolean' => [[self::TEMPLATE_ID, true]],
            'float' => [[self::TEMPLATE_ID, 1.0]],
        ];
    }

    /**
     * @dataProvider invalidIdListProvider
     * @param array<array-key, mixed> $ids
     */
    public function testAnInvalidIdListIsRejected(array $ids): void
    {
        $template = $this->withCredentials($this->template(), 'S', ['O']);
        $before = $this->snapshot([$template]);

        $e = $this->rejection($this->vault, self::withTemplateId($ids, (int)$template->id), $this->adminId);

        $this->assertSame(422, $e->status);
        $this->assertSame('job_template_ids must be a non-empty list of job template IDs.', $e->getMessage());
        $this->assertSame([], $e->templateIds);
        $this->assertSame($before, $this->snapshot([$template]));
    }

    public function testMoreThanTheMaximumNumberOfTemplatesIsRejected(): void
    {
        $template = $this->withCredentials($this->template(), 'S', ['O']);
        $before = $this->snapshot([$template]);
        $ids = array_merge([(int)$template->id], range(900_000_001, 900_000_000 + VaultCredentialAssignmentService::MAX_TEMPLATES));

        $e = $this->rejection($this->vault, $ids, $this->adminId);

        $this->assertSame(422, $e->status);
        $this->assertSame('At most 500 job templates can be assigned at once.', $e->getMessage());
        $this->assertSame([], $e->templateIds);
        $this->assertSame($before, $this->snapshot([$template]));
    }

    /**
     * Exactly MAX_TEMPLATES distinct ids pass the limit, however often they
     * repeat; this request then fails on the unknown ones.
     */
    public function testTheMaximumCountsDistinctIdsAndIncludesTheLimit(): void
    {
        $template = $this->withCredentials($this->template(), 'S', ['O']);
        $before = $this->snapshot([$template]);
        $unknown = range(900_000_001, 900_000_000 + VaultCredentialAssignmentService::MAX_TEMPLATES - 1);

        $e = $this->rejection($this->vault, array_merge([(int)$template->id], $unknown, $unknown), $this->adminId);

        $this->assertSame(422, $e->status);
        $this->assertSame($unknown, $e->templateIds);
        $this->assertStringStartsWith('Job template(s) not found: #900000001, #900000002, ', $e->getMessage());
        $this->assertSame($before, $this->snapshot([$template]));
    }

    /**
     * Templates of other teams are reported exactly like ids that do not
     * exist, so the answer does not reveal them.
     */
    public function testUnknownAndHiddenTemplatesAreReportedAlikeAsNotFound(): void
    {
        [$operatorId, $projects] = $this->teamFixture();
        $own = $this->withCredentials($this->template('', $projects['operator']), null, ['O']);
        $hidden = $this->withCredentials($this->template('', $projects['foreign']), null, ['O']);
        $unknownId = 999_999_999;
        $before = $this->snapshot([$own, $hidden]);

        $unknown = $this->rejection($this->vault, [$unknownId], $operatorId);
        $hiddenOnly = $this->rejection($this->vault, [(int)$hidden->id], $operatorId);
        $mixed = $this->rejection($this->vault, [(int)$own->id, (int)$hidden->id, $unknownId], $operatorId);

        $this->assertSame([422, 'Job template(s) not found: #999999999.', [$unknownId]], [$unknown->status, $unknown->getMessage(), $unknown->templateIds]);
        $this->assertSame(
            [422, 'Job template(s) not found: #' . $hidden->id . '.', [(int)$hidden->id]],
            [$hiddenOnly->status, $hiddenOnly->getMessage(), $hiddenOnly->templateIds]
        );
        $this->assertSame(
            [422, 'Job template(s) not found: #' . $hidden->id . ', #999999999.', [(int)$hidden->id, $unknownId]],
            [$mixed->status, $mixed->getMessage(), $mixed->templateIds]
        );
        $this->assertSame($before, $this->snapshot([$own, $hidden]));
    }

    /**
     * A deleted template is reported like an unknown id and left alone.
     *
     * Regression: the service looked the templates up with
     * JobTemplate::find()->where(...), and where() replaced the default
     * scope that hides deleted templates, so a deleted template got the
     * vault assigned.
     */
    public function testADeletedTemplateIsReportedAsNotFoundAndLeftAlone(): void
    {
        $deleted = $this->withCredentials($this->template(), 'S', ['O']);
        $this->assertTrue($deleted->softDelete());
        $before = $this->snapshot([$deleted]);

        $e = $this->rejection($this->vault, [(int)$deleted->id], $this->adminId);

        $this->assertSame(
            [422, 'Job template(s) not found: #' . $deleted->id . '.', [(int)$deleted->id]],
            [$e->status, $e->getMessage(), $e->templateIds]
        );
        $this->assertSame($before, $this->snapshot([$deleted]));
    }

    public function testTemplatesTheUserMaySeeButNotChangeAreForbidden(): void
    {
        [$operatorId, $projects] = $this->teamFixture();
        $firstViewOnly = $this->withCredentials($this->template('', $projects['viewer']), null, ['O']);
        $own = $this->withCredentials($this->template('', $projects['operator']), null, ['O']);
        $secondViewOnly = $this->withCredentials($this->template('', $projects['viewer']), 'S', []);
        $before = $this->snapshot([$firstViewOnly, $own, $secondViewOnly]);

        $e = $this->rejection($this->vault, [(int)$firstViewOnly->id, (int)$own->id, (int)$secondViewOnly->id], $operatorId);

        $this->assertSame(403, $e->status);
        $this->assertSame('You may not change job template(s) #' . $firstViewOnly->id . ', #' . $secondViewOnly->id . '.', $e->getMessage());
        $this->assertSame([(int)$firstViewOnly->id, (int)$secondViewOnly->id], $e->templateIds);
        $this->assertSame($before, $this->snapshot([$firstViewOnly, $own, $secondViewOnly]), 'the template the user may change is left alone as well');
    }

    /**
     * Without a user every template is hidden, so the request is rejected
     * like one with unknown ids and nothing is written.
     *
     * Regression: the deny-all filter ['0=1'] made Yii's query builder throw
     * an InvalidArgumentException instead.
     */
    public function testWithoutAUserNothingIsAssigned(): void
    {
        $template = $this->withCredentials($this->template(), 'S', ['O']);
        $before = $this->snapshot([$template]);

        $e = $this->rejection($this->vault, [(int)$template->id], null);

        $this->assertSame(
            [422, 'Job template(s) not found: #' . $template->id . '.', [(int)$template->id]],
            [$e->status, $e->getMessage(), $e->templateIds]
        );
        $this->assertSame($before, $this->snapshot([$template]));
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * An operator-role user whose team operates one project and only views
     * another; a third project belongs to a team they are not in; the fourth
     * is open.
     *
     * @return array{0: int, 1: array{open: int, operator: int, viewer: int, foreign: int}}
     */
    private function teamFixture(): array
    {
        $operatorId = $this->userWithRole('operator');
        $team = (int)$this->createTeam($this->adminId)->id;
        $foreignTeam = (int)$this->createTeam($this->adminId)->id;
        $this->addTeamMember($team, $operatorId);

        return [$operatorId, [
            'open' => $this->openProjectId,
            'operator' => $this->restrictedProject($team, TeamProject::ROLE_OPERATOR),
            'viewer' => $this->restrictedProject($team, TeamProject::ROLE_VIEWER),
            'foreign' => $this->restrictedProject($foreignTeam, TeamProject::ROLE_OPERATOR),
        ]];
    }

    private function restrictedProject(int $teamId, string $role): int
    {
        $projectId = (int)$this->createProject($this->adminId)->id;
        $this->createTeamProject($teamId, $projectId, $role);

        return $projectId;
    }

    private function userWithRole(string $roleName): int
    {
        $user = $this->createUser('vca-' . $roleName);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $role = $auth->getRole($roleName);
        $this->assertNotNull($role, $roleName);
        $auth->assign($role, (string)$user->id);

        return (int)$user->id;
    }

    /**
     * @param array<string, string> $secrets
     */
    private function storedCredential(string $type, array $secrets, string $name): Credential
    {
        $credential = new Credential();
        $credential->name = $name . '-' . uniqid('', true);
        $credential->credential_type = $type;
        $credential->created_by = $this->adminId;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($credential, $secrets), (string)json_encode($credential->errors));

        return $credential;
    }

    private function credential(string $symbol): Credential
    {
        if ($symbol === 'V') {
            return $this->vault;
        }
        if (!isset($this->credentials[$symbol])) {
            $type = match ($symbol[0]) {
                'O' => Credential::TYPE_VAULT,
                'S' => Credential::TYPE_SSH_KEY,
                default => Credential::TYPE_TOKEN,
            };
            $credential = $this->createCredential($this->adminId, $type);
            $credential->name = 'vca-' . strtolower($symbol) . '-' . uniqid('', true);
            $credential->save(false);
            $this->credentials[$symbol] = $credential;
        }

        return $this->credentials[$symbol];
    }

    private function template(string $name = '', ?int $projectId = null): JobTemplate
    {
        $template = $this->createJobTemplate($projectId ?? $this->openProjectId, $this->inventoryId, $this->runnerGroupId, $this->adminId);
        if ($name !== '') {
            $template->name = $name;
            $template->save(false);
        }

        return $template;
    }

    /**
     * A template that fails its own validation, written without it.
     *
     * @param array<string, string> $invalid attribute => stored value
     * @param list<string> $additional
     */
    private function brokenTemplate(array $invalid, ?string $primary, array $additional): JobTemplate
    {
        $template = $this->template();
        foreach ($invalid as $attribute => $value) {
            $template->setAttribute($attribute, $value);
        }
        $template->save(false);

        return $this->withCredentials($template, $primary, $additional);
    }

    /**
     * Stores the credentials directly, so legacy layouts with two vault
     * passwords are possible: the primary mirrored into the pivot first, as
     * the application writes it, sort orders spaced by ten and updated_at set
     * to UNTOUCHED_AT, so a later save by the service shows.
     *
     * @param list<string> $additional
     */
    private function withCredentials(JobTemplate $template, ?string $primary, array $additional): JobTemplate
    {
        $db = \Yii::$app->db;
        $primaryId = $primary === null ? null : (int)$this->credential($primary)->id;
        $db->createCommand()
            ->update('{{%job_template}}', ['credential_id' => $primaryId, 'updated_at' => self::UNTOUCHED_AT], ['id' => $template->id])
            ->execute();
        $db->createCommand()->delete('{{%job_template_credential}}', ['job_template_id' => $template->id])->execute();
        foreach (array_merge($primary === null ? [] : [$primary], $additional) as $index => $symbol) {
            $db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $this->credential($symbol)->id,
                'sort_order' => ($index + 1) * 10,
            ])->execute();
        }

        return $this->fresh($template);
    }

    private function fresh(JobTemplate $template): JobTemplate
    {
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    // -------------------------------------------------------------------------
    // Expectations and readers
    // -------------------------------------------------------------------------

    /**
     * @param list<int> $fixtureIds
     * @return list<int> the fixture templates offered to the user, ascending
     */
    private function candidateIds(int $userId, array $fixtureIds): array
    {
        $offered = array_column($this->service->candidates($this->vault, $userId), 'id');

        return $this->sorted(array_values(array_intersect($offered, $fixtureIds)));
    }

    /**
     * @param list<string> $vaults
     * @return array<string, mixed>
     */
    private function candidateRow(JobTemplate $template, string $state, array $vaults): array
    {
        $project = Project::findOne($template->project_id);
        $this->assertNotNull($project);

        return [
            'id' => (int)$template->id,
            'name' => $template->name,
            'project_id' => (int)$template->project_id,
            'project_name' => $project->name,
            'current' => $state,
            'vaults' => $this->described($vaults),
        ];
    }

    /**
     * @param list<string> $replaced
     * @return array<string, mixed>
     */
    private function expectedItem(JobTemplate $template, string $status, array $replaced = [], ?string $error = null): array
    {
        return [
            'job_template_id' => (int)$template->id,
            'name' => $template->name,
            'status' => $status,
            'replaced' => $this->described($replaced),
            'error' => $error,
        ];
    }

    /**
     * The result items as they are, keys in their order: the API returns
     * them like this. Regression: the key order used to depend on the
     * status.
     *
     * @return list<array<string, mixed>>
     */
    private function normalisedItems(VaultAssignmentResult $result): array
    {
        return $result->items;
    }

    /**
     * @param array{attached: list<string>, detached: list<string>, primary: array{0: string|null, 1: string|null}|null} $diff
     * @return array<string, mixed> as CredentialAttachmentDiff::toAuditArray() writes it
     */
    private function expectedDiff(array $diff): array
    {
        $describe = fn (string $symbol): array => [
            'id' => (int)$this->credential($symbol)->id,
            'name' => $this->credential($symbol)->name,
            'credential_type' => $this->credential($symbol)->credential_type,
        ];
        $expected = [];
        if ($diff['attached'] !== []) {
            $expected['attached'] = array_map($describe, $diff['attached']);
        }
        if ($diff['detached'] !== []) {
            $expected['detached'] = array_map($describe, $diff['detached']);
        }
        if ($diff['primary'] !== null) {
            [$from, $to] = $diff['primary'];
            $expected['primary'] = [
                'from' => $from === null ? null : (int)$this->credential($from)->id,
                'to' => $to === null ? null : (int)$this->credential($to)->id,
            ];
        }

        return $expected;
    }

    /**
     * @param list<string> $symbols
     * @return list<array{id: int, name: string}>
     */
    private function described(array $symbols): array
    {
        return array_map(
            fn (string $symbol): array => ['id' => (int)$this->credential($symbol)->id, 'name' => $this->credential($symbol)->name],
            $symbols
        );
    }

    /**
     * @param list<string> $symbols
     * @return list<int>
     */
    private function ids(array $symbols): array
    {
        return array_map(fn (string $symbol): int => (int)$this->credential($symbol)->id, $symbols);
    }

    /**
     * The stored credentials as symbols: the primary, and all of them in
     * precedence order.
     *
     * @return array{primary: string|null, ordered: list<string>}
     */
    private function storedLayout(JobTemplate $template): array
    {
        $fresh = $this->fresh($template);

        return [
            'primary' => $fresh->credential_id === null ? null : $this->symbolOf((int)$fresh->credential_id),
            'ordered' => array_map(fn (Credential $credential): string => $this->symbolOf((int)$credential->id), $fresh->orderedCredentials()),
        ];
    }

    private function symbolOf(int $credentialId): string
    {
        if ($credentialId === (int)$this->vault->id) {
            return 'V';
        }
        foreach ($this->credentials as $symbol => $credential) {
            if ((int)$credential->id === $credentialId) {
                return $symbol;
            }
        }
        $this->fail("Credential #{$credentialId} is not part of the fixture.");
    }

    /**
     * The template rows and pivot rows as stored, and the number of audit
     * entries: equal snapshots before and after a call mean it wrote nothing.
     *
     * @param list<JobTemplate> $templates
     * @return array{templates: list<array{row: array<string, mixed>|false, pivot: list<array<string, mixed>>}>, audit_entries: int}
     */
    private function snapshot(array $templates): array
    {
        return [
            'templates' => array_map(static fn (JobTemplate $template): array => [
                'row' => (new Query())
                    ->select(['credential_id', 'updated_at'])
                    ->from('{{%job_template}}')
                    ->where(['id' => $template->id])
                    ->one(),
                'pivot' => (new Query())
                    ->select(['credential_id', 'sort_order'])
                    ->from('{{%job_template_credential}}')
                    ->where(['job_template_id' => $template->id])
                    ->orderBy(['sort_order' => SORT_ASC])
                    ->all(),
            ], $templates),
            'audit_entries' => (int)AuditLog::find()->count(),
        ];
    }

    /**
     * Metadata of the template's job-template.updated audit entries.
     *
     * @return list<array<string, mixed>>
     */
    private function updateAudits(JobTemplate $template): array
    {
        $logs = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_TEMPLATE_UPDATED, 'object_type' => 'job_template', 'object_id' => $template->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return array_map(static function (AuditLog $log): array {
            $metadata = json_decode((string)$log->metadata, true);

            return is_array($metadata) ? $metadata : [];
        }, $logs);
    }

    /**
     * @param array<array-key, mixed> $ids
     * @return array<array-key, mixed> with TEMPLATE_ID replaced, keys kept
     */
    private static function withTemplateId(array $ids, int $templateId): array
    {
        foreach ($ids as $key => $value) {
            if (is_array($value)) {
                $ids[$key] = self::withTemplateId($value, $templateId);
            } elseif ($value === self::TEMPLATE_ID) {
                $ids[$key] = $templateId;
            }
        }

        return $ids;
    }

    /**
     * @param array<array-key, mixed> $ids
     */
    private function rejection(Credential $vault, array $ids, ?int $userId): VaultAssignmentException
    {
        try {
            $this->service->assign($vault, $ids, $userId, ['source' => 'test']);
        } catch (VaultAssignmentException $e) {
            return $e;
        }
        $this->fail('The request must be rejected.');
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function credentialService(): CredentialService
    {
        /** @var CredentialService $service */
        $service = \Yii::$app->get('credentialService');

        return $service;
    }
}
