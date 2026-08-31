<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryOperation;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class InventoryPendingDocuments extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

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
        $draftAdjustments = InventoryAdjustment::query()->where('status', 'draft')->count();
        $pendingTransfers = InventoryOperation::query()
            ->where('operation_type', OperationType::InternalTransfer->value)
            ->whereIn('stage', [
                OperationStage::Draft->value,
                OperationStage::Waiting->value,
                OperationStage::Ready->value,
                OperationStage::InTransit->value,
                OperationStage::PartiallyReceived->value,
            ])
            ->count();

        return [
            Stat::make(__('admin.inventory.dashboard.draft_adjustments'), (string) $draftAdjustments)
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color($draftAdjustments > 0 ? 'warning' : 'success')
                ->url(AdjustmentResource::getUrl('index')),
            Stat::make(__('admin.inventory.dashboard.pending_transfers'), (string) $pendingTransfers)
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color($pendingTransfers > 0 ? 'warning' : 'success')
                ->url(InventoryOperationResource::getUrl('transfers')),
        ];
    }
}
