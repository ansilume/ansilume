<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\services\CredentialService;

/**
 * Restores E2E fixtures by name on every seed, so specs may rely on their
 * exact state. Everything is written with save(false) and raw pivot rows:
 * some fixtures are deliberately invalid by today's rules, like templates
 * saved before a rule existed.
 */
final class E2eFixtureHelper
{
    /**
     * @param array<string, string>|null $secrets null stores no secret
     */
    public static function credential(string $name, string $type, int $userId, ?array $secrets, ?string $username = null): Credential
    {
        $credential = Credential::findOne(['name' => $name]) ?? new Credential();
        $credential->name = $name;
        $credential->credential_type = $type;
        $credential->username = $username;
        $credential->env_var_name = null;
        $credential->secret_data = null;
        $credential->created_by = $userId;
        $credential->save(false);
        if ($secrets !== null) {
            /** @var CredentialService $service */
            $service = \Yii::$app->get('credentialService');
            $service->storeSecrets($credential, $secrets);
        }

        return $credential;
    }

    /**
     * @param array{0: int, 1: int, 2: int} $parents project, inventory and runner group ids
     * @param list<Credential> $additional
     */
    public static function template(string $name, array $parents, int $userId, ?Credential $primary, array $additional): JobTemplate
    {
        $template = JobTemplate::findOne(['name' => $name]) ?? new JobTemplate();
        $template->name = $name;
        $template->project_id = $parents[0];
        $template->inventory_id = $parents[1];
        $template->runner_group_id = $parents[2];
        $template->credential_id = $primary?->id;
        $template->playbook = 'site.yml';
        $template->verbosity = 0;
        $template->forks = 5;
        $template->become = false;
        $template->timeout_minutes = 30;
        $template->created_by = $userId;
        $template->save(false);

        $db = \Yii::$app->db;
        $db->createCommand()->delete('{{%job_template_credential}}', ['job_template_id' => $template->id])->execute();
        foreach ($additional as $position => $credential) {
            $db->createCommand()->insert('{{%job_template_credential}}', [
                'job_template_id' => $template->id,
                'credential_id' => $credential->id,
                'sort_order' => $position,
            ])->execute();
        }

        return $template;
    }

    /**
     * A manual project without team: every role can see it.
     */
    public static function project(string $name, int $userId): Project
    {
        $project = Project::findOne(['name' => $name]) ?? new Project();
        $project->name = $name;
        $project->scm_type = Project::SCM_TYPE_MANUAL;
        $project->scm_branch = 'main';
        $project->status = Project::STATUS_NEW;
        $project->created_by = $userId;
        $project->save(false);

        return $project;
    }

    /**
     * @param string $source the YAML content of a static inventory, else the path in the project
     */
    public static function inventory(string $name, string $type, ?int $projectId, int $userId, string $source): Inventory
    {
        $inventory = Inventory::findOne(['name' => $name]) ?? new Inventory();
        $inventory->name = $name;
        $inventory->inventory_type = $type;
        $inventory->project_id = $projectId;
        $inventory->content = $type === Inventory::TYPE_STATIC ? $source : null;
        $inventory->source_path = $type === Inventory::TYPE_STATIC ? null : $source;
        $inventory->created_by = $userId;
        $inventory->save(false);

        return $inventory;
    }

    /**
     * The owner of the runtime directory, for files a seeder writes there as
     * root, so the user of a dev checkout can still remove them.
     *
     * @return array{uid: int, gid: int}|null null unless the seeder runs as root
     */
    public static function runtimeOwner(): ?array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return null;
        }
        $runtime = (string)\Yii::getAlias('@runtime');
        $uid = fileowner($runtime);
        $gid = filegroup($runtime);

        return $uid === false || $gid === false ? null : ['uid' => $uid, 'gid' => $gid];
    }
}
