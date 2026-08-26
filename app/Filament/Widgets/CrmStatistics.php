<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\CrmPermission;
use App\Models\CustomerProfile;
use App\Models\PriceFloorOverride;
use App\Models\PriceHistory;
use App\Models\PricingTier;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class CrmStatistics extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(CrmPermission::CustomerView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        $activeCustomers = CustomerProfile::query()->where('is_active', true)->count();

        $activePricingTiers = PricingTier::query()->current()->count();

        /**
         * Price floor overrides have no active/expiry column — each row is
         * an immutable, permanently-approved exception (see
         * {@see PriceFloorOverride::booted()}) — so every recorded override
         * remains in effect indefinitely.
         */
        $activePriceFloorOverrides = PriceFloorOverride::query()->count();

        $priceChangesThisMonth = PriceHistory::query()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        return [
            Stat::make('Active customers', $activeCustomers),
            Stat::make('Active pricing tiers', $activePricingTiers),
            Stat::make('Active price floor overrides', $activePriceFloorOverrides),
            Stat::make('Price changes this month', $priceChangesThisMonth),
        ];
    }
}
