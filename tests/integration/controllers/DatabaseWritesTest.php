<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\ApiToken;
use app\models\AuditLog;
use app\models\Job;
use app\models\Project;
use app\models\Runner;
use app\models\User;
use app\tests\integration\DbTestCase;

/**
 * DatabaseWrites, which the SubmittedNumbers tests take for "nothing was
 * written": it names every table a write changes, inserts, updates and
 * deletes alike, also of rows the test created in its transaction, and
 * leaves out only the columns every request writes.
 */
class DatabaseWritesTest extends DbTestCase
{
    private int $userId;

    private DatabaseWrites $writes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int)$this->createUser('database-writes')->id;
        $this->writes = new DatabaseWrites(\Yii::$app->db, $this->userId);
    }

    public function testNoWriteNamesNoTable(): void
    {
        $before = $this->writes->snapshot();
        Project::find()->all();

        $this->assertSame([], DatabaseWrites::written($before, $this->writes->snapshot()));
    }

    public function testAnInsertAnUpdateAndADeleteNameTheirTables(): void
    {
        $before = $this->writes->snapshot();
        $project = $this->createProject($this->userId);
        $this->assertSame(['project'], DatabaseWrites::written($before, $this->writes->snapshot()), 'an insert');

        $before = $this->writes->snapshot();
        Project::updateAll(['description' => 'changed'], ['id' => $project->id]);
        $group = $this->createRunnerGroup($this->userId);
        $this->assertSame(['project', 'runner_group'], DatabaseWrites::written($before, $this->writes->snapshot()), 'an update and an insert');

        $before = $this->writes->snapshot();
        $group->delete();
        $this->assertSame(['runner_group'], DatabaseWrites::written($before, $this->writes->snapshot()), 'a delete');
    }

    public function testAnAuditEntryIsAWrite(): void
    {
        $before = $this->writes->snapshot();
        \Yii::$app->get('auditService')->log(AuditLog::ACTION_PROJECT_UPDATED, 'project', 1);

        $this->assertSame(['audit_log'], DatabaseWrites::written($before, $this->writes->snapshot()));
    }

    /**
     * Every API request stamps its token, every runner request its runner
     * and the job it reports on: no write of the request's.
     */
    public function testTheColumnsEveryRequestWritesAreLeftOut(): void
    {
        $token = ApiToken::generate($this->userId, 'database-writes')['token'];
        $runner = $this->createRunner((int)$this->createRunnerGroup($this->userId)->id, $this->userId);
        $job = $this->createJob((int)$this->createJobTemplate(
            (int)$this->createProject($this->userId)->id,
            (int)$this->createInventory($this->userId)->id,
            (int)$runner->runner_group_id,
            $this->userId
        )->id, $this->userId);
        $before = $this->writes->snapshot();

        ApiToken::updateAll(['last_used_at' => time() + 60], ['id' => $token->id]);
        Runner::updateAll(['last_seen_at' => time() + 60, 'updated_at' => time() + 60], ['id' => $runner->id]);
        Job::updateAll(['last_progress_at' => time() + 60], ['id' => $job->id]);

        $this->assertSame([], DatabaseWrites::written($before, $this->writes->snapshot()));
        Runner::updateAll(['name' => 'database-writes-renamed'], ['id' => $runner->id]);
        $this->assertSame(['runner'], DatabaseWrites::written($before, $this->writes->snapshot()), 'the other columns are watched');
    }

    /**
     * The users of the test (from the first user on) are summed column by
     * column; of the others, a save shows in the last change.
     */
    public function testAUserIsAWrite(): void
    {
        $before = $this->writes->snapshot();
        User::updateAll(['email' => 'database-writes-changed@example.com'], ['id' => $this->userId]);
        $this->assertSame(['user'], DatabaseWrites::written($before, $this->writes->snapshot()), 'a user of the test');

        $earlier = new DatabaseWrites(\Yii::$app->db, $this->userId + 1);
        $before = $earlier->snapshot();
        User::updateAll(['updated_at' => time() + 60], ['id' => $this->userId]);
        $this->assertSame(['user'], DatabaseWrites::written($before, $earlier->snapshot()), 'a user before the first one, saved');
    }
}
