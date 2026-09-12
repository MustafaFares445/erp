<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SupportPermission;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\Concerns\ChecksSupportPermissions;

final class TicketPolicy
{
    use ChecksSupportPermissions;

    public function viewAny(User $user): bool
    {
        return $this->isActiveCustomer($user) || $this->authorizeSupportAbility($user, 'viewAny');
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if ($this->isActiveCustomer($user)) {
            return (int) $ticket->customer_id === (int) $user->customerProfile?->getKey();
        }

        return $this->authorizeSupportAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->isActiveCustomer($user) || $this->authorizeSupportAbility($user, 'create');
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

    public function restore(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'restoreAny');
    }

    public function assign(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'assign');
    }

    public function settlePayment(User $user): bool
    {
        return $this->authorizeSupportAbility($user, 'settlePayment');
    }

    public function work(User $user, Ticket $ticket): bool
    {
        if ($this->authorizeSupportAbility($user, 'manage')) {
            return true;
        }

        return $this->authorizeSupportAbility($user, 'work')
            && $ticket->assigned_employee_id !== null
            && $ticket->assigned_employee_id === $user->employeeProfile?->getKey();
    }

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

    private function isActiveCustomer(User $user): bool
    {
        $profile = $user->customerProfile;

        return $user->isCustomer()
            && $profile instanceof CustomerProfile
            && $profile->is_active;
    }
}
