<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Models\ReceivableWriteOff;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountingPermissions;

final class ReceivableWriteOffPolicy
{
    use ChecksAccountingPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'create');
    }

    public function approve(User $user, ReceivableWriteOff $writeOff): bool
    {
        return $writeOff->isDraft()
            && (int) $writeOff->recorded_by !== (int) $user->getKey()
            && $this->authorizeAccountingAbility($user, 'approve');
    }

    public function cancel(User $user, ReceivableWriteOff $writeOff): bool
    {
        return $writeOff->isDraft()
            && $this->authorizeAccountingAbility($user, 'cancel');
    }

    public function update(User $user, ReceivableWriteOff $writeOff): bool
    {
        return $writeOff->isDraft()
            && $this->authorizeAccountingAbility($user, 'update');
    }

    public function delete(): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function accountingPermissionMap(): array
    {
        return [
            'viewAny' => AccountingPermission::WriteOffRecord->value,
            'view' => AccountingPermission::WriteOffRecord->value,
            'create' => AccountingPermission::WriteOffRecord->value,
            'update' => AccountingPermission::WriteOffRecord->value,
            'cancel' => AccountingPermission::WriteOffRecord->value,
            'approve' => AccountingPermission::WriteOffApprove->value,
        ];
    }
}
