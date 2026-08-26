<?php

declare(strict_types=1);

namespace App\Data\Purchasing;

use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

/** @phpstan-type ConfirmationItemInput array{product_variant_id: int, requested_quantity: float, notes?: string|null} */
final class SupplierConfirmationRequestData extends Data
{
    /** @param list<ConfirmationItemInput> $items */
    public function __construct(
        public ?Model $target,
        public ?CustomerProfile $customer,
        public int $supplierId,
        public array $items,
        public ?string $notes = null,
    ) {}
}
