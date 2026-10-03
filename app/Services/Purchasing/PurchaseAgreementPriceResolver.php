<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\PurchaseAgreementStatus;
use App\Models\PurchaseAgreementLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final readonly class PurchaseAgreementPriceResolver
{
    public function resolve(
        int $supplierId,
        int $productVariantId,
        int $unitId,
        Carbon $onDate,
        ?string $currencyCode = null,
    ): ?PurchaseAgreementLine {
        return PurchaseAgreementLine::query()
            ->where('product_variant_id', $productVariantId)
            ->where('unit_id', $unitId)
            ->whereHas('agreement', static fn (Builder $query): Builder => $query
                ->where('supplier_id', $supplierId)
                ->where('status', PurchaseAgreementStatus::Active->value)
                ->when($currencyCode !== null, static fn (Builder $agreement): Builder => $agreement->where('currency_code', mb_strtoupper((string) $currencyCode)))
                ->whereDate('starts_on', '<=', $onDate->toDateString())
                ->where(static fn (Builder $dates): Builder => $dates
                    ->whereNull('ends_on')
                    ->orWhereDate('ends_on', '>=', $onDate->toDateString())))
            ->with('agreement')
            ->latest('id')
            ->first();
    }
}
