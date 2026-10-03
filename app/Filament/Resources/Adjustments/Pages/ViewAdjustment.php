<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Pages;

use App\Filament\Resources\Adjustments\Actions\AdjustmentActions;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Models\InventoryAdjustment;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Hosts the **Confirm** action — the sole stock-mutating control in the
 * whole resource (FR-009). It is a thin adapter built by
 * {@see AdjustmentActions}, shared with the list table, over
 * InventoryAdjustmentService::confirm(); this page computes nothing itself.
 */
final class ViewAdjustment extends ViewRecord
{
    protected static string $resource = AdjustmentResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (InventoryAdjustment $record): bool => $record->isDraft()),
            AdjustmentActions::createCorrection(),
            AdjustmentActions::confirm(),
        ];
    }
}
