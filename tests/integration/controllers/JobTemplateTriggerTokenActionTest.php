<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobTemplateController;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\AssetManager;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\View;

/**
 * The trigger token actions of JobTemplateController and the trigger card of
 * the template page: a trigger runs as the user who generated its token, and
 * only users who may change the template (job-template.update and operator
 * access to its project) see the card.
 */
class JobTemplateTriggerTokenActionTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /**
     * Regression: the trigger ran as the template's creator, so whoever could
     * generate a token launched jobs with the creator's rights.
     */
    public function testGeneratingATokenRecordsTheUserWhoGeneratedIt(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $own = $scope['own'];

        $result = $this->makeController()->actionGenerateTriggerToken((int)$own->id);

        $this->assertInstanceOf(Response::class, $result);
        $own->refresh();
        $this->assertNotNull($own->trigger_token);
        $this->assertSame((int)$scope['member']->id, (int)$own->trigger_token_created_by);
        $this->assertSame((int)$scope['member']->id, $own->getTriggerUserId());
        $this->assertNotSame((int)$scope['member']->id, (int)$own->created_by);
        $audit = AuditLog::findOne(['action' => AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_GENERATED, 'object_id' => $own->id]);
        $this->assertNotNull($audit);
        $this->assertSame((int)$scope['member']->id, (int)$audit->user_id);
    }

    public function testANewTokenRunsAsTheUserWhoGeneratedIt(): void
    {
        $scope = $this->teamScope();
        $own = $scope['own'];
        $own->generateTriggerToken((int)$scope['admin']->id);
        $this->loginAs($scope['member']);

        $this->makeController()->actionGenerateTriggerToken((int)$own->id);

        $own->refresh();
        $this->assertSame((int)$scope['member']->id, (int)$own->trigger_token_created_by);
    }

    public function testRevokingATokenForgetsWhoGeneratedIt(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $own = $scope['own'];
        $own->generateTriggerToken((int)$scope['member']->id);

        $this->makeController()->actionRevokeTriggerToken((int)$own->id);

        $own->refresh();
        $this->assertNull($own->trigger_token);
        $this->assertNull($own->trigger_token_created_by);
        $this->assertNotNull(AuditLog::findOne(['action' => AuditLog::ACTION_TEMPLATE_TRIGGER_TOKEN_REVOKED, 'object_id' => $own->id]));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tokenActionOnATemplateTheUserMayNotOperateProvider(): array
    {
        return [
            'generate, project the team only views' => ['generate', 'viewed'],
            'revoke, project the team only views' => ['revoke', 'viewed'],
            'generate, another team\'s project' => ['generate', 'foreign'],
            'revoke, another team\'s project' => ['revoke', 'foreign'],
        ];
    }

    /**
     * @dataProvider tokenActionOnATemplateTheUserMayNotOperateProvider
     */
    public function testTokenActionsNeedOperatorAccessToTheProject(string $action, string $key): void
    {
        $scope = $this->teamScope();
        $template = $scope[$key];
        $this->assertInstanceOf(JobTemplate::class, $template);
        $template->generateTriggerToken((int)$scope['admin']->id);
        $template->refresh();
        $token = $template->trigger_token;
        $this->loginAs($scope['member']);

        $ctrl = $this->makeController();
        try {
            $action === 'generate'
                ? $ctrl->actionGenerateTriggerToken((int)$template->id)
                : $ctrl->actionRevokeTriggerToken((int)$template->id);
            $this->fail('Expected ForbiddenHttpException');
        } catch (ForbiddenHttpException) {
            // expected
        }

        $template->refresh();
        $this->assertSame($token, $template->trigger_token);
        $this->assertSame((int)$scope['admin']->id, (int)$template->trigger_token_created_by);
    }

    // ── Trigger card ─────────────────────────────────────────────────────────

    public function testTheTriggerCardNamesTheUserWhoGeneratedTheToken(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $generator = $this->createUserWithRole('<b>generator</b>', 'operator');
        $own = $scope['own'];
        $own->generateTriggerToken((int)$generator->id);

        $card = $this->triggerCard($own);

        $this->assertStringContainsString(
            'Runs as <strong>' . Html::encode($generator->username) . '</strong>, who generated the token.',
            $card
        );
        $this->assertStringNotContainsString('<b>generator</b>', $card);
        $this->assertStringContainsString('Calls are refused while this user is disabled or may not launch this template.', $card);
    }

    public function testTheTriggerCardOfALegacyTokenNamesTheTemplateCreator(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);
        $own = $scope['own'];
        $own->generateTriggerToken((int)$scope['member']->id);
        JobTemplate::updateAll(['trigger_token_created_by' => null], ['id' => $own->id]);
        $own->refresh();
        /** @var User $creator */
        $creator = $scope['admin'];

        $card = $this->triggerCard($own);

        $this->assertStringContainsString(
            'Runs as <strong>' . Html::encode($creator->username) . '</strong>, '
                . Html::encode('the template\'s creator, because this token was generated before'),
            $card
        );
        $this->assertStringContainsString('Revoke it and generate a new one to run the trigger as yourself.', $card);
    }

    public function testTheTriggerCardWithoutATokenSaysTheTriggerWillRunAsTheViewer(): void
    {
        $scope = $this->teamScope();
        $this->loginAs($scope['member']);

        $card = $this->triggerCard($scope['own']);

        $this->assertStringContainsString('No trigger token configured.', $card);
        $this->assertStringContainsString('The trigger will run as you.', $card);
        $this->assertStringNotContainsString('Runs as', $card);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function tokenStateProvider(): array
    {
        return [
            'no token' => ['none'],
            'a generated token' => ['generated'],
            'a token from before the generator was recorded' => ['legacy'],
        ];
    }

    /**
     * Regression: the page showed the trigger card, and with it whom the
     * trigger runs as, to every holder of job-template.update, also to a
     * team member whose team may only view the template's project. The
     * card's buttons then answered 403.
     *
     * @dataProvider tokenStateProvider
     */
    public function testATeamViewerWithTheOperatorRoleSeesNoTriggerCard(string $state): void
    {
        $scope = $this->teamScope();
        $viewed = $scope['viewed'];
        $runsAs = $this->giveTriggerToken($viewed, $state);
        $this->loginAs($scope['member']);

        $page = $this->viewPage($viewed);

        $this->assertStringContainsString(Html::encode($viewed->name), $page, 'the page is shown');
        $this->assertNoTriggerCard($page, $runsAs);
    }

    /**
     * @dataProvider tokenStateProvider
     */
    public function testAViewerSeesNoTriggerCard(string $state): void
    {
        $scope = $this->teamScope('viewer');
        $open = $scope['open'];
        $runsAs = $this->giveTriggerToken($open, $state);
        $this->loginAs($scope['member']);

        $page = $this->viewPage($open);

        $this->assertStringContainsString(Html::encode($open->name), $page, 'the page is shown');
        $this->assertNoTriggerCard($page, $runsAs);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function unrestrictedUserProvider(): array
    {
        return [
            'an admin' => [false],
            'a superadmin without a role' => [true],
        ];
    }

    /**
     * Admins, and superadmins without a role too, may change every template,
     * also one whose project a team may only view, so they see its card.
     *
     * @dataProvider unrestrictedUserProvider
     */
    public function testUsersWhoMayChangeEveryTemplateSeeTheTriggerCard(bool $superadmin): void
    {
        $scope = $this->teamScope();
        $viewed = $scope['viewed'];
        $viewed->generateTriggerToken((int)$scope['outsider']->id);
        $user = $superadmin ? $this->createUser('superadmin') : $this->createUserWithRole('admin', 'admin');
        $user->is_superadmin = $superadmin;
        $user->save(false);
        $this->loginAs($user);

        $card = $this->triggerCard($viewed);

        $this->assertStringContainsString(
            'Runs as <strong>' . Html::encode($scope['outsider']->username) . '</strong>, who generated the token.',
            $card
        );
        $this->assertStringContainsString('revoke-trigger-token', $card);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Put the template into a trigger token state; returns the user its
     * trigger then runs as, null without a token.
     */
    private function giveTriggerToken(JobTemplate $template, string $state): ?User
    {
        if ($state === 'generated') {
            $generator = $this->createUserWithRole('generator', 'operator');
            $template->generateTriggerToken((int)$generator->id);
            return $generator;
        }
        if ($state === 'legacy') {
            $template->generateTriggerToken((int)$template->created_by);
            JobTemplate::updateAll(['trigger_token_created_by' => null], ['id' => $template->id]);
            return User::findOne($template->created_by);
        }
        return null;
    }

    /**
     * The page shows neither the trigger card nor whom the trigger runs as.
     */
    private function assertNoTriggerCard(string $page, ?User $runsAs): void
    {
        $this->assertStringNotContainsString('Inbound Trigger', $page);
        $this->assertStringNotContainsString('trigger-runs-as', $page);
        $this->assertStringNotContainsString('trigger-not-configured', $page);
        $this->assertStringNotContainsString('Runs as', $page);
        $this->assertStringNotContainsString('generate-trigger-token', $page);
        $this->assertStringNotContainsString('revoke-trigger-token', $page);
        if ($runsAs !== null) {
            $this->assertStringNotContainsString(Html::encode($runsAs->username), $page);
        }
    }

    /**
     * The trigger card of the template page.
     */
    private function triggerCard(JobTemplate $template): string
    {
        $html = $this->viewPage($template);
        $start = strpos($html, 'Inbound Trigger');
        $this->assertNotFalse($start, 'the trigger card is shown');
        $end = strpos($html, 'Ansible Lint', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /**
     * The template page as actionView() renders it for the logged-in user,
     * without the layout. The console application of the tests has no web
     * root, so asset bundles are dummies; view, asset manager and
     * controller are restored.
     */
    private function viewPage(JobTemplate $template): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        $ctrl = new class ('job-template', \Yii::$app) extends JobTemplateController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
        \Yii::$app->controller = $ctrl;
        try {
            return $ctrl->actionView((int)$template->id);
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    private function makeController(): JobTemplateController
    {
        return new class ('job-template', \Yii::$app) extends JobTemplateController {
            /** @var mixed the route passed to redirect() */
            public mixed $capturedRedirect = null;

            public function redirect($url, $statusCode = 302): Response
            {
                $this->capturedRedirect = $url;
                $r = new Response();
                $r->content = 'redirected';
                return $r;
            }
        };
    }
}
