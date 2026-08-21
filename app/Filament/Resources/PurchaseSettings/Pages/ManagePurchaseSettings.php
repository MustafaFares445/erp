<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseSettings\Pages;

use App\Filament\Resources\PurchaseSettings\PurchaseSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManagePurchaseSettings extends ManageRecords
{
    protected static string $resource = PurchaseSettingResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
