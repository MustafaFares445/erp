<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Widgets\Concerns\BuildsTrendStats;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequirement;
use App\Models\SalesProcurementRequirement;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use App\Services\Settings\CurrencyCatalogService;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\MoneyFormatter;
use App\Support\QuantityFormatter;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Purchasing's four headline cards: spend in the selected window (default
 * currency only — there is no cross-currency summation), the sourcing
 * backlog, POs awaiting approval and overdue deliveries. The finer-grained
 * work queues live in the attention table and the stage chart.
 */
final class PurchasingStatistics extends StatsOverviewWidget
{
    use BuildsTrendStats;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        return [
            $this->spendStat(),
            $this->needsSourcingStat(),
            $this->awaitingApprovalStat(),
            $this->overdueStat(),
        ];
    }

    private function spendStat(): Stat
    {
        $period = $this->dashboardPeriod();
        $currency = app(CurrencyCatalogService::class)->defaultCode();

        $current = $this->ordersInCurrency($currency, $period->from, $period->to)->get(['ordered_at', 'total_amount']);
        $previous = DashboardPeriod::toFloat($this->ordersInCurrency($currency, $period->previousFrom, $period->previousTo)->sum('total_amount'));
        $total = DashboardPeriod::toFloat($current->sum('total_amount'));

        return $this->trendStat(
            __('dashboards.purchasing.kpis.spend', ['currency' => $currency]),
            MoneyFormatter::formatAmount($total, $currency),
            $total,
            $previous,
            $period->sumSeries($current->map(static fn (PurchaseOrder $order): array => [$order->ordered_at, $order->total_amount])),
            Heroicon::OutlinedBanknotes,
            PurchaseOrderResource::getUrl('index'),
            higherIsBetter: false,
        );
    }

    private function needsSourcingStat(): Stat
    {
        [$inventoryNeeds, $inventoryQuantity] = $this->inventoryPurchaseNeeds();
        $salesNeeds = SalesProcurementRequirement::query()
            ->whereNotIn('status', ['fulfilled', 'cancelled', 'superseded'])
            ->whereNull('purchase_order_id')
            ->count();
        $needsSourcing = $inventoryNeeds + $salesNeeds;

        return Stat::make(__('dashboards.purchasing.kpis.needs_sourcing'), (string) $needsSourcing)
            ->description(__('dashboards.purchasing.kpis.needs_sourcing_detail', [
                'inventory' => $inventoryNeeds,
                'sales' => $salesNeeds,
                'quantity' => QuantityFormatter::display($inventoryQuantity),
            ]))
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color($needsSourcing > 0 ? 'warning' : 'success')
            ->url(PurchaseNeeds::getUrl());
    }

    private function awaitingApprovalStat(): Stat
    {
        $pendingApproval = $this->orders()
            ->where('status', PurchaseOrderStatus::PendingApproval->value)
            ->count();

        return Stat::make(__('dashboards.purchasing.kpis.awaiting_approval'), (string) $pendingApproval)
            ->description(__('dashboards.purchasing.kpis.awaiting_approval_detail'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color($pendingApproval > 0 ? 'warning' : 'success')
            ->url(PurchaseOrderResource::getUrl('index', ['activeTab' => 'approval']));
    }

    private function overdueStat(): Stat
    {
        $overdue = $this->orders()
            ->whereDate('expected_at', '<', today())
            ->whereNotIn('status', [
                PurchaseOrderStatus::Received->value,
                PurchaseOrderStatus::Closed->value,
                PurchaseOrderStatus::Cancelled->value,
            ])
            ->count();

        return Stat::make(__('dashboards.purchasing.kpis.overdue'), (string) $overdue)
            ->description(__('dashboards.purchasing.kpis.overdue_detail'))
            ->icon(Heroicon::OutlinedClock)
            ->color($overdue > 0 ? 'danger' : 'success')
            ->url(PurchaseOrderResource::getUrl('index', ['activeTab' => 'overdue']));
    }

    /** @return Builder<PurchaseOrder> */
    private function orders(): Builder
    {
        return PurchaseOrder::query()
            ->when($this->dashboardFilter('supplierId'), static fn (Builder $query, int $supplierId): Builder => $query->where('supplier_id', $supplierId));
    }

    /** @return Builder<PurchaseOrder> */
    private function ordersInCurrency(string $currency, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->orders()
            ->where('currency_code', $currency)
            ->whereDate('ordered_at', '>=', $from->toDateString())
            ->whereDate('ordered_at', '<=', $to->toDateString());
    }

    /**
     * Active replenishment requirements whose uncovered quantity is not
     * fully met by an internal transfer — the residual external need.
     *
     * @return array{0:int,1:float}
     */
    private function inventoryPurchaseNeeds(): array
    {
        $transferSuggestions = app(ReplenishmentTransferSuggestionService::class);
        $count = 0;
        $quantity = 0.0;

        /** @var Collection<int, ReplenishmentRequirement> $requirements */
        $requirements = ReplenishmentRequirement::query()->active()->get();

        foreach ($requirements as $requirement) {
            $remaining = $requirement->remainingUncoveredQuantity();
            $transferQuantity = 0.0;

            foreach ($transferSuggestions->suggest($requirement) as $suggestion) {
                $transferQuantity += $suggestion->suggestedBaseQuantity;
            }

            $purchaseNeed = max(0.0, round($remaining - $transferQuantity, 6));

            if ($purchaseNeed <= 0) {
                continue;
            }

            $count++;
            $quantity += $purchaseNeed;
        }

        return [$count, $quantity];
    }
}
