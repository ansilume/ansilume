<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\WorkflowStepsController;
use app\controllers\api\v1\WorkflowTemplatesController;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\User;
use app\models\WorkflowStep;
use app\models\WorkflowTemplate;
use app\services\WorkflowStepChangeGuard;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\WorkflowStepWriteFixtures;

/**
 * The team scope of the API's step changes, checked again under the lock.
 * Every API request that changes a workflow's steps checks operator access
 * to the project of every job step before the write path (C13, step 4:
 * 403 "Forbidden."), and the write path checks it again with the workflow
 * locked (WorkflowStepChangeGuard). The race replayed here, for the per-step
 * endpoints and the bulk PUT with a "steps" list: between the caller's check
 * and the lock, another user adds a job step of a project the caller may
 * only view. The change is refused with the same answer as the check before
 * the lock, and nothing of it is written.
 */
final class WorkflowStepTeamScopeRaceApiTest extends WebControllerTestCase
{
    use WorkflowStepApiRequests;
    use WorkflowStepWriteFixtures;

    /** The API's step write paths: case => what the request does. */
    private const PATHS = [
        'POST /steps' => 'create',
        'PUT /steps/{stepId}' => 'update',
        'POST /steps/{stepId}/move' => 'move',
        'DELETE /steps/{stepId}' => 'delete',
        'PUT /workflow-templates/{id} with steps' => 'bulk',
    ];

    /** @var array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate} */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->teamScope();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pathProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::PATHS) as $path) {
            $cases[$path] = [$path];
        }

        return $cases;
    }

    /**
     * regression: the team scope of a step change was checked only before
     * the lock. A job step of a project the caller may only view, added by
     * another user between the caller's check and the lock, did not stop
     * the caller's change. Now the write path refuses it under the lock with
     * the answer of the check before it: 403 "Forbidden.", nothing written,
     * no audit entry.
     *
     * @dataProvider pathProvider
     */
    public function testRegressionAStepOfAViewOnlyProjectAddedMeanwhileStopsTheChange(string $path): void
    {
        $workflow = $this->workflow();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);
        $added = [];

        $answer = $this->meanwhile(
            function () use ($workflow, &$added): void {
                $added[] = $this->namedStep((int)$workflow->id, 'Report', 30, WorkflowStep::TYPE_JOB, (int)$this->s['viewed']->id);
            },
            fn (): array => $this->send($path, $workflow)
        );

        $this->assertApiError(403, WorkflowStepsController::FORBIDDEN, $answer);
        $this->assertSame(WorkflowStepChangeGuard::TEAM_SCOPE_REFUSED, WorkflowStepsController::FORBIDDEN, 'the text of the check before the lock');
        $this->assertCount(1, $added, 'the other user\'s step was added between the check and the lock');
        $after = $this->stepRows((int)$workflow->id);
        unset($after[(int)$added[0]->id]);
        $this->assertSame($before, $after, 'nothing of the caller\'s change was written');
        $this->assertSame([], $this->stepAudits((int)$workflow->id));
        $this->assertFalse(AuditLog::find()->where([
            'action' => AuditLog::ACTION_WORKFLOW_TEMPLATE_UPDATED,
            'object_id' => $workflow->id,
        ])->exists());
    }

    /**
     * Without a step added meanwhile, the same requests change the steps for
     * the member: the refusal above is the check under the lock.
     *
     * @dataProvider pathProvider
     */
    public function testWithoutTheRaceTheMemberChangesTheSteps(string $path): void
    {
        $workflow = $this->workflow();
        $before = $this->stepRows((int)$workflow->id);
        $this->tokenOf($this->s['member']);

        $answer = $this->send($path, $workflow);

        $this->assertSame(self::PATHS[$path] === 'create' ? 201 : 200, $answer['status'], (string)json_encode($answer['body']));
        $this->assertNotSame($before, $this->stepRows((int)$workflow->id));
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Build and Deploy, job steps of projects the member may operate: their
     * team's and one without a team.
     */
    private function workflow(): WorkflowTemplate
    {
        $workflow = $this->createWorkflowTemplate((int)$this->s['admin']->id);
        $this->namedStep((int)$workflow->id, 'Build', 10, WorkflowStep::TYPE_JOB, (int)$this->s['own']->id);
        $this->namedStep((int)$workflow->id, 'Deploy', 20, WorkflowStep::TYPE_JOB, (int)$this->s['open']->id);

        return $workflow;
    }

    /**
     * Send the request of $path for the workflow's step Build, through the
     * URL rules and the controller they route to (access rules included).
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function send(string $path, WorkflowTemplate $workflow): array
    {
        $id = (int)$workflow->id;
        $build = $this->stepIdOf($id, 'Build');

        return match (self::PATHS[$path]) {
            'create' => $this->api('POST', $this->stepsPath($id), ['name' => 'Lint', 'step_type' => 'pause']),
            'update' => $this->api('PUT', $this->stepPath($id, $build), ['name' => 'Compile']),
            'move' => $this->api('POST', $this->movePath($id, $build), ['position' => 'end']),
            'delete' => $this->api('DELETE', $this->stepPath($id, $build)),
            default => $this->replaceSteps($workflow),
        };
    }

    /**
     * PUT /api/v1/workflow-templates/{id} with a "steps" list of one pause
     * step, through the URL rules and WorkflowTemplatesController.
     *
     * @return array{status: int, body: array<mixed>}
     */
    private function replaceSteps(WorkflowTemplate $workflow): array
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['steps' => [['name' => 'Only', 'step_type' => 'pause']]]);
        $request->setPathInfo('api/v1/workflow-templates/' . (int)$workflow->id);
        $request->setQueryParams([]);
        $this->assertSame(
            ['api/v1/workflow-templates/update', ['id' => (string)$workflow->id]],
            $this->webRules()->parseRequest($request)
        );
        \Yii::$app->response->statusCode = 200;
        $result = (new WorkflowTemplatesController('api/v1/workflow-templates', \Yii::$app))->runAction('update', ['id' => (string)$workflow->id]);
        $this->assertIsArray($result);

        return ['status' => \Yii::$app->response->statusCode, 'body' => $result];
    }
}
