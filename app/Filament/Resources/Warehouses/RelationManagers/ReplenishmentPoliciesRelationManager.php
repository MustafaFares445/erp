<?php

declare(strict_types=1);

namespace App\Filament\Resources\Warehouses\RelationManagers;

use App\Models\ProductVariant;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ReplenishmentPoliciesRelationManager extends RelationManager
{
    protected static string $relationship = 'replenishmentPolicies';

    #[\Override]
    public static function getTitle(): string
    {
        return 'Replenishment Policies';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_variant_id')
                ->label('Product Variant')
                ->relationship('productVariant', 'sku')
                ->getOptionLabelFromRecordUsing(static fn (ProductVariant $record): string => trim($record->sku.' — '.$record->name, ' —'))
                ->searchable(['sku', 'name'])
                ->preload()
                ->required()
                ->disabledOn('edit'),
            TextInput::make('min_quantity')
                ->label('Min Quantity')
                ->numeric()
                ->minValue(0)
                ->step(0.000001)
                ->required(),
            TextInput::make('max_quantity')
                ->label('Max Quantity')
                ->numeric()
                ->minValue(0.000001)
                ->step(0.000001)
                ->rule('gt:min_quantity')
                ->required(),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('productVariant.name')
                    ->label('Variant')
                    ->searchable(),
                TextColumn::make('min_quantity')
                    ->label('Min')
                    ->numeric(decimalPlaces: 3),
                TextColumn::make('max_quantity')
                    ->label('Max')
                    ->numeric(decimalPlaces: 3),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
