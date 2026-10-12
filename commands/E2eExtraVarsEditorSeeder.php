<?php

declare(strict_types=1);

namespace app\commands;

use app\components\SurveyField;
use app\models\JobTemplate;

/**
 * Fixtures for tests/e2e/tests/job-templates/extra-vars-editor.spec.ts, of
 * the Extra Vars editor (web/js/extra-vars-editor.js) on the template form
 * and the launch page:
 *
 * - e2e-extra-vars-bigint-template: no survey, and Extra Vars stored as the
 *   text {"build_id": 9007199254740993}. JSON.parse reads that integer as
 *   9007199254740992, so an editor that rewrote the stored text on load
 *   changed it on every save and every launch.
 * - e2e-extra-vars-bigint-secret-template: a password field db_password,
 *   and Extra Vars that hold such an integer and a value for db_password,
 *   saved before such keys were refused. The rewritten integer counted as
 *   a change of the Extra Vars, so even a description-only edit was refused
 *   with "Remove "db_password" from Extra Vars".
 * - e2e-extra-vars-refusal-template: plain Extra Vars, for the edits the
 *   spec expects to be refused (a number too large for JSON, invalid JSON):
 *   they store nothing.
 *
 * All are restored by name on every seed, with the Extra Vars written as
 * text (no validation, no re-encoding). They use the project and runner
 * group of {@see E2eSurveySecretSeeder}: that project has no local path, so
 * saving them runs no ansible-lint, and no runner serves that group, so a
 * launched job stays queued.
 */
final class E2eExtraVarsEditorSeeder
{
    public const BIGINT_TEMPLATE = 'e2e-extra-vars-bigint-template';
    /** Above 2^53: JavaScript numbers cannot hold it exactly. */
    public const BIGINT = '9007199254740993';
    public const BIGINT_EXTRA_VARS = '{"build_id": ' . self::BIGINT . '}';
    public const BIGINT_SECRET_TEMPLATE = 'e2e-extra-vars-bigint-secret-template';
    /** The value of the password field's key in the Extra Vars of BIGINT_SECRET_TEMPLATE. */
    public const BIGINT_SECRET_VALUE = 'e2e-legacy-extra-var-Zt41';
    public const BIGINT_SECRET_EXTRA_VARS = '{"build_id": ' . self::BIGINT
        . ', "db_password": "' . self::BIGINT_SECRET_VALUE . '"}';
    public const REFUSAL_TEMPLATE = 'e2e-extra-vars-refusal-template';
    public const REFUSAL_EXTRA_VARS = '{"timeout_s": 30}';
    public const DESCRIPTION = 'E2E fixture: Extra Vars the editor must post as stored';

    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(string): void $logger
     */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param array{0: int, 1: int, 2: int} $parents project, inventory and runner group ids
     */
    public function seed(int $userId, array $parents): void
    {
        $password = [[
            'name' => 'db_password',
            'label' => 'Database password',
            'type' => SurveyField::TYPE_PASSWORD,
            'required' => false,
            'default' => '',
            'options' => [],
            'hint' => '',
        ]];
        $restored = [
            self::restore(self::BIGINT_TEMPLATE, $parents, $userId, [], self::BIGINT_EXTRA_VARS),
            self::restore(self::BIGINT_SECRET_TEMPLATE, $parents, $userId, $password, self::BIGINT_SECRET_EXTRA_VARS),
            self::restore(self::REFUSAL_TEMPLATE, $parents, $userId, [], self::REFUSAL_EXTRA_VARS),
        ];

        $names = array_map(
            static fn (JobTemplate $template): string => sprintf("'%s' (ID %d)", $template->name, $template->id),
            $restored
        );
        $last = (string)array_pop($names);
        ($this->logger)('  Restored Extra Vars editor fixtures ' . implode(', ', $names) . " and {$last}.\n");
    }

    /**
     * The template $name with the survey $fields (none when empty) and the
     * Extra Vars text $extraVars, stored as it is.
     *
     * @param array{0: int, 1: int, 2: int} $parents
     * @param list<array<string, mixed>> $fields
     */
    private static function restore(
        string $name,
        array $parents,
        int $userId,
        array $fields,
        string $extraVars
    ): JobTemplate {
        $template = E2eFixtureHelper::surveyTemplate($name, $parents, $userId, $fields, 0);
        $template->updateAttributes([
            'survey_fields' => $fields === [] ? null : $template->survey_fields,
            'extra_vars' => $extraVars,
            'description' => self::DESCRIPTION,
        ]);

        return $template;
    }
}
