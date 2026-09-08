<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Purchasing\PurchaseOrderApprovalService;

/**
 * A purchase order's lifecycle (data-model.md §8).
 *
 * The transition matrix lives here rather than in the service for the same
 * reason {@see OperationStage} keeps its own: it is the one rule every caller
 * needs and no caller should restate.
 *
 * `Accepted` is the cross-module activation point (Phase 0 remediation):
 * supplier communication (`sent_at`) is audit metadata recorded on top of
 * `Accepted`, not a separate lifecycle state — it never gates receiving,
 * warehouse allocation, or downstream record creation.
 *
 * One gate sits *outside* this matrix because it depends on data the enum
 * cannot see: `Accepted -> Cancelled` is legal here but refused by
 * {@see PurchaseOrderApprovalService::cancel()} once any receipt has completed
 * (FR-026), and `PartiallyReceived` has no `Cancelled` target at all — by
 * definition a receipt has already completed against it, so the short-close
 * path is the only way out.
 *
 * @see /specs/017-purchasing-orders-suppliers/data-model.md §8
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->permittedTargets(), true);
    }

    /**
     * Whether a receipt may be initiated against an order in this state (FR-036).
     */
    public function isReceivable(): bool
    {
        return match ($this) {
            self::Accepted, self::PartiallyReceived => true,
            default => false,
        };
    }

    /**
     * Whether the order's own fields and lines may still be changed (FR-025).
     *
     * Only a draft. Acceptance is the immutability boundary, and approval is
     * upstream of it, so a pending-approval order is already frozen: the
     * figure that was submitted is the figure that gets accepted.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the order has passed acceptance and so may record supplier
     * communication metadata (`sent_at`). Communication is not itself a
     * lifecycle state, so this is a range check rather than a single case.
     */
    public function isAcceptedOrLater(): bool
    {
        return match ($this) {
            self::Accepted, self::PartiallyReceived, self::Received, self::Closed => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Received, self::Closed, self::Cancelled => true,
            default => false,
        };
    }

    public function label(): string
    {
        return __('admin.purchasing.order_status.'.$this->value);
    }

    /** @return list<self> */
    private function permittedTargets(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Accepted, self::Cancelled],
            self::PendingApproval => [self::Accepted, self::Rejected, self::Cancelled],
            self::Rejected => [self::Draft, self::Cancelled],
            self::Accepted => [self::PartiallyReceived, self::Received, self::Closed, self::Cancelled],
            self::PartiallyReceived => [self::Received, self::Closed],
            self::Received, self::Closed, self::Cancelled => [],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
