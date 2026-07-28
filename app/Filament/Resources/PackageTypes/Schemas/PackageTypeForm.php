<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class PackageTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('admin.package.type.fields.name'))
                ->required()
                ->maxLength(150),
            TextInput::make('code')
                ->label(__('admin.package.type.fields.code'))
                ->maxLength(50)
                ->unique(ignoreRecord: true),
            Toggle::make('is_active')
                ->label(__('admin.package.type.fields.is_active'))
                ->default(true),
        ]);
    }
}
