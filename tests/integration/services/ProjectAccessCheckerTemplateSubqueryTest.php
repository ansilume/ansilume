<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\Job;
use app\models\JobTemplate;
use app\models\Schedule;
use app\services\ProjectAccessChecker;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\db\Query;

/**
 * ProjectAccessChecker::templateIdSubquery() and the column parameter of
 * buildJobFilter(), which the workflow, approval and analytics scoping build on.
 */
class ProjectAccessCheckerTemplateSubqueryTest extends DbTestCase
{
    use TeamScopeFixtures;

    private function checker(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');
        return $checker;
    }

    /**
     * @param list<int> $among
     * @return list<int>
     */
    private function selected(int $userId, bool $operate, array $among): array
    {
        $query = $this->checker()->templateIdSubquery($userId, $operate);
        $this->assertNotNull($query);
        $ids = array_map('intval', $query->andWhere(['jt.id' => $among])->column());
        sort($ids);
        return $ids;
    }

    public function testSelectsVisibleOrOperableTemplatesIncludingSoftDeletedOnes(): void
    {
        $s = $this->teamScope();
        $s['own']->softDelete();
        $all = [$s['own']->id, $s['viewed']->id, $s['foreign']->id, $s['open']->id];

        $visible = [$s['own']->id, $s['viewed']->id, $s['open']->id];
        sort($visible);
        $operable = [$s['own']->id, $s['open']->id];
        sort($operable);

        $this->assertSame($visible, $this->selected($s['member']->id, false, $all));
        $this->assertSame($operable, $this->selected($s['member']->id, true, $all));
    }

    public function testIsNullForAdminsAndWithoutRestrictedProjects(): void
    {
        $s = $this->teamScope();
        $this->assertNull($this->checker()->templateIdSubquery($s['admin']->id));
        $this->assertNull($this->checker()->templateIdSubquery($s['admin']->id, true));
    }

    public function testIsNullWhenNoProjectIsRestricted(): void
    {
        $user = $this->createUserWithRole('no_restrictions', 'operator');

        $this->assertNull($this->checker()->templateIdSubquery($user->id, true));
        $this->assertNull($this->checker()->buildJobFilter($user->id, 'j.job_template_id'));
    }

    /**
     * The filter applies to the column it is given. Schedules joined to the
     * jobs of their template: both tables have a job_template_id, so a filter
     * on an unqualified job_template_id would not even run.
     */
    public function testBuildJobFilterTakesTheColumnToFilter(): void
    {
        $s = $this->teamScope();
        $adminId = (int)$s['admin']->id;
        $ownSchedule = $this->schedule($s['own'], $adminId);
        $foreignSchedule = $this->schedule($s['foreign'], $adminId);
        $this->createJob((int)$s['own']->id, $adminId);
        $this->createJob((int)$s['foreign']->id, $adminId);

        $filter = $this->checker()->buildJobFilter((int)$s['member']->id, 's.job_template_id');
        $this->assertNotNull($filter);
        $ids = array_map('intval', (new Query())
            ->select('s.id')
            ->distinct()
            ->from(['s' => Schedule::tableName()])
            ->innerJoin(['j' => Job::tableName()], 'j.job_template_id = s.job_template_id')
            ->where(['s.id' => [$ownSchedule, $foreignSchedule]])
            ->andWhere($filter)
            ->column());

        $this->assertSame([$ownSchedule], $ids);
        $this->assertSame(ProjectAccessChecker::DENY_ALL, $this->checker()->buildJobFilter(null, 's.job_template_id'));
    }

    private function schedule(JobTemplate $template, int $createdBy): int
    {
        $schedule = new Schedule();
        $schedule->name = 'filter-column-' . uniqid('', true);
        $schedule->job_template_id = (int)$template->id;
        $schedule->cron_expression = '0 3 * * *';
        $schedule->timezone = 'UTC';
        $schedule->enabled = true;
        $schedule->created_by = $createdBy;
        $schedule->created_at = time();
        $schedule->updated_at = time();
        $schedule->save(false);

        return (int)$schedule->id;
    }
}
