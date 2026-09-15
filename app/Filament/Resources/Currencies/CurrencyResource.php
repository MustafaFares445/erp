<?php

declare(strict_types=1);

namespace App\Filament\Resources\Currencies;

use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Models\Currency;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

final class CurrencyResource extends Resource
{
    protected static ?string $model = Currency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.system';

    protected static ?int $navigationSort = 20;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.currencies');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.currency');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('admin.currencies.fields.code'))
                ->required()
                ->length(3)
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(static fn (string $state): string => mb_strtoupper(mb_trim($state))),
            TextInput::make('name')
                ->label(__('admin.currencies.fields.name'))
                ->required()
                ->maxLength(100),
            Toggle::make('is_active')
                ->label(__('admin.currencies.fields.active'))
                ->default(true)
                ->disabled(static fn (Get $get): bool => (bool) $get('is_default')),
            Toggle::make('is_default')
                ->label(__('admin.currencies.fields.default'))
                ->live(),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.currencies.fields.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.currencies.fields.name'))
                    ->searchable()
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label(__('admin.currencies.fields.active'))
                    ->disabled(fn (Currency $record): bool => $record->is_default),
                IconColumn::make('is_default')
                    ->label(__('admin.currencies.fields.default'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('admin.common.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (Currency $record): bool => ! $record->is_default),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageCurrencies::route('/')];
    }
}
