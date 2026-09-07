<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SalesPermission;
use App\Enums\SalesReportType;
use App\Filament\Resources\SalesReports\SalesReportResource;
use App\Models\User;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Sales\SalesReportService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * SL-15's two named leak points, surfaced on the Sales dashboard: a
 * completed delivery not yet invoiced, and an issued invoice not yet
 * collected. Both figures come straight from {@see SalesReportService} —
 * {@see SalesReportService::invoicedNotCollected()} delegates entirely to
 * {@see AccountsReceivableService::aging()}, so
 * this widget can never disagree with the AR module for the same figure.
 */
final class SalesLeakage extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public static function canView(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can(SalesPermission::ReportView->value);
    }

    #[\Override]
    protected function getStats(): array
    {
        $service = app(SalesReportService::class);

        $delivered = $service->deliveredNotInvoiced();
        $deliveredCount = is_int($delivered['count'] ?? null) ? $delivered['count'] : 0;

        $invoiced = $service->invoicedNotCollected();
        $outstandingMinor = is_int($invoiced['outstanding_minor'] ?? null) ? $invoiced['outstanding_minor'] : 0;

        return [
            Stat::make('Delivered, not invoiced', (string) $deliveredCount)
                ->description('Completed deliveries with no linked invoice, as of today')
                ->icon(Heroicon::OutlinedTruck)
                ->color($deliveredCount > 0 ? 'warning' : 'success')
                ->url(SalesReportResource::getUrl('index', ['reportType' => SalesReportType::DeliveredNotInvoiced->value])),
            Stat::make('Invoiced, not collected', number_format($outstandingMinor / 100, 2))
                ->description('Outstanding receivable balance, as of today (from AR aging)')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color($outstandingMinor > 0 ? 'warning' : 'success')
                ->url(SalesReportResource::getUrl('index', ['reportType' => SalesReportType::InvoicedNotCollected->value])),
        ];
    }
}
