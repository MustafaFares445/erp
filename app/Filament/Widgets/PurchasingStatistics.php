<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchasePermission;
use App\Enums\SupplierConfirmationStatus;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequirement;
use App\Models\SupplierConfirmation;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use App\Support\QuantityFormatter;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

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

        $openOrders = PurchaseOrder::query()->whereNotIn('status', $terminalStatuses)->count();

        $pendingApproval = PurchaseOrder::query()
            ->where('status', PurchaseOrderStatus::PendingApproval->value)
            ->count();

        $pendingConfirmations = SupplierConfirmation::query()
            ->where('confirmation_status', SupplierConfirmationStatus::Pending->value)
            ->count();

        $spendThisMonth = PurchaseOrder::query()
            ->whereBetween('ordered_at', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->sum('total_amount');

        return [
            Stat::make('Open purchase orders', $openOrders),
            Stat::make('Purchase orders pending approval', $pendingApproval),
            Stat::make('Supplier confirmations pending', $pendingConfirmations),
            Stat::make('PO spend this month', $this->formatMoney($spendThisMonth)),
            $this->requirementsWaitingForPurchaseStat(),
        ];
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
            ]));
    }

    private function formatMoney(int|float|string|null $value): string
    {
        return number_format(is_numeric($value) ? (float) $value : 0, 2);
    }
}
