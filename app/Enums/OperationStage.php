<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\InventoryOperation;

/**
 * The lifecycle stage of an {@see InventoryOperation} (FR-002, data-model.md §1).
 *
 * `InTransit` applies only to {@see OperationType::InternalTransfer} (V-03). This is the sole
 * per-type difference in the whole lifecycle — every other stage and transition is identical
 * across Receipt, Delivery and Internal Transfer, which is the point of this feature.
 */
enum OperationStage: string
{
    case Draft = 'draft';
    case Waiting = 'waiting';
    case Ready = 'ready';
    case InTransit = 'in_transit';
    case PartiallyReceived = 'partially_received';
    case Done = 'done';
    case Canceled = 'canceled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Canceled => true,
            default => false,
        };
    }

    /**
     * Whether moving from this stage to $target is legal for the given operation type
     * (data-model.md §1, R-001).
     *
     * `Ready` is where the type-specific fork lives: a receipt or delivery moves straight to
     * `Done`, but an internal transfer MUST pass through `InTransit` first — the custody rule
     * requires the source to lose on-hand at `InTransit`, so allowing `Ready → Done` directly for
     * a transfer would skip that decrement and silently break the invariant that a warehouse's
     * balance changes exactly when its custody changes.
     */
    public function canTransitionTo(self $target, OperationType $type): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Waiting, self::Ready, self::Canceled], true),
            self::Waiting => in_array($target, [self::Ready, self::Canceled], true),
            self::Ready => match ($type) {
                OperationType::InternalTransfer => in_array($target, [self::InTransit, self::Waiting, self::Canceled], true),
                OperationType::Receipt, OperationType::Delivery => in_array($target, [self::Done, self::Waiting, self::Canceled], true),
            },
            self::InTransit => $type === OperationType::InternalTransfer
                && in_array($target, [self::PartiallyReceived, self::Done, self::Canceled], true),
            self::PartiallyReceived => $type === OperationType::InternalTransfer
                && in_array($target, [self::Done, self::Canceled], true),
            self::Done, self::Canceled => false,
        };
    }

    public function label(): string
    {
        return __('admin.inventory.operation.stages.'.$this->value);
    }
}
