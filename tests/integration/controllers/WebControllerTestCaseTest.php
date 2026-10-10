<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

/**
 * The web context every controller test starts from.
 */
class WebControllerTestCaseTest extends WebControllerTestCase
{
    /**
     * Regression: $_SESSION outlives a test in the PHPUnit process, so a
     * flash one test set and never read showed up in the exact flash
     * assertions of a later one (UserControllerActionTest failed after
     * ScheduleControllerActionTest with its "Schedule ... enabled." flash).
     */
    public function testTheNextTestStartsWithoutTheFlashesOfThisOne(): void
    {
        // Set and never read, as by an action whose test ignores its flash.
        \Yii::$app->session->setFlash('success', 'Left behind by an earlier test.');

        // What PHPUnit does between two tests.
        $this->tearDown();
        $this->setUp();

        $this->assertSame([], \Yii::$app->session->getAllFlashes());
    }

    /**
     * Data a test leaves in the session goes with it, not only flashes.
     */
    public function testTheNextTestStartsWithAnEmptySession(): void
    {
        \Yii::$app->session->set('left_behind', 'value');

        $this->tearDown();
        $this->setUp();

        $this->assertNull(\Yii::$app->session->get('left_behind'));
    }
}
