<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\SalesPermission;
use App\Models\InventoryOperation;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryPermissions;
use App\Services\Inventory\InventoryOperationService;

/**
 * Authorizes the {@see InventoryOperation} lifecycle (US1, contracts/inventory-operations.md).
 *
 * Unlike the single-type policies it replaces, one operation covers three permission families —
 * Receipt, Delivery, Internal Transfer — so the ability→permission mapping must resolve per
 * record via `operation_type`, not through the fixed map
 * {@see ChecksInventoryPermissions} assumes. `create`/`update`/`delete`
 * reuse the type's `*Create` permission — segregation between "create" and "confirm" mirrors
 * {@see StockTransferPolicy} (FR-022/FR-023). `update` and `delete` are further restricted to
 * `Draft` (V-04); the stage-transition abilities each require the stage the corresponding
 * {@see InventoryOperationService} method demands.
 */
final class InventoryOperationPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->can(SalesPermission::DeliveryNoteView->value)) {
            return true;
        }

        if ($user->can(InventoryPermission::ReceiptView->value)) {
            return true;
        }

        if ($user->can(InventoryPermission::DeliveryView->value)) {
            return true;
        }

        return $user->can(InventoryPermission::TransferView->value);
    }

    public function view(User $user, InventoryOperation $operation): bool
    {
        if ($operation->operation_type === OperationType::Delivery
            && $user->can(SalesPermission::DeliveryNoteView->value)) {
            return true;
        }

        return $user->can($this->permission($operation->operation_type, 'view'));
    }

    public function create(User $user): bool
    {
        if ($user->can(InventoryPermission::ReceiptCreate->value)) {
            return true;
        }

        if ($user->can(InventoryPermission::DeliveryCreate->value)) {
            return true;
        }

        return $user->can(InventoryPermission::TransferCreate->value);
    }

    /**
     * Type-specific create check, used by the create page for each of the three navigation
     * entries before `operation_type` exists on a record to authorize against.
     */
    public function createType(User $user, OperationType $type): bool
    {
        return $user->can($this->permission($type, 'create'));
    }

    public function update(User $user, InventoryOperation $operation): bool
    {
        if (! $user->can($this->permission($operation->operation_type, 'create'))) {
            return false;
        }

        return $operation->isDraft();
    }

    public function delete(User $user, InventoryOperation $operation): bool
    {
        if (! $user->can($this->permission($operation->operation_type, 'create'))) {
            return false;
        }

        return $operation->isDraft();
    }

    /** Guard for {@see InventoryOperationService::markReady()}. */
    public function markReady(User $user, InventoryOperation $operation): bool
    {
        return $user->can($this->permission($operation->operation_type, 'confirm'))
            && in_array($operation->stage, [OperationStage::Draft, OperationStage::Waiting], true);
    }

    /** Guard for {@see InventoryOperationService::dispatch()}. */
    public function dispatch(User $user, InventoryOperation $operation): bool
    {
        return $operation->operation_type === OperationType::InternalTransfer
            && $user->can($this->permission($operation->operation_type, 'confirm'))
            && $operation->isReady();
    }

    /** Guard for {@see InventoryOperationService::complete()}. */
    public function complete(User $user, InventoryOperation $operation): bool
    {
        $requiredStage = $operation->operation_type === OperationType::InternalTransfer
            ? OperationStage::InTransit
            : OperationStage::Ready;

        return $user->can($this->permission($operation->operation_type, 'confirm'))
            && $operation->stage === $requiredStage;
    }

    /** Guard for {@see InventoryOperationService::cancel()}. */
    public function cancel(User $user, InventoryOperation $operation): bool
    {
        return $user->can($this->permission($operation->operation_type, 'confirm'))
            && ! $operation->isTerminal();
    }

    public function restore(User $user): bool
    {
        return $this->create($user);
    }

    public function forceDelete(): bool
    {
        return false;
    }

    public function deleteAny(): bool
    {
        return false;
    }

    public function forceDeleteAny(): bool
    {
        return false;
    }

    public function restoreAny(): bool
    {
        return false;
    }

    private function permission(OperationType $type, string $suffix): string
    {
        return match ($type) {
            OperationType::Receipt => match ($suffix) {
                'view' => InventoryPermission::ReceiptView->value,
                'create' => InventoryPermission::ReceiptCreate->value,
                default => InventoryPermission::ReceiptConfirm->value,
            },
            OperationType::Delivery => match ($suffix) {
                'view' => InventoryPermission::DeliveryView->value,
                'create' => InventoryPermission::DeliveryCreate->value,
                default => InventoryPermission::DeliveryConfirm->value,
            },
            OperationType::InternalTransfer => match ($suffix) {
                'view' => InventoryPermission::TransferView->value,
                'create' => InventoryPermission::TransferCreate->value,
                default => InventoryPermission::TransferConfirm->value,
            },
        };
    }
}
