<?php

declare(strict_types=1);

namespace app\components;

use yii\base\Behavior;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;

/**
 * Clears user.ldap_sync_disabled whenever a user's status changes and the
 * same save does not set the mark itself (attached in User::behaviors()).
 *
 * The mark says that ldap/sync deactivated the account because the directory
 * no longer had the user, so that an LDAP sign-in or an ldap/sync that finds
 * the user activates it again (LdapUserProvisioner). ldap/sync sets it in the
 * save that deactivates the account, and the provisioner clears it in the
 * save that activates it again. Every other change of an account's status
 * (the status toggle, the Edit User form, PUT /api/v1/users/{id},
 * setup/admin, the E2E seeds, and any writer added later) clears it here, so
 * an account deactivated in Ansilume stays inactive whatever the directory
 * says (CLAUDE.md security rule 15). A save that leaves the status as it is
 * keeps the mark.
 *
 * A save limited to some attributes that saves the status but not the mark,
 * such as save(false, ['status']), saves the mark right after it. Only
 * ActiveRecord saves raise the events: a bulk User::updateAll() or
 * updateAttributes() of the status must set the mark itself.
 */
class LdapSyncDisabledBehavior extends Behavior
{
    /** The column of the mark. */
    public const COLUMN = 'ldap_sync_disabled';

    /** @return array<string, string> */
    public function events(): array
    {
        return [
            BaseActiveRecord::EVENT_BEFORE_UPDATE => 'clearOnStatusChange',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'saveWithTheStatus',
        ];
    }

    /**
     * Before an update: a changed status clears the mark unless the save
     * changes the mark too. Compared as stored, so a status of "10" for 10,
     * or 1 for true, is no change.
     */
    public function clearOnStatusChange(): void
    {
        $user = $this->user();
        $statusChanged = (int)$user->getOldAttribute('status') !== (int)$user->getAttribute('status');
        $markChanged = (bool)$user->getOldAttribute(self::COLUMN) !== (bool)$user->getAttribute(self::COLUMN);
        if ($statusChanged && !$markChanged) {
            $user->setAttribute(self::COLUMN, false);
        }
    }

    /**
     * After an update that saved the status but left the mark out (a save
     * limited to some attributes): the mark is saved too, so it never stays
     * behind the status it belongs to.
     */
    public function saveWithTheStatus(AfterSaveEvent $event): void
    {
        $user = $this->user();
        if (array_key_exists('status', $event->changedAttributes) && $user->isAttributeChanged(self::COLUMN, false)) {
            $user->updateAttributes([self::COLUMN]);
        }
    }

    private function user(): BaseActiveRecord
    {
        /** @var BaseActiveRecord $owner attached to User only */
        $owner = $this->owner;

        return $owner;
    }
}
