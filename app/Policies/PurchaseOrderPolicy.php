<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PurchasePermission;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Policies\Concerns\ChecksPurchasePermissions;

/**
 * Purchase order authorization.
 *
 * Each lifecycle step is its own ability rather than a shared `update`, because
 * the whole point of the approval gate is that submitting, approving, sending,
 * cancelling, and short-closing are *different* privileges
 * (contracts/permissions.md §2).
 *
 * Two rules outrank permission entirely and so take the record, not just the
 * user: {@see self::update()} refuses once the order has left draft (R-C), and
 * {@see self::cancel()} refuses once a receipt has completed (R-D). A user with
 * every purchasing permission still cannot edit a sent order.
 */
final class PurchaseOrderPolicy
{
    use ChecksPurchasePermissions;

    public function viewAny(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'viewAny');
    }

    public function view(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'create');
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status->isEditable()
            && $this->authorizePurchaseAbility($user, 'update');
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status->isEditable()
            && $this->authorizePurchaseAbility($user, 'delete');
    }

    public function restore(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'restore');
    }

    public function submit(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status->isEditable()
            && $this->authorizePurchaseAbility($user, 'submit');
    }

    public function approve(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'approve');
    }

    public function send(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'send');
    }

    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return ! $purchaseOrder->hasCompletedReceipt()
            && $this->authorizePurchaseAbility($user, 'cancel');
    }

    public function close(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'close');
    }

    public function receive(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status->isReceivable()
            && $this->authorizePurchaseAbility($user, 'receive');
    }

    public function viewAudit(User $user): bool
    {
        return $this->authorizePurchaseAbility($user, 'viewAudit');
    }

    /** @return array<string, string> */
    protected function purchasePermissionMap(): array
    {
        return [
            'viewAny' => PurchasePermission::OrderView->value,
            'view' => PurchasePermission::OrderView->value,
            'create' => PurchasePermission::OrderManage->value,
            'update' => PurchasePermission::OrderManage->value,
            'delete' => PurchasePermission::OrderManage->value,
            'restore' => PurchasePermission::RecordRestore->value,
            'submit' => PurchasePermission::OrderSubmit->value,
            'approve' => PurchasePermission::OrderApprove->value,
            'send' => PurchasePermission::OrderSend->value,
            'cancel' => PurchasePermission::OrderCancel->value,
            'close' => PurchasePermission::OrderClose->value,
            'receive' => PurchasePermission::OrderReceive->value,
            'viewAudit' => PurchasePermission::AuditView->value,
        ];
    }
}
