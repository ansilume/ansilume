<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\CredentialAssignmentController;
use app\models\AuditLog;
use app\models\Credential;
use app\models\JobTemplate;
use app\models\TeamProject;
use app\models\User;
use app\services\CredentialWriteService;
use app\services\VaultCredentialAssignmentService;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Web UI for assigning one vault password to several job templates from the
 * credential page. The access filter needs job-template.update, the action
 * needs credential.view and a vault password, and a request that fails the
 * pre-checks changes nothing and says why.
 */
class CredentialAssignmentControllerTest extends WebControllerTestCase
{
    /** Not the API's wording ("job_template_ids must be ..."), which is no help in a form. */
    private const NO_SELECTION = 'Select at least one job template.';

    protected function setUp(): void
    {
        parent::setUp();
        // $_SESSION outlives a test: start without the flashes of earlier tests.
        \Yii::$app->session->removeAllFlashes();
    }

    // -- actionIndex ----------------------------------------------------------

    public function testIndexRendersTheVaultItsCandidatesAndAUsableSecret(): void
    {
        $userId = (int)$this->loginWithRole('operator')->id;
        $vault = $this->storedVault($userId);
        $other = $this->storedVault($userId);
        $withOther = $this->template($userId);
        $this->setPrimary($withOther, $other);
        $withThis = $this->template($userId);
        $this->attach($withThis, $vault, 0);
        $without = $this->template($userId);

        $ctrl = $this->makeController();
        $result = $ctrl->actionIndex((int)$vault->id);

        $this->assertSame('rendered:index', $result);
        $this->assertSame('index', $ctrl->capturedView);
        $this->assertSame(['vault', 'candidates', 'secretUsable'], array_keys($ctrl->capturedParams));
        $this->assertInstanceOf(Credential::class, $ctrl->capturedParams['vault']);
        $this->assertSame((int)$vault->id, (int)$ctrl->capturedParams['vault']->id);
        $this->assertTrue($ctrl->capturedParams['secretUsable']);
        $rows = $this->rowsById($ctrl->capturedParams['candidates']);
        $this->assertSame([
            'id' => (int)$withOther->id,
            'name' => $withOther->name,
            'project_id' => (int)$withOther->project_id,
            'project_name' => $withOther->project->name,
            'current' => VaultCredentialAssignmentService::STATE_OTHER,
            'vaults' => [['id' => (int)$other->id, 'name' => $other->name]],
        ], $rows[(int)$withOther->id]);
        $this->assertSame(VaultCredentialAssignmentService::STATE_THIS, $rows[(int)$withThis->id]['current']);
        $this->assertSame(VaultCredentialAssignmentService::STATE_NONE, $rows[(int)$without->id]['current']);
        $this->assertSame([], $rows[(int)$without->id]['vaults']);
    }

