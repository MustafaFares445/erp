<?php

declare(strict_types=1);

namespace App\Filament\Resources\Taxes;

use App\Filament\Resources\Taxes\Pages\ListTaxes;
use App\Filament\Resources\Taxes\Pages\ViewTaxRegister;
use App\Models\TaxRecognitionEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

final class TaxResource extends Resource
{
    protected static ?string $model = TaxRecognitionEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 209;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.taxes');
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('tax_date', 'desc')
            ->columns([
                TextColumn::make('tax_date')->date()->sortable(),
                TextColumn::make('direction')->badge()->sortable(),
                TextColumn::make('tax_type')->label('Tax treatment')->searchable(),
                TextColumn::make('source_type')->label('Document')->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextColumn::make('source_id')->label('Document ID')->sortable(),
                TextColumn::make('tax_amount')->numeric(decimalPlaces: 2)->sortable(),
            ])
            ->filters([
                SelectFilter::make('direction')->options([
                    'input' => 'Input tax',
                    'deferred_output' => 'Deferred output tax',
                ]),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTaxes::route('/'),
            'register' => ViewTaxRegister::route('/register'),
        ];
    }
}
