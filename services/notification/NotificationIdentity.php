<?php

declare(strict_types=1);

namespace app\services\notification;

use app\models\NotificationTemplate;

/**
 * Whom a notification template delivers as (delivers_as): the user whose
 * access decides which events it delivers. That is the user who last
 * changed what the template delivers or where to:
 * - its channel, or its destination: the destinationKeys of its channel's
 *   definition (ChannelDefinition: the channel's URL, token, channel and
 *   chat keys), every key for a channel without them or that the
 *   installation does not have;
 * - its events, its workflow scope, its workflow filter or its level.
 * A new template delivers as its creator. A save of a template that
 * delivers as an inactive user makes the saver the user it delivers as
 * too: such a template delivers nothing.
 *
 * Renaming, the description, the subject and the body, another config key,
 * a reordering of the events and a save that changes nothing keep the user
 * it delivers as.
 *
 * Why: otherwise a user who edits someone else's template, an operator an
 * admin's, chooses where it delivers while it delivers what its creator may
 * see: the events of every team.
 *
 * The events count as changed when they name another set of events; the
 * destination when a key holds a value that means another destination
 * (meaning()), or a config that is no JSON object changes its text. A key
 * holds the same destination in each shape the edit form and the API post
 * it back in: a missing key, null, '' and an empty list or map are the same
 * (the edit form of 2.14 stored a webhook template's headers as {}, the
 * form now posts no headers key without a header), a scalar counts by its text,
 * as the channels read it (-100 and "-100" are the same Telegram chat: the
 * form posts a number as its text), and a map whatever the order of its
 * keys. Every other change of a value is one: another URL, header, chat or
 * recipient, a header emptied or renamed, a recipient moved in the list.
 *
 * Usage, for a change of a loaded template, as ScheduleRunAs: create it
 * before the change is loaded, validate the change (the validation puts the
 * stored channel secrets back in place of the placeholders, so a secret sent
 * back as the placeholder is no change), write its workflow filter, then
 * apply() the editor's ID when changesIdentity() holds, save, and add
 * auditContext() to the change's notification-template.updated audit entry.
 */
final class NotificationIdentity
{
    /**
     * The key of a change of the user a template delivers as in the context
     * of its notification-template.updated audit entry: {from, to}, the user
     * IDs before and after the change.
     */
    public const AUDIT_KEY = 'delivers_as';

    /** The attributes besides the config, the events and the workflow filter that decide what or where a template delivers. */
    private const IDENTITY_ATTRIBUTES = ['channel', 'workflow_scope', 'level'];

    /** @var array<string, mixed> the template's stored values (its old attributes); [] for a new template */
    private readonly array $stored;

    /** @var list<int> the workflow filter as stored */
    private readonly array $storedFilter;

    /** @var array{delivers_as?: array{from: int, to: int}} */
    private array $auditContext = [];

    /**
     * Take the stored values of $template, before its change is loaded.
     */
    public function __construct(private readonly NotificationTemplate $template)
    {
        $this->stored = $template->getOldAttributes();
        $this->storedFilter = $template->getWorkflowTemplateIds();
    }

    /**
     * Whether the change of the template about to be saved makes its saver
     * the user it delivers as: a new template, a template that delivers as
     * an inactive user, or a change of what or where it delivers. Call it
     * once the change is validated and its workflow filter written.
     */
    public function changesIdentity(): bool
    {
        if ($this->stored === [] || $this->template->isDeliveringAsInactive()) {
            return true;
        }
        foreach (self::IDENTITY_ATTRIBUTES as $attribute) {
            if (self::text($this->stored[$attribute] ?? null) !== self::text($this->template->getAttribute($attribute))) {
                return true;
            }
        }

        return $this->eventsChanged()
            || $this->destinationChanged()
            || $this->storedFilter !== $this->template->getWorkflowTemplateIds();
    }

    /**
     * Make $userId the user the template delivers as, so the save stores it
     * with the change, and record the change for the audit when the template
     * delivered as another user.
     */
    public function apply(int $userId): void
    {
        $from = $this->stored['delivers_as'] ?? null;
        $this->template->delivers_as = $userId;
        $this->auditContext = $from !== null && (int)$from !== $userId
            ? [self::AUDIT_KEY => ['from' => (int)$from, 'to' => $userId]]
            : [];
    }

    /**
     * The context the notification-template.updated audit entry of the save
     * after apply() gets: delivers_as with the user the template delivered
     * as and the one it delivers as now, or [] when it keeps its user.
     *
     * @return array{delivers_as?: array{from: int, to: int}}
     */
    public function auditContext(): array
    {
        return $this->auditContext;
    }

    private function eventsChanged(): bool
    {
        return self::eventSet($this->stored['events'] ?? null) !== self::eventSet($this->template->getAttribute('events'));
    }

    /**
     * Whether a key that says where the template delivers, every key for a
     * channel without destination keys, holds a value that means another
     * destination (meaning()); for a config that is no JSON object, whether
     * its text changed. The configs stay in this method: they hold the
     * channel's secrets.
     */
    private function destinationChanged(): bool
    {
        $storedText = self::text($this->stored['config'] ?? null);
        $currentText = self::text($this->template->getAttribute('config'));
        $stored = json_decode($storedText, true);
        $current = json_decode($currentText, true);
        if (!is_array($stored) || !is_array($current)) {
            return $storedText !== $currentText;
        }
        $keys = ChannelRegistry::definitionOf(self::text($this->template->getAttribute('channel')))?->destinationKeys
            ?? array_keys($stored + $current);
        foreach ($keys as $key) {
            if (self::meaning($stored[$key] ?? null) !== self::meaning($current[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a value of a config means for where the template delivers, so
     * that its shapes compare alike: '' for nothing (null, '', false and an
     * empty list or map, as for a missing key), a scalar's text, as the
     * channels read it (TelegramChannel sends (string) of its chat_id), and
     * a list or map of the meanings of its items, a map by its keys in their
     * text's order.
     *
     * A value of a config can be a channel secret: marked, so that no stack
     * trace shows it.
     *
     * @return string|array<int|string, mixed>
     */
    private static function meaning(#[\SensitiveParameter] mixed $value): string|array
    {
        if (!is_array($value) || $value === []) {
            return self::text($value);
        }
        $meaning = array_map(static fn (#[\SensitiveParameter] mixed $item): string|array => self::meaning($item), $value);
        ksort($meaning, SORT_STRING);

        return $meaning;
    }

    /**
     * The events of a comma-separated list, each once, sorted.
     *
     * @return list<string>
     */
    private static function eventSet(mixed $events): array
    {
        $set = array_values(array_unique(array_filter(array_map('trim', explode(',', self::text($events))))));
        sort($set);

        return $set;
    }

    /**
     * An attribute's value for the comparison: as text, '' for none. It
     * takes the configs too, with their channel secrets: marked.
     */
    private static function text(#[\SensitiveParameter] mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
