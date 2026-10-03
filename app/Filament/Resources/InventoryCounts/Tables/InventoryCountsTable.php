<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCounts\Tables;

use App\Enums\CountScope;
use App\Enums\InventoryCountStatus;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\InventoryCount;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

final class InventoryCountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('count_number')->label(__('admin.inventory.count.fields.count'))->searchable()->sortable(),
                TextColumn::make('warehouse.name')->label(__('admin.inventory.count.fields.warehouse'))->searchable(),
                TextColumn::make('scope_type')->label(__('admin.inventory.count.fields.scope'))->badge(),
                TextColumn::make('status')->label(__('admin.inventory.count.fields.status'))->badge()->sortable(),
                TextColumn::make('lines_count')->label(__('admin.inventory.count.fields.lines'))->counts('lines'),
                TextColumn::make('counter.name')->label(__('admin.inventory.count.fields.counted_by'))->placeholder(__('—')),
                TextColumn::make('confirmedBy.name')->label(__('admin.inventory.count.fields.confirmed_by'))->placeholder(__('—')),
                TextColumn::make('opened_at')->dateTime()->sortable(),
            ])
            ->groups([
                Group::make('status')->label(__('admin.inventory.count.fields.status')),
                Group::make('warehouse.name')->label(__('admin.inventory.count.fields.warehouse')),
                Group::make('scope_type')->label(__('admin.inventory.count.fields.scope')),
                Group::make('opened_at')->label(__('Opened at'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('admin.inventory.count.fields.status'))
                        ->options(InventoryCountStatus::class)
                        ->multiple(),
                    TextConstraint::make('count_number')->label(__('admin.inventory.count.fields.count')),
                    RelationshipConstraint::make('warehouse')
                        ->label(__('admin.inventory.count.fields.warehouse'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    SelectConstraint::make('scope_type')
                        ->label(__('admin.inventory.count.fields.scope'))
                        ->options(CountScope::class)
                        ->multiple(),
                    RelationshipConstraint::make('counter')
                        ->label(__('admin.inventory.count.fields.counted_by'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    DateConstraint::make('opened_at')->label(__('Opened at')),
                ]),
            ])
            ->recordUrl(fn (InventoryCount $record): string => InventoryCountResource::getUrl('view', ['record' => $record]));
    }
}
