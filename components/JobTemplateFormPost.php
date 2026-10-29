<?php

declare(strict_types=1);

namespace app\components;

use app\models\JobTemplate;

/**
 * What the job template form posts (JobTemplateController), read before the
 * template is saved, and a rejected submission prepared to be shown again.
 * The request's JSON parser serves the form too, so a post can hold what a
 * browser never sends: lists, objects and JSON numbers beyond the int range.
 */
final class JobTemplateFormPost
{
    /**
     * The fields of the form that hold an ID or a whole number, but the
     * project, whose empty list is no project (TeamScopingTrait), refused by
     * the required rule, and the approval rule, which the rule check reads
     * first (JobTemplateRuleAssignment).
     */
    public const SINGLE_VALUE_FIELDS = ['inventory_id', 'credential_id', 'runner_group_id', 'verbosity', 'forks', 'timeout_minutes'];

    /**
     * The fields $post (the request's post) holds for $model, as load()
     * reads them; none when they are no array.
     *
     * @param array<array-key, mixed> $post
     * @return array<array-key, mixed>
     */
    public static function fields(JobTemplate $model, array $post): array
    {
        $fields = $post[$model->formName()] ?? [];

        return is_array($fields) ? $fields : [];
    }

    /**
     * Whether $post holds a list or an object, a JSON [] or {} among them,
     * in one of SINGLE_VALUE_FIELDS: each such field gets its integer rule's
     * error ("Forks must be an integer.", SubmittedInteger::refusesLists()),
     * before anything reads it, and the caller saves nothing. The rules take
     * an empty list for empty, and the column stored it as 0 (verbosity 0,
     * no forks, no timeout), or its foreign key refused 0 with a server
     * error (the credential).
     *
     * @param array<array-key, mixed> $post
     */
    public static function refusesLists(JobTemplate $model, array $post): bool
    {
        return SubmittedInteger::refusesLists($model, self::fields($model, $post), self::SINGLE_VALUE_FIELDS);
    }

    /**
     * Empties each value of the rejected submission $model that the form
     * cannot show, so that its field is shown empty with its error: a list
     * or an object, of which a field makes text ("Array to string
     * conversion"), and a float that is no whole number the integer rule
     * accepts, as a JSON body gives 1e30 or 1e999, which an (int) cast warns
     * about on PHP 8.5. The form and the template read the primary
     * credential as the integer rule reads an ID (form.php,
     * JobTemplate::credentialSnapshot(): SubmittedId), so this is the second
     * line of defence.
     */
    public static function showable(JobTemplate $model): void
    {
        foreach ($model->getAttributes() as $attribute => $value) {
            if (is_array($value) || (is_float($value) && SubmittedInteger::of($value) === null)) {
                $model->setAttribute($attribute, null);
            }
        }
    }
}
