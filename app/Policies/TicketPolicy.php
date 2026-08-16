<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class TicketPolicy
{
    use ChecksSupportPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'create');
    }

    public function update(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'update');
    }

    public function delete(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'deleteAny');
    }

    /**
     * Restoration is System-Admin-only (FR-001, User Story 1 scenario 2) —
     * never granted to Support Manager, unlike ordinary ticket management.
     */
    public function restore(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'restoreAny');
    }

    /**
     * Manager-unrestricted assignment/reassignment (FR-023).
     */
    public function assign(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'assign');
    }

    /**
     * Settlement is System-Admin-only (permissions.md) — never granted to
     * Support Manager, unlike every other ticket ability.
     */
    public function settlePayment(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'settlePayment');
    }

    /**
     * Manager-unrestricted work on any ticket, OR the assigned Support
     * Agent working their own ticket (FR-003, contracts/permissions.md).
     */
    public function work(User $user, Ticket $ticket): bool
    {
        if ($this->authorizeSupportAbility($user, 'manage')) {
            return true;
        }

        return $this->authorizeSupportAbility($user, 'work')
            && $ticket->assigned_employee_id !== null
            && $ticket->assigned_employee_id === $user->employeeProfile?->getKey();
    }

    /**
     * Posting a message: unrestricted for a Manager, own-ticket-only for an
     * Agent (FR-030, US3).
     */
    public function message(User $user, Ticket $ticket): bool
    {
        if (! $this->authorizeSupportAbility($user, 'message')) {
            return false;
        }

        if ($this->authorizeSupportAbility($user, 'manage')) {
            return true;
        }

        return $ticket->assigned_employee_id !== null
            && $ticket->assigned_employee_id === $user->employeeProfile?->getKey();
    }

    /** @return array<string, string> */
    protected function supportPermissionMap(): array
    {
        return [
            'viewAny' => SupportPermission::TicketView->value,
            'view' => SupportPermission::TicketView->value,
            'create' => SupportPermission::TicketManage->value,
            'update' => SupportPermission::TicketManage->value,
            'delete' => SupportPermission::TicketManage->value,
            'deleteAny' => SupportPermission::TicketManage->value,
            'restore' => SupportPermission::RecordRestore->value,
            'restoreAny' => SupportPermission::RecordRestore->value,
            'manage' => SupportPermission::TicketManage->value,
            'assign' => SupportPermission::TicketAssign->value,
            'settlePayment' => SupportPermission::TicketSettlePayment->value,
            'work' => SupportPermission::TicketWork->value,
            'message' => SupportPermission::TicketMessage->value,
        ];
    }
}
