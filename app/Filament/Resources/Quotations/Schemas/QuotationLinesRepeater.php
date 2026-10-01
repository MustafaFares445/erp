<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Schemas;

use App\Data\Inventory\ResolvedPrice;
use App\Enums\InventoryPermission;
use App\Filament\Resources\Quotations\Support\QuotationLinePriceFloorApprovals;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Unit;
use App\Services\Inventory\PriceResolver;
use App\Services\Sales\PriceProvenanceService;
use App\Services\Sales\QuotationService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * A quotation's lines as a plain array field, deliberately **not**
 * `->relationship()` — {@see QuotationService::syncLines()}
 * resolves each line's default price and tax and recomputes document totals,
 * so persistence must go through the service (via the Create/Edit pages'
 * `handleRecordCreation`/`handleRecordUpdate`), not Filament's own
 * relationship-repeater save.
 *
 * `unit_price` is pre-filled with the resolved tier price as soon as a
 * variant is chosen (FR-015), while `use_tier_price` is checked. Unchecking
 * it hands pricing back to the author: the field becomes editable and stops
 * recalculating on variant/quantity changes. `use_tier_price` is a
 * presentational toggle only — it is never dehydrated — so the service
 * still tells "left at the resolved default" apart from "overridden" by
 * comparing the submitted amount against a freshly resolved one, not by
 * blank-vs-filled.
 *
 * A manual price below the variant's floor (FR-016) still needs an approved
 * `price_floor_override_id` to save — that refusal is unchanged. What this
 * schema adds is the affordance to get one: `price_floor_override_reason`
 * appears only for someone who holds
 * {@see InventoryPermission::PriceFloorApprove}, and the
 * Create/Edit pages spend it via
 * {@see QuotationLinePriceFloorApprovals}
 * before the line ever reaches {@see QuotationService}.
 */
