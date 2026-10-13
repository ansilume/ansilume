<?php

declare(strict_types=1);

namespace app\commands;

use app\models\Credential;
use app\models\JobTemplate;
use app\models\TeamProject;

/**
 * The fixture of the E2E specs for a job template whose content only admins
 * may change (tests/e2e/tests/job-templates/rbac.spec.ts and
 * approval-rule.spec.ts): a job template with an approval rule ("gated").
 * Users without approval-rule.update may rename it and change its
 * description, but nothing it runs.
 *
 * - e2e-locked-template: e2e-admin's, gated by e2e-approval-rule, in
 *   e2e-locked-project, with the inventory and runner group of e2e-template.
 *   Its primary credential is e2e-template's, e2e-locked-credential is an
 *   additional one, and its extra vars, survey and options differ from the
 *   form's defaults, so a spec can see that a rename keeps every one of them.
 * - e2e-locked-project: a manual project without a team, so every role sees
 *   the template: e2e-admin and e2e-operator may change it (as far as each
 *   may), e2e-viewer may only view it. It has no local path, so a save of the
 *   template does not run ansible-lint.
 * - e2e-locked-credential: a token credential (the token is a test fixture,
 *   not a secret).
 *
 * Restored by name on every seed, a soft-deleted template too. A spec
 * renames the template to RENAMED and back; a run that stopped before it
 * renamed it back leaves RENAMED, which the next seed restores in place.
 */
final class E2eLockedTemplateSeeder
{
    public const TEMPLATE = 'e2e-locked-template';

    /** The name the spec renames the template to before it renames it back. */
    public const RENAMED = 'e2e-locked-template-renamed';

    public const PROJECT = 'e2e-locked-project';

    public const CREDENTIAL = 'e2e-locked-credential';

    public const DESCRIPTION = 'E2E fixture: only admins may change what it runs';

    public const PLAYBOOK = 'site.yml';

    public const EXTRA_VARS = '{"env":"e2e-locked","replicas":2}';

    /**
     * The survey: a text field and a select field.
     *
     * @var list<array{name: string, label: string, type: string, required: bool, default: string, options: list<string>, hint: string}>
     */
    public const SURVEY = [
        [
            'name' => 'region',
            'label' => 'Region',
            'type' => 'text',
            'required' => true,
            'default' => 'eu-central',
            'options' => [],
            'hint' => 'Where it runs',
        ],
        [
            'name' => 'size',
            'label' => 'Size',
            'type' => 'select',
            'required' => false,
            'default' => 'small',
            'options' => ['small', 'large'],
            'hint' => '',
        ],
    ];

    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void $logger */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Restore the project, the credential and the template, which $userId
     * (e2e-admin) created, gated by the rule #$ruleId (e2e-approval-rule),
     * with the inventory, runner group and primary credential of job
     * template #$jobTemplateId (e2e-template).
     *
     * @throws \RuntimeException when job template #$jobTemplateId does not exist
     */
    public function seed(int $userId, int $jobTemplateId, int $ruleId): void
    {
        $source = JobTemplate::findOne($jobTemplateId);
        if ($source === null) {
            throw new \RuntimeException("Job template #{$jobTemplateId} not found.");
        }
        $credential = E2eFixtureHelper::credential(self::CREDENTIAL, Credential::TYPE_TOKEN, $userId, ['token' => 'e2e-locked-dummy-token']);
        $credential->env_var_name = 'E2E_LOCKED_TOKEN';
        $credential->save(false);
        $template = $this->template($userId, $source, $credential, $ruleId);

        ($this->logger)(sprintf(
            "  Restored %s (ID %d) in %s (ID %d), gated by approval rule #%d, with %s (ID %d): only admins change what it runs.\n",
            self::TEMPLATE,
            $template->id,
            self::PROJECT,
            $template->project_id,
            $ruleId,
            self::CREDENTIAL,
            $credential->id
        ));
    }

    /**
     * The template, restored in place: a soft-deleted or renamed copy comes
     * back under its name instead of a second one.
     */
    private function template(int $userId, JobTemplate $source, Credential $credential, int $ruleId): JobTemplate
    {
        if (JobTemplate::findOne(['name' => self::TEMPLATE]) === null) {
            /** @var JobTemplate|null $previous */
            $previous = JobTemplate::findWithDeleted()
                ->where(['name' => [self::TEMPLATE, self::RENAMED]])
                ->orderBy(['id' => SORT_DESC])
                ->one();
            $previous?->updateAttributes(['name' => self::TEMPLATE, 'deleted_at' => null]);
        }
        $project = E2eFixtureHelper::project(self::PROJECT, $userId);
        $project->description = 'E2E fixture: no team, every role sees its job template';
        $project->local_path = null;
        $project->save(false);
        // Without a team, every role sees the project's templates.
        TeamProject::deleteAll(['project_id' => $project->id]);
        $parents = [0 => (int)$project->id, 1 => (int)$source->inventory_id, 2 => (int)$source->runner_group_id];
        $primary = $source->credential_id === null ? null : Credential::findOne((int)$source->credential_id);
        $template = E2eFixtureHelper::template(self::TEMPLATE, $parents, $userId, $primary, [$credential]);
        $template->description = self::DESCRIPTION;
        $template->playbook = self::PLAYBOOK;
        $template->approval_rule_id = $ruleId;
        $template->extra_vars = self::EXTRA_VARS;
        $template->survey_fields = (string)json_encode(self::SURVEY, JSON_UNESCAPED_SLASHES);
        $template->verbosity = 1;
        $template->forks = 7;
        $template->timeout_minutes = 45;
        $template->limit = 'localhost';
        $template->tags = 'e2e';
        $template->skip_tags = 'slow';
        $template->become = true;
        $template->become_method = 'sudo';
        $template->become_user = 'root';
        $template->trigger_token = null;
        $template->trigger_token_created_by = null;
        $template->save(false);

        return $template;
    }
}
