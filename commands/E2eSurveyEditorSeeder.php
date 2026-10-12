<?php

declare(strict_types=1);

namespace app\commands;

use app\components\SurveyField;
use app\models\JobTemplate;

/**
 * Fixtures for tests/e2e/tests/job-templates/survey-editor.spec.ts, of the
 * survey editor and the template page:
 *
 * - e2e-survey-xss-template: survey text that ends a script element or opens
 *   an HTML comment in it. The survey editor puts the survey into an inline
 *   script, which must keep it data: window.__e2eSurveyXss stays unset (a
 *   stored XSS before the editor encoded it with Json::htmlEncode).
 * - e2e-survey-legacy-template: a survey saved before the survey rules, with
 *   a field name that is no variable name (db-host) and a password field
 *   with a default. Its pages must never carry the default. No spec saves
 *   it, so it keeps the default for the whole run.
 * - e2e-survey-legacy-edit-template: the same survey, for the spec that saves
 *   an edit that leaves the survey alone (the save drops the default).
 * - e2e-survey-extra-vars-template: Extra Vars that hold a value for its
 *   password field, saved before such keys were refused. The template page
 *   shows that value only as $encrypted$.
 * - e2e-survey-rewritten-vars-template: such Extra Vars as well, with a float
 *   written 1.0. The form's Extra Vars editor used to post the stored text
 *   rewritten (1.0 as 1); it now posts it as stored, so a description-only
 *   edit saves and leaves the text as the seed stored it.
 *
 * All are restored by name on every seed. They use the project and runner
 * group of {@see E2eSurveySecretSeeder}: that project has no local path, so
 * saving them runs no ansible-lint.
 */
final class E2eSurveyEditorSeeder
{
    public const XSS_TEMPLATE = 'e2e-survey-xss-template';
    public const LEGACY_TEMPLATE = 'e2e-survey-legacy-template';
    public const LEGACY_EDIT_TEMPLATE = 'e2e-survey-legacy-edit-template';
    /** Ends the editor's script element unless its JSON is HTML-safe. */
    public const XSS_LABEL = '</script><script>window.__e2eSurveyXss = 1</script>';
    /** Opens an HTML comment inside the script element unless its JSON is HTML-safe. */
    public const XSS_HINT = '<!--<script>';
    public const XSS_DEFAULT = '</script><script>window.__e2eSurveyXss = 2</script>';
    /** A field name the survey rules refuse for new fields, but keep for stored ones. */
    public const LEGACY_NAME = 'db-host';
    /** A default of a password field, from before password fields lost their defaults. */
    public const LEGACY_DEFAULT = 'e2e-legacy-default-Jv38';
    public const EXTRA_VARS_TEMPLATE = 'e2e-survey-extra-vars-template';
    /** The value of the password field's key in the template's Extra Vars. */
    public const LEGACY_EXTRA_VAR = 'e2e-legacy-extra-var-Pq90';
    public const REWRITTEN_VARS_TEMPLATE = 'e2e-survey-rewritten-vars-template';
    /** The value of the password field's key in the Extra Vars of REWRITTEN_VARS_TEMPLATE. */
    public const REWRITTEN_EXTRA_VAR = 'e2e-legacy-extra-var-Hm63';
    /** The stored Extra Vars of REWRITTEN_VARS_TEMPLATE, as text: JSON.stringify(JSON.parse()) would write 1.0 as 1. */
    public const REWRITTEN_EXTRA_VARS = '{"cpu_ratio": 1.0, "db_password": "' . self::REWRITTEN_EXTRA_VAR . '"}';

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
        $restored = [
            E2eFixtureHelper::surveyTemplate(self::XSS_TEMPLATE, $parents, $userId, [
                self::field('deploy_target', self::XSS_LABEL, SurveyField::TYPE_TEXT, self::XSS_DEFAULT, self::XSS_HINT),
            ], 0),
            E2eFixtureHelper::surveyTemplate(self::LEGACY_TEMPLATE, $parents, $userId, self::legacySurvey(), 0),
            E2eFixtureHelper::surveyTemplate(self::LEGACY_EDIT_TEMPLATE, $parents, $userId, self::legacySurvey(), 0),
            self::withExtraVars(
                E2eFixtureHelper::surveyTemplate(self::EXTRA_VARS_TEMPLATE, $parents, $userId, self::passwordSurvey(), 0),
                (string)json_encode(['region' => 'eu-central', 'db_password' => self::LEGACY_EXTRA_VAR])
            ),
            self::withExtraVars(
                E2eFixtureHelper::surveyTemplate(self::REWRITTEN_VARS_TEMPLATE, $parents, $userId, self::passwordSurvey(), 0),
                self::REWRITTEN_EXTRA_VARS
            ),
        ];

        $names = array_map(static fn (JobTemplate $template): string => sprintf("'%s' (ID %d)", $template->name, $template->id), $restored);
        $last = (string)array_pop($names);
        ($this->logger)('  Restored survey editor fixtures ' . implode(', ', $names) . " and {$last}.\n");
    }

    /**
     * A survey saved before the survey rules: a field name that is no
     * variable name, and a password field with a default.
     *
     * @return list<array<string, mixed>>
     */
    private static function legacySurvey(): array
    {
        return [
            self::field(self::LEGACY_NAME, 'Database host', SurveyField::TYPE_TEXT, 'db.example.test', ''),
            self::field('db_password', 'Database password', SurveyField::TYPE_PASSWORD, self::LEGACY_DEFAULT, ''),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function passwordSurvey(): array
    {
        return [self::field('db_password', 'Database password', SurveyField::TYPE_PASSWORD, '', '')];
    }

    /**
     * $template with the Extra Vars text $extraVars, stored as it is: saved
     * before the rule against password keys in Extra Vars.
     */
    private static function withExtraVars(JobTemplate $template, string $extraVars): JobTemplate
    {
        $template->updateAttributes(['extra_vars' => $extraVars]);

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(string $name, string $label, string $type, string $default, string $hint): array
    {
        return [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'required' => false,
            'default' => $default,
            'options' => [],
            'hint' => $hint,
        ];
    }
}
