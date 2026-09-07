<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\InventoryAdjustment;
use App\Models\InventoryCount;
use App\Services\Inventory\InventoryCountService;

/**
 * Workflow status of an {@see InventoryCount} (GAP-MW-06).
 *
 * `Draft` is the scope-only state immediately after
 * {@see InventoryCountService::open()} generates the
 * sheet; the service moves it straight to `Counting` once lines exist.
 * `PendingReview` is reached only through
 * {@see InventoryCountService::submitForReview()},
 * which can also send it back to `Counting` for a further round of counting.
 * `Confirmed` and `Cancelled` are terminal — a confirmed count has produced
 * its one {@see InventoryAdjustment} (or none, if there was no
 * variance) and a cancelled count has posted nothing.
 */
enum InventoryCountStatus: string
{
    case Draft = 'draft';
    case Counting = 'counting';
    case PendingReview = 'pending_review';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Counting, self::Cancelled], true),
            self::Counting => in_array($target, [self::PendingReview, self::Cancelled], true),
            self::PendingReview => in_array($target, [self::Confirmed, self::Counting, self::Cancelled], true),
            self::Confirmed, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Confirmed || $this === self::Cancelled;
    }
}
