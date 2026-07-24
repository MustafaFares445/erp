<?php

declare(strict_types=1);

namespace App\Filament\Resources\Units\Pages;

use App\Filament\Resources\Units\UnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageUnits extends ManageRecords
{
    protected static string $resource = UnitResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
