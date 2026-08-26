<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SalesPermission;
use App\Models\Quotation;
use App\Models\User;
use App\Policies\Concerns\ChecksSalesPermissions;

/**
 * Quotation authorization.
 *
 * `manage` (create/update/delete) does not imply `decide` or `convert`
 * (FR-072, contracts/permissions.md §1): drafting an offer is our own
 * record, while recording the customer's accept/reject commits the company
 * to a price on a third party's behalf, and conversion is what enters the
 * fulfilment machinery.
 *
 * `update` and `delete` are refused outright once the quotation has been
 * sent — content immutability is an invariant, not a privilege, matching
 * the `JournalEntry` / `Invoice` precedent.
 */
final class QuotationPolicy
{
    use ChecksSalesPermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'create');
    }

    public function update(User $user, Quotation $quotation): bool
    {
        if ($quotation->isFrozen()) {
            return false;
        }

        return $this->authorizeSalesAbility($user, 'update');
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        if ($quotation->isFrozen()) {
            return false;
        }

        return $this->authorizeSalesAbility($user, 'delete');
    }

    public function send(User $user, Quotation $quotation): bool
    {
        return $this->authorizeSalesAbility($user, 'update');
    }

    public function decide(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'decide');
    }

    public function convert(User $user): bool
    {
        return $this->authorizeSalesAbility($user, 'convert');
    }

    /** @return array<string, string> */
    protected function salesPermissionMap(): array
    {
        return [
            'viewAny' => SalesPermission::QuotationView->value,
            'view' => SalesPermission::QuotationView->value,
            'create' => SalesPermission::QuotationManage->value,
            'update' => SalesPermission::QuotationManage->value,
            'delete' => SalesPermission::QuotationManage->value,
            'decide' => SalesPermission::QuotationDecide->value,
            'convert' => SalesPermission::QuotationConvert->value,
        ];
    }
}
