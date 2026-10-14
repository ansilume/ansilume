<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobController;
use app\models\Job;
use app\models\JobArtifact;
use yii\base\Module;

/**
 * The Preview buttons of a job's artifacts (views/job/view.php). An image
 * opens in an <img> and a PDF in a sandboxed <iframe>, from the artifact's
 * inline download; a text artifact (text, JSON, XML, YAML) is fetched from
 * job/artifact-content, which answers JSON, and written into a <pre> as
 * text. tests/e2e/tests/jobs/artifacts.spec.ts follows the previews in a
 * browser, signed out and with error answers too.
 */
class JobViewArtifactPreviewScriptTest extends WebControllerTestCase
{
    use RendersRealViews;

    /** What the preview of a text artifact shows when its fetch failed. */
    private const FAILED = 'Failed to load preview.';

    /**
     * regression: the preview of a text artifact asked for anything (fetch's
     * Accept: * / *), so a signed-out click was sent to the login page, which
     * kept the artifact as the address to return to: signed in again, the
     * user landed on the artifact's JSON instead of the dashboard. And it
     * read every answer as the artifact: an error answer, which is JSON too
     * (403: no access any more; 404: the artifact or its file gone), showed
     * an empty preview. It asks for JSON now, and an answer that is not OK
     * fails the preview, with the failure written as text.
     */
    public function testRegressionThePreviewAskedForAnythingAndShowedAnErrorAnswerAsAnEmptyArtifact(): void
    {
        $script = $this->previewScript($this->renderJobPage()['html']);

        $this->assertStringContainsString(
            "fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })",
            $script
        );
        $this->assertSame(1, substr_count($script, 'fetch('), 'the one fetch of the previews');
        $this->assertMatchesRegularExpression(
            '/if \(!r\.ok\) \{\s*throw new Error\(\'preview failed: \' \+ r\.status\);\s*\}\s*return r\.json\(\);/',
            $script,
            'an answer that is not OK fails the preview before it is read'
        );
        $this->assertStringContainsString("content.textContent = data.content || '';", $script);
        $this->assertStringContainsString("content.textContent = '" . self::FAILED . "';", $script);
        $this->assertStringNotContainsString('data.error', $script, 'no error answer fills the preview');
        $this->assertStringNotContainsString('innerHTML', $script, 'the artifact is written as text');
    }

    /**
     * Only a text artifact is fetched, and from job/artifact-content, which
     * answers JSON: the one address the fetch asks for JSON. An image and a
     * PDF load their inline download, and an artifact of another type has
     * no Preview button.
     */
    public function testOnlyATextArtifactIsFetchedAndFromTheJsonEndpoint(): void
    {
        $page = $this->renderJobPage();
        $job = $page['job'];
        $ids = $page['artifacts'];

        $this->assertSame(
            [
                $ids['report.txt'] => ['text', "/job/artifact-content?id={$job}&artifact_id={$ids['report.txt']}"],
                $ids['screenshot.png'] => ['image', "/job/download-artifact?id={$job}&artifact_id={$ids['screenshot.png']}&inline=1"],
                $ids['report.pdf'] => ['frame', "/job/download-artifact?id={$job}&artifact_id={$ids['report.pdf']}&inline=1"],
            ],
            $this->previewButtons($page['html'])
        );
        $script = $this->previewScript($page['html']);
        // The image and the frame return before the fetch: it reads text artifacts only.
        $fetch = strpos($script, 'fetch(');
        $this->assertNotFalse($fetch);
        foreach (["if (kind === 'image') {", "if (kind === 'frame') {"] as $branch) {
            $at = strpos($script, $branch);
            $this->assertNotFalse($at, $branch);
            $this->assertLessThan($fetch, $at, $branch);
        }
    }

    /**
     * A job without artifacts renders no preview script.
     */
    public function testAJobWithoutArtifactsHasNoPreviewScript(): void
    {
        $html = $this->renderJobPage(false)['html'];

        $this->assertStringNotContainsString('artifact-preview-btn', $html);
        $this->assertStringNotContainsString(self::FAILED, $html);
    }

    /**
     * The page of a finished job (no poll) with the artifacts report.txt,
     * screenshot.png, report.pdf and archive.zip, or none: the HTML, the
     * job's ID and the artifact IDs by name.
     *
     * @return array{html: string, job: int, artifacts: array<string, int>}
     */
    private function renderJobPage(bool $withArtifacts = true): array
    {
        $user = $this->createUser('artifact-preview');
        $user->is_superadmin = true;
        $user->save(false);
        $this->loginAs($user);
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
        $job = $this->createJob((int)$template->id, (int)$user->id, Job::STATUS_SUCCEEDED);
        $artifacts = [];
        $types = ['report.txt' => 'text/plain', 'screenshot.png' => 'image/png', 'report.pdf' => 'application/pdf', 'archive.zip' => 'application/zip'];
        foreach ($withArtifacts ? $types : [] as $name => $mimeType) {
            $artifacts[$name] = $this->createArtifact($job, $name, $mimeType);
        }
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $ctrl = new class ('job', $app) extends JobController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };
        $html = $this->withRealViews($ctrl, '/job/view?id=' . $job->id, static fn (): string => $ctrl->actionView((int)$job->id));

        return ['html' => $html, 'job' => (int)$job->id, 'artifacts' => $artifacts];
    }

    private function createArtifact(Job $job, string $name, string $mimeType): int
    {
        $artifact = new JobArtifact();
        $artifact->job_id = (int)$job->id;
        $artifact->filename = $name;
        $artifact->display_name = $name;
        $artifact->mime_type = $mimeType;
        $artifact->size_bytes = 42;
        $artifact->storage_path = '/tmp/nonexistent-artifact-preview/' . $name;
        $artifact->created_at = time();
        $this->assertTrue($artifact->save(false));

        return (int)$artifact->id;
    }

    /**
     * The Preview buttons of $html: artifact ID => [preview kind, address].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function previewButtons(string $html): array
    {
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING));
        $buttons = (new \DOMXPath($dom))->query('//button[contains(concat(" ", @class, " "), " artifact-preview-btn ")]');
        $this->assertInstanceOf(\DOMNodeList::class, $buttons);
        $found = [];
        foreach ($buttons as $button) {
            $this->assertInstanceOf(\DOMElement::class, $button);
            $found[(int)$button->getAttribute('data-artifact-id')] = [
                $button->getAttribute('data-preview-kind'),
                $button->getAttribute('data-url'),
            ];
        }
        ksort($found);

        return $found;
    }

    /**
     * The script of the Preview buttons: from its <script> tag to the first
     * "</script", which is where the browser ends it.
     */
    private function previewScript(string $html): string
    {
        $tag = strpos($html, "<script>\ndocument.querySelectorAll('.artifact-preview-btn')");
        $this->assertNotFalse($tag, 'the preview script is missing');
        $start = $tag + strlen('<script>');
        $end = stripos($html, '</script', $start);
        $this->assertNotFalse($end, 'the preview script does not end');

        return substr($html, $start, $end - $start);
    }
}
