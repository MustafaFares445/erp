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

        return ($user?->can(InventoryPermission::ReceiptView->value) ?? false)
            || ($user?->can(InventoryPermission::DeliveryView->value) ?? false)
            || ($user?->can(InventoryPermission::TransferView->value) ?? false);
    }

    #[\Override]
    protected function getStats(): array
    {
        return array_map(
            fn (OperationStage $stage): Stat => Stat::make($stage->label(), (string) $this->countByStage($stage)),
            [OperationStage::Draft, OperationStage::Waiting, OperationStage::Ready, OperationStage::InTransit],
        );
    }

    private function countByStage(OperationStage $stage): int
    {
        return InventoryOperation::query()->where('stage', $stage->value)->count();
    }
}
