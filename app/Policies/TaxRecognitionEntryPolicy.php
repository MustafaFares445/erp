<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountingPermission;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountingPermissions;

final class TaxRecognitionEntryPolicy
{
    use ChecksAccountingPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeAccountingAbility($user, 'viewAny');
    }

    public function view(User $user, TaxRecognitionEntry $taxRecognitionEntry): bool
    {
        return $this->authorizeAccountingAbility($user, 'view');
    }

    /** @return array<string, string> */
    protected function accountingPermissionMap(): array
    {
        return [
            'viewAny' => AccountingPermission::TaxView->value,
            'view' => AccountingPermission::TaxView->value,
        ];
    }
}
