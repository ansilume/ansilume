<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\TriggerTokenResolver;
use app\controllers\JobTemplateController;
use app\controllers\WorkflowTemplateController;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowTemplate;
use app\tests\integration\TeamScopeFixtures;
use yii\helpers\Html;
use yii\web\AssetManager;
use yii\web\Controller;
use yii\web\Response;
use yii\web\View;

/**
 * The Inbound Trigger card of job and workflow template pages shows only the
 * header form: the token goes into the X-Trigger-Token header, never into a
 * URL (URLs end up in access logs and proxies).
 *
 * Regression: right after generation the card printed the raw token in
 * URLs (?token=…), the copy buttons broke on text with a single quote, and
 * the layout printed the token a second time, because the card read the
 * flash without deleting it and the layout prints every flash that is left.
 */
class TriggerCardViewTest extends WebControllerTestCase
{
    use TeamScopeFixtures;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function kindProvider(): array
    {
        return [
            'job template' => ['job', '', '/trigger/fire'],
            'workflow template' => ['workflow', 'wf-', '/trigger/fire-workflow'],
        ];
    }

    /**
     * @dataProvider kindProvider
     */
    public function testAFreshTokenIsShownOnceInTheHeaderFormOnly(string $kind, string $prefix, string $endpoint): void
    {
        $admin = $this->signedInAdmin();
        $template = $this->template($kind, $admin);
        $this->generateThroughTheWebAction($kind, $template);
        $token = $this->rawTokenOfTheFlash();

        $html = $this->page($kind, $template);

        $card = $this->card($html);
        $url = $this->appBase() . $endpoint;
        $curl = 'curl -X POST -H "' . TriggerTokenResolver::HEADER . ': ' . $token . '" -H "Content-Type: application/json" -d "{}" ' . $url;
        $this->assertStringContainsString('<code id="' . $prefix . 'trigger-token-display">' . $token . '</code>', $card);
        $this->assertStringContainsString('>' . Html::encode($curl) . '</code>', $card);
        $this->assertStringContainsString('id="' . $prefix . 'trigger-endpoint">' . Html::encode($url) . '</code>', $card);
        $this->assertStringNotContainsString('token=' . $token, $html, 'no URL with the token');
        $this->assertStringNotContainsString('/' . $token, $html, 'no path with the token');
        $this->assertStringNotContainsString('?token=', $card);

        // The copy buttons read their text from a data attribute; no value
        // goes into the onclick JavaScript.
        $this->assertStringContainsString('id="btn-copy-' . $prefix . 'trigger-token" class="btn btn-sm btn-outline-secondary ms-2" data-copy-text="' . $token . '"', $card);
        $this->assertStringContainsString('id="btn-copy-' . $prefix . 'curl" class="btn btn-outline-secondary btn-sm" data-copy-text="' . Html::encode($curl) . '"', $card);
        $this->assertSame(2, preg_match_all('/onclick="var btn = this; copyToClipboard\(btn\.getAttribute\(&#039;data-copy-text&#039;\)\)/', $card));
        $this->assertSame(0, preg_match('/onclick="[^"]*' . $token . '/', $html));

        $this->assertSame(1, substr_count($html, 'data-copy-text="' . $token . '"'));
    }

    /**
     * Regression: the layout prints every flash that is left, and the card
     * read the token's flash without deleting it, so the page showed the
     * raw token a second time, in an alert.
     *
     * @dataProvider kindProvider
     */
    public function testTheLayoutDoesNotPrintTheTokenAgain(string $kind, string $prefix): void
    {
        $admin = $this->signedInAdmin();
        $template = $this->template($kind, $admin);
        $this->generateThroughTheWebAction($kind, $template);
        $token = $this->rawTokenOfTheFlash();

        $html = $this->page($kind, $template);

        $this->assertStringContainsString('<code id="' . $prefix . 'trigger-token-display">' . $token . '</code>', $html, 'the card shows it');
        $this->assertStringNotContainsString('alert-trigger_token_raw', $html, 'the layout does not print it again');
        $this->assertArrayNotHasKey('trigger_token_raw', $this->session()->getAllFlashes(), 'the flash is used up');
    }

