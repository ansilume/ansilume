<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\models\Project;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * What the section of docs/updating.md for the update from 2.10.1 says
 * about the Git fields of the project form and the credential of a manual
 * project is what Project and its form do: the type of the credential is
 * checked against the URL for git projects only
 * (Project::SSH_URL_NEEDS_SSH_KEY, Project::HTTPS_URL_NEEDS_TOKEN_OR_PASSWORD),
 * as the branch is; the browser checks every Git field (Project::GIT_FIELDS)
 * only while Git is chosen; and the form shows the server's errors of the
 * Git fields it hides above itself (Project::GIT_FIELDS_REFUSED).
 * OpenApiProjectCredentialContractTest checks the spec.
 *
 * Regression (wave 5g of 2.11.0): a manual project whose hidden Git fields
 * held an SSH URL and a Token credential was refused under the hidden
 * Credential field, so the form saved nothing and showed no error, and the
 * API refused it too; the docs said nothing of it. Fails on the docs from
 * before.
 */
class ProjectGitFieldsDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    /** What validates the type of the credential. */
    private const CREDENTIAL_TYPE_RULE = 'validateScmCredentialType';

    /**
     * regression: nothing said that the credential of a manual project is
     * not checked, nor what the form shows for an error of a hidden Git
     * field. The fixes and the API contract change say so, quoting the
     * model.
     */
    public function testRegressionTheFixesAndTheApiSayTheCredentialOfAManualProjectIsNotChecked(): void
    {
        $section = $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION);
        $fixes = $this->between($section, '**Fixes that change behaviour:**', '**API contract change:**');
        $expected = [
            '- A manual project is saved with the URL, branch and credential it was given under Git: the type of its credential is checked against its URL for git projects only, as its branch is.',
            'A New Project with an SSH URL and a Token credential chosen under Git, then switched to Manual (no SCM), was refused with `' . Project::SSH_URL_NEEDS_SSH_KEY
                . '` under the hidden Credential field (Scm Credential Id): the form showed no error and saved nothing, and so did the Edit form of such a project (`'
                . Project::HTTPS_URL_NEEDS_TOKEN_OR_PASSWORD . '` for an HTTPS URL with an SSH Key credential).',
            'A git project is checked as before, also when a save makes a manual project a git project.',
            'The browser checks the URL, Branch and Credential fields only while Git is chosen.',
            'An error that the server still finds in them while the form hides them, such as a credential deleted while the form was open, is shown above the form: `'
                . Project::GIT_FIELDS_REFUSED . '`, followed by the errors.',
            'The browser counts an emoji, and every other character outside the Basic Multilingual Plane, as two against the length of a field, so the Edit form of a manual project whose stored URL or branch was within its length for the server but over it for the browser was not sent, and showed no error.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $fixes, 'The fixes of updating.md');
        }
        $this->assertMentions(
            '- `POST /api/v1/projects` and `PUT /api/v1/projects/{id}` check the type of `scm_credential_id` against `scm_url` for git projects only (`scm_type` `git` once the request is applied).'
                . ' A manual project with an SSH URL and a credential that is no SSH Key credential, or with an HTTPS URL and one that is neither a Token nor a Username/Password credential, is saved (`201`, `200`) and queues no sync; it was refused with `422` `'
                . Project::SSH_URL_NEEDS_SSH_KEY . '` or `' . Project::HTTPS_URL_NEEDS_TOKEN_OR_PASSWORD
                . '` A git project, and a `PUT` that makes such a manual project a git project, still get that `422`, and nothing is saved. A credential that does not exist is refused for every project, as before.',
            $this->between($section, '**API contract change:**', '**Database and deployment.**'),
            'The API contract change of updating.md'
        );
    }

    /**
     * What the fixes say is what the model and the form do: the rule of the
     * credential type weighs git projects only, as the branch rule does;
     * every check of a Git field in the browser waits for Git to be chosen;
     * the form shows the errors of the Git fields it hides above itself;
     * and the labels are those the fixes and the alert name.
     */
    public function testWhatTheFixesSayIsWhatTheModelAndTheFormDo(): void
    {
        $this->assertSame(['scm_url', 'scm_branch', 'scm_credential_id'], Project::GIT_FIELDS, 'The URL, Branch and Credential fields.');
        $when = $this->ruleOf('scm_credential_id', self::CREDENTIAL_TYPE_RULE)['when'] ?? null;
        $this->assertInstanceOf(\Closure::class, $when, 'The credential type of git projects only.');
        $branchWhen = $this->ruleOf('scm_branch', 'match')['when'] ?? null;
        $this->assertInstanceOf(\Closure::class, $branchWhen);
        $attributes = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        foreach ([Project::SCM_TYPE_GIT => true, Project::SCM_TYPE_MANUAL => false] as $type => $checked) {
            $typed = (new \ReflectionClass(Project::class))->newInstanceWithoutConstructor();
            $attributes->setValue($typed, ['scm_type' => $type]);
            $this->assertSame($checked, $when($typed), 'The credential of a ' . $type . ' project is ' . ($checked ? '' : 'not ') . 'checked.');
            $this->assertSame($checked, $branchWhen($typed), 'As its branch is.');
        }

        $gitChosen = (new \ReflectionClass(Project::class))->getConstant('GIT_CHOSEN_IN_THE_FORM');
        $this->assertIsString($gitChosen);
        $this->assertStringContainsString("type.value === '" . Project::SCM_TYPE_GIT . "'", $gitChosen, 'While Git is chosen.');
        $browserChecks = 0;
        foreach ($this->rulesOfTheGitFields() as $rule) {
            if (in_array($rule[1] ?? null, ['string', 'integer', 'match'], true)) {
                $browserChecks++;
                $this->assertSame($gitChosen, $rule['whenClient'] ?? null, 'The browser checks ' . implode(', ', (array)$rule[0]) . ' (' . (string)$rule[1] . ') only while Git is chosen.');
            }
        }
        $this->assertSame(4, $browserChecks, 'The URL, the branch (length and format) and the credential ID are checked in the browser.');

        $form = (string)file_get_contents(dirname(__DIR__, 3) . '/views/project/form.php');
        $this->assertStringContainsString('$hiddenGitFieldErrors = $model->scm_type !== Project::SCM_TYPE_GIT ? $model->gitFieldErrors() : [];', $form, 'The errors of the hidden Git fields.');
        $this->assertStringContainsString('<?= Html::encode(Project::GIT_FIELDS_REFUSED) ?>', $form, 'They are shown above the form.');
        $this->assertLessThan(strpos($form, "ActiveForm::begin(['id' => 'project-form'])"), strpos($form, 'Project::GIT_FIELDS_REFUSED'), 'Above the form.');

        $project = new Project();
        $this->assertSame('Scm Credential Id', $project->getAttributeLabel('scm_credential_id'), 'The label of the Credential field.');
        $this->assertSame('Scm Type', $project->getAttributeLabel('scm_type'), 'The field the alert names.');
    }

    /**
     * Project's rule $validator of $attribute, the only one.
     *
     * @return array<int|string, mixed>
     */
    private function ruleOf(string $attribute, string $validator): array
    {
        $rules = array_values(array_filter(
            $this->rulesOfTheGitFields(),
            static fn (array $rule): bool => ($rule[0] ?? null) === [$attribute] && ($rule[1] ?? null) === $validator
        ));
        $this->assertCount(1, $rules, "Project has one rule {$validator} of {$attribute}.");

        return $rules[0];
    }

    /**
     * Project's rules that name a Git field.
     *
     * @return list<array<int|string, mixed>>
     */
    private function rulesOfTheGitFields(): array
    {
        $project = (new \ReflectionClass(Project::class))->newInstanceWithoutConstructor();
        $rules = [];
        foreach ($project->rules() as $rule) {
            if (is_array($rule) && array_intersect((array)($rule[0] ?? []), Project::GIT_FIELDS) !== []) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }
}
