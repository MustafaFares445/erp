<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Pages;

use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Models\InventoryAdjustment;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * The form itself is read-only once confirmed (`AdjustmentForm`'s
 * schema-level `disabled()`), and the policy's `update` ability already
 * returns `false` for a confirmed record — Filament's `authorizeAccess()`
 * on `EditRecord` refuses the page entirely in that case (FR-016), so no
 * extra redirect logic is needed here.
 */
final class EditAdjustment extends EditRecord
{
    protected static string $resource = AdjustmentResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (InventoryAdjustment $record): bool => $record->isDraft()),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
