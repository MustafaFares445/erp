<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Enums\SupplierDebitNoteStatus;
use App\Models\SupplierDebitNote;
use App\Models\User;

final class SupplierDebitNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccountingPermission::SupplierDebitNoteView->value);
    }

    public function view(User $user): bool
    {
        return $user->can(AccountingPermission::SupplierDebitNoteView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(AccountingPermission::SupplierDebitNoteManage->value);
    }

    public function update(User $user, SupplierDebitNote $note): bool
    {
        return $user->can(AccountingPermission::SupplierDebitNoteManage->value)
            && $note->status === SupplierDebitNoteStatus::Draft;
    }

    public function confirm(User $user, SupplierDebitNote $note): bool
    {
        return $user->can(AccountingPermission::SupplierDebitNoteConfirm->value)
            && $note->status === SupplierDebitNoteStatus::Draft;
    }

    public function reverse(User $user, SupplierDebitNote $note): bool
    {
        return $user->can(AccountingPermission::SupplierDebitNoteReverse->value)
            && $note->status === SupplierDebitNoteStatus::Confirmed;
    }

    public function delete(User $user, SupplierDebitNote $note): bool
    {
        return $this->update($user, $note);
    }
}
