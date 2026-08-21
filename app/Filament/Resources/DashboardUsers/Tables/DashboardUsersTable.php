<?php

declare(strict_types=1);

namespace App\Filament\Resources\DashboardUsers\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class DashboardUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles.name')->label(__('admin.crm.fields.dashboard_role'))->badge(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
