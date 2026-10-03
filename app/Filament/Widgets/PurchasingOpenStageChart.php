<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\BillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\PurchaseOrder;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Open purchase orders by the stage holding them up right now, optionally
 * for one supplier. A current-state view, so it ignores the date range.
 */
final class PurchasingOpenStageChart extends ChartWidget
{
    use InteractsWithDashboardFilters;

    protected ?string $maxHeight = '300px';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderView->value) ?? false;
    }

    #[\Override]
    public function getHeading(): string
    {
        return __('dashboards.purchasing.charts.open_by_stage');
    }

    #[\Override]
    protected function getData(): array
    {
        $approval = $this->orders()
            ->where('status', PurchaseOrderStatus::PendingApproval->value)
            ->count();

        $readyToSend = $this->orders()
            ->where('status', PurchaseOrderStatus::Accepted->value)
            ->whereNull('sent_at')
            ->count();

        $awaitingSupplier = $this->orders()
            ->whereNotNull('sent_at')
            ->whereHas('confirmations', static fn (Builder $query): Builder => $query->where('confirmation_status', 'pending'))
            ->count();

        $receiving = $this->orders()
            ->whereNotNull('sent_at')
            ->whereIn('status', [
                PurchaseOrderStatus::Accepted->value,
                PurchaseOrderStatus::PartiallyReceived->value,
            ])
            ->whereDoesntHave('confirmations', static fn (Builder $query): Builder => $query->where('confirmation_status', 'pending'))
            ->count();

        $accounting = $this->orders()
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
                'label' => __('dashboards.purchasing.charts.purchase_orders'),
                'data' => [$approval, $readyToSend, $awaitingSupplier, $receiving, $accounting],
                'backgroundColor' => ['#f59e0b', '#3b82f6', '#8b5cf6', '#22c55e', '#64748b'],
            ]],
            'labels' => [
                __('dashboards.purchasing.stages.approval'),
                __('dashboards.purchasing.stages.ready_to_send'),
                __('dashboards.purchasing.stages.supplier'),
                __('dashboards.purchasing.stages.receiving'),
                __('dashboards.purchasing.stages.accounting'),
            ],
        ];
    }

    #[\Override]
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }

    #[\Override]
    protected function getType(): string
    {
        return 'bar';
    }

    /** @return Builder<PurchaseOrder> */
    private function orders(): Builder
    {
        return PurchaseOrder::query()
            ->when($this->dashboardFilter('supplierId'), static fn (Builder $query, int $supplierId): Builder => $query->where('supplier_id', $supplierId));
    }
}
