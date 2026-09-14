<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Enums\InventoryPermission;
use App\Enums\PurchasePermission;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ManageProductVendors extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'supplierProductReferences';

    #[\Override]
    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can(InventoryPermission::ProductView->value) ?? false;
    }

    /** @return array<Action> */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add supplier product reference')
                ->visible(fn (): bool => self::canManageCommercialReference()),
        ];
    }

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')
                ->relationship('supplier', 'name')
                ->searchable()
                ->preload(),
            TextInput::make('supplier_name')->label('Supplier product name')->required()->maxLength(255),
            TextInput::make('supplier_item_number')->label('Supplier product number')->required()->maxLength(255),
            TextInput::make('country_code')->maxLength(2),
            TextInput::make('purchase_cost')->numeric()->minValue(0)
                ->visible(fn (): bool => self::canViewCommercialReference()),
            TextInput::make('currency_code')->maxLength(3)
                ->visible(fn (): bool => self::canViewCommercialReference()),
            TextInput::make('manufacturer')->maxLength(255),
            TextInput::make('notes')->maxLength(2000)
                ->visible(fn (): bool => self::canViewCommercialReference()),
        ]);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('supplier.name')->searchable()->sortable(),
            TextColumn::make('supplier_name')->label('Supplier product name')->searchable(),
            TextColumn::make('supplier_item_number')->label('Supplier product number')->searchable(),
            TextColumn::make('country_code')->label('Country'),
            TextColumn::make('purchase_cost')->money('USD')->visible(fn (): bool => self::canViewCommercialReference()),
            TextColumn::make('currency_code')->visible(fn (): bool => self::canViewCommercialReference()),
        ])->recordActions([
            EditAction::make()->visible(fn (): bool => self::canManageCommercialReference()),
        ]);
    }

    private static function canViewCommercialReference(): bool
    {
        return auth()->user()?->can(PurchasePermission::ProductReferenceView->value) ?? false;
    }

    private static function canManageCommercialReference(): bool
    {
        return auth()->user()?->can(PurchasePermission::ProductReferenceManage->value) ?? false;
    }
}
