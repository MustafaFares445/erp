<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SalesPermission;
use App\Models\Invoice;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class SalesRevenueTrend extends ChartWidget
{
    protected ?string $heading = 'Revenue trend';

    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();
        if ($user?->can(SalesPermission::QuotationView->value) ?? false) {
            return true;
        }
        if ($user?->can(SalesPermission::OrderView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(SalesPermission::InvoiceView->value) ?? false);
    }

    #[\Override]
    protected function getData(): array
    {
        /** @var Collection<int, Carbon> $months */
        $months = collect(range(5, 0))
            ->map(fn (int $offset): Carbon => now()->startOfMonth()->subMonths($offset));

        /** @var Collection<int, Invoice> $invoices */
        $invoices = Invoice::query()
            ->whereBetween('invoice_date', [$months->first()->toDateString(), now()->endOfMonth()->toDateString()])
            ->get(['invoice_date', 'total_amount']);

        /** @var Collection<string, Collection<int, Invoice>> $totalsByMonth */
        $totalsByMonth = $invoices->groupBy(fn (Invoice $invoice): string => $invoice->invoice_date->format('Y-m'));

        return [
            'datasets' => [[
                'label' => 'Revenue',
                'data' => $months
                    ->map(fn (Carbon $month): float => (float) ($totalsByMonth->get($month->format('Y-m'))?->sum('total_amount') ?? 0))
                    ->all(),
            ]],
            'labels' => $months->map(fn (Carbon $month): string => $month->format('M Y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
