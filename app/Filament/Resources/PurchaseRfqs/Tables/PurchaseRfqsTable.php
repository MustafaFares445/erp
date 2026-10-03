<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseRfqs\Tables;

use App\Enums\PurchaseRfqStatus;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

final class PurchaseRfqsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('rfq_number')->label(__('Reference'))->searchable()->sortable(),
                TextColumn::make('requester.name')->label(__('Buyer'))->sortable(),
                TextColumn::make('awardedSupplier.name')->label(__('Awarded supplier'))->placeholder('—')->sortable(),
                TextColumn::make('needed_by')->label(__('Needed by'))->date()->placeholder('—')->sortable(),
                TextColumn::make('closes_at')->label(__('Closes at'))->dateTime()->placeholder('—')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('suppliers_count')->counts('suppliers')->label(__('Suppliers'))->badge()->color('gray'),
                TextColumn::make('currency_code')->label(__('Currency')),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('status')->label(__('Status')),
                Group::make('requester.name')->label(__('Buyer')),
                Group::make('awardedSupplier.name')->label(__('Awarded supplier')),
                Group::make('currency_code')->label(__('Currency')),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Status'))
                        ->options(PurchaseRfqStatus::class)
                        ->multiple(),
                    TextConstraint::make('rfq_number')->label(__('Reference')),
                    RelationshipConstraint::make('requester')
                        ->label(__('Buyer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    RelationshipConstraint::make('awardedSupplier')
                        ->label(__('Awarded supplier'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    TextConstraint::make('currency_code')->label(__('Currency')),
                    DateConstraint::make('needed_by')->label(__('Needed by')),
                    DateConstraint::make('closes_at')->label(__('Closes at')),
                    DateConstraint::make('created_at')->label(__('Created at')),
                ]),
            ]);
    }
}
