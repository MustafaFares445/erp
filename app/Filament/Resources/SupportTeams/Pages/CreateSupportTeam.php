<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportTeams\Pages;

use App\Filament\Resources\SupportTeams\SupportTeamResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSupportTeam extends CreateRecord
{
    protected static string $resource = SupportTeamResource::class;
}
