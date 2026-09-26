<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Widgets;

use App\Enums\SalesPermission;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class InvoicesOverview extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        return (bool) (auth()->user()?->can(SalesPermission::InvoiceView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $issuedThisMonth = Invoice::query()->issuedThisMonth()->count();
        $unpaid = Invoice::query()->unpaid()->count();
        $partiallyPaid = Invoice::query()->partiallyPaid()->count();
        $overdue = Invoice::query()->overdue()->count();

        return [
            Stat::make('Issued this month', $issuedThisMonth)
                ->url(InvoiceResource::getUrl('index', ['activeTab' => 'issued_this_month'])),
            Stat::make('Unpaid', $unpaid)
                ->url(InvoiceResource::getUrl('index', ['activeTab' => 'unpaid'])),
            Stat::make('Partially paid', $partiallyPaid)
                ->url(InvoiceResource::getUrl('index', ['activeTab' => 'partially_paid'])),
            Stat::make('Overdue', $overdue)
                ->description('Past due date with a balance remaining')
                ->url(InvoiceResource::getUrl('index', ['activeTab' => 'overdue'])),
        ];
    }
}
