<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\OrderPaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesPermission;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class SalesStatistics extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->can(SalesPermission::QuotationView->value) ?? false)
            || ($user?->can(SalesPermission::OrderView->value) ?? false)
            || ($user?->can(SalesPermission::InvoiceView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        $openQuotations = Quotation::query()
            ->whereIn('status', [QuotationStatus::Draft->value, QuotationStatus::Sent->value])
            ->count();

        $ordersAwaitingPayment = Order::query()
            ->whereIn('payment_status', [OrderPaymentStatus::Unpaid->value, OrderPaymentStatus::PartiallyPaid->value])
            ->count();

        $overdueOutstanding = (float) (Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereDate('due_date', '<', today()->toDateString())
            ->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as outstanding')
            ->value('outstanding') ?? 0);

        $paymentsThisMonth = (float) Payment::query()
            ->whereNull('reversed_at')
            ->whereBetween('payment_date', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()])
            ->sum('amount');

        return [
            Stat::make('Open quotations', $openQuotations),
            Stat::make('Orders awaiting payment', $ordersAwaitingPayment),
            Stat::make('Invoices overdue', $this->formatMoney($overdueOutstanding)),
            Stat::make('Payments received this month', $this->formatMoney($paymentsThisMonth)),
        ];
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2);
    }
}
