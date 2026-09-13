<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Models\ProductVariantUnit;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Services\Purchasing\PurchaseOrderService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.resources.purchase_orders'))
                    ->description('Create the commercial order and its supplier-supported items. Warehouse allocation happens after acceptance.')
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
                        TextInput::make('currency_code')
                            ->label(__('admin.purchasing.fields.currency_code'))
                            ->required()
                            ->length(3)
                            ->default('AED')
                            ->extraInputAttributes(['style' => 'text-transform: uppercase']),
                        DatePicker::make('ordered_at')
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
                    ->description('Choose products supplied by this supplier. Destinations are allocated to warehouses only after the PO is accepted.')
                    ->visible(fn (?PurchaseOrder $record): bool => ! ($record instanceof PurchaseOrder))
                    ->schema([
                        Repeater::make('lines')
                            ->label('')
                            ->minItems(1)
                            ->required()
                            ->reorderable(false)
                            ->schema([
                                Select::make('product_variant_id')
                                    ->label(__('admin.purchasing.fields.product_variant'))
                                    ->options(fn (Get $get): array => self::supportedVariantOptions($get('../../supplier_id')))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->required()
                                    ->disabled(fn (Get $get): bool => ! is_numeric($get('../../supplier_id')))
                                    ->helperText('Only active items configured for the selected supplier are available.')
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                        if (! is_numeric($state)) {
                                            $set('unit_id', null);
                                            $set('unit_cost', null);

                                            return;
                                        }

                                        $unitId = self::defaultPurchaseUnitId((int) $state);
                                        $set('unit_id', $unitId);
                                        $set('unit_cost', self::defaultUnitCost($get('../../supplier_id'), (int) $state, $unitId));
                                    }),
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
                                    }),
                                TextInput::make('quantity_ordered')
                                    ->label(__('admin.purchasing.fields.quantity_ordered'))
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
                                DatePicker::make('expected_at')
                                    ->label(__('admin.purchasing.fields.expected_at')),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                    ]),
            ])
            ->disabled(fn (?PurchaseOrder $record): bool => $record instanceof PurchaseOrder && ! $record->status->isEditable());
    }

    /** @return array<int, string> */
    private static function supportedVariantOptions(mixed $supplierId): array
    {
        if (! is_numeric($supplierId)) {
            return [];
        }

        $order = new PurchaseOrder;
        $order->supplier_id = (int) $supplierId;

        return app(PurchaseOrderService::class)->supportedVariantOptions($order);
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
}
