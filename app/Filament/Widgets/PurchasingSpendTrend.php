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
    protected ?string $heading = 'PO spend by month';

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
            throw new \LogicException('The trailing month range must not be empty.');
        }

        /** @var Collection<int, PurchaseOrder> $orders */
        $orders = PurchaseOrder::query()
            ->whereBetween('ordered_at', [$firstMonth->toDateString(), now()->endOfMonth()->toDateString()])
            ->get(['ordered_at', 'total_amount']);

        /** @var Collection<string, Collection<int, PurchaseOrder>> $totalsByMonth */
        $totalsByMonth = $orders->groupBy(fn (PurchaseOrder $order): string => $order->ordered_at->format('Y-m'));

        return [
            'datasets' => [[
                'label' => 'PO spend',
                'data' => $months
                    ->map(function (Carbon $month) use ($totalsByMonth): float {
                        $total = $totalsByMonth->get($month->format('Y-m'))?->sum('total_amount') ?? 0;

                        return is_numeric($total) ? (float) $total : 0.0;
                    })
                    ->all(),
            ]],
            'labels' => $months->map(fn (Carbon $month): string => $month->format('M Y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
