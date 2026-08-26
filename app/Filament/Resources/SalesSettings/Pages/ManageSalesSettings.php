<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesSettings\Pages;

use App\Filament\Resources\SalesSettings\SalesSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageSalesSettings extends ManageRecords
{
    protected static string $resource = SalesSettingResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
