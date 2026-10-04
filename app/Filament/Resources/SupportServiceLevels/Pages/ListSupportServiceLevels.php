<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportServiceLevels\Pages;

use App\Filament\Resources\SupportServiceLevels\SupportServiceLevelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSupportServiceLevels extends ListRecords
{
    protected static string $resource = SupportServiceLevelResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
