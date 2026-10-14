<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\UserController;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\web\AssetManager;
use yii\web\Response;
use yii\web\View;

/**
 * The web UI and user.ldap_sync_disabled, the mark of an account that
 * ldap/sync deactivated because the directory no longer had the user
 * (CLAUDE.md security rule 15):
 * - the status toggle and the Edit User form clear the mark when they change
 *   the status, so an account deactivated in Ansilume stays inactive
 *   whatever the directory says; a save that keeps the status keeps it;
 * - the user page says, under the status of an inactive LDAP account,
 *   whether the directory activates it again.
 */
class UserLdapSyncMarkTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUserWithRole('mark_admin', 'admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $this->loginAs($admin);
    }

    /**
     * regression: activating an account ldap/sync deactivated, then
     * deactivating it with the status toggle, left the mark, so the next
     * LDAP sign-in or ldap/sync would activate the account an admin had
     * just deactivated. Each change of the status clears it.
     */
    public function testRegressionTheStatusToggleClearsTheMark(): void
    {
        $user = $this->deactivatedByLdapSync('toggle');

        $this->makeController()->actionToggleStatus((int)$user->id);
        $this->assertSame([User::STATUS_ACTIVE, false], $this->stateOf($user), 'activated');

        $this->makeController()->actionToggleStatus((int)$user->id);
        $this->assertSame([User::STATUS_INACTIVE, false], $this->stateOf($user), 'deactivated in Ansilume');
        $this->assertSame(UserController::DEACTIVATED_IN_ANSILUME, $this->statusNote($user));
    }

    /**
     * regression: the Edit User form left the mark when it changed the
     * status.
     */
    public function testRegressionTheEditFormClearsTheMarkWhenItChangesTheStatus(): void
    {
        $user = $this->deactivatedByLdapSync('form');
        $this->setPost(['UserForm' => $this->formOf($user, ['status' => (string)User::STATUS_ACTIVE])]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$user->id);

        $this->assertSame(['view', 'id' => $user->id], $ctrl->capturedRedirect, 'saved');
        $this->assertSame([User::STATUS_ACTIVE, false], $this->stateOf($user));
    }

    public function testASaveOfTheEditFormThatKeepsTheStatusKeepsTheMark(): void
    {
        $user = $this->deactivatedByLdapSync('form_keep');
        $email = 'kept-mark-' . uniqid('', true) . '@example.com';
        $this->setPost(['UserForm' => $this->formOf($user, ['email' => $email])]);

        $ctrl = $this->makeController();
        $ctrl->actionUpdate((int)$user->id);

        $this->assertSame(['view', 'id' => $user->id], $ctrl->capturedRedirect, 'saved');
        $this->assertSame([User::STATUS_INACTIVE, true], $this->stateOf($user));
        $this->assertSame($email, User::findOne($user->id)?->email);
    }

    /**
     * The note under the status names which case an inactive LDAP account
     * is; active and local accounts have none.
     */
    public function testTheUserPageSaysWhetherTheDirectoryActivatesAnInactiveLdapAccountAgain(): void
    {
        $bySync = $this->deactivatedByLdapSync('page_sync');
        $inAnsilume = $this->ldapUser('page_ansilume');
        $inAnsilume->status = User::STATUS_INACTIVE;
        $inAnsilume->save(false);
        $active = $this->ldapUser('page_active');
        $local = $this->createUser('page_local');
        $local->status = User::STATUS_INACTIVE;
        $local->save(false);

        $this->assertSame(UserController::DEACTIVATED_BY_LDAP_SYNC, $this->statusNote($bySync));
        $this->assertSame(UserController::DEACTIVATED_IN_ANSILUME, $this->statusNote($inAnsilume));
        $this->assertNull($this->statusNote($active));
        $this->assertNull($this->statusNote($local));
    }

    private function deactivatedByLdapSync(string $suffix): User
    {
        $user = $this->ldapUser($suffix);
        $user->status = User::STATUS_INACTIVE;
        $user->ldap_sync_disabled = true;
        $user->save(false);
        $this->assertSame([User::STATUS_INACTIVE, true], $this->stateOf($user), 'the fixture');

        return $user;
    }

    private function ldapUser(string $suffix): User
    {
        $user = $this->createUser('mark_ldap_' . $suffix);
        $user->markAsLdapManaged();
        $user->ldap_dn = 'uid=' . $user->username . ',dc=test';
        $user->ldap_uid = 'guid-' . $user->username;
        $user->save(false);

        return $user;
    }

    /**
     * @param array<string, string> $changes
     * @return array<string, string>
     */
    private function formOf(User $user, array $changes): array
    {
        return array_merge([
            'username' => (string)$user->username,
            'email' => (string)$user->email,
            'password' => '',
            'role' => 'viewer',
            'status' => (string)$user->status,
            'is_superadmin' => '0',
        ], $changes);
    }

    /**
     * @return array{0: int, 1: bool} the stored status and mark
     */
    private function stateOf(User $user): array
    {
        $fresh = User::findOne($user->id);
        $this->assertNotNull($fresh);

        return [(int)$fresh->status, (bool)$fresh->ldap_sync_disabled];
    }

    /**
     * The text of the note under the status on the user page; null when it
     * has none.
     */
    private function statusNote(User $user): ?string
    {
        $ctrl = $this->makeController();
        $html = $this->page($ctrl, '/user/view?id=' . $user->id, fn () => $ctrl->actionView((int)$user->id));
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $nodes = (new \DOMXPath($document))->query('//*[@id="user-status-note"]');
        $this->assertNotFalse($nodes);
        $this->assertLessThanOrEqual(1, $nodes->length);
        $note = $nodes->length === 1 ? trim((string)$nodes->item(0)?->textContent) : null;
        $this->assertSame($ctrl->capturedParams['statusNote'] ?? null, $note, 'the page shows the note the controller gives it');

        return $note;
    }

    /**
     * What $action renders with the real views, without the layout.
     *
     * @param \Closure(): (string|Response) $action
     */
    private function page(UserController $ctrl, string $url, \Closure $action): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        \Yii::$app->controller = $ctrl;
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setUrl($url);
        $ctrl->rendersViews = true;
        try {
            $html = $action();
            $this->assertIsString($html, 'the page was rendered, not redirected');

            return $html;
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    private function makeController(): UserController
    {
        return new class ('user', \Yii::$app) extends UserController {
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            /** @var array<string, mixed> the parameters render() was given */
            public array $capturedParams = [];

            /** Whether render() renders the real view, without the layout. */
            public bool $rendersViews = false;

            public function render($view, $params = []): string
            {
                $this->capturedParams = $params;
                return $this->rendersViews ? $this->renderPartial($view, $params) : 'rendered:' . $view;
            }

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                $response = new Response();
                $response->content = 'redirected';
                return $response;
            }
        };
    }
}
