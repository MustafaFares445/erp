<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportSkills\Pages;

use App\Filament\Resources\SupportSkills\SupportSkillResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSupportSkill extends EditRecord
{
    protected static string $resource = SupportSkillResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
