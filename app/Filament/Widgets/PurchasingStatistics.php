<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

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
        $terminalStatuses = array_map(
            static fn (PurchaseOrderStatus $status): string => $status->value,
            array_filter(PurchaseOrderStatus::cases(), static fn (PurchaseOrderStatus $status): bool => $status->isTerminal()),
        );

        $stats = [
            Stat::make('Open Purchase Orders', PurchaseOrder::query()->whereNotIn('status', $terminalStatuses)->count())
                ->description('Commercial commitments still in progress')
                ->url(PurchaseOrderResource::getUrl('index')),
            Stat::make('Pending approval', PurchaseOrder::query()->where('status', PurchaseOrderStatus::PendingApproval->value)->count())
                ->description('Purchasing Manager action required')
                ->url(PurchaseOrderResource::getUrl('index')),
            Stat::make('Supplier responses pending', SupplierConfirmation::query()->where('confirmation_status', SupplierConfirmationStatus::Pending->value)->count())
                ->description('Supplier commitment evidence outstanding')
                ->url(SupplierConfirmationResource::getUrl('index')),
            Stat::make('Supplier backorders', SupplierConfirmationItem::query()
                ->where('confirmation_status', SupplierConfirmationStatus::Partial->value)
                ->where('backordered_base_quantity', '>', 0)
                ->count())
                ->description('Confirmed responses with quantity still backordered')
                ->url(SupplierConfirmationResource::getUrl('index')),
            $this->requirementsWaitingForPurchaseStat(),
            Stat::make('Awaiting warehouse allocation', PurchaseInbound::query()
                ->where('status', PurchaseInboundStatus::AwaitingAllocation->value)
                ->count())
                ->description('Inventory must allocate confirmed inbound quantity')
                ->url(PurchaseInboundResource::getUrl('index')),
            Stat::make('Overdue inbound', PurchaseInbound::query()
                ->whereNotIn('status', [PurchaseInboundStatus::Received->value, PurchaseInboundStatus::Cancelled->value])
                ->whereHas('purchaseOrder', static fn (Builder $query): Builder => $query->whereDate('expected_at', '<', today()))
                ->count())
                ->description('Expected date passed with inbound work still open')
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
            $currency = $row->getAttribute('currency_code');

            if (! is_string($currency)) {
                continue;
            }

            if ($currency === '') {
                continue;
            }

            $currencyValue = mb_strtoupper($currency);
            $amount = $row->getAttribute('amount');

            $stats[] = Stat::make("PO spend this month · {$currencyValue}", number_format(is_numeric($amount) ? (float) $amount : 0, 2))
                ->description('No cross-currency summation');
        }

        return $stats;
    }

    private function salesNeedsStat(): Stat
    {
        $requirements = SalesProcurementRequirement::query()
            ->whereNotIn('status', ['fulfilled', 'cancelled'])
            ->get();

        $quantity = $requirements->sum(fn (SalesProcurementRequirement $requirement): float => (float) $requirement->outstandingBaseQuantity());

        return Stat::make('Sales purchase needs', (string) $requirements->count())
            ->description(QuantityFormatter::display($quantity).' base units still required')
            ->url(PurchaseNeeds::getUrl());
    }

    private function requirementsWaitingForPurchaseStat(): Stat
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

        return Stat::make(__('replenishment.waiting_for_purchase'), (string) $count)
            ->description(__('replenishment.waiting_for_purchase_description', [
                'quantity' => QuantityFormatter::display($quantity),
            ]))
            ->url(PurchaseNeeds::getUrl());
    }
}
