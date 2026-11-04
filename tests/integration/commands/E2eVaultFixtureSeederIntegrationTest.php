<?php

declare(strict_types=1);

namespace app\tests\integration\commands;

use app\commands\E2eVaultFixtureSeeder;
use app\helpers\FileHelper;
use app\models\JobTemplate;
use app\models\Project;
use app\tests\integration\DbTestCase;

/**
 * The fixture of projects/lint-vault.spec.ts and inventories/vault.spec.ts:
 * a manual project with vault-encrypted files, in the runtime directory by
 * default, which the app (Parse Inventory) and the queue-worker (lint) both
 * see. Seeded as root, its files get the owner of the runtime directory, so
 * the user of a dev checkout can remove them; the E2E teardown removes the
 * project. The rollback removes the records.
 */
class E2eVaultFixtureSeederIntegrationTest extends DbTestCase
{
    private string $runtime;

    private string $previousRuntime;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $onPath = array_filter(
            explode(PATH_SEPARATOR, (string)getenv('PATH')),
            static fn (string $dir): bool => is_executable($dir . '/ansible-vault')
        );
        if ($onPath === []) {
            $this->markTestSkipped('The seeder needs ansible-vault.');
        }
        $wwwData = function_exists('posix_getpwnam') ? posix_getpwnam('www-data') : false;
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || $wwwData === false) {
            $this->markTestSkipped('The seeder adopts the files as root, for a runtime directory of www-data.');
        }
        $this->runtime = sys_get_temp_dir() . '/ansilume_vault_fixture_runtime_' . uniqid('', true);
        mkdir($this->runtime);
        // A runtime directory of another user than the seeder's, as in a git checkout.
        chown($this->runtime, (int)$wwwData['uid']);
        chgrp($this->runtime, (int)$wwwData['gid']);
        $this->previousRuntime = (string)\Yii::getAlias('@runtime');
        \Yii::setAlias('@runtime', $this->runtime);
    }

    protected function tearDown(): void
    {
        if (isset($this->runtime)) {
            \Yii::setAlias('@runtime', $this->previousRuntime);
            FileHelper::removeDirectory($this->runtime);
        }
        parent::tearDown();
    }

    public function testTheProjectIsInTheRuntimeDirectoryOwnedLikeItAndTheTeardownRemovesIt(): void
    {
        $user = $this->createUser('vault-fixture');
        $seeder = new E2eVaultFixtureSeeder(function (string $line): void {
            $this->log[] = $line;
        });
        $dir = $this->runtime . '/e2e-vault-project';

        $seeder->seed((int)$user->id, (int)$this->createRunnerGroup((int)$user->id)->id);

        $this->assertSame('@runtime/e2e-vault-project', E2eVaultFixtureSeeder::DIRECTORY);
        $project = Project::findOne(['name' => E2eVaultFixtureSeeder::PROJECT]);
        $this->assertNotNull($project, implode('', $this->log));
        $this->assertSame($dir, $project->local_path);
        $this->assertNotNull(JobTemplate::findOne(['name' => E2eVaultFixtureSeeder::TEMPLATE]));
        $owner = fileowner($this->runtime);
        $this->assertNotSame(0, $owner);
        foreach ([$dir, $dir . '/site.yml', $dir . '/inventory/group_vars', $dir . '/inventory/group_vars/all.yml', $dir . '/vault.yml'] as $path) {
            $this->assertFileExists($path);
            $this->assertSame($owner, fileowner($path), "{$path} has the owner of the runtime directory");
            $this->assertSame(filegroup($this->runtime), filegroup($path));
        }
        $this->assertStringStartsWith('$ANSIBLE_VAULT;', (string)file_get_contents($dir . '/vault.yml'));

        $seeder->teardown();

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertSame("  Deleted vault fixture project {$dir}.\n", end($this->log));
    }

    /**
     * Not run as root, the seeder cannot give the files away: they keep
     * the seeder's own user.
     */
    public function testWithoutAnOwnerToGiveThemToTheFilesStayTheSeeders(): void
    {
        $user = $this->createUser('vault-fixture-own');
        $seeder = new class (function (string $line): void {
            $this->log[] = $line;
        }) extends E2eVaultFixtureSeeder {
            protected function runtimeOwner(): ?array
            {
                return null;
            }
        };

        $seeder->seed((int)$user->id, (int)$this->createRunnerGroup((int)$user->id)->id);

        $dir = $this->runtime . '/e2e-vault-project';
        $this->assertSame(0, fileowner($dir), 'the seeder\'s user, root');
        $this->assertSame(0, fileowner($dir . '/site.yml'));
        $this->assertSame("  Seeded vault fixture project e2e-vault-project (ID " . Project::findOne(['name' => E2eVaultFixtureSeeder::PROJECT])?->id . ").\n", end($this->log));
    }
}
