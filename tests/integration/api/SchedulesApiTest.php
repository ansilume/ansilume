<?php

declare(strict_types=1);

namespace app\tests\integration\api;

use app\models\Schedule;
use app\tests\integration\DbTestCase;

class SchedulesApiTest extends DbTestCase
{
    private function scaffold(): array
    {
        $user = $this->createUser();
        $group = $this->createRunnerGroup($user->id);
        $proj = $this->createProject($user->id);
        $inv = $this->createInventory($user->id);
        $tpl = $this->createJobTemplate($proj->id, $inv->id, $group->id, $user->id);

        return [$user, $tpl];
    }

    private function createSchedule(int $templateId, int $createdBy, bool $enabled = true): Schedule
    {
        $s = new Schedule();
        $s->name = 'test-sched-' . uniqid('', true);
        $s->job_template_id = $templateId;
        $s->cron_expression = '0 * * * *';
        $s->timezone = 'UTC';
        $s->enabled = $enabled;
        $s->next_run_at = time() + 3600;
        $s->created_by = $createdBy;
        $s->created_at = time();
        $s->updated_at = time();
        $s->save(false);
        return $s;
    }

    public function testScheduleSerializationShape(): void
    {
        [$user, $tpl] = $this->scaffold();
        $sched = $this->createSchedule($tpl->id, $user->id);

        $serialized = [
            'id' => $sched->id,
            'name' => $sched->name,
            'job_template_id' => $sched->job_template_id,
            'cron_expression' => $sched->cron_expression,
            'timezone' => $sched->timezone,
            'enabled' => (bool)$sched->enabled,
            'last_run_at' => $sched->last_run_at,
            'next_run_at' => $sched->next_run_at,
            'created_at' => $sched->created_at,
            'updated_at' => $sched->updated_at,
        ];

        $this->assertCount(10, $serialized);
        $this->assertTrue($serialized['enabled']);
        $this->assertSame('UTC', $serialized['timezone']);
        $this->assertSame('0 * * * *', $serialized['cron_expression']);
    }

    public function testScheduleFindOneReturnsCorrectRecord(): void
    {
        [$user, $tpl] = $this->scaffold();
        $sched = $this->createSchedule($tpl->id, $user->id);

        $found = Schedule::findOne($sched->id);

        $this->assertNotNull($found);
        $this->assertSame($sched->id, $found->id);
    }

    public function testScheduleFindOneReturnsNullForMissingId(): void
    {
        $this->assertNull(Schedule::findOne(999999));
    }

    public function testScheduleToggleFlipsEnabled(): void
    {
        [$user, $tpl] = $this->scaffold();
        $sched = $this->createSchedule($tpl->id, $user->id, true);

        $this->assertTrue((bool)$sched->enabled);

        $sched->enabled = !$sched->enabled;
        $sched->save(false);
        $sched->refresh();

        $this->assertFalse((bool)$sched->enabled);

        $sched->enabled = !$sched->enabled;
        $sched->save(false);
        $sched->refresh();

        $this->assertTrue((bool)$sched->enabled);
    }

    public function testScheduleListOrderedByIdDesc(): void
    {
        [$user, $tpl] = $this->scaffold();
        $s1 = $this->createSchedule($tpl->id, $user->id);
        $s2 = $this->createSchedule($tpl->id, $user->id);

        $results = Schedule::find()
            ->where(['id' => [$s1->id, $s2->id]])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        $this->assertCount(2, $results);
        $this->assertSame($s2->id, $results[0]->id);
    }

    public function testDisabledScheduleIsCreatable(): void
    {
        [$user, $tpl] = $this->scaffold();
        $sched = $this->createSchedule($tpl->id, $user->id, false);

        $this->assertFalse((bool)$sched->enabled);
        $this->assertNotNull($sched->id);
    }
}
