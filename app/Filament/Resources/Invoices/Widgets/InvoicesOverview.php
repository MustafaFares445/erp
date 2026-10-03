<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Widgets;

use App\Enums\SalesPermission;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Support\MoneyFormatter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

final class InvoicesOverview extends StatsOverviewWidget
{
    /** @var array<string, int> */
    protected int|array|null $columns = [
        'default' => 1,
        '@md' => 2,
        '@xl' => 3,
        '@5xl' => 5,
    ];

    #[\Override]
    public static function canView(): bool
    {
        return (bool) (auth()->user()?->can(SalesPermission::InvoiceView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $openReceivablesMinor = self::sumOutstandingMinor(Invoice::query()->active());
        $overdue = Invoice::query()->overdue()->count();
        $overdueMinor = self::sumOutstandingMinor(Invoice::query()->overdue());
        $unpaid = Invoice::query()->unpaid()->count();
        $partiallyPaid = Invoice::query()->partiallyPaid()->count();
        $draft = Invoice::query()->where('status', 'draft')->count();

        return [
            Stat::make(__('Open receivables'), MoneyFormatter::format($openReceivablesMinor))
                ->description(__('Outstanding across all issued invoices'))
                ->url(InvoiceResource::getUrl('index', ['tab' => 'needs_attention'])),
            Stat::make(__('Overdue invoices'), (string) $overdue)
                ->description($overdue > 0 ? MoneyFormatter::format($overdueMinor).' overdue' : 'Nothing overdue')
                ->color($overdue > 0 ? 'danger' : 'success')
                ->url(InvoiceResource::getUrl('index', ['tab' => 'overdue'])),
            Stat::make(__('Unpaid invoices'), (string) $unpaid)
                ->url(InvoiceResource::getUrl('index', ['tab' => 'unpaid'])),
            Stat::make(__('Partially paid'), (string) $partiallyPaid)
                ->url(InvoiceResource::getUrl('index', ['tab' => 'partially_paid'])),
            Stat::make(__('Draft invoices'), (string) $draft)
                ->description(__('Awaiting issue'))
                ->url(InvoiceResource::getUrl('index', ['tab' => 'draft'])),
        ];
    }

    /** @param  Builder<Invoice>  $query */
    private static function sumOutstandingMinor(Builder $query): int
    {
        $total = $query
            ->selectRaw('SUM(CASE WHEN (total_amount - amount_paid - credited_amount) > 0 THEN (total_amount - amount_paid - credited_amount) ELSE 0 END) as total')
            ->value('total');

        $totalAmount = is_numeric($total) ? (float) $total : 0.0;

        return (int) round($totalAmount * 100);
    }
}
