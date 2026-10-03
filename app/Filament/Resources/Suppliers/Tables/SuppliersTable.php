<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Tables;

use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\Supplier;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

final class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchPlaceholder(__('Name, code, email…'))
            ->defaultSort('name')
            ->columns([
                FavoriteColumn::make(),
                ImageColumn::make('logo_path')->label(__('Logo'))->disk('public')->circular()->imageHeight(40),
                TextColumn::make('name')->label(__('Supplier'))->description(fn (Supplier $record): string => $record->code)->searchable(['name', 'code'])->sortable(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('active_catalog_count')
                    ->label(__('Products'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('open_po_count')
                    ->label(__('Open POs'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('pending_confirmation_count')
                    ->label(__('Awaiting response'))
                    ->badge()
                    ->color(fn (mixed $state): string => is_numeric($state) && (int) $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('last_purchase_at')
                    ->label(__('Last purchase'))
                    ->date()
                    ->placeholder(__('—'))
                    ->sortable(),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('is_active')
                    ->label(__('Active supplier'))
                    ->getTitleFromRecordUsing(static fn (Supplier $record): string => $record->is_active ? __('Active') : __('Inactive')),
                Group::make('requires_confirmation')
                    ->label(__('Confirmation required'))
                    ->getTitleFromRecordUsing(static fn (Supplier $record): string => $record->requires_confirmation ? __('Yes') : __('No')),
                Group::make('created_at')->label(__('Created at'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    TextConstraint::make('name')->label(__('Supplier name')),
                    TextConstraint::make('code')->label(__('Supplier code')),
                    TextConstraint::make('email')->label(__('Email')),
                    TextConstraint::make('phone')->label(__('Phone')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                ]),
                TernaryFilter::make('is_active')->label(__('Active supplier')),
                TernaryFilter::make('requires_confirmation')->label(__('Confirmation required')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                    RestoreAction::make(),
                ]),
            ]);
    }
}
