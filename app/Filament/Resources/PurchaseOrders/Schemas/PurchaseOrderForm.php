<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Enums\PurchaseOrderDocument;
use App\Filament\Support\CurrencySelect;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Services\Purchasing\PurchaseOrderService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.resources.purchase_orders'))
                ->description(__('admin.purchasing.hints.po_commercial_only'))
                ->schema([
                    TextInput::make('purchase_order_number')
                        ->label(__('admin.purchasing.fields.purchase_order_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?PurchaseOrder $record): bool => $record instanceof PurchaseOrder),
                    Select::make('supplier_id')
                        ->label(__('admin.purchasing.fields.supplier'))
                        ->options(fn (): array => Supplier::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        ->disabled(fn (?PurchaseOrder $record): bool => $record instanceof PurchaseOrder && $record->lines()->exists())
                        ->afterStateUpdated(function (Set $set, ?PurchaseOrder $record): void {
                            if (! $record instanceof PurchaseOrder) {
                                $set('lines', []);
                            }
                        }),
                    CurrencySelect::make('currency_code')
                        ->label(__('admin.purchasing.fields.currency_code'))
                        ->required(),                    DatePicker::make('ordered_at')
                        ->label(__('admin.purchasing.fields.ordered_at'))
                        ->required()
                        ->default(today()),
                    DatePicker::make('expected_at')
                        ->label(__('admin.purchasing.fields.expected_at')),
                    Textarea::make('notes')
                        ->label(__('admin.purchasing.fields.notes'))
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(__('admin.purchasing.fields.lines'))
                ->description(__('admin.purchasing.hints.po_supplier_first'))
                ->visible(fn (Get $get, ?PurchaseOrder $record): bool => ! $record instanceof PurchaseOrder && is_numeric($get('supplier_id')))
                ->schema([
                    Repeater::make('lines')
                        ->label('')
                        ->defaultItems(0)
                        ->minItems(1)
                        ->required()
                        ->reorderable(false)
                        ->addActionLabel(__('admin.purchasing.actions.add_line'))
                        ->schema([
                            Select::make('product_id')
                                ->label(__('admin.purchasing.fields.product'))
                                ->options(fn (Get $get): array => self::supportedProductOptions($get('../../supplier_id')))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required()
                                ->dehydrated(false)->afterStateUpdated(function (Set $set): void {
                                    $set('product_variant_id', null);
                                    $set('unit_id', null);
                                    $set('unit_cost', null);
                                    $set('brand', null);
                                    $set('supplier_product_name', null);
                                    $set('supplier_item_number_preview', null);
                                    $set('reference_currency', null);
                                }),
                            Select::make('product_variant_id')
                                ->label(__('admin.purchasing.fields.product_variant'))
                                ->options(fn (Get $get): array => self::supportedVariantOptions(
                                    $get('../../supplier_id'),
                                    $get('product_id'),
                                ))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required()
                                ->disabled(fn (Get $get): bool => ! is_numeric($get('product_id')))
                                ->helperText(__('admin.purchasing.hints.supplier_variants_only'))
                                ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                    if (! is_numeric($state)) {
                                        self::clearVariantContext($set);

                                        return;
                                    }

                                    $variantId = (int) $state;
                                    $unitId = self::defaultPurchaseUnitId($variantId);
                                    $set('unit_id', $unitId);
                                    $set('unit_cost', self::defaultUnitCost($get('../../supplier_id'), $variantId, $unitId));
                                    self::fillVariantContext($get('../../supplier_id'), $variantId, $set);
                                }),                            TextInput::make('brand')
                                ->label(__('admin.purchasing.fields.brand'))
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('supplier_product_name')
                                ->label(__('admin.purchasing.fields.supplier_product_name'))
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('supplier_item_number_preview')
                                ->label(__('admin.purchasing.fields.supplier_item_number'))
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('reference_currency')
                                ->label(__('admin.purchasing.fields.currency_code'))
                                ->disabled()
                                ->dehydrated(false),
                            Select::make('unit_id')
                                ->label(__('admin.purchasing.fields.unit'))
                                ->options(fn (Get $get): array => self::purchaseUnitOptions($get('product_variant_id')))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required()
                                ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                    if (! is_numeric($state) || ! is_numeric($get('product_variant_id'))) {
                                        return;
                                    }

                                    $set('unit_cost', self::defaultUnitCost(
                                        $get('../../supplier_id'),
                                        (int) $get('product_variant_id'),
                                        (int) $state,
                                    ));
                                }),                            TextInput::make('quantity_ordered')
                                ->label(__('admin.purchasing.fields.quantity'))
                                ->numeric()
                                ->minValue(0.001)
                                ->step(0.001)
                                ->required(),
                            TextInput::make('unit_cost')
                                ->label(__('admin.purchasing.fields.unit_cost'))
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->required()
                                ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.purchasing.hints.unit_cost_source')),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ]),
            Section::make(__('admin.purchasing.sections.documents'))
                ->description(__('admin.purchasing.descriptions.documents'))
                ->columns(2)
                ->schema(array_map(
                    self::purchaseOrderDocumentUpload(...),
                    PurchaseOrderDocument::cases(),
                )),
        ])->disabled(fn (?PurchaseOrder $record): bool => $record instanceof PurchaseOrder && ! $record->status->isEditable());
    }

    private static function purchaseOrderDocumentUpload(PurchaseOrderDocument $document): FileUpload
    {
        return FileUpload::make($document->value)
            ->label($document->label())
            ->multiple()
            ->maxFiles(1)
            ->formatStateUsing(static fn (mixed $state): array => is_array($state) ? $state : (filled($state) ? [$state] : []))
            ->mutateStateForValidationUsing(static fn (mixed $state): array => is_array($state) ? $state : (filled($state) ? [$state] : []))
            ->disk('local')
            ->directory('purchase-order-documents/'.$document->value)
            ->visibility('private')
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(5120)
            ->preventFilePathTampering(
                allowFilePathUsing: static function (?PurchaseOrder $record, string $file) use ($document): bool {
                    if (! $record instanceof PurchaseOrder) {
                        return false;
                    }

                    return $record->getFirstMedia($document->value)?->getPathRelativeToRoot() === $file;
                },
            )
            ->afterStateHydrated(static function (FileUpload $component, ?PurchaseOrder $record) use ($document): void {
                if (! $record instanceof PurchaseOrder) {
                    return;
                }

                $media = $record->getFirstMedia($document->value);

                $component->state($media instanceof Media ? [$media->getPathRelativeToRoot()] : []);
            });
    }

    /** @return array<int, string> */
    private static function supportedProductOptions(mixed $supplierId): array
    {
        if (! is_numeric($supplierId)) {
            return [];
        }

        return SupplierProductReference::query()
            ->where('supplier_id', (int) $supplierId)
            ->where('is_active', true)
            ->whereHas('productVariant', static fn (Builder $query) => $query->where('is_active', true)
                ->whereHas('product', static fn (Builder $products) => $products->where('is_active', true)))
            ->with('productVariant.product:id,name')->get()
            ->mapWithKeys(static function (SupplierProductReference $reference): array {
                $product = $reference->productVariant?->product;

                return $product instanceof Product ? [$product->id => $product->name] : [];
            })
            ->unique()
            ->sort()
            ->all();
    }

    /** @return array<int, string> */
    private static function supportedVariantOptions(mixed $supplierId, mixed $productId): array
    {
        if (! is_numeric($supplierId) || ! is_numeric($productId)) {
            return [];
        }

        return SupplierProductReference::query()
            ->where('supplier_id', (int) $supplierId)
            ->where('is_active', true)
            ->whereHas('productVariant', static fn (Builder $query) => $query
                ->where('product_id', (int) $productId)
                ->where('is_active', true))
            ->with('productVariant:id,product_id,sku,name')
            ->orderBy('supplier_item_number')
            ->get()
            ->mapWithKeys(static function (SupplierProductReference $reference): array {
                $variant = $reference->productVariant;
                if (! $variant instanceof ProductVariant) {
                    return [];
                }

                $label = $variant->name !== '' ? $variant->name.' ('.$variant->sku.')' : $variant->sku;

                return [$variant->id => $label];
            })
            ->all();
    }

    /** @return array<int, string> */
    private static function purchaseUnitOptions(mixed $variantId): array
    {
        if (! is_numeric($variantId)) {
            return [];
        }

        return ProductVariantUnit::query()
            ->with('unit:id,name')
            ->where('product_variant_id', (int) $variantId)
            ->where('is_active', true)
            ->where('is_purchase', true)
            ->orderByDesc('is_base')
            ->get()
            ->mapWithKeys(static fn (ProductVariantUnit $configuration): array => $configuration->unit instanceof Unit
                ? [$configuration->unit_id => $configuration->unit->name]
                : [])
            ->all();
    }

    private static function defaultPurchaseUnitId(int $variantId): ?int
    {
        $unitId = ProductVariantUnit::query()
            ->where('product_variant_id', $variantId)
            ->where('is_active', true)
            ->where('is_purchase', true)
            ->orderByDesc('is_base')
            ->value('unit_id');

        return is_numeric($unitId) ? (int) $unitId : null;
    }

    private static function defaultUnitCost(mixed $supplierId, int $variantId, ?int $unitId): float
    {
        if (! is_numeric($supplierId) || ! is_int($unitId)) {
            return 0.0;
        }

        $reference = app(PurchaseOrderService::class)->referenceFor((int) $supplierId, $variantId);
        if (! $reference instanceof SupplierProductReference) {
            return 0.0;
        }

        $factor = ProductVariantUnit::query()
            ->where('product_variant_id', $variantId)
            ->where('unit_id', $unitId)
            ->where('is_active', true)
            ->where('is_purchase', true)
            ->value('factor_to_base');

        return is_numeric($factor)
            ? round((float) $reference->purchase_cost * (float) $factor, 2)
            : 0.0;
    }

    private static function fillVariantContext(mixed $supplierId, int $variantId, Set $set): void
    {
        if (! is_numeric($supplierId)) {
            self::clearVariantContext($set);

            return;
        }

        $reference = SupplierProductReference::query()
            ->with('productVariant.product.brand')
            ->activeFor((int) $supplierId, $variantId)
            ->first();
        if (! $reference instanceof SupplierProductReference) {
            self::clearVariantContext($set);

            return;
        }

        $set('brand', $reference->productVariant?->product?->brand?->name);
        $set('supplier_product_name', $reference->supplier_name);
        $set('supplier_item_number_preview', $reference->supplier_item_number);
        $set('reference_currency', $reference->currency_code);
    }

    private static function clearVariantContext(Set $set): void
    {
        $set('unit_id', null);
        $set('unit_cost', null);
        $set('brand', null);
        $set('supplier_product_name', null);
        $set('supplier_item_number_preview', null);
        $set('reference_currency', null);
    }
}
