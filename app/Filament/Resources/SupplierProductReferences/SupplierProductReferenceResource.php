<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierProductReferences;

use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\SupplierProductReferences\Pages\ManageSupplierProductReferences;
use App\Filament\Support\CurrencySelect;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Services\Purchasing\SupplierCostWritebackService;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
 * {@see SupplierCostWritebackService} when a Purchase Order is accepted.
 * It therefore represents the latest accepted/agreed supplier cost, not the
 * last paid price or a receipt-completion cost. A buyer may still enter a newly
 * quoted price before the next order.
 *
 * @see /Docs/domains/purchasing/README.md User Story 6
 */
final class SupplierProductReferenceResource extends Resource
{
    protected static ?string $model = SupplierProductReference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.vendors';

    protected static ?int $navigationSort = 105;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('Supplier Products');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('Supplier Product');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('Supplier Products');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')
                ->label(__('Supplier'))
                ->options(fn (): array => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->preload()
                ->required(),
            Select::make('product_variant_id')
                ->label(__('Product / variant'))
                ->relationship('productVariant', 'sku')
                ->getOptionLabelFromRecordUsing(fn (ProductVariant $record): string => self::variantLabel($record))
                ->searchable(['sku', 'name', 'product.name'])
                ->preload()
                ->live()
                ->required()
                ->helperText(__('Adding an active supplier product automatically makes this supplier available for sourcing.')),
            Select::make('purchase_unit_id')
                ->label(__('Purchase UoM'))
                ->options(fn (Get $get): array => self::purchaseUnitOptions($get('product_variant_id')))
                ->searchable()
                ->preload()
                ->helperText(__('Optional supplier-specific purchase unit. It must be enabled for purchasing on the selected variant.')),
            TextInput::make('pack_size')
                ->label(__('Supplier pack size'))
                ->numeric()
                ->minValue(0.000001)
                ->step(0.000001)
                ->helperText(__('Optional commercial pack size. Inventory conversion still follows the variant UoM configuration.')),
            Select::make('availability_status')
                ->label(__('Availability'))
                ->options([
                    'active' => 'Active',
                    'temporarily_unavailable' => 'Temporarily unavailable',
                    'discontinued' => 'Discontinued',
                ])
                ->default('active')
                ->required(),
            Toggle::make('is_preferred')
                ->label(__('Preferred supplier'))
                ->helperText(__('Preferred suppliers are highlighted first when buyers source this variant.')),
            TextInput::make('supplier_name')
                ->label(__('Supplier product name'))
                ->maxLength(255)
                ->placeholder(__('Optional — supplier naming can be added later')),
            TextInput::make('supplier_item_number')
                ->label(__('Supplier item number'))
                ->maxLength(100)
                ->placeholder(__('Optional')),
            TextInput::make('purchase_cost')
                ->label(__('Reference cost'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->helperText(__('Optional. Buyers may enter the quoted PO cost when no reference cost is available.')),
            CurrencySelect::make('currency_code')->label(__('Currency')),
            TextInput::make('lead_time_days')
                ->label(__('Lead time (days)'))
                ->numeric()
                ->minValue(0)
                ->maxValue(3650),
            TextInput::make('minimum_order_quantity')
                ->label(__('Minimum order quantity'))
                ->numeric()
                ->minValue(0)
                ->step(0.001),
            DatePicker::make('valid_from')->label(__('Valid from')),
            DatePicker::make('valid_to')->label(__('Valid to')),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder(__('Product, SKU, supplier…'))
            ->defaultSort('is_preferred', 'desc')
            ->columns([
                ImageColumn::make('product_image')
                    ->label('')
                    ->getStateUsing(static fn (SupplierProductReference $record): ?string => $record->productVariant?->mainImageUrl())
                    ->imageHeight(42)
                    ->square(),
                ImageColumn::make('supplier.logo_path')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->imageHeight(36)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('productVariant.product.name')
                    ->label(__('Product'))
                    ->description(static fn (SupplierProductReference $record): string => self::referenceVariantDescription($record))
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query
                        ->where('supplier_item_number', 'like', "%{$search}%")
                        ->orWhere('supplier_name', 'like', "%{$search}%")
                        ->orWhereHas('productVariant', static fn (Builder $variant): Builder => $variant
                            ->where('sku', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhereHas('product', static fn (Builder $product): Builder => $product
                                ->where('name', 'like', "%{$search}%"))))
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label(__('Supplier'))
                    ->description(static fn (SupplierProductReference $record): ?string => $record->supplier_item_number ?: $record->supplier_name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('availability_status')
                    ->label(__('Availability'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => match ($state) {
                        'temporarily_unavailable' => 'Temporarily unavailable',
                        'discontinued' => 'Discontinued',
                        default => 'Active',
                    })
                    ->color(static fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'temporarily_unavailable' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('purchase_cost')
                    ->label(__('Reference cost'))
                    ->money(static fn (SupplierProductReference $record): string => $record->currency_code)
                    ->placeholder(__('Not configured'))
                    ->sortable(),
                TextColumn::make('purchaseUnit.name')
                    ->label(__('Purchase UoM'))
                    ->placeholder(__('Base purchase UoM'))
                    ->toggleable(),
                TextColumn::make('pack_size')
                    ->label(__('Pack size'))
                    ->numeric(decimalPlaces: 6)
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('lead_time_days')
                    ->label(__('Lead time'))
                    ->suffix(__(' days'))
                    ->placeholder(__('—'))
                    ->sortable(),
                TextColumn::make('valid_from')->label(__('Valid from'))->date()->placeholder(__('—'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('valid_to')->label(__('Valid to'))->date()->placeholder(__('—'))->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_preferred')
                    ->label(__('Preferred'))
                    ->boolean()
                    ->trueIcon(Heroicon::Star)
                    ->falseIcon(Heroicon::OutlinedStar),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label(__('Supplier'))
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('availability_status')
                    ->label(__('Availability'))
                    ->options([
                        'active' => 'Active',
                        'temporarily_unavailable' => 'Temporarily unavailable',
                        'discontinued' => 'Discontinued',
                    ]),
                TernaryFilter::make('is_preferred')->label(__('Preferred supplier')),
                SelectFilter::make('currency_code')->label(__('Currency'))->options(fn (): array => SupplierProductReference::query()
                    ->whereNotNull('currency_code')
                    ->distinct()
                    ->orderBy('currency_code')
                    ->pluck('currency_code', 'currency_code')
                    ->all()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                    RestoreAction::make(),
                ]),
            ]);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'supplier',
            'productVariant.media',
            'productVariant.product.media',
            'productVariant.product.brand',
            'purchaseUnit',
        ]);
    }

    /** @return array<int, string> */
    private static function purchaseUnitOptions(mixed $variantId): array
    {
        if (! is_numeric($variantId)) {
            return [];
        }

        return ProductVariantUnit::query()
            ->with('unit:id,name,symbol')
            ->where('product_variant_id', (int) $variantId)
            ->where('is_active', true)
            ->where('is_purchase', true)
            ->orderByDesc('is_base')
            ->get()
            ->mapWithKeys(static fn (ProductVariantUnit $configuration): array => $configuration->unit instanceof Unit
                ? [$configuration->unit_id => mb_trim($configuration->unit->name.' · '.$configuration->unit->symbol, ' ·')]
                : [])
            ->all();
    }

    private static function variantLabel(ProductVariant $variant): string
    {
        $product = $variant->product;
        $productName = $product instanceof Product ? $product->name : 'Product';

        return mb_trim($productName.' · '.$variant->name.' · '.$variant->sku, ' ·');
    }

    private static function referenceVariantDescription(SupplierProductReference $reference): string
    {
        $variant = $reference->productVariant;

        if (! $variant instanceof ProductVariant) {
            return 'Variant unavailable';
        }

        return mb_trim($variant->name.' · '.$variant->sku, ' ·');
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageSupplierProductReferences::route('/')];
    }
}
