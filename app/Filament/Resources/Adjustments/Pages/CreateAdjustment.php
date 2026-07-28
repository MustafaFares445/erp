<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Pages;

use App\Filament\Resources\Adjustments\AdjustmentResource;
use Filament\Resources\Pages\CreateRecord;

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
    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl('edit', [
            'record' => $this->getRecord()->getKey(),
        ]);
    }
}
