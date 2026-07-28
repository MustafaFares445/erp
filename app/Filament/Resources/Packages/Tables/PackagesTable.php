<?php

declare(strict_types=1);

namespace App\Filament\Resources\Packages\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class PackagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('admin.package.fields.name'))->searchable()->sortable(),
                TextColumn::make('packageType.name')->label(__('admin.package.fields.package_type'))->searchable()->sortable(),
                TextColumn::make('warehouse.name')->label(__('admin.package.fields.warehouse'))->searchable()->sortable(),
                TextColumn::make('location.name')->label(__('admin.package.fields.location'))->searchable()->toggleable(),
                IconColumn::make('is_active')->label(__('admin.package.fields.is_active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('package_type_id')->relationship('packageType', 'name')->searchable()->preload(),
                SelectFilter::make('warehouse_id')->relationship('warehouse', 'name')->searchable()->preload(),
                TernaryFilter::make('is_active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }
}
