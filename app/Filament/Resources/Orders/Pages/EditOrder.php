<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Edits only the two fields {@see OrderForm}
 * exposes — payment term and notes. Written by Filament directly: neither
 * touches a total or a line, so there is no invariant here for a service to
 * guard.
 */
final class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
