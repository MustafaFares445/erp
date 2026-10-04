<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportSkills\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SupportSkillForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Skill'))->schema([
                TextInput::make('code')->required()->maxLength(80)->unique(ignoreRecord: true),
                TextInput::make('name')->required()->maxLength(255),
                Toggle::make('is_active')->default(true),
            ])->columns(2),
        ]);
    }
}
