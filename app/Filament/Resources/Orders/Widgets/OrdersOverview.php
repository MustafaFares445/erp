<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Widgets;

use App\Enums\SalesPermission;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Settings\CurrencyCatalogService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

final class OrdersOverview extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        return (bool) (auth()->user()?->can(SalesPermission::OrderView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $activeCount = Order::query()->active()->count();
        $activeValue = (float) Order::query()->active()->sum('grand_total');
        $awaitingFulfillment = Order::query()->awaitingFulfillment()->count();
        $blocked = Order::query()->blocked()->count();
        $currency = app(CurrencyCatalogService::class)->defaultCode();

        return [
            Stat::make(__('Active orders'), $activeCount)
                ->description(self::formatMoney($activeValue, $currency).' in progress')
                ->url(OrderResource::getUrl('index', ['tab' => 'active'])),
            Stat::make(__('Awaiting fulfillment'), $awaitingFulfillment)
                ->description(__('Confirmed, not yet released'))
                ->url(OrderResource::getUrl('index', ['tab' => 'awaiting_fulfillment'])),
            Stat::make(__('Requires attention'), $blocked)
                ->description(__('Released, blocked on procurement'))
                ->url(OrderResource::getUrl('index', ['tab' => 'requires_attention'])),
        ];
    }

    private static function formatMoney(float $amount, string $currency): string
    {
        $formatted = Number::currency($amount, $currency);

        return $formatted === false ? "{$currency} {$amount}" : $formatted;
    }
}
