<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\JobTemplateFormPost;
use app\models\JobTemplate;
use PHPUnit\Framework\TestCase;

/**
 * What the job template form posts, read before the template is saved: its
 * fields, a list where an ID or a whole number belongs, and the values of a
 * rejected submission that the form cannot show. The request's JSON parser
 * serves the form, so a post can hold lists, objects and JSON numbers beyond
 * the int range; ListsForWholeNumbersTest and JobTemplateControllerIntRangeTest
 * post them to the form itself.
 */
class JobTemplateFormPostTest extends TestCase
{
    public function testTheFieldsAreThoseOfTheForm(): void
    {
        $model = new JobTemplate();

        $this->assertSame(['name' => 'web'], JobTemplateFormPost::fields($model, ['JobTemplate' => ['name' => 'web'], 'credential_ids' => [3]]));
        $this->assertSame([], JobTemplateFormPost::fields($model, ['JobTemplate' => 'text']), 'fields that are no array are none');
        $this->assertSame([], JobTemplateFormPost::fields($model, []));
    }

    /**
     * Regression: an empty list in such a field skipped the rules, and the
     * template was saved with verbosity, forks or timeout 0, or failed the
     * credential's foreign key (500). The project and the approval rule are
     * read elsewhere: an empty list is no project, and the rule is checked
     * first.
     */
    public function testAListInAnIdOrNumberFieldGetsTheIntegerRulesError(): void
    {
        $model = new JobTemplate();
        $post = ['JobTemplate' => [
            'verbosity' => [],
            'credential_id' => ['a' => 1],
            'forks' => '5',
            'project_id' => [],
            'approval_rule_id' => [],
            'limit' => ['x'],
        ]];

        $this->assertTrue(JobTemplateFormPost::refusesLists($model, $post));
        $this->assertSame(
            ['credential_id' => ['Credential Id must be an integer.'], 'verbosity' => ['Verbosity must be an integer.']],
            $this->sortedErrors($model)
        );
    }

    public function testAPostWithoutAListRefusesNothing(): void
    {
        $model = new JobTemplate();

        $this->assertFalse(JobTemplateFormPost::refusesLists($model, ['JobTemplate' => ['verbosity' => '2', 'forks' => '', 'project_id' => []]]));
        $this->assertFalse(JobTemplateFormPost::refusesLists($model, ['JobTemplate' => 'text']));
        $this->assertSame([], $model->getErrors());
    }

    /**
     * A list or an object, and a float that is no whole number, are emptied
     * for the form; everything else stays, a whole float included.
     */
    public function testShowableEmptiesWhatTheFormCannotShow(): void
    {
        $model = new JobTemplate();
        $model->setAttributes([
            'name' => 'web',
            'verbosity' => [],
            'forks' => 1e30,
            'timeout_minutes' => 5.0,
            'limit' => ['x'],
            'credential_id' => INF,
            'inventory_id' => 7,
        ], false);

        JobTemplateFormPost::showable($model);

        $this->assertSame(
            ['name' => 'web', 'verbosity' => null, 'forks' => null, 'timeout_minutes' => 5.0, 'limit' => null, 'credential_id' => null, 'inventory_id' => 7],
            $model->getAttributes(['name', 'verbosity', 'forks', 'timeout_minutes', 'limit', 'credential_id', 'inventory_id'])
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function sortedErrors(JobTemplate $model): array
    {
        $errors = $model->getErrors();
        ksort($errors);

        return $errors;
    }
}
