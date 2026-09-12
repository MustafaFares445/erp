<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers;

use App\Enums\PurchasePermission;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
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
            Toggle::make('requires_confirmation')
                ->label('Require confirmation for accepted purchase orders')
                ->helperText('When enabled, accepting a purchase order automatically opens one pending supplier-confirmation workflow.')
                ->default(false)
                ->visible(fn (): bool => self::canManageSupplierCommercialData()),
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
                ->columnSpanFull()
                ->visible(fn (): bool => auth()->user()?->can(PurchasePermission::ProductReferenceManage->value) ?? false),
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
            ToggleColumn::make('is_active'),
            ToggleColumn::make('requires_confirmation')
                ->label('Confirmation required')
                ->visible(fn (): bool => self::canManageSupplierCommercialData()),
        ])->filters([
            TernaryFilter::make('is_active'),
            TernaryFilter::make('requires_confirmation')
                ->label('Confirmation required')
                ->visible(fn (): bool => self::canManageSupplierCommercialData()),
            TrashedFilter::make(),
        ])
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

    private static function canManageSupplierCommercialData(): bool
    {
        return auth()->user()?->can(PurchasePermission::SupplierManage->value) ?? false;
    }
}
