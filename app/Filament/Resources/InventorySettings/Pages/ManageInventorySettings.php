<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventorySettings\Pages;

use App\Filament\Resources\InventorySettings\InventorySettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageInventorySettings extends ManageRecords
{
    protected static string $resource = InventorySettingResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
