<?php

declare(strict_types=1);

namespace app\tests\integration\docs;

use app\models\Team;
use app\models\TeamProject;
use app\models\User;
use app\services\ProjectAccessChecker;
use app\tests\integration\DbTestCase;
use app\tests\unit\docs\ReadsOperatorDocs;
use Symfony\Component\Yaml\Yaml;

/**
 * What the docs say about team management is what ProjectAccessChecker
 * does: a project that no team has is open, so removing a project from its
 * last team, or deleting that team, opens it to every signed-in user, as far
 * as their roles allow; whoever manages teams holds the admin role and
 * reaches every project. docs/updating.md (the known limits of the section
 * for the update from 2.10.1) and the team endpoints of web/openapi.yaml
 * say so (DelegatedAdministrationDocsContractTest pins that the team pages
 * and endpoints take the admin role).
 */
class TeamManagementDocsContractTest extends DbTestCase
{
    use ReadsOperatorDocs;

    /** What the spec says of a project after a team lost it. */
    private const SPEC_OPEN = 'A project that no team has any more is open: every signed-in user reaches it, as far as their permissions allow.';

    public function testRemovingAProjectFromItsLastTeamOpensIt(): void
    {
        [$project, $team, $outsider, $admin] = $this->restrictedProject();
        $checker = new ProjectAccessChecker();
        $this->assertFalse($checker->canView((int)$outsider->id, $project), 'A user outside the team does not reach a team-restricted project.');
        $this->assertTrue($checker->canOperate((int)$admin->id, $project), 'Whoever manages teams reaches every project themselves.');

        TeamProject::deleteAll(['team_id' => $team->id, 'project_id' => $project]);

        $this->assertTrue($checker->canView((int)$outsider->id, $project), 'Without a team the project is open.');
        $this->assertTrue($checker->canOperate((int)$outsider->id, $project), 'Its roles decide what the user may do there.');
    }

    public function testDeletingTheLastTeamOfAProjectOpensIt(): void
    {
        [$project, $team, $outsider] = $this->restrictedProject();
        $checker = new ProjectAccessChecker();
        $this->assertFalse($checker->canView((int)$outsider->id, $project));

        $team->delete();

        $this->assertSame(0, (int)TeamProject::find()->where(['project_id' => $project])->count(), 'Deleting a team removes its projects.');
        $this->assertTrue($checker->canView((int)$outsider->id, $project), 'Without a team the project is open.');
    }

    public function testTheDocsSayWhatTeamManagementDecides(): void
    {
        $this->assertMentions(
            'A project that no team has is open: every signed-in user reaches it, as far as their roles allow, so removing a project from its last team, or deleting that team, opens the project to everyone.',
            $this->flat('docs/updating.md', '## After updating from 2.10.1 or older: workflow steps and waiting runs'),
            'The known limits of updating.md'
        );
        $spec = Yaml::parseFile($this->root() . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $this->assertStringContainsString(
            'Projects without a team stay open to everyone with the permission, so removing a project from its last team, or deleting that team, opens it. Admins, superadmins and installations without team-assigned projects are not restricted; only admins and superadmins manage teams (`/api/v1/teams`).',
            trim((string)preg_replace('/\s+/', ' ', (string)($spec['info']['description'] ?? '')))
        );
        foreach ([['/api/v1/teams/{id}', 'delete'], ['/api/v1/teams/{id}/projects/{projectId}', 'delete']] as [$path, $method]) {
            $description = trim((string)preg_replace('/\s+/', ' ', (string)($spec['paths'][$path][$method]['description'] ?? '')));
            $this->assertStringContainsString(self::SPEC_OPEN, $description, "{$method} {$path}");
        }
    }

    /**
     * A project that only a team has, as viewer; a viewer outside the team
     * and an admin outside it.
     *
     * @return array{0: int, 1: Team, 2: User, 3: User}
     */
    private function restrictedProject(): array
    {
        $admin = $this->userWith('admin', 'docs-teams-admin');
        $project = (int)$this->createProject((int)$admin->id)->id;
        $team = $this->createTeam((int)$admin->id);
        $member = $this->userWith('viewer', 'docs-teams-member');
        $this->addTeamMember((int)$team->id, (int)$member->id);
        $this->createTeamProject((int)$team->id, $project, TeamProject::ROLE_VIEWER);

        return [$project, $team, $this->userWith('viewer', 'docs-teams-outsider'), $admin];
    }

    private function userWith(string $role, string $suffix): User
    {
        $user = $this->createUser($suffix);
        $auth = \Yii::$app->authManager;
        $this->assertNotNull($auth);
        $item = $auth->getRole($role);
        $this->assertNotNull($item, $role);
        $auth->assign($item, (string)$user->id);

        return $user;
    }
}
