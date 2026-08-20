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
 * Two gates sit *outside* this matrix because they depend on data the enum
 * cannot see. `Sent -> Cancelled` is legal here but refused by
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
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Sent = 'sent';
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
            self::Sent, self::PartiallyReceived => true,
            default => false,
        };
    }

    /**
     * Whether the order's own fields and lines may still be changed (FR-025).
     *
     * Only a draft. Transmission is the immutability boundary, and approval is
     * upstream of it, so an approved-but-unsent order is already frozen: the
     * figure that was approved is the figure that gets sent.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
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
            self::Draft => [self::PendingApproval, self::Approved, self::Cancelled],
            self::PendingApproval => [self::Approved, self::Rejected, self::Cancelled],
            self::Rejected => [self::Draft, self::Cancelled],
            self::Approved => [self::Sent, self::Cancelled],
            self::Sent => [self::PartiallyReceived, self::Received, self::Closed, self::Cancelled],
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
