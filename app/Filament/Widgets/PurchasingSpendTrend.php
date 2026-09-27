<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\PurchasePermission;
use App\Models\PurchaseOrder;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class PurchasingSpendTrend extends ChartWidget
{
    protected ?string $heading = 'PO spend by month and currency';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderView->value) ?? false;
    }

    #[\Override]
    protected function getData(): array
    {
        /** @var Collection<int, Carbon> $months */
        $months = collect(range(5, 0))
            ->map(fn (int $offset): Carbon => now()->startOfMonth()->subMonths($offset));

        $firstMonth = $months->first();

        if (! $firstMonth instanceof Carbon) {
            return ['datasets' => [], 'labels' => []];
        }

        /** @var Collection<int, PurchaseOrder> $orders */
        $orders = PurchaseOrder::query()
            ->whereBetween('ordered_at', [$firstMonth->toDateString(), now()->endOfMonth()->toDateString()])
            ->get(['ordered_at', 'total_amount', 'currency_code']);

        $currencies = $orders->pluck('currency_code')->filter()->unique()->sort()->values();

        $datasets = $currencies->map(function (mixed $currency) use ($orders, $months): array {
            $code = (string) $currency;
            $currencyOrders = $orders->where('currency_code', $code);

            return [
                'label' => "PO spend · {$code}",
                'data' => $months->map(function (Carbon $month) use ($currencyOrders): float {
                    return (float) $currencyOrders
                        ->filter(fn (PurchaseOrder $order): bool => $order->ordered_at->format('Y-m') === $month->format('Y-m'))
                        ->sum('total_amount');
                })->all(),
            ];
        })->values()->all();

        return [
            'datasets' => $datasets,
            'labels' => $months->map(fn (Carbon $month): string => $month->format('M Y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
