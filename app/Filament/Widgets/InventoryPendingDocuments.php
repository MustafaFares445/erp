<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Enums\TransferStatus;
use App\Models\InventoryAdjustment;
use App\Models\StockTransfer;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class InventoryPendingDocuments extends StatsOverviewWidget
{
    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();
        if ($user?->can(InventoryPermission::AdjustmentView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(InventoryPermission::TransferView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        return [
            Stat::make('Draft adjustments', InventoryAdjustment::query()->where('status', 'draft')->count()),
            Stat::make('Draft transfers', StockTransfer::query()->where('status', TransferStatus::Draft->value)->count()),
        ];
    }
}
