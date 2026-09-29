<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\BillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Models\PurchaseOrder;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

final class PurchasingOpenStageChart extends ChartWidget
{
    protected ?string $heading = 'Open Purchase Orders by stage';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderView->value) ?? false;
    }

    #[\Override]
    protected function getData(): array
    {
        $approval = PurchaseOrder::query()
            ->where('status', PurchaseOrderStatus::PendingApproval->value)
            ->count();

        $readyToSend = PurchaseOrder::query()
            ->where('status', PurchaseOrderStatus::Accepted->value)
            ->whereNull('sent_at')
            ->count();

        $awaitingSupplier = PurchaseOrder::query()
            ->whereNotNull('sent_at')
            ->whereHas('confirmations', static fn (Builder $query): Builder => $query->where('confirmation_status', 'pending'))
            ->count();
        $receiving = PurchaseOrder::query()
            ->whereNotNull('sent_at')
            ->whereIn('status', [
                PurchaseOrderStatus::Accepted->value,
                PurchaseOrderStatus::PartiallyReceived->value,
            ])
            ->whereDoesntHave('confirmations', static fn (Builder $query): Builder => $query->where('confirmation_status', 'pending'))
            ->count();

        $accounting = PurchaseOrder::query()
            ->where('status', PurchaseOrderStatus::Received->value)
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('bills')
                    ->orWhereHas('bills', static fn (Builder $bills): Builder => $bills->whereNotIn('status', [
                        BillStatus::Paid->value,
                        BillStatus::Cancelled->value,
                    ]));
            })
            ->count();

        return [
            'datasets' => [[
                'label' => 'Purchase Orders',
                'data' => [$approval, $readyToSend, $awaitingSupplier, $receiving, $accounting],
            ]],
            'labels' => ['Approval', 'Ready to send', 'Supplier', 'Receiving', 'Accounting'],
        ];
    }

    #[\Override]
    protected function getType(): string
    {
        return 'bar';
    }
}
