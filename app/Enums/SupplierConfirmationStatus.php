<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Purchasing\SupplierConfirmationService;

/**
 * The state of one supplier confirmation record (data-model.md §8).
 *
 * `Pending` is what an admin records when the supplier has been asked but has
 * not answered. Answering moves it once, to `Confirmed` or `Rejected`, and
 * there it stays: an answered confirmation is evidence, and evidence is
 * append-only (FR-031). A supplier who changes their mind produces a new row,
 * which is why {@see SupplierConfirmationService} exposes no amend path.
 *
 * @see /specs/017-purchasing-orders-suppliers/research.md R-007
 */
enum SupplierConfirmationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function isAnswered(): bool
    {
        return $this !== self::Pending;
    }

    public function canTransitionTo(self $target): bool
    {
        return $this === self::Pending && $target->isAnswered();
    }

    public function label(): string
    {
        return __('admin.purchasing.confirmation_status.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
