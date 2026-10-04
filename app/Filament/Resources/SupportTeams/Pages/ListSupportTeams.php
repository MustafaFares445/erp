<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportTeams\Pages;

use App\Filament\Resources\SupportTeams\SupportTeamResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSupportTeams extends ListRecords
{
    protected static string $resource = SupportTeamResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