final class QuotationLinesRepeater
{
    public static function make(): Repeater
    {
        return Repeater::make('lines')
            ->columns(12)
            ->schema([
                Grid::make(12)
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('product_variant_image')
                            ->label(new HtmlString('&nbsp;'))
                            ->content(static fn (Get $get): HtmlString => self::productImagePreview($get('product_variant_id')))
                            ->columnSpan(1),
                        Select::make('product_id')
                            ->label(__('admin.sales.fields.product'))
                            ->options(fn (): array => self::productOptions())
                            ->searchable()
                            ->searchPrompt(__('Search by product name...'))
                            ->searchDebounce(300)
                            ->preload()
                            ->required()
                            ->live()
                            ->dehydrated(false)
                            ->columnSpan(fn (Get $get): int => self::hasMultipleVariants($get('product_id')) ? 3 : 5)
                            ->afterStateHydrated(function (Select $component, mixed $state, Get $get): void {
                                if ($state !== null || ! is_numeric($get('product_variant_id'))) {
                                    return;
                                }

                                $component->state(ProductVariant::query()
                                    ->whereKey((int) $get('product_variant_id'))
                                    ->value('product_id'));
                            })
                            ->afterStateUpdated(static function (Set $set, Get $get, mixed $state): void {
                                $variantId = self::singleVariantId($state);
                                $set('product_variant_id', $variantId);
                                $set('unit_id', self::defaultSaleUnitId($variantId));

                                if ($get('use_tier_price')) {
                                    $set('unit_price', self::resolvedUnitPrice($variantId, $get));
                                }
                            }),
                        Select::make('product_variant_id')
                            ->label(__('admin.sales.fields.product_variant'))
                            ->options(fn (Get $get): array => self::variantOptions($get('product_id')))
                            ->searchable()
                            ->searchPrompt(__('Search by SKU...'))
                            ->searchDebounce(300)
                            ->preload()
                            ->disabled(fn (Get $get): bool => ! is_numeric($get('product_id')))
                            ->visible(fn (Get $get): bool => self::hasMultipleVariants($get('product_id')))
                            ->required(fn (Get $get): bool => self::hasMultipleVariants($get('product_id')))
                            ->dehydrated(true)
                            ->dehydratedWhenHidden()
                            ->live()
                            ->columnSpan(2)
                            ->afterStateUpdated(static function (Set $set, Get $get, mixed $state): void {
                                $set('unit_id', self::defaultSaleUnitId($state));

                                if ($get('use_tier_price')) {
                                    $set('unit_price', self::resolvedUnitPrice($state, $get));
                                }
                            }),
                        Select::make('unit_id')
                            ->label(__('admin.sales.fields.unit'))
                            ->options(static fn (Get $get): array => self::saleUnitOptions($get('product_variant_id')))
                            ->searchable()
                            ->searchPrompt(__('Search by unit name...'))
                            ->searchDebounce(300)
                            ->preload()
                            ->required()
                            ->live()
                            ->columnSpan(3),
                        TextInput::make('quantity')
                            ->label(__('admin.sales.fields.quantity'))
                            ->numeric()
                            ->minValue(0.001)
                            ->required()
                            ->columnSpan(3),
                    ]),
                Grid::make(12)
                    ->columnSpanFull()
                    ->schema([
                        Checkbox::make('use_tier_price')
                            ->label(__('admin.sales.fields.use_tier_price'))
                            ->default(true)
                            ->live()
                            ->dehydrated(false)
                            ->columnSpan(3)
                            ->extraFieldWrapperAttributes(['class' => 'flex items-end pb-2.5'])
                            ->afterStateUpdated(static function (Set $set, Get $get, bool $state): void {
                                if ($state) {
                                    $set('unit_price', self::resolvedUnitPrice($get('product_variant_id'), $get));
                                }
                            }),
                        TextInput::make('unit_price')
                            ->label(__('admin.sales.fields.unit_price'))
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->columnSpan(5)
                            ->readOnly(static fn (Get $get): bool => (bool) $get('use_tier_price'))
                            ->helperText(static fn (Get $get): ?string => $get('use_tier_price')
                                ? self::resolvedPriceHelperText($get)
                                : self::belowFloorHelperText($get)),
                        TextInput::make('tax_amount')
                            ->label(__('admin.sales.fields.tax_amount'))
                            ->numeric()
                            ->minValue(0)
                            ->columnSpan(4),
                    ]),
                Hidden::make('price_floor_override_id'),
                Textarea::make('price_floor_override_reason')
                    ->label(__('admin.sales.fields.price_floor_override_reason'))
                    ->rows(2)
                    ->columnSpanFull()
                    ->visible(self::needsFloorOverrideReason(...))
                    ->required(self::needsFloorOverrideReason(...)),
                RichEditor::make('description')
                    ->label(__('admin.sales.fields.description'))
                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'underline'])
                    ->columnSpanFull(),
            ])
            ->addActionLabel(__('admin.sales.actions.add_line'))
            ->required()
            ->minItems(1)
            ->reorderable(false);
    }

    private static function productImagePreview(mixed $variantId): HtmlString
    {
        $url = is_numeric($variantId)
            ? ProductVariant::find((int) $variantId)?->mainImageUrl()
            : null;

        if ($url === null) {
            return new HtmlString(
                '<div class="flex h-16 w-16 items-center justify-center rounded-lg bg-gray-100 text-xs text-gray-400 dark:bg-gray-800">No image</div>'
            );
        }

        return new HtmlString(
            '<img src="'.e($url).'" alt="" class="h-16 w-16 rounded-lg object-cover" />'
        );
    }

    private static function resolvedPriceHelperText(Get $get): ?string
    {
        $resolved = self::resolvePrice($get('product_variant_id'), $get);

        if (! $resolved instanceof ResolvedPrice) {
            return null;
        }

        return __('admin.sales.hints.resolved_price_source', [
            'source' => $resolved->source->label(),
            'amount' => number_format($resolved->amount, 2),
        ]);
    }

    private static function resolvedUnitPrice(mixed $variantId, Get $get): ?float
    {
        return self::resolvePrice($variantId, $get)?->amount;
    }

    /**
     * A best-effort preview of the same floor check
     * {@see PriceProvenanceService::forManualPrice()} runs
     * authoritatively on save. It exists to decide whether the reason field
     * is shown, not to be the source of truth: the service still refuses the
     * line if this preview is wrong or was bypassed.
     */
    private static function belowFloorHelperText(Get $get): ?string
    {
        $variantId = $get('product_variant_id');

        if (! is_numeric($variantId)) {
            return null;
        }

        $variant = ProductVariant::find((int) $variantId);

        if (! $variant instanceof ProductVariant || $variant->min_price === null) {
            return null;
        }

        $baseEquivalentPrice = self::baseEquivalentPrice($get, $variant);

        if ($baseEquivalentPrice === null || $baseEquivalentPrice >= (float) $variant->min_price) {
            return null;
        }

        $replacements = ['floor' => number_format((float) $variant->min_price, 2)];

        if (is_numeric($get('price_floor_override_id'))) {
            return __('admin.sales.hints.below_floor', $replacements);
        }

        return self::canApproveFloorOverride()
            ? __('admin.sales.hints.below_floor_can_approve', $replacements)
            : __('admin.sales.hints.below_floor_needs_approval', $replacements);
    }

    private static function needsFloorOverrideReason(Get $get): bool
    {
        if (is_numeric($get('price_floor_override_id')) || ! self::canApproveFloorOverride()) {
            return false;
        }

        $variantId = $get('product_variant_id');

        if (! is_numeric($variantId)) {
            return false;
        }

        $variant = ProductVariant::find((int) $variantId);

        if (! $variant instanceof ProductVariant || $variant->min_price === null) {
            return false;
        }

        $baseEquivalentPrice = self::baseEquivalentPrice($get, $variant);

        return $baseEquivalentPrice !== null && $baseEquivalentPrice < (float) $variant->min_price;
    }

    private static function canApproveFloorOverride(): bool
    {
        return (bool) auth()->user()?->can(InventoryPermission::PriceFloorApprove->value);
    }

    /**
     * The manually entered `unit_price`, converted back to the variant's
     * base unit so it is comparable to `min_price` — mirroring
     * {@see PriceProvenanceService::forManualPrice()}.
     */
    private static function baseEquivalentPrice(Get $get, ProductVariant $variant): ?float
    {
        $unitPrice = $get('unit_price');

        if (! is_numeric($unitPrice)) {
            return null;
        }

        $unitId = $get('unit_id');
        $factorToBase = is_numeric($unitId)
            ? ProductVariantUnit::query()
                ->where('product_variant_id', $variant->getKey())
                ->where('unit_id', (int) $unitId)
                ->value('factor_to_base')
            : null;

        $factor = is_numeric($factorToBase) ? (float) $factorToBase : 1.0;

        return $factor > 0.0 ? (float) $unitPrice / $factor : null;
    }

    private static function resolvePrice(mixed $variantId, Get $get): ?ResolvedPrice
    {
        if (! is_numeric($variantId)) {
            return null;
        }

        $variant = ProductVariant::find((int) $variantId);

        if (! $variant instanceof ProductVariant) {
            return null;
        }

        $customerId = $get('../../customer_id');
        $customer = is_numeric($customerId)
            ? CustomerProfile::find((int) $customerId)?->user
            : null;

        return app(PriceResolver::class)->resolve($variant, $customer);
    }

    /** @return array<int, string> */
    private static function saleUnitOptions(mixed $variantId): array
    {
        if (! is_numeric($variantId)) {
            return [];
        }

        return ProductVariantUnit::query()
            ->with('unit:id,name,symbol')
            ->where('product_variant_id', (int) $variantId)
            ->where('is_active', true)
            ->where('is_sale', true)
            ->orderByDesc('is_base')
            ->get()
            ->mapWithKeys(static function (ProductVariantUnit $configuration): array {
                $unit = $configuration->unit;

                return [
                    $configuration->unit_id => $unit instanceof Unit
                        ? $unit->name
                        : (string) $configuration->unit_id,
                ];
            })
            ->all();
    }

    private static function defaultSaleUnitId(mixed $variantId): ?int
    {
        if (! is_numeric($variantId)) {
            return null;
        }

        $unitId = ProductVariantUnit::query()
            ->where('product_variant_id', (int) $variantId)
            ->where('is_active', true)
            ->where('is_sale', true)
            ->orderByDesc('is_base')
            ->value('unit_id');

        return is_numeric($unitId) ? (int) $unitId : null;
    }

    /** @return array<int, string> */
    private static function productOptions(): array
    {
        $options = [];

        foreach (Product::query()
            ->where('is_active', true)
            ->whereHas('variants', fn (Builder $variants): Builder => $variants->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name']) as $product) {
            $productId = self::toInteger($product->getKey());

            if ($productId !== null) {
                $options[$productId] = (string) $product->name;
            }
        }

        return $options;
    }

    /** @return array<int, string> */
    private static function variantOptions(mixed $productId): array
    {
        if (! is_numeric($productId)) {
            return [];
        }

        $options = [];

        foreach (ProductVariant::query()
            ->where('product_id', (int) $productId)
            ->where('is_active', true)
            ->orderBy('sku')
            ->get(['id', 'sku']) as $variant) {
            $variantId = self::toInteger($variant->getKey());

            if ($variantId !== null) {
                $options[$variantId] = (string) $variant->sku;
            }
        }

        return $options;
    }

    private static function hasMultipleVariants(mixed $productId): bool
    {
        return count(self::variantOptions($productId)) > 1;
    }

    private static function singleVariantId(mixed $productId): ?int
    {
        $variantIds = array_keys(self::variantOptions($productId));

        return count($variantIds) === 1 ? (int) $variantIds[0] : null;
    }

    private static function toInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
