<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\PurchaseAgreementStatus;
use App\Models\PurchaseAgreementLine;
use Illuminate\Support\Carbon;

final readonly class PurchaseAgreementPriceResolver
{
    public function resolve(int $supplierId, int $productVariantId, int $unitId, Carbon $onDate): ?PurchaseAgreementLine
    {
        return PurchaseAgreementLine::query()
            ->where('product_variant_id', $productVariantId)
            ->where('unit_id', $unitId)
            ->whereHas('agreement', static fn ($query) => $query
                ->where('supplier_id', $supplierId)
                ->where('status', PurchaseAgreementStatus::Active->value)
                ->whereDate('starts_on', '<=', $onDate->toDateString())
                ->where(static fn ($dates) => $dates
                    ->whereNull('ends_on')
                    ->orWhereDate('ends_on', '>=', $onDate->toDateString())))
            ->with('agreement')
            ->latest('id')
            ->first();
    }
}
