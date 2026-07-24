<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Resources\Transfers\TransferResource;
use App\Models\StockTransfer;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * The form itself is read-only once confirmed (`TransferForm`'s
 * schema-level `disabled()`), and the policy's `update` ability already
 * returns `false` for a confirmed record — Filament's `authorizeAccess()`
 * on `EditRecord` refuses the page entirely in that case (FR-017), so no
 * extra redirect logic is needed here.
 */
final class EditTransfer extends EditRecord
{
    protected static string $resource = TransferResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (StockTransfer $record): bool => $record->isDraft()),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
