<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportTeams\Pages;

use App\Filament\Resources\SupportTeams\SupportTeamResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSupportTeam extends EditRecord
{
    protected static string $resource = SupportTeamResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
