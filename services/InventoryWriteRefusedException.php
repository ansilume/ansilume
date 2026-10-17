<?php

declare(strict_types=1);

namespace app\services;

/**
 * A create, update or delete of an inventory refused by InventoryWriteService,
 * before anything was written, with an HTTP-style status:
 * - 403: the approval rule is for admins to assign or remove
 *   (ApprovalRuleAssignmentPolicy::ASSIGN_REFUSED); the inventory has an
 *   approval rule and only admins change its hosts or delete it
 *   (GatedInventoryLock::CHANGE_REFUSED, DELETE_REFUSED); or the user may no
 *   longer operate its project, checked again under the inventory's row lock
 *   (InventoryWriteService::TEAM_SCOPE_REFUSED);
 * - 404: the inventory was deleted meanwhile (InventoryWriteService::NOT_FOUND);
 * - 409: job templates use the inventory, deleted ones included
 *   (InventoryWriteService::DELETE_IN_USE, details used_by:
 *   InventoryUsage::toArray()).
 *
 * The message is meant for users and never carries a database error. $details
 * are the extra fields of the API error.
 */
final class InventoryWriteRefusedException extends \RuntimeException
{
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const CONFLICT = 409;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message, public readonly int $status, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
