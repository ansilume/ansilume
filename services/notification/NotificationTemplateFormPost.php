<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * What the web form of a notification template posts, read as the API
 * reads a body, and the save of the template with it
 * (NotificationTemplateSave): the same fields, rules and messages as POST
 * and PUT /api/v1/notification-templates.
 *
 * The template's fields (FORM) are read by NotificationTemplateBody (load()):
 * a list or an object where a field takes one value, which only a forged
 * post or a JSON body brings (config/web.php parses one for the web forms
 * too), is refused with the API's message at FIELDS_ERROR, and the template
 * is left as it was; the events and the config are taken as a list and an
 * object too, as the API takes them.
 *
 * Its workflow filter (FILTER_FIELD) holds the IDs of the workflow
 * templates selected in the multi-select, each read as the integer rule
 * reads an ID (NotificationTemplateBody::workflowTemplateIdsOf():
 * SubmittedId), and '' (the multi-select's hidden field) when none is
 * selected, so that an empty selection empties the editor's part of the
 * filter. A post without the field keeps the filter. Any other value (a
 * number beyond the int range, a fraction, a list in the list, an object)
 * is refused with NotificationTemplateBody::WORKFLOW_TEMPLATE_IDS_FORM at
 * the template's error of the filter (NotificationTemplateSave::
 * FILTER_ATTRIBUTE), beside the errors of its own rules.
 *
 * A refused post writes nothing.
 */
final class NotificationTemplateFormPost
{
    /** The fields of the template in the post: those of the form, NotificationTemplate::formName(). */
    public const FORM = 'NotificationTemplate';

    /** The field of the post that holds the workflow templates of the filter. */
    public const FILTER_FIELD = NotificationTemplateSave::FILTER_ATTRIBUTE;

    /** The template's error of fields it cannot take, which the form shows above its fields. */
    public const FIELDS_ERROR = 'form';

    /** Why load() refused the fields; null when it applied them, or did not run. */
    private ?string $fieldsRefusal = null;

    /**
     * @param bool $refused whether the filter holds what it cannot take
     * @param list<int>|null $workflowTemplateIds the IDs of the filter as posted; null when the post holds no
     *     filter, or one that is refused
     * @param mixed $fields the fields of the template (FORM), the channel secrets of the config as typed among them
     */
    private function __construct(
        public readonly bool $refused,
        public readonly ?array $workflowTemplateIds,
        #[\SensitiveParameter] private readonly mixed $fields
    ) {
    }

    /**
     * What $post, the request's post, holds for the template and its
     * filter; its config holds the channel secrets as typed: marked, so
     * that no stack trace shows them.
     *
     * @param array<array-key, mixed> $post
     */
    public static function of(#[\SensitiveParameter] array $post): self
    {
        $fields = $post[self::FORM] ?? null;
        if (!array_key_exists(self::FILTER_FIELD, $post)) {
            return new self(false, null, $fields);
        }
        $value = $post[self::FILTER_FIELD];
        $ids = $value === '' ? [] : NotificationTemplateBody::workflowTemplateIdsOf($value);

        return new self($ids === null, $ids, $fields);
    }

    /**
     * Applies the posted fields to $template as the API applies a body
     * (NotificationTemplateBody), unless one holds what the field cannot
     * take: then the template is left as it was, and create() and update()
     * refuse the post. Whether the post holds the form's fields.
     */
    public function load(NotificationTemplate $template): bool
    {
        if (!is_array($this->fields)) {
            return false;
        }
        $this->fieldsRefusal = NotificationTemplateBody::apply($template, $this->fields)->refusal;

        return true;
    }

    /**
     * Creates $template, whose creator and identity the caller set, with
     * the posted filter as the user $userId sets it. Whether it was saved;
     * otherwise the template holds the errors and nothing is written.
     */
    public function create(NotificationTemplate $template, int $userId): bool
    {
        return !$this->refusedFor($template) && NotificationTemplateSave::create($template, $this->workflowTemplateIds, $userId);
    }

    /**
     * Saves the change of $template that the user $userId made, with the
     * posted filter, as the user it delivers as when the change makes them
     * that ($identity, built before the change was loaded). Whether it was
     * saved; otherwise the template holds the errors and nothing is
     * written.
     */
    public function update(NotificationTemplate $template, NotificationIdentity $identity, int $userId): bool
    {
        return !$this->refusedFor($template) && NotificationTemplateSave::update($template, $identity, $this->workflowTemplateIds, $userId);
    }

    /**
     * Whether the post is refused: then $template holds the refusal of its
     * fields (FIELDS_ERROR), or the errors of its own rules and the refusal
     * of its filter.
     */
    private function refusedFor(NotificationTemplate $template): bool
    {
        if ($this->fieldsRefusal !== null) {
            $template->addError(self::FIELDS_ERROR, $this->fieldsRefusal);

            return true;
        }
        if (!$this->refused) {
            return false;
        }
        $template->validate();
        $template->addError(NotificationTemplateSave::FILTER_ATTRIBUTE, NotificationTemplateBody::WORKFLOW_TEMPLATE_IDS_FORM);

        return true;
    }
}