    /**
     * The page offers the templates of the signed-in user: open projects and
     * team projects with the operator role, not those the user may only view
     * or cannot see at all.
     */
    public function testIndexOffersOnlyTemplatesTheUserMayChange(): void
    {
        $operator = $this->loginWithRole('operator');
        $userId = (int)$operator->id;
        $vault = $this->storedVault($userId);
        $open = $this->template($userId);
        $teamOperated = $this->teamTemplate($operator, TeamProject::ROLE_OPERATOR);
        $viewOnly = $this->teamTemplate($operator, TeamProject::ROLE_VIEWER);
        $foreign = $this->foreignTemplate();

        $ctrl = $this->makeController();
        $ctrl->actionIndex((int)$vault->id);

        $ids = array_keys($this->rowsById($ctrl->capturedParams['candidates']));
        $this->assertContains((int)$open->id, $ids);
        $this->assertContains((int)$teamOperated->id, $ids);
        $this->assertNotContains((int)$viewOnly->id, $ids);
        $this->assertNotContains((int)$foreign->id, $ids);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unusableSecretProvider(): array
    {
        return [
            'no secret stored' => [null],
            'secret no longer decrypts' => ['not-a-ciphertext'],
        ];
    }

    /**
     * @dataProvider unusableSecretProvider
     */
    public function testIndexFlagsAVaultWithoutAUsableSecret(?string $secretData): void
    {
        $userId = (int)$this->loginWithRole('operator')->id;
        $vault = $this->legacyVault($userId, $secretData);
        $template = $this->template($userId);

        $ctrl = $this->makeController();
        $ctrl->actionIndex((int)$vault->id);

        $this->assertFalse($ctrl->capturedParams['secretUsable']);
        $this->assertContains((int)$template->id, array_keys($this->rowsById($ctrl->capturedParams['candidates'])));
    }

    public function testIndexOfANonVaultCredentialIs404(): void
    {
        $userId = (int)$this->loginWithRole('operator')->id;
        $token = $this->storedCredential($userId, Credential::TYPE_TOKEN, ['token' => 'tok-not-a-vault']);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage("Vault password #{$token->id} not found.");
        $this->makeController()->actionIndex((int)$token->id);
    }

    public function testIndexOfAMissingCredentialIs404(): void
    {
        $this->loginWithRole('operator');
        $missingId = $this->missingCredentialId();

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage("Vault password #{$missingId} not found.");
        $this->makeController()->actionIndex($missingId);
    }

    public function testIndexWithoutCredentialViewIsForbidden(): void
    {
        $editor = $this->loginAsTemplateEditor();
        $vault = $this->storedVault((int)$editor->id);

        $this->expectException(ForbiddenHttpException::class);
        $this->expectExceptionMessage('You are not allowed to view credentials.');
        $this->makeController()->actionIndex((int)$vault->id);
    }

    /**
     * Without credential.view the answer does not depend on whether the
     * credential exists, so ids cannot be probed.
     */
    /**
     * Regression: the action asked for credential.view without the
     * superadmin exception the access rules and the API make, so a
     * superadmin without that role got past the filter and then a 403.
     */
    public function testASuperadminWithoutRolesMayOpenAndAssign(): void
    {
        $superadmin = $this->createUser('vault-assign-superadmin');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);
        $this->loginAs($superadmin);
        $vault = $this->storedVault((int)$superadmin->id);
        $template = $this->template((int)$superadmin->id);

        $this->assertSame('rendered:index', $this->makeController()->runAction('index', ['id' => $vault->id]));
        $this->setPost(['job_template_ids' => [(string)$template->id]]);
        $ctrl = $this->makeController();
        $ctrl->runAction('assign', ['id' => $vault->id]);

        $this->assertSame(['/credential/view', 'id' => (int)$vault->id], $ctrl->capturedRedirect);
        $this->assertSame([(int)$vault->id], $this->credentialIds($template));
    }

    public function testWithoutCredentialViewAMissingCredentialIsForbiddenToo(): void
    {
        $this->loginAsTemplateEditor();

        $this->expectException(ForbiddenHttpException::class);
        $this->makeController()->actionIndex($this->missingCredentialId());
    }

    /**
     * Regression: "Select all" ticked every row, and with more templates
     * than one request may change the whole request was rejected; the page
     * did not mention the limit.
     */
    public function testTheListCapsSelectAllAtTheLimitAndSaysSo(): void
    {
        $vault = $this->storedVault((int)$this->loginWithRole('operator')->id);
        $limit = VaultCredentialAssignmentService::MAX_TEMPLATES;

        $over = $this->renderIndex($vault, $this->candidateRows($limit + 1));
        $this->assertStringContainsString('id="vault-assign-limit"', $over);
        $this->assertStringContainsString('At most ' . $limit . ' job templates can be assigned at once.', $over);
        $this->assertStringContainsString('var limit = ' . $limit . ',', $over);

        // Rows that already have this vault cannot be selected and do not count.
        $atLimit = array_merge($this->candidateRows($limit), [['current' => 'this'] + $this->candidateRows(1)[0]]);
        $this->assertStringNotContainsString('id="vault-assign-limit"', $this->renderIndex($vault, $atLimit));
    }

    // -- actionAssign ---------------------------------------------------------
    // -- actionAssign ---------------------------------------------------------

