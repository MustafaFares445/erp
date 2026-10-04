<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportSkills\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SupportSkillsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('employees_count')->counts('employees')->label(__('Employees')),
            IconColumn::make('is_active')->boolean(),
        ])->recordActions([EditAction::make()]);
    }
}
