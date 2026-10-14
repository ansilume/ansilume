<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\ApprovalController;
use app\models\ApprovalDecision;
use app\models\ApprovalRequest;
use app\models\User;
use app\services\runstate\ApprovalVotes;
use yii\base\Module;

/**
 * The request page (views/approval/view.php) lists the votes on a request
 * in the order they were cast: the votes and the order of the API's
 * "decisions" (runstate\ApprovalVotes).
 */
class ApprovalPageVoteOrderTest extends WebControllerTestCase
{
    /**
     * regression: the request page listed the votes by user ID, while the
     * API answered them in the order cast. ApprovalRequest::getDecisions()
     * had no order, and MariaDB read the votes through the unique index
     * (approval_request_id, user_id) once the table held the votes of other
     * requests too. The voter with the higher user ID votes first here.
     */
    public function testRegressionTheRequestPageListedTheVotesByUserId(): void
    {
        $admin = $this->createUser('apvo-admin');
        $admin->is_superadmin = true;
        $admin->save(false);
        $second = $this->createUser('apvo-second');
        $first = $this->createUser('apvo-first');
        $this->assertGreaterThan((int)$second->id, (int)$first->id, 'the user who votes first has the higher ID');
        foreach ([1, 2] as $other) {
            $this->voteOn($this->requestBy($admin), [$admin, $second, $first]);
        }
        $request = $this->requestBy($admin);
        $this->voteOn($request, [$first, $second]);
        $this->loginAs($admin);

        $listed = $this->listedVoters($this->requestPage($request));

        $this->assertSame([(string)$first->username, (string)$second->username], $listed, 'the votes in the order cast');
        $answered = ApprovalVotes::of([(int)$request->id])[(int)$request->id] ?? [];
        $this->assertSame(array_column($answered, 'username'), $listed, 'the votes of the API, in its order');
    }

    /**
     * A pending request of a job that $launcher launched.
     */
    private function requestBy(User $launcher): ApprovalRequest
    {
        $userId = (int)$launcher->id;
        $template = $this->createJobTemplate(
            (int)$this->createProject($userId)->id,
            (int)$this->createInventory($userId)->id,
            (int)$this->createRunnerGroup($userId)->id,
            $userId
        );
        $request = new ApprovalRequest();
        $request->job_id = (int)$this->createJob((int)$template->id, $userId)->id;
        $request->approval_rule_id = (int)$this->createApprovalRule($userId)->id;
        $request->status = ApprovalRequest::STATUS_PENDING;
        $request->requested_at = time();
        $this->assertTrue($request->save(false));

        return $request;
    }

    /**
     * A vote by each of $voters on $request, in that order.
     *
     * @param list<User> $voters
     */
    private function voteOn(ApprovalRequest $request, array $voters): void
    {
        foreach ($voters as $voter) {
            $vote = new ApprovalDecision();
            $vote->approval_request_id = (int)$request->id;
            $vote->user_id = (int)$voter->id;
            $vote->decision = ApprovalDecision::DECISION_APPROVED;
            $vote->created_at = time();
            $this->assertTrue($vote->save(false));
        }
    }

    /**
     * The page of $request as the signed-in user sees it.
     */
    private function requestPage(ApprovalRequest $request): string
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $ctrl = new class ('approval', $app) extends ApprovalController {
            use CapturesParams;
        };
        $ctrl->actionView((int)$request->id);
        $previous = \Yii::$app->controller;
        \Yii::$app->controller = $ctrl;
        try {
            return \Yii::$app->view->renderFile('@app/views/approval/view.php', $ctrl->capturedParams);
        } finally {
            \Yii::$app->controller = $previous;
        }
    }

    /**
     * The users of the rows of the page's Decisions table, top to bottom.
     *
     * @return list<string>
     */
    private function listedVoters(string $html): array
    {
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING));
        $cells = (new \DOMXPath($doc))->query('//h5[normalize-space()="Decisions"]/following-sibling::table[1]/tbody/tr/td[1]');
        $this->assertNotFalse($cells);
        $voters = [];
        foreach ($cells as $cell) {
            $voters[] = trim($cell->textContent);
        }

        return $voters;
    }
}
