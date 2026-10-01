<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\BillStatus;
use App\Enums\PurchaseInboundStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequirement;
use App\Models\SalesProcurementRequirement;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use App\Support\QuantityFormatter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

final class PurchasingStatistics extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(PurchasePermission::OrderView->value) ?? false;
    }

    #[\Override]
    protected function getStats(): array
    {
        [$inventoryNeeds, $inventoryQuantity] = $this->inventoryPurchaseNeeds();
        $salesNeeds = SalesProcurementRequirement::query()
            ->whereNotIn('status', ['fulfilled', 'cancelled', 'superseded'])
            ->whereNull('purchase_order_id')
            ->count();
        $needsSourcing = $inventoryNeeds + $salesNeeds;

        $pendingApproval = PurchaseOrder::query()
            ->where('status', PurchaseOrderStatus::PendingApproval->value)
            ->count();

        $awaitingSupplier = SupplierConfirmation::query()
            ->where('confirmation_status', SupplierConfirmationStatus::Pending->value)
            ->whereHas('purchaseOrder', static fn (Builder $query): Builder => $query
                ->whereNotNull('sent_at')
                ->whereIn('status', [
                    PurchaseOrderStatus::Accepted->value,
                    PurchaseOrderStatus::PartiallyReceived->value,
                ]))
            ->count();

        $expectedThisWeek = PurchaseOrder::query()
            ->whereBetween('expected_at', [today(), now()->endOfWeek()->toDateString()])
            ->whereIn('status', [
                PurchaseOrderStatus::Accepted->value,
                PurchaseOrderStatus::PartiallyReceived->value,
            ])
            ->count();

        $overdue = PurchaseOrder::query()
            ->whereDate('expected_at', '<', today())
            ->whereNotIn('status', [
                PurchaseOrderStatus::Received->value,
                PurchaseOrderStatus::Closed->value,
                PurchaseOrderStatus::Cancelled->value,
            ])
            ->count();

        $accountingExceptions = PurchaseOrder::query()
            ->where('status', PurchaseOrderStatus::Received->value)
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('bills')
                    ->orWhereHas('bills', static fn (Builder $bills): Builder => $bills->where('status', BillStatus::Draft->value));
            })
            ->count();

        $stats = [
            Stat::make(__('Needs sourcing'), (string) $needsSourcing)
                ->description($inventoryNeeds.' inventory · '.$salesNeeds.' sales needs · '.number_format($inventoryQuantity, 2).' inventory units')
                ->color($needsSourcing > 0 ? 'warning' : 'success')
                ->url(PurchaseNeeds::getUrl()),
            Stat::make(__('Awaiting approval'), (string) $pendingApproval)
                ->description(__('Purchasing Manager action required'))
                ->color($pendingApproval > 0 ? 'warning' : 'success')
                ->url(PurchaseOrderResource::getUrl('index', ['activeTab' => 'approval'])),
            Stat::make(__('Awaiting supplier'), (string) $awaitingSupplier)
                ->description(__('Sent POs still waiting for a supplier response'))
                ->color($awaitingSupplier > 0 ? 'warning' : 'success')
                ->url(SupplierConfirmationResource::getUrl('index')),
            Stat::make(__('Expected this week'), (string) $expectedThisWeek)
                ->description(__('Open Purchase Orders due before week end'))
                ->color('info')
                ->url(PurchaseOrderResource::getUrl('index')),
            Stat::make(__('Overdue deliveries'), (string) $overdue)
                ->description(__('Expected date passed and receiving is still open'))
                ->color($overdue > 0 ? 'danger' : 'success')
                ->url(PurchaseOrderResource::getUrl('index', ['activeTab' => 'overdue'])),
            Stat::make(__('Accounting exceptions'), (string) $accountingExceptions)
                ->description(__('Received goods with a missing or draft supplier bill'))
                ->color($accountingExceptions > 0 ? 'warning' : 'success')
                ->url(PurchaseOrderResource::getUrl('index', ['activeTab' => 'accounting'])),
            Stat::make(__('Supplier backorders'), SupplierConfirmationItem::query()
                ->where('confirmation_status', SupplierConfirmationStatus::Partial->value)
                ->where('backordered_base_quantity', '>', 0)
                ->whereHas('confirmation.purchaseOrder', static fn (Builder $query): Builder => $query
                    ->whereIn('status', [
                        PurchaseOrderStatus::Accepted->value,
                        PurchaseOrderStatus::PartiallyReceived->value,
                    ]))
                ->count())
                ->description(__('Active Purchase Orders with supplier quantity still backordered'))
                ->url(SupplierConfirmationResource::getUrl('index')),
            $this->requirementsWaitingForPurchaseStat(),
            Stat::make(__('Awaiting warehouse allocation'), PurchaseInbound::query()
                ->where('status', PurchaseInboundStatus::AwaitingAllocation->value)
                ->count())
                ->description(__('Inventory must allocate confirmed inbound quantity'))
                ->url(PurchaseInboundResource::getUrl('index')),
            Stat::make(__('Overdue inbound'), PurchaseInbound::query()
                ->whereNotIn('status', [PurchaseInboundStatus::Received->value, PurchaseInboundStatus::Cancelled->value])
                ->whereHas('purchaseOrder', static fn (Builder $query): Builder => $query->whereDate('expected_at', '<', today()))
                ->count())
                ->description(__('Expected date passed with inbound work still open'))
                ->url(PurchaseInboundResource::getUrl('index')),
            $this->salesNeedsStat(),
        ];

        $spendByCurrency = PurchaseOrder::query()
            ->whereBetween('ordered_at', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->whereNotNull('currency_code')
            ->where('currency_code', '!=', '')
            ->selectRaw('currency_code, SUM(total_amount) AS amount')
            ->groupBy('currency_code')
            ->orderBy('currency_code')
            ->get();

        foreach ($spendByCurrency as $row) {
            $currencyValue = $row->getAttribute('currency_code');

            if (! is_string($currencyValue)) {
                continue;
            }

            if ($currencyValue === '') {
                continue;
            }

            $currency = mb_strtoupper($currencyValue);
            $amount = $row->getAttribute('amount');

            $stats[] = Stat::make("PO spend this month · {$currency}", number_format(is_numeric($amount) ? (float) $amount : 0, 2))
                ->description(__('No cross-currency summation'));
        }

        return $stats;
    }

    private function salesNeedsStat(): Stat
    {
        $requirements = SalesProcurementRequirement::query()
            ->whereNotIn('status', ['fulfilled', 'cancelled', 'superseded'])
            ->whereNull('purchase_order_id')
            ->get();

        $quantity = $requirements->sum(fn (SalesProcurementRequirement $requirement): float => (float) $requirement->outstandingBaseQuantity());

        return Stat::make(__('Sales purchase needs'), (string) $requirements->count())
            ->description(QuantityFormatter::display($quantity).' base units still required')
            ->url(PurchaseNeeds::getUrl());
    }

    private function requirementsWaitingForPurchaseStat(): Stat
    {
        [$count, $quantity] = $this->inventoryPurchaseNeeds();

        return Stat::make(__('replenishment.waiting_for_purchase'), (string) $count)
            ->description(__('replenishment.waiting_for_purchase_description', [
                'quantity' => QuantityFormatter::display($quantity),
            ]))
            ->url(PurchaseNeeds::getUrl());
    }

    /** @return array{0:int,1:float} */
    private function inventoryPurchaseNeeds(): array
    {
        $transferSuggestions = app(ReplenishmentTransferSuggestionService::class);
        $count = 0;
        $quantity = 0.0;

        foreach (ReplenishmentRequirement::query()->active()->get() as $requirement) {
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
