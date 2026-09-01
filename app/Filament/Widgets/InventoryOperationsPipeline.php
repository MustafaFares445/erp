<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Models\InventoryOperation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class InventoryOperationsPipeline extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();
        if ($user?->can(InventoryPermission::ReceiptView->value) ?? false) {
            return true;
        }
        if ($user?->can(InventoryPermission::DeliveryView->value) ?? false) {
            return true;
        }

        return (bool) ($user?->can(InventoryPermission::TransferView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        return array_map(
            fn (OperationStage $stage): Stat => Stat::make($stage->label(), (string) $this->countByStage($stage)),
            [OperationStage::Draft, OperationStage::Waiting, OperationStage::Ready, OperationStage::InTransit, OperationStage::PartiallyReceived],
        );
    }

    private function countByStage(OperationStage $stage): int
    {
        return InventoryOperation::query()->where('stage', $stage->value)->count();
    }
}
