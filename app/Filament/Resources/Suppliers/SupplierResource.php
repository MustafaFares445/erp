<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.suppliers');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(50)->unique(ignoreRecord: true),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(50),
            Toggle::make('is_active')->default(true),
            Textarea::make('address')->columnSpanFull(),
            Repeater::make('productReferences')
                ->relationship()
                ->schema([
                    Select::make('product_variant_id')->relationship('productVariant', 'sku')->required()->searchable()->preload(),
                    TextInput::make('supplier_item_number')->required()->maxLength(100),
                    TextInput::make('supplier_name')->maxLength(255),
                    TextInput::make('country_code')->maxLength(2),
                    TextInput::make('manufacturer')->maxLength(255),
                    TextInput::make('purchase_cost')->numeric()->minValue(0)->step(0.01),
                    TextInput::make('currency_code')->default('USD')->maxLength(3),
                    Textarea::make('notes')->columnSpanFull(),
                    Toggle::make('is_active')->default(true),
                ])
                ->columnSpanFull(),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable(),
            TextColumn::make('phone')->searchable(),
            IconColumn::make('is_active')->boolean(),
        ])->filters([TernaryFilter::make('is_active'), TrashedFilter::make()])
            ->recordActions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageSuppliers::route('/')];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
