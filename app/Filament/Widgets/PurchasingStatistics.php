<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\BillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequirement;
use App\Models\SalesProcurementRequirement;
use App\Models\SupplierConfirmation;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
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

        return [
            Stat::make('Needs sourcing', (string) $needsSourcing)
                ->description($inventoryNeeds.' inventory · '.$salesNeeds.' sales needs · '.number_format($inventoryQuantity, 2).' inventory units')
                ->color($needsSourcing > 0 ? 'warning' : 'success')
                ->url(PurchaseNeeds::getUrl()),
            Stat::make('Awaiting approval', (string) $pendingApproval)
                ->description('Purchasing Manager action required')
                ->color($pendingApproval > 0 ? 'warning' : 'success')
                ->url(PurchaseOrderResource::getUrl('index', ['activeTab' => 'approval'])),
            Stat::make('Awaiting supplier', (string) $awaitingSupplier)
                ->description('Sent POs still waiting for a supplier response')
                ->color($awaitingSupplier > 0 ? 'warning' : 'success')
                ->url(SupplierConfirmationResource::getUrl('index')),
            Stat::make('Expected this week', (string) $expectedThisWeek)
                ->description('Open Purchase Orders due before week end')
                ->color('info')
                ->url(PurchaseOrderResource::getUrl('index')),
            Stat::make('Overdue deliveries', (string) $overdue)
                ->description('Expected date passed and receiving is still open')
                ->color($overdue > 0 ? 'danger' : 'success')
                ->url(PurchaseOrderResource::getUrl('index', ['activeTab' => 'overdue'])),
            Stat::make('Accounting exceptions', (string) $accountingExceptions)
                ->description('Received goods with a missing or draft supplier bill')
                ->color($accountingExceptions > 0 ? 'warning' : 'success')
                ->url(PurchaseOrderResource::getUrl('index', ['activeTab' => 'accounting'])),
        ];
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
