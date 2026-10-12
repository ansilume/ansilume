<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\JobController;
use app\models\Job;
use app\models\JobLog;
use app\models\User;

/**
 * The job page writes the job's log output into an inline script, which
 * feeds it to the ANSI renderer. Log output is whatever the playbook prints.
 */
class JobViewLogScriptTest extends WebControllerTestCase
{
    use RendersRealViews;

    /**
     * Regression: each log chunk went into the script through json_encode(),
     * which leaves "<" and "!" as they are. In script data, "<!--<script"
     * puts the HTML parser into its double-escaped state, where the closing
     * </script> is read as text: the rest of the job page disappeared into
     * the script, and output printed later could end it and run script.
     * The chunks are now hex-escaped (Json::htmlEncode), so nothing in them
     * can end the script or change how the parser reads it.
     */
    public function testLogOutputCannotEndTheScriptOrSwallowThePage(): void
    {
        $chunks = [
            "TASK [debug] *****\nok: [web1] => {\"msg\": \"</script><script>alert('log')</script>\"}\n",
            "<!--<script>\n",
            "after: \"quotes\" & 'apostrophes' </SCRIPT > <!-- -->\n",
            "unicode: Grüße — ✓\n",
        ];
        $job = $this->jobWithLog(Job::STATUS_FAILED, $chunks);

        $script = $this->logScript($this->renderJobPage($job));

        $this->assertFalse(stripos($script, '</script') !== false, 'log output ends the script element');
        $this->assertFalse(stripos($script, '<script') !== false, 'log output opens a script inside the script');
        $this->assertStringNotContainsString('<!--', $script);
        $this->assertSame($chunks, $this->appendedChunks($script), 'the page shows the log as it is');
    }

    /**
     * A running job's page polls for more output; its URL still reaches the
     * script intact.
     */
    public function testARunningJobPollsItsOwnLog(): void
    {
        $job = $this->jobWithLog(Job::STATUS_RUNNING, ["PLAY [all] ****\n"]);

        $html = $this->renderJobPage($job);

        $this->assertSame(1, preg_match('/var pollUrl\s*=\s*(.+);/', $html, $m));
        $this->assertSame('/job/log-poll?id=' . $job->id, json_decode($m[1], true));
        $this->assertSame(["PLAY [all] ****\n"], $this->appendedChunks($this->logScript($html)));
    }

    /**
     * @param list<string> $chunks
     */
    private function jobWithLog(string $status, array $chunks): Job
    {
        $user = $this->createUser('job-log-script');
        $user->is_superadmin = true;
        $user->save(false);
        $this->loginAs($user);
        $template = $this->createJobTemplate(
            (int)$this->createProject((int)$user->id)->id,
            (int)$this->createInventory((int)$user->id)->id,
            (int)$this->createRunnerGroup((int)$user->id)->id,
            (int)$user->id
        );
        $job = $this->createJob((int)$template->id, (int)$user->id, $status);
        foreach ($chunks as $index => $content) {
            $log = new JobLog();
            $log->job_id = (int)$job->id;
            $log->stream = JobLog::STREAM_STDOUT;
            $log->content = $content;
            $log->sequence = $index + 1;
            $log->created_at = time();
            $this->assertTrue($log->save(false));
        }

        return $job;
    }

    private function renderJobPage(Job $job): string
    {
        $ctrl = new class ('job', \Yii::$app) extends JobController {
            public function render($view, $params = []): string
            {
                return $this->renderPartial($view, $params);
            }
        };

        return $this->withRealViews($ctrl, '/job/view?id=' . $job->id, static fn (): string => $ctrl->actionView((int)$job->id));
    }

    /**
     * The content of the script that renders the log: from its <script> tag
     * to the first "</script", which is where the browser ends it.
     */
    private function logScript(string $html): string
    {
        $tag = strpos($html, "<script>\nvar _au");
        $this->assertNotFalse($tag, 'the log script is missing');
        $start = $tag + strlen('<script>');
        $end = stripos($html, '</script', $start);
        $this->assertNotFalse($end, 'the log script does not end');

        return substr($html, $start, $end - $start);
    }

    /**
     * The decoded arguments of the appendAnsi(...) calls in $script.
     *
     * @return list<mixed>
     */
    private function appendedChunks(string $script): array
    {
        preg_match_all('/^appendAnsi\((.*)\);$/m', $script, $m);

        return array_map(static fn (string $json): mixed => json_decode($json, true), $m[1]);
    }
}