    /**
     * @dataProvider kindProvider
     */
    public function testAnActiveTokenShowsTheMaskedHeaderForm(string $kind, string $prefix, string $endpoint): void
    {
        $admin = $this->signedInAdmin();
        $template = $this->template($kind, $admin);
        $token = $template->generateTriggerToken((int)$admin->id);

        $card = $this->card($this->page($kind, $template));

        $masked = 'curl -X POST -H "' . TriggerTokenResolver::HEADER . ': ****************" -H "Content-Type: application/json" -d "{}" ' . $this->appBase() . $endpoint;
        $this->assertStringContainsString('<code id="' . $prefix . 'trigger-curl-masked" class="d-block text-break">' . Html::encode($masked) . '</code>', $card);
        $this->assertStringNotContainsString($token, $card);
        $this->assertStringNotContainsString($prefix . 'trigger-token-display', $card);
        $this->assertStringNotContainsString('?token=', $card);
        $this->assertStringNotContainsString('/trigger/*', $card, 'not the deprecated path form');
        $this->assertStringContainsString('Authorization: Bearer &lt;token&gt;</code> works as well.', $this->page($kind, $template));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function urlFormsProvider(): array
    {
        return [
            'job template: the query and the path form' => ['job', '', '(?token=… or /trigger/<token>)'],
            'workflow template: the query form only' => ['workflow', 'wf-', '(?token=…)'],
        ];
    }

    /**
     * The note under the header form names the deprecated URL forms that
     * the card's endpoint takes, while the token is fresh and afterwards.
     *
     * Regression: both cards said that callers sending the token as
     * /trigger/<token> keep working. That path reaches job templates only: a
     * workflow token sent there gets a 404 and an invalid-token notification.
     *
     * @dataProvider urlFormsProvider
     */
    public function testTheTransportNoteNamesTheUrlFormsOfTheCardsEndpoint(string $kind, string $prefix, string $forms): void
    {
        $admin = $this->signedInAdmin();
        $template = $this->template($kind, $admin);
        $this->generateThroughTheWebAction($kind, $template);
        $expected = 'Authorization: Bearer <token> works as well. Callers that send the token in the URL ' . $forms
            . ' keep working, but that form is deprecated: URLs end up in access logs. Move them to the header.';

        $fresh = $this->page($kind, $template);
        $active = $this->page($kind, $template);

        $this->assertStringContainsString('id="' . $prefix . 'trigger-token-display"', $fresh, 'first the fresh token');
        $this->assertStringContainsString('id="' . $prefix . 'trigger-curl-masked"', $active, 'then the masked header form');
        $this->assertSame($expected, $this->transportNote($fresh, $prefix));
        $this->assertSame($expected, $this->transportNote($active, $prefix));
    }

    /**
     * A flashed token that is no longer the template's token (it was
     * replaced or revoked in between) is not shown, and not printed by the
     * layout either.
     *
     * @dataProvider kindProvider
     */
    public function testAFlashedTokenThatIsNoLongerTheTemplatesIsNotShown(string $kind, string $prefix): void
    {
        $admin = $this->signedInAdmin();
        $template = $this->template($kind, $admin);
        $stale = $template->generateTriggerToken((int)$admin->id);
        $template->generateTriggerToken((int)$admin->id);
        $this->session()->setFlash('trigger_token_raw', $stale);

        $html = $this->page($kind, $template);

        $this->assertStringNotContainsString($stale, $html);
        $this->assertStringContainsString($prefix . 'trigger-curl-masked', $html);
        $this->assertArrayNotHasKey('trigger_token_raw', $this->session()->getAllFlashes());
    }

    /**
     * A user who may only view the template gets no card, and the flash is
     * used up all the same, so the layout cannot print it.
     *
     * @dataProvider kindProvider
     */
    public function testWithoutTheCardTheFlashIsUsedUpToo(string $kind): void
    {
        $scope = $this->teamScope('viewer');
        $template = $kind === 'job' ? $scope['open'] : $this->createWorkflowWithJobSteps((int)$scope['admin']->id, (int)$scope['open']->id);
        $token = $template->generateTriggerToken((int)$scope['admin']->id);
        $this->session()->setFlash('trigger_token_raw', $token);
        $this->loginAs($scope['member']);

        $html = $this->page($kind, $template);

        $this->assertStringNotContainsString('Inbound Trigger', $html);
        $this->assertStringNotContainsString($token, $html);
        $this->assertArrayNotHasKey('trigger_token_raw', $this->session()->getAllFlashes());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function signedInAdmin(): User
    {
        $admin = $this->createUserWithRole('card_admin', 'admin');
        $this->loginAs($admin);

        return $admin;
    }

    private function template(string $kind, User $owner): JobTemplate|WorkflowTemplate
    {
        if ($kind === 'workflow') {
            return $this->createWorkflowTemplate((int)$owner->id);
        }

        return $this->createJobTemplate(
            (int)$this->createProject((int)$owner->id)->id,
            (int)$this->createInventory((int)$owner->id)->id,
            (int)$this->createRunnerGroup((int)$owner->id)->id,
            (int)$owner->id
        );
    }

    /**
     * Generate the token with the web action, which flashes the raw token
     * for the next page view.
     */
    private function generateThroughTheWebAction(string $kind, JobTemplate|WorkflowTemplate $template): void
    {
        $controller = $kind === 'job'
            ? new class ('job-template', \Yii::$app) extends JobTemplateController {
                public function redirect($url, $statusCode = 302): Response
                {
                    return new Response();
                }
            }
            : new class ('workflow-template', \Yii::$app) extends WorkflowTemplateController {
                public function redirect($url, $statusCode = 302): Response
                {
                    return new Response();
                }
            };
        $controller->actionGenerateTriggerToken((int)$template->id);
    }

    private function rawTokenOfTheFlash(): string
    {
        $flashes = $this->session()->getAllFlashes();
        $this->assertArrayHasKey('trigger_token_raw', $flashes);
        $this->assertIsString($flashes['trigger_token_raw']);
        // getAllFlashes() marks it for deletion in the next request only; it
        // is still there for the page.
        return $flashes['trigger_token_raw'];
    }

    /**
     * The template page as the next request renders it, layout included. The
     * console application of the tests has no web root, so asset bundles are
     * dummies; view, asset manager and controller are restored.
     */
    private function page(string $kind, JobTemplate|WorkflowTemplate $template): string
    {
        $components = \Yii::$app->getComponents(true);
        $originals = ['view' => $components['view'] ?? null, 'assetManager' => $components['assetManager'] ?? null];
        $previousController = \Yii::$app->controller;
        \Yii::$app->set('assetManager', new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        \Yii::$app->set('view', new View());
        /** @var Controller&object{actionView: callable} $controller */
        $controller = $kind === 'job'
            ? new JobTemplateController('job-template', \Yii::$app)
            : new WorkflowTemplateController('workflow-template', \Yii::$app);
        \Yii::$app->controller = $controller;
        try {
            $html = $controller->actionView((int)$template->id);
            $this->assertIsString($html);
            $this->assertStringContainsString('<div id="page-content">', $html, 'rendered with the layout, which prints the flashes');

            return $html;
        } finally {
            \Yii::$app->controller = $previousController;
            foreach ($originals as $id => $definition) {
                \Yii::$app->set($id, $definition);
            }
        }
    }

    /**
     * The Inbound Trigger card of a page, up to its closing note on the
     * other transports (which names the deprecated URL forms in words).
     */
    private function card(string $html): string
    {
        $start = strpos($html, 'Inbound Trigger');
        $this->assertNotFalse($start, 'the trigger card is shown');
        $end = strpos($html, 'trigger-transport-note', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /**
     * The text of the card's note on the other transports, without tags and
     * entities, whitespace collapsed.
     */
    private function transportNote(string $html, string $prefix): string
    {
        $pattern = '~<p [^>]*\bid="' . preg_quote($prefix, '~') . 'trigger-transport-note">(.*?)</p>~s';
        $this->assertSame(1, preg_match_all($pattern, $html, $matches), 'the page shows the note once');
        $text = html_entity_decode(strip_tags($matches[1][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The base URL the card puts in front of the endpoint: APP_URL when set.
     */
    private function appBase(): string
    {
        $base = \Yii::$app->params['appBaseUrl'] ?? null;
        $this->assertIsString($base, 'the test configuration sets appBaseUrl');

        return rtrim($base, '/');
    }

    private function session(): \yii\web\Session
    {
        /** @var \yii\web\Session $session */
        $session = \Yii::$app->session;

        return $session;
    }
}
