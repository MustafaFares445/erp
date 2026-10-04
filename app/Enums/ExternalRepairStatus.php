<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Where a supplier / manufacturer repair (RMA) stands. */
enum ExternalRepairStatus: string implements HasColor, HasLabel
{
    use HasTranslatedLabel;

    case Requested = 'requested';
    case Approved = 'approved';
    case ShippedToSupplier = 'shipped_to_supplier';
    case ReceivedBySupplier = 'received_by_supplier';
    case Repairing = 'repairing';
    case Repaired = 'repaired';
    case ReplacementApproved = 'replacement_approved';
    case ReplacementReceived = 'replacement_received';
    case ReturnedToCompany = 'returned_to_company';
    case Cancelled = 'cancelled';

    public function isClosed(): bool
    {
        return in_array($this, [self::ReplacementReceived, self::ReturnedToCompany, self::Cancelled], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => ! $status->isClosed()),
        ));
    }

    /** @return list<self> */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Cancelled],
            self::Approved => [self::ShippedToSupplier, self::Cancelled],
            self::ShippedToSupplier => [self::ReceivedBySupplier],
            self::ReceivedBySupplier => [self::Repairing],
            self::Repairing => [self::Repaired, self::ReplacementApproved],
            self::Repaired => [self::ReturnedToCompany],
            self::ReplacementApproved => [self::ReplacementReceived],
            self::ReplacementReceived, self::ReturnedToCompany, self::Cancelled => [],
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested, self::Approved => 'info',
            self::ShippedToSupplier, self::ReceivedBySupplier, self::Repairing => 'warning',
            self::Repaired, self::ReplacementApproved => 'primary',
            self::ReplacementReceived, self::ReturnedToCompany => 'success',
            self::Cancelled => 'gray',
        };
    }
}
