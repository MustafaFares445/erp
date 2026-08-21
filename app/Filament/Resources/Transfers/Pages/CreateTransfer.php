<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Resources\Transfers\TransferResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * No custom `mutateFormDataBeforeCreate()` is needed: `created_by` is set by
 * the model's `TracksBlameable` boot hook, and `status`/`transfer_number` are
 * left at their `draft`/`null` column defaults — no number is issued until
 * confirm (research D3).
 */
final class CreateTransfer extends CreateRecord
{
    protected static string $resource = TransferResource::class;
}
