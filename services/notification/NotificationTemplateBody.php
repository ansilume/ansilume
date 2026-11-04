<?php

declare(strict_types=1);

namespace app\services\notification;

use app\components\ApiBodyValues;
use app\components\SubmittedId;
use app\models\NotificationTemplate;

/**
 * The request body of POST and PUT /api/v1/notification-templates, and the
 * fields the template's web form posts (NotificationTemplateFormPost), so
 * that both take the same fields with the same rules and messages, read
 * into a template (apply()): its text fields, its config as an object or
 * as JSON text, its events as a list of names or as their comma-separated
 * text, and the IDs of its workflow filter (workflow_template_ids), which
 * the caller writes with the save (NotificationTemplateSave). A field the
 * body leaves out keeps its value. No body sets who created the template,
 * whom it delivers as (NotificationIdentity) or its channel state.
 *
 * A body whose field holds what the field cannot take is refused before
 * the template changes: a list or an object for a text field
 * (ApiBodyValues::NOT_A_VALUE: a string cast of it answered 500), events
 * of another form (EVENTS_FORM), and workflow template IDs that are no list
 * of IDs (WORKFLOW_TEMPLATE_IDS_FORM), each read as the integer rule reads
 * an ID (SubmittedId): its digits as text and a number without a fraction
 * are an ID; a fraction, a number beyond the int range, true, a list and
 * text that is no number are none, and neither is a number below 1.
 *
 * The body holds the channel secrets of the config, so its parameter is
 * marked and no refusal names a value.
 */
final class NotificationTemplateBody
{
    /** The refusal (422) of events that are neither text nor a list of names. */
    public const EVENTS_FORM = 'events must be a list of event names, or the names as comma-separated text.';

    /** The refusal (422) of workflow_template_ids that are no list of workflow template IDs. */
    public const WORKFLOW_TEMPLATE_IDS_FORM = 'workflow_template_ids must be a list of workflow template IDs.';

    /** The fields of the body that take one value, never a list or an object. */
    public const TEXT_FIELDS = ['name', 'description', 'channel', 'subject_template', 'body_template', 'level', 'workflow_scope'];

    /** The fields of the body that apply() writes to the template, in the order it writes them. */
    private const FIELDS = ['name', 'description', 'channel', 'config', 'subject_template', 'body_template', 'level', 'workflow_scope'];

    /** The fields that null empties. */
    private const NULLABLE = ['description', 'config', 'subject_template', 'body_template'];

    /**
     * @param string|null $refusal why the body was refused (422), and the template left as it was; null when it was applied
     * @param list<int>|null $workflowTemplateIds the IDs of the workflow filter; null when the body leaves them out
     */
    private function __construct(public readonly ?string $refusal, public readonly ?array $workflowTemplateIds)
    {
    }

    /**
     * Applies $body to $template, unless one of its fields holds what the
     * field cannot take (see the class): then the template is left as it
     * was, and the result names the refusal.
     *
     * @param array<array-key, mixed> $body the request body, the channel secrets of its config included
     */
    public static function apply(NotificationTemplate $template, #[\SensitiveParameter] array $body): self
    {
        $refusal = self::refusalOf($body);
        if ($refusal !== null) {
            return new self($refusal, null);
        }
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $template->setAttribute($field, self::textOf($field, $body[$field]));
            }
        }
        if (array_key_exists('events', $body)) {
            $template->events = (string)self::eventsOf($body['events']);
        }
        $ids = array_key_exists('workflow_template_ids', $body) ? self::workflowTemplateIdsOf($body['workflow_template_ids']) : null;

        return new self(null, $ids);
    }

    /**
     * The workflow template IDs of $value, a list, each read as the integer
     * rule reads an ID (SubmittedId), in their order; null when $value is
     * no list of IDs of 1 or more: a web form's posted list of the filter
     * is read the same way.
     *
     * @return list<int>|null
     */
    public static function workflowTemplateIdsOf(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $ids = [];
        foreach ($value as $entry) {
            $id = SubmittedId::of($entry);
            if ($id === null || $id < 1) {
                return null;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Why $body is refused, as the class says: the refusal of the first of
     * its fields that holds what the field cannot take; null when none does.
     *
     * @param array<array-key, mixed> $body
     */
    private static function refusalOf(#[\SensitiveParameter] array $body): ?string
    {
        $refusal = ApiBodyValues::listValueRefusal($body, self::TEXT_FIELDS);
        if ($refusal === null && array_key_exists('events', $body) && self::eventsOf($body['events']) === null) {
            $refusal = self::EVENTS_FORM;
        }
        if ($refusal === null && array_key_exists('workflow_template_ids', $body) && self::workflowTemplateIdsOf($body['workflow_template_ids']) === null) {
            $refusal = self::WORKFLOW_TEMPLATE_IDS_FORM;
        }

        return $refusal;
    }

    /**
     * The events of $events as the template stores them, comma-separated:
     * text as it is, a list of names joined; null for a list with anything
     * but names, or an object.
     */
    private static function eventsOf(mixed $events): ?string
    {
        if ($events === null || is_scalar($events)) {
            return (string)$events;
        }
        if (!is_array($events) || !array_is_list($events)) {
            return null;
        }
        foreach ($events as $event) {
            if (!is_string($event)) {
                return null;
            }
        }

        return implode(',', $events);
    }

    /**
     * The value $field of the template takes for the body's $submitted: a
     * list or an object, which only the config reaches here with, as JSON
     * text; null for null where the field may be empty; else the value as
     * text, null as ''.
     */
    private static function textOf(string $field, #[\SensitiveParameter] mixed $submitted): ?string
    {
        if (is_array($submitted)) {
            return (string)json_encode($submitted);
        }
        if ($submitted === null && in_array($field, self::NULLABLE, true)) {
            return null;
        }

        return is_scalar($submitted) ? (string)$submitted : '';
    }
}
