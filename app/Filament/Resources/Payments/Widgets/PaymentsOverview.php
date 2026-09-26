<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Widgets;

use App\Enums\SalesPermission;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

final class PaymentsOverview extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        return (bool) (auth()->user()?->can(SalesPermission::PaymentView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $collectedByCurrency = Payment::query()->collectedThisMonth()
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $collectedCount = Payment::query()->collectedThisMonth()->count();
        $unallocated = Payment::query()->unallocated()->count();

        return [
            Stat::make('Collected this month', $collectedCount)
                ->description(self::formatByCurrency($collectedByCurrency))
                ->url(PaymentResource::getUrl('index', ['activeTab' => 'collected_this_month'])),
            Stat::make('Unallocated / requires review', $unallocated)
                ->description('Posted, not fully applied to an invoice')
                ->url(PaymentResource::getUrl('index', ['activeTab' => 'unallocated'])),
        ];
    }

    /** @param  Collection<string, mixed>  $byCurrency */
    private static function formatByCurrency(Collection $byCurrency): string
    {
        if ($byCurrency->isEmpty()) {
            return 'No payments collected this month';
        }

        return $byCurrency
            ->map(static function (mixed $total, string $currency): string {
                $amount = is_numeric($total) ? (float) $total : 0.0;
                $formatted = Number::currency($amount, $currency);

                return $formatted === false ? "{$currency} {$amount}" : $formatted;
            })
            ->implode(' · ');
    }
}
