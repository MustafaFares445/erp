<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportSkills\Pages;

use App\Filament\Resources\SupportSkills\SupportSkillResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSupportSkills extends ListRecords
{
    protected static string $resource = SupportSkillResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
