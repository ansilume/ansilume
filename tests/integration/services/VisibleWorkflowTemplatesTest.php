<?php

declare(strict_types=1);

namespace app\tests\integration\services;

use app\models\User;
use app\services\VisibleWorkflowTemplates;
use app\tests\integration\DbTestCase;
use app\tests\integration\TeamScopeFixtures;

/**
 * Which workflow templates a user may see, by ID (VisibleWorkflowTemplates):
 * the live ones whose job steps' projects the user may view
 * (WorkflowAccessChecker), while the user is active and holds
 * workflow.view.
 */
class VisibleWorkflowTemplatesTest extends DbTestCase
{
    use TeamScopeFixtures;

    public function testATeamMemberSeesTheWorkflowsOfTheirTeamsAndOpenProjects(): void
    {
        $scope = $this->teamScope();
        $adminId = (int)$scope['admin']->id;
        $own = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id);
        $viewed = $this->createWorkflowWithJobSteps($adminId, (int)$scope['viewed']->id, (int)$scope['open']->id);
        $foreign = $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id, (int)$scope['foreign']->id);
        $noJobs = $this->createWorkflowTemplate($adminId);
        $all = [(int)$foreign->id, (int)$own->id, (int)$viewed->id, (int)$noJobs->id, 987654321];

        $expected = [(int)$own->id, (int)$viewed->id, (int)$noJobs->id];
        sort($expected);
        $this->assertSame($expected, VisibleWorkflowTemplates::ids((int)$scope['member']->id, $all));

        $everything = [(int)$own->id, (int)$viewed->id, (int)$foreign->id, (int)$noJobs->id];
        sort($everything);
        $this->assertSame($everything, VisibleWorkflowTemplates::ids($adminId, $all), 'admins are not restricted');

        $foreignOnly = $this->createWorkflowWithJobSteps($adminId, (int)$scope['foreign']->id);
        $ids = [(int)$foreignOnly->id, (int)$foreign->id, (int)$own->id, (int)$noJobs->id];
        $this->assertSame([(int)$noJobs->id, (int)$foreignOnly->id], VisibleWorkflowTemplates::ids((int)$scope['outsider']->id, $ids), 'a step of a project the outsider may not see hides the workflow');
        $this->assertNotContains((int)$foreignOnly->id, VisibleWorkflowTemplates::ids((int)$scope['member']->id, $ids));
    }

    public function testEachIdOnceAscending(): void
    {
        $user = $this->createUserWithRole('vwt-order', 'viewer');
        $first = $this->createWorkflowTemplate((int)$user->id);
        $second = $this->createWorkflowTemplate((int)$user->id);

        $this->assertSame(
            [(int)$first->id, (int)$second->id],
            VisibleWorkflowTemplates::ids((int)$user->id, [(int)$second->id, (int)$first->id, (int)$second->id])
        );
        $this->assertSame([], VisibleWorkflowTemplates::ids((int)$user->id, []));
    }

    public function testADeletedWorkflowTemplateIsSeenByNobody(): void
    {
        $admin = $this->createUserWithRole('vwt-deleted', 'admin');
        $deleted = $this->createWorkflowTemplate((int)$admin->id);
        $deleted->softDelete();

        $this->assertSame([], VisibleWorkflowTemplates::ids((int)$admin->id, [(int)$deleted->id]));
    }

    /**
     * Without workflow.view, or with an inactive account, a user sees no
     * workflow template; a superadmin holds every permission.
     */
    public function testNobodyElseSeesAny(): void
    {
        $owner = $this->createUserWithRole('vwt-owner', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$owner->id);
        $ids = [(int)$workflow->id];
        $noRole = $this->createUser('vwt-norole');
        $inactive = $this->createUserWithRole('vwt-inactive', 'admin');
        $inactive->status = User::STATUS_INACTIVE;
        $inactive->save(false);
        $superadmin = $this->createUser('vwt-super');
        $superadmin->is_superadmin = true;
        $superadmin->save(false);

        $this->assertSame([], VisibleWorkflowTemplates::ids((int)$noRole->id, $ids), 'no workflow.view');
        $this->assertSame([], VisibleWorkflowTemplates::ids((int)$inactive->id, $ids), 'inactive');
        $this->assertSame([], VisibleWorkflowTemplates::ids(0, $ids), 'nobody');
        $this->assertSame($ids, VisibleWorkflowTemplates::ids((int)$superadmin->id, $ids));
        $this->assertSame('workflow.view', VisibleWorkflowTemplates::PERMISSION);

        $this->assertSame([], VisibleWorkflowTemplates::options((int)$noRole->id), 'no workflow.view');
        $this->assertSame([], VisibleWorkflowTemplates::options((int)$inactive->id), 'inactive');
        $this->assertSame([], VisibleWorkflowTemplates::options(0), 'nobody');
        $this->assertSame((string)$workflow->name, VisibleWorkflowTemplates::options((int)$superadmin->id)[(int)$workflow->id] ?? null);
    }

    /**
     * What the workflow filter of a notification template's form offers its
     * editor: every live workflow template they may see, by name, and none
     * other; the IDs of the others are not in it.
     */
    public function testTheOptionsAreTheWorkflowTemplatesTheUserMaySeeByName(): void
    {
        $scope = $this->teamScope();
        $adminId = (int)$scope['admin']->id;
        $own = $this->namedWorkflow($adminId, 'vwt-options-b-own', (int)$scope['own']->id);
        $open = $this->namedWorkflow($adminId, 'vwt-options-a-open', (int)$scope['open']->id);
        $foreign = $this->namedWorkflow($adminId, 'vwt-options-c-foreign', (int)$scope['foreign']->id);
        $deleted = $this->namedWorkflow($adminId, 'vwt-options-d-deleted', (int)$scope['own']->id);
        $deleted->softDelete();
        $mine = array_flip([(int)$own->id, (int)$open->id, (int)$foreign->id, (int)$deleted->id]);

        $member = array_intersect_key(VisibleWorkflowTemplates::options((int)$scope['member']->id), $mine);
        $admin = array_intersect_key(VisibleWorkflowTemplates::options($adminId), $mine);

        $this->assertSame([(int)$open->id => 'vwt-options-a-open', (int)$own->id => 'vwt-options-b-own'], $member);
        $this->assertSame(
            [(int)$open->id => 'vwt-options-a-open', (int)$own->id => 'vwt-options-b-own', (int)$foreign->id => 'vwt-options-c-foreign'],
            $admin,
            'admins are not restricted; a deleted one is offered to nobody'
        );
        $this->assertSame(array_keys($admin), array_keys(array_intersect_key(VisibleWorkflowTemplates::options($adminId), $admin)), 'by name');
    }

    private function namedWorkflow(int $userId, string $name, int $jobTemplateId): \app\models\WorkflowTemplate
    {
        $workflow = $this->createWorkflowWithJobSteps($userId, $jobTemplateId);
        $workflow->name = $name;
        $this->assertTrue($workflow->save(false));

        return $workflow;
    }
}
