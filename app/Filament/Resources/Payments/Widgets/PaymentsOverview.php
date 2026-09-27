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
        $depositsByCurrency = Payment::query()->customerDeposits()
            ->selectRaw('currency, SUM(amount - (select coalesce(sum(payment_allocations.amount), 0) from payment_allocations where payment_allocations.payment_id = payments.id)) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');
        $draftCount = Payment::query()->where('status', 'draft')->count();
        $reversedThisMonth = Payment::query()->whereNotNull('reversed_at')
            ->whereBetween('reversed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        return [
            Stat::make(__('admin.sales.payment_tabs.collected_this_month'), $collectedCount)
                ->description(self::formatByCurrency($collectedByCurrency))
                ->url(PaymentResource::getUrl('index', ['activeTab' => 'collected_this_month'])),
            Stat::make(__('admin.sales.payment_tabs.customer_deposits'), self::formatByCurrency($depositsByCurrency))
                ->description(__('admin.sales.payment_ui.deposit_stat_description'))
                ->url(PaymentResource::getUrl('index', ['activeTab' => 'customer_deposits'])),
            Stat::make(__('admin.sales.payment_tabs.draft'), $draftCount)
                ->description(__('admin.sales.payment_ui.draft_description'))
                ->url(PaymentResource::getUrl('index', ['activeTab' => 'draft'])),
            Stat::make(__('admin.sales.payment_ui.reversed_this_month'), $reversedThisMonth)
                ->description(__('admin.sales.payment_ui.reversed_description'))
                ->url(PaymentResource::getUrl('index', ['activeTab' => 'reversed'])),
        ];
    }

    /** @param  Collection<string, mixed>  $byCurrency */
    private static function formatByCurrency(Collection $byCurrency): string
    {
        if ($byCurrency->isEmpty()) {
            return '—';
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
