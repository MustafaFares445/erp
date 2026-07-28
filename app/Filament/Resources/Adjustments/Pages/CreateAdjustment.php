<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Pages;

use App\Filament\Resources\Adjustments\AdjustmentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * No custom `mutateFormDataBeforeCreate()` is needed: `created_by` is set by
 * the model's `TracksBlameable` boot hook, and `status`/`adjustment_number`
 * are left at their `draft`/`null` column defaults — no number is issued
 * until confirm (research R6).
 */
final class CreateAdjustment extends CreateRecord
{
    protected static string $resource = AdjustmentResource::class;

    /**
     * Item lines require the persisted adjustment as their relation-manager
     * owner. Open the edit page after creation so the same item table and
     * draft-only calculations are immediately available.
     */
    #[\Override]
    protected function getRedirectUrl(): string
    {
        $record = $this->getRecord();

        if (! $record instanceof Model) {
            throw new LogicException('The adjustment must be persisted before redirecting to its edit page.');
        }

        return $this->getResourceUrl('edit', [
            'record' => $record->getKey(),
        ]);
    }
}