    public function testAssignChangesTheSelectedTemplatesAndRedirectsToTheCredential(): void
    {
        $userId = (int)$this->loginWithRole('operator')->id;
        $vault = $this->storedVault($userId);
        $other = $this->storedVault($userId);
        $without = $this->template($userId);
        $withOther = $this->template($userId);
        $this->setPrimary($withOther, $other);
        $withThis = $this->template($userId);
        $this->attach($withThis, $vault, 0);
        $notSelected = $this->template($userId);
        $this->setPost(['job_template_ids' => [(string)$without->id, (string)$withOther->id, (string)$withThis->id]]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionAssign((int)$vault->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(['/credential/view', 'id' => (int)$vault->id], $ctrl->capturedRedirect);
        $this->assertSame(
            ['success' => "Vault password \"{$vault->name}\": assigned to 1 job template(s), replaced another vault password on 1, 1 already had it."],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertSame([(int)$vault->id], $this->credentialIds($without));
        $this->assertSame([(int)$vault->id], $this->credentialIds($withOther));
        $this->assertSame((int)$vault->id, (int)$this->reload($withOther)->credential_id, 'the vault password takes over the primary slot');
        $this->assertSame([(int)$vault->id], $this->credentialIds($withThis));
        $this->assertSame([], $this->credentialIds($notSelected));

        $audits = $this->templateAudits((int)$withOther->id);
        $this->assertCount(1, $audits);
        $this->assertSame('web', $audits[0]['source']);
        $this->assertSame(['credential_id' => (int)$vault->id, 'replaced' => [(int)$other->id]], $audits[0]['vault_assignment']);
        $this->assertSame([], $this->templateAudits((int)$withThis->id), 'a template that already had the vault password is not saved again');
    }

    public function testAssignWarnsAboutATemplateThatCannotBeSaved(): void
    {
        $userId = (int)$this->loginWithRole('operator')->id;
        $vault = $this->storedVault($userId);
        $valid = $this->template($userId);
        // Stored with a value the form rejects, so saving it fails validation.
        $invalid = $this->template($userId);
        $invalid->forks = 500;
        $invalid->save(false);
        $this->setPost(['job_template_ids' => [(string)$valid->id, (string)$invalid->id]]);

        $ctrl = $this->makeController();
        $ctrl->actionAssign((int)$vault->id);

        $this->assertSame(['/credential/view', 'id' => (int)$vault->id], $ctrl->capturedRedirect);
        $this->assertSame(
            ['warning' => "Vault password \"{$vault->name}\": assigned to 1 job template(s), 1 failed: \"{$invalid->name}\" (Forks must be no greater than 200.)."],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertSame([(int)$vault->id], $this->credentialIds($valid));
        $this->assertSame([], $this->credentialIds($invalid));
    }

    public function testAssignWithoutASelectionFlashesTheErrorAndReturnsToTheList(): void
    {
        $vault = $this->storedVault((int)$this->loginWithRole('operator')->id);
        $this->setPost([]);

        $ctrl = $this->makeController();
        $result = $ctrl->actionAssign((int)$vault->id);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(['index', 'id' => (int)$vault->id], $ctrl->capturedRedirect);
        $this->assertSame(['danger' => self::NO_SELECTION], \Yii::$app->session->getAllFlashes());
    }

    /**
     * A single value instead of a list is no selection, even when it is the
     * id of a template the user may change.
     */
    public function testANonArrayJobTemplateIdsIsTreatedAsNoSelection(): void
    {
        $userId = (int)$this->loginWithRole('operator')->id;
        $vault = $this->storedVault($userId);
        $template = $this->template($userId);
        $this->setPost(['job_template_ids' => (string)$template->id]);

        $ctrl = $this->makeController();
        $ctrl->actionAssign((int)$vault->id);

        $this->assertSame(['index', 'id' => (int)$vault->id], $ctrl->capturedRedirect);
        $this->assertSame(['danger' => self::NO_SELECTION], \Yii::$app->session->getAllFlashes());
        $this->assertSame([], $this->credentialIds($template));
    }

    /**
     * One template the user may only view rejects the whole request: the
     * template the user may change is left alone too.
     */
    public function testAssignIncludingATemplateTheUserMayNotChangeChangesNothing(): void
    {
        $operator = $this->loginWithRole('operator');
        $userId = (int)$operator->id;
        $vault = $this->storedVault($userId);
        $open = $this->template($userId);
        $viewOnly = $this->teamTemplate($operator, TeamProject::ROLE_VIEWER);
        $this->setPost(['job_template_ids' => [(string)$open->id, (string)$viewOnly->id]]);

        $ctrl = $this->makeController();
        $ctrl->actionAssign((int)$vault->id);

        $this->assertSame(['index', 'id' => (int)$vault->id], $ctrl->capturedRedirect);
        $this->assertSame(['danger' => "You may not change job template(s) #{$viewOnly->id}."], \Yii::$app->session->getAllFlashes());
        $this->assertSame([], $this->credentialIds($open));
        $this->assertSame([], $this->credentialIds($viewOnly));
        $this->assertSame([], $this->templateAudits((int)$open->id));
    }

    public function testAssignOfAVaultWithoutAUsableSecretChangesNothing(): void
    {
        $userId = (int)$this->loginWithRole('operator')->id;
        $vault = $this->legacyVault($userId, null);
        $template = $this->template($userId);
        $this->setPost(['job_template_ids' => [(string)$template->id]]);

        $ctrl = $this->makeController();
        $ctrl->actionAssign((int)$vault->id);

        $this->assertSame(['index', 'id' => (int)$vault->id], $ctrl->capturedRedirect);
        $this->assertSame(
            ['danger' => 'The vault password has no usable secret, so jobs would fail. Enter it on the credential page first.'],
            \Yii::$app->session->getAllFlashes()
        );
        $this->assertSame([], $this->credentialIds($template));
    }

    public function testAssignToANonVaultCredentialIs404AndChangesNothing(): void
    {
        $userId = (int)$this->loginWithRole('operator')->id;
        $token = $this->storedCredential($userId, Credential::TYPE_TOKEN, ['token' => 'tok-not-a-vault']);
        $template = $this->template($userId);
        $this->setPost(['job_template_ids' => [(string)$template->id]]);

        $this->assertThrows(
            NotFoundHttpException::class,
            "Vault password #{$token->id} not found.",
            fn () => $this->makeController()->actionAssign((int)$token->id)
        );
        $this->assertSame([], $this->credentialIds($template));
    }

    public function testAssignToAMissingCredentialIs404(): void
    {
        $this->loginWithRole('operator');
        $this->setPost(['job_template_ids' => ['1']]);

        $this->expectException(NotFoundHttpException::class);
        $this->makeController()->actionAssign($this->missingCredentialId());
    }

    public function testAssignWithoutCredentialViewIsForbiddenAndChangesNothing(): void
    {
        $userId = (int)$this->loginAsTemplateEditor()->id;
        $vault = $this->storedVault($userId);
        $template = $this->template($userId);
        $this->setPost(['job_template_ids' => [(string)$template->id]]);

        $this->assertThrows(
            ForbiddenHttpException::class,
            'You are not allowed to view credentials.',
            fn () => $this->makeController()->actionAssign((int)$vault->id)
        );
        $this->assertSame([], $this->credentialIds($template));
    }

    // -- Access and verb filters (through runAction) --------------------------

    public function testTheAccessFilterDeniesAViewer(): void
    {
        $userId = (int)$this->loginWithRole('viewer')->id;
        $vault = $this->storedVault($userId);
        $template = $this->template($userId);

        $this->assertDeniedByTheAccessFilter(fn () => $this->makeController()->runAction('index', ['id' => $vault->id]));
        $this->setPost(['job_template_ids' => [(string)$template->id]]);
        $this->assertDeniedByTheAccessFilter(fn () => $this->makeController()->runAction('assign', ['id' => $vault->id]));
        $this->assertSame([], $this->credentialIds($template));
        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    public function testTheAccessFilterAdmitsAnOperator(): void
    {
        $userId = (int)$this->loginWithRole('operator')->id;
        $vault = $this->storedVault($userId);
        $template = $this->template($userId);

        $this->assertSame('rendered:index', $this->makeController()->runAction('index', ['id' => $vault->id]));

        $this->setPost(['job_template_ids' => [(string)$template->id]]);
        $ctrl = $this->makeController();
        $ctrl->runAction('assign', ['id' => $vault->id]);

        $this->assertSame(['/credential/view', 'id' => (int)$vault->id], $ctrl->capturedRedirect);
        $this->assertSame([(int)$vault->id], $this->credentialIds($template));
    }

    /**
     * job-template.update gets past the filter; the action itself still asks
     * for credential.view.
     */
    public function testATemplateEditorWithoutCredentialViewPassesTheFilterButNotTheAction(): void
    {
        $vault = $this->storedVault((int)$this->loginAsTemplateEditor()->id);

        $this->assertThrows(
            ForbiddenHttpException::class,
            'You are not allowed to view credentials.',
            fn () => $this->makeController()->runAction('index', ['id' => $vault->id])
        );
    }

    public function testAssignAcceptsOnlyPost(): void
    {
        $userId = (int)$this->loginWithRole('operator')->id;
        $vault = $this->storedVault($userId);
        $template = $this->template($userId);
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['job_template_ids' => [(string)$template->id]]);

        $this->expectException(MethodNotAllowedHttpException::class);
        try {
            $this->makeController()->runAction('assign', ['id' => $vault->id]);
        } finally {
            $this->assertSame([], $this->credentialIds($template));
        }
    }

    // -- Helpers --------------------------------------------------------------

    private function makeController(): CredentialAssignmentController
    {
        return new class ('credential-assignment', \Yii::$app) extends CredentialAssignmentController {
            public string $capturedView = '';
            /** @var array<string, mixed> */
            public array $capturedParams = [];
            /** @var array<int|string, mixed> */
            public array $capturedRedirect = [];

            public function render($view, $params = []): string
            {
                $this->capturedView = $view;
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;
                return 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = (array)$url;
                $response = new Response();
                $response->content = 'redirected';
                return $response;
            }
        };
    }

    /**
     * The real index view, rendered for the given candidate rows.
     *
     * @param list<array<string, mixed>> $candidates
     */
    private function renderIndex(Credential $vault, array $candidates): string
    {
        $ctrl = new CredentialAssignmentController('credential-assignment', \Yii::$app);
        \Yii::$app->controller = $ctrl;
        try {
            return $ctrl->renderPartial('index', ['vault' => $vault, 'candidates' => $candidates, 'secretUsable' => true]);
        } finally {
            \Yii::$app->controller = null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidateRows(int $count): array
    {
        return array_map(static fn (int $i): array => [
            'id' => $i,
            'name' => 'tpl-' . $i,
            'project_id' => 1,
            'project_name' => 'project',
            'current' => 'none',
            'vaults' => [],
        ], range(1, $count));
    }

    private function loginWithRole(string $roleName): User
    {
        $user = $this->createUser('vault-assign');
        $auth = $this->authManager();
        $role = $auth->getRole($roleName);
        $this->assertNotNull($role, $roleName);
        $auth->assign($role, (string)$user->id);
        $this->loginAs($user);

        return $user;
    }

    /**
     * A user who may change job templates but not see credentials: a custom
     * role that holds job-template.update only.
     */
    private function loginAsTemplateEditor(): User
    {
        $user = $this->createUser('vault-assign-editor');
        $auth = $this->authManager();
        $role = $auth->createRole('vault-assign-editor-' . uniqid());
        $auth->add($role);
        $permission = $auth->getPermission('job-template.update');
        $this->assertNotNull($permission);
        $auth->addChild($role, $permission);
        $auth->assign($role, (string)$user->id);
        $this->loginAs($user);

        return $user;
    }

    private function authManager(): \yii\rbac\ManagerInterface
    {
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);

        return $auth;
    }

    private function storedVault(int $userId): Credential
    {
        return $this->storedCredential($userId, Credential::TYPE_VAULT, ['vault_password' => 'vault-secret-' . uniqid()]);
    }

    /**
     * @param array<string, string> $secrets
     */
    private function storedCredential(int $userId, string $type, array $secrets): Credential
    {
        $credential = new Credential();
        $credential->name = 'vault-assign-' . $type . '-' . uniqid('', true);
        $credential->credential_type = $type;
        $credential->created_by = $userId;
        /** @var CredentialWriteService $writer */
        $writer = \Yii::$app->get('credentialWriteService');
        $this->assertTrue($writer->create($credential, $secrets), (string)json_encode($credential->errors));

        return $credential;
    }

    /**
     * A vault password stored before secrets were required, or one whose
     * secret no longer decrypts.
     */
    private function legacyVault(int $userId, ?string $secretData): Credential
    {
        $vault = $this->createCredential($userId, Credential::TYPE_VAULT);
        $vault->secret_data = $secretData;
        $vault->save(false);

        return $vault;
    }

    private function template(int $userId, ?int $projectId = null): JobTemplate
    {
        return $this->createJobTemplate(
            $projectId ?? (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
    }

    /**
     * A template in a project restricted to a team of $member with $role.
     */
    private function teamTemplate(User $member, string $role): JobTemplate
    {
        $userId = (int)$member->id;
        $project = $this->createProject($userId);
        $team = $this->createTeam($userId);
        $this->addTeamMember((int)$team->id, $userId);
        $this->createTeamProject((int)$team->id, (int)$project->id, $role);

        return $this->template($userId, (int)$project->id);
    }

    /**
     * A template in a project restricted to a team the signed-in user is not in.
     */
    private function foreignTemplate(): JobTemplate
    {
        $owner = $this->createUser('vault-assign-owner');
        $ownerId = (int)$owner->id;
        $project = $this->createProject($ownerId);
        $team = $this->createTeam($ownerId);
        $this->addTeamMember((int)$team->id, $ownerId);
        $this->createTeamProject((int)$team->id, (int)$project->id);

        return $this->template($ownerId, (int)$project->id);
    }

    private function setPrimary(JobTemplate $template, Credential $credential): void
    {
        $template->credential_id = $credential->id;
        $template->save(false);
    }

    private function attach(JobTemplate $template, Credential $credential, int $sortOrder): void
    {
        \Yii::$app->db->createCommand()->insert('{{%job_template_credential}}', [
            'job_template_id' => $template->id,
            'credential_id' => $credential->id,
            'sort_order' => $sortOrder,
        ])->execute();
    }

    private function reload(JobTemplate $template): JobTemplate
    {
        $fresh = JobTemplate::findOne($template->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    /**
     * The template's credential ids as stored now, primary first.
     *
     * @return list<int>
     */
    private function credentialIds(JobTemplate $template): array
    {
        return array_column($this->reload($template)->credentialSnapshot(), 'id');
    }

    /**
     * @param mixed $candidates
     * @return array<int, array<string, mixed>>
     */
    private function rowsById($candidates): array
    {
        $this->assertIsArray($candidates);
        $rows = [];
        foreach ($candidates as $row) {
            $this->assertIsArray($row);
            $rows[(int)$row['id']] = $row;
        }

        return $rows;
    }

    /**
     * Metadata of the job-template.updated audit entries of a template, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function templateAudits(int $templateId): array
    {
        $logs = AuditLog::find()
            ->where(['action' => AuditLog::ACTION_TEMPLATE_UPDATED, 'object_type' => 'job_template', 'object_id' => $templateId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        $entries = [];
        foreach ($logs as $log) {
            $meta = json_decode((string)$log->metadata, true);
            $this->assertIsArray($meta);
            $entries[] = $meta;
        }

        return $entries;
    }

    private function missingCredentialId(): int
    {
        return (int)Credential::find()->max('id') + 1000;
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private function assertThrows(string $class, string $message, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertSame($message, $e->getMessage());
            return;
        }
        $this->fail("expected {$class}");
    }

    /**
     * The access filter answers with its own message; the action's checks
     * use different ones.
     */
    private function assertDeniedByTheAccessFilter(callable $call): void
    {
        $this->assertThrows(ForbiddenHttpException::class, 'You are not allowed to perform this action.', $call);
    }
}
