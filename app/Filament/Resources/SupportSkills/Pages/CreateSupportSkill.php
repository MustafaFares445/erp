<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportSkills\Pages;

use App\Filament\Resources\SupportSkills\SupportSkillResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSupportSkill extends CreateRecord
{
    protected static string $resource = SupportSkillResource::class;
}
