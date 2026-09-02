<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Schemas;

use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Unit;
use App\Services\Sales\QuotationService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * A quotation's lines as a plain array field, deliberately **not**
 * `->relationship()` — {@see QuotationService::syncLines()}
 * resolves each line's default price and tax and recomputes document totals,
 * so persistence must go through the service (via the Create/Edit pages'
 * `handleRecordCreation`/`handleRecordUpdate`), not Filament's own
 * relationship-repeater save.
 *
 * `unit_price` and `tax_amount` are left blank by default so the service can
 * tell "no override given" apart from "overridden to zero" (FR-015, FR-017).
 */
final class QuotationLinesRepeater
{
    public static function make(): Repeater
    {
        return Repeater::make('lines')
            ->columns(5)
            ->schema([
                Select::make('product_variant_id')
                    ->label(__('admin.sales.fields.product_variant'))
                    ->options(fn (): array => ProductVariant::query()
                        ->where('is_active', true)
                        ->orderBy('sku')
                        ->pluck('sku', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(static function (Set $set, mixed $state): void {
                        $set('unit_id', self::defaultSaleUnitId($state));
                    }),
                Select::make('unit_id')
                    ->label(__('admin.sales.fields.unit'))
                    ->options(static fn (Get $get): array => self::saleUnitOptions($get('product_variant_id')))
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('quantity')
                    ->label(__('admin.sales.fields.quantity'))
                    ->numeric()
                    ->minValue(0.001)
                    ->required(),
                TextInput::make('unit_price')
                    ->label(__('admin.sales.fields.unit_price'))
                    ->numeric()
                    ->minValue(0)
                    ->placeholder(__('admin.sales.hints.resolved_price_source')),
                TextInput::make('tax_amount')
                    ->label(__('admin.sales.fields.tax_amount'))
                    ->numeric()
                    ->minValue(0),
                TextInput::make('description')
                    ->label(__('admin.sales.fields.description'))
                    ->columnSpan(5),
            ])
            ->addActionLabel(__('admin.sales.actions.add_line'))
            ->required()
            ->minItems(1);
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
}
