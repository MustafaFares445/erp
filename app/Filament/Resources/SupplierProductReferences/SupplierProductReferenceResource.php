<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierProductReferences;

use App\Filament\Resources\SupplierProductReferences\Pages\ManageSupplierProductReferences;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Services\Purchasing\SupplierCostWritebackService;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Supplier product references as a first-class surface.
 *
 * They were previously reachable only through the supplier form, which made
 * them impossible to search across suppliers — the question a buyer actually
 * asks is "who sells this part, and what did we last pay?", not "what does this
 * one supplier sell?".
 *
 * `purchase_cost` is editable here **and** written automatically by
 * {@see SupplierCostWritebackService} when a receipt
 * completes (FR-048). Both are legitimate: the writeback records what was paid,
 * and a buyer may still enter a newly quoted price before the next order.
 *
 * @see /specs/017-purchasing-orders-suppliers/spec.md User Story 6
 */
final class SupplierProductReferenceResource extends Resource
{
    protected static ?string $model = SupplierProductReference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.purchasing';

    protected static ?int $navigationSort = 105;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.supplier_product_references');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.supplier_product_references');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')
                ->label(__('admin.purchasing.fields.supplier'))
                ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->preload()
                ->required(),
            Select::make('product_variant_id')
                ->label(__('admin.purchasing.fields.product_variant'))
                ->relationship('productVariant', 'sku')
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('supplier_item_number')
                ->label(__('admin.purchasing.fields.supplier_item_number'))
                ->required()
                ->maxLength(100),
            TextInput::make('manufacturer')->label('Manufacturer')->maxLength(255),
            TextInput::make('purchase_cost')
                ->label(__('admin.purchasing.fields.purchase_cost'))
                ->numeric()
                ->minValue(0)
                ->step(0.01),
            TextInput::make('currency_code')
                ->label(__('admin.purchasing.fields.currency_code'))
                ->length(3)
                ->default('AED'),
            Toggle::make('is_active')->label('Active')->default(true),
            Textarea::make('notes')->label(__('admin.purchasing.fields.notes'))->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier.name')->label(__('admin.purchasing.fields.supplier'))->searchable()->sortable(),
                TextColumn::make('productVariant.sku')->label(__('admin.purchasing.fields.product_variant'))->searchable()->sortable(),
                TextColumn::make('supplier_item_number')->label(__('admin.purchasing.fields.supplier_item_number'))->searchable(),
                TextColumn::make('manufacturer')->label('Manufacturer')->searchable()->placeholder('—'),
                TextColumn::make('purchase_cost')->label(__('admin.purchasing.fields.purchase_cost'))->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('currency_code')->label(__('admin.purchasing.fields.currency_code')),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label(__('admin.purchasing.fields.supplier'))
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('is_active')->label('Active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageSupplierProductReferences::route('/')];
    }
}
