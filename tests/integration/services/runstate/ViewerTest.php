<?php

declare(strict_types=1);

namespace app\tests\integration\services\runstate;

use app\models\User;
use app\services\runstate\Viewer;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\CountsQueries;
use app\tests\integration\TeamScopeFixtures;

/**
 * What a viewer may do: a superadmin everything, anyone else what RBAC
 * grants, nobody (a guest, a missing or disabled account) nothing.
 */
class ViewerTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use CountsQueries;

    public function testASuperadminMayEverything(): void
    {
        $user = $this->createUser('vw-super');
        $user->is_superadmin = true;
        $user->save(false);

        $viewer = Viewer::of((int)$user->id);

        $this->assertSame((int)$user->id, $viewer->userId);
        $this->assertTrue($viewer->may('approval.view'));
        $this->assertTrue($viewer->may('workflow.cancel'));
        $this->assertTrue($viewer->may('no.such.permission'));
    }

    public function testAUserMayWhatTheirRolesGrant(): void
    {
        $viewer = Viewer::of((int)$this->createUserWithRole('vw-viewer', 'viewer')->id);
        $operator = Viewer::of((int)$this->createUserWithRole('vw-operator', 'operator')->id);

        $this->assertTrue($viewer->may('approval.view'));
        $this->assertTrue($viewer->may('workflow.view'));
        $this->assertFalse($viewer->may('approval.decide'));
        $this->assertFalse($viewer->may('workflow.launch'));
        $this->assertTrue($operator->may('approval.decide'));
        $this->assertTrue($operator->may('workflow.launch'));
        $this->assertFalse(Viewer::of((int)$this->createUser('vw-roleless')->id)->may('approval.view'));
    }

    public function testADisabledOrMissingAccountMayNothing(): void
    {
        $disabled = $this->createUserWithRole('vw-disabled', 'admin');
        $disabled->status = User::STATUS_INACTIVE;
        $disabled->is_superadmin = true;
        $disabled->save(false);

        $this->assertFalse(Viewer::of((int)$disabled->id)->may('approval.view'));
        $this->assertFalse(Viewer::of((int)$disabled->id + 100000)->may('approval.view'));
        $this->assertFalse(Viewer::of(0)->may('approval.view'));
        $this->assertSame(0, Viewer::of(0)->userId);
    }

    public function testTheAnswersAreKept(): void
    {
        $viewer = Viewer::of((int)$this->createUserWithRole('vw-operator', 'operator')->id);
        $this->assertTrue($viewer->may('approval.decide'));

        $this->assertSame(0, $this->queriesOf(static fn () => $viewer->may('approval.decide')));
        $this->assertSame(0, $this->queriesOf(static fn () => Viewer::of(0)->may('approval.decide')), 'nobody is not looked up');
        $this->assertGreaterThan(0, $this->queriesOf(static fn () => $viewer->may('workflow.launch')), 'each permission is asked once');
    }

    public function testCurrentIsTheSignedInUser(): void
    {
        $operator = $this->createUserWithRole('vw-operator', 'operator');
        $this->loginAs($operator);

        $viewer = Viewer::current();

        $this->assertSame((int)$operator->id, $viewer->userId);
        $this->assertTrue($viewer->may('approval.decide'));
        $this->assertFalse($viewer->may('approval-rule.update'));
    }

    public function testCurrentIsNobodyForAGuestAndOutsideAWebRequest(): void
    {
        $guest = Viewer::current();
        $this->assertSame(0, $guest->userId);
        $this->assertFalse($guest->may('workflow.view'));

        \Yii::$app->set('user', null);
        $console = Viewer::current();
        $this->assertSame(0, $console->userId);
        $this->assertFalse($console->may('workflow.view'));
    }
}
