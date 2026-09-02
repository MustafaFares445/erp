<?php

declare(strict_types=1);

use App\Enums\QuotationDecision;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductVariantUomService;
use App\Services\Sales\QuotationConversionService;
use App\Services\Sales\QuotationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('prices and snapshots a quotation in its selected sale UOM', function (): void {
    [$variant, $piece, $box] = salesUomVariant();

    $quotation = app(QuotationService::class)->create(
        [
            'customer_id' => CustomerProfile::factory()->create()->getKey(),
            'issue_date' => now()->toDateString(),
        ],
        [[
            'product_variant_id' => $variant->getKey(),
            'unit_id' => $box->getKey(),
            'quantity' => 2,
        ]],
    );

    $line = $quotation->lines()->sole();

    expect($line->unit_id)->toBe($box->getKey())
        ->and($line->transaction_unit_id)->toBe($box->getKey())
        ->and($line->transaction_quantity)->toBe('2.000000')
        ->and($line->conversion_factor_snapshot)->toBe('100.000000')
        ->and($line->base_quantity)->toBe('200.000000')
        // The variant base price is 2 per Piece, so one Box of 100 Pieces is 200.
        ->and($line->unit_price)->toBe('200.00')
        ->and($variant->unit_id)->toBe($piece->getKey());
});

it('preserves six decimal places in the commercial quantity and its frozen base snapshot', function (): void {
    $unit = Unit::factory()->create([
        'code' => 'SALES-SIX-DECIMAL',
        'name' => 'Sales six decimal unit',
        'symbol' => 'S6D',
        'family' => 'volume',
        'precision' => 6,
        'allows_decimal' => true,
    ]);
    $variant = ProductVariant::factory()->create([
        'unit_id' => $unit->getKey(),
        'base_price' => 1,
        'min_price' => 0,
    ]);

    app(ProductVariantUomService::class)->sync($variant, [[
        'unit_id' => $unit->getKey(),
        'is_base' => true,
        'is_purchase' => true,
        'is_sale' => true,
        'is_display' => true,
        'factor_to_base' => '1',
        'rounding_increment' => '0.000001',
        'permits_cross_family_conversion' => false,
        'is_active' => true,
    ]]);

    $quotation = app(QuotationService::class)->create(
        [
            'customer_id' => CustomerProfile::factory()->create()->getKey(),
            'issue_date' => now()->toDateString(),
        ],
        [[
            'product_variant_id' => $variant->getKey(),
            'unit_id' => $unit->getKey(),
            'quantity' => '1.123456',
            'unit_price' => 1,
            'tax_amount' => 0,
        ]],
    );

    $line = $quotation->lines()->sole();

    expect($line->quantity)->toBe('1.123456')
        ->and($line->transaction_quantity)->toBe('1.123456')
        ->and($line->conversion_factor_snapshot)->toBe('1.000000')
        ->and($line->base_quantity)->toBe('1.123456');
});

it('rejects an explicitly supplied UOM that is not configured for sales', function (): void {
    [$variant] = salesUomVariant();
    $unconfigured = Unit::factory()->whole()->create([
        'code' => 'SALES-NOT-ALLOWED',
        'name' => 'Not allowed sale unit',
        'symbol' => 'NSA',
        'family' => 'count',
    ]);

    expect(fn () => app(QuotationService::class)->create(
        [
            'customer_id' => CustomerProfile::factory()->create()->getKey(),
            'issue_date' => now()->toDateString(),
        ],
        [[
            'product_variant_id' => $variant->getKey(),
            'unit_id' => $unconfigured->getKey(),
            'quantity' => 1,
        ]],
    ))->toThrow(ValidationException::class);
});

it('preserves mixed sale UOM lines for the same SKU through quotation conversion', function (): void {
    [$variant, $piece, $box] = salesUomVariant();
    $quotation = app(QuotationService::class)->create(
        [
            'customer_id' => CustomerProfile::factory()->create()->getKey(),
            'issue_date' => now()->toDateString(),
        ],
        [
            [
                'product_variant_id' => $variant->getKey(),
                'unit_id' => $box->getKey(),
                'quantity' => 1,
                'unit_price' => 200,
                'tax_amount' => 0,
            ],
            [
                'product_variant_id' => $variant->getKey(),
                'unit_id' => $piece->getKey(),
                'quantity' => 50,
                'unit_price' => 2,
                'tax_amount' => 0,
            ],
        ],
    );

    $quotation = acceptSalesUomQuotation($quotation);

    $order = app(QuotationConversionService::class)->convert($quotation);
    $lines = $order->lines()->orderBy('unit_id')->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->sum(fn ($line): float => (float) $line->base_quantity))->toBe(150.0);

    $boxLine = $lines->firstWhere('unit_id', $box->getKey());
    $pieceLine = $lines->firstWhere('unit_id', $piece->getKey());

    expect($boxLine)->not->toBeNull()
        ->and($boxLine?->transaction_quantity)->toBe('1.000000')
        ->and($boxLine?->conversion_factor_snapshot)->toBe('100.000000')
        ->and($boxLine?->base_quantity)->toBe('100.000000')
        ->and($pieceLine)->not->toBeNull()
        ->and($pieceLine?->transaction_quantity)->toBe('50.000000')
        ->and($pieceLine?->conversion_factor_snapshot)->toBe('1.000000')
        ->and($pieceLine?->base_quantity)->toBe('50.000000');
});

/** @return array{ProductVariant, Unit, Unit} */
function salesUomVariant(): array
{
    $piece = Unit::factory()->whole()->create([
        'code' => 'SALES-PIECE',
        'name' => 'Sales Piece',
        'symbol' => 'SPC',
        'family' => 'count',
    ]);
    $box = Unit::factory()->whole()->create([
        'code' => 'SALES-BOX',
        'name' => 'Sales Box',
        'symbol' => 'SBX',
        'family' => 'count',
    ]);
    $variant = ProductVariant::factory()->create([
        'unit_id' => $piece->getKey(),
        'base_price' => 2,
        'min_price' => 1,
    ]);

    app(ProductVariantUomService::class)->sync($variant, [
        [
            'unit_id' => $piece->getKey(),
            'is_base' => true,
            'is_purchase' => true,
            'is_sale' => true,
            'is_display' => true,
            'factor_to_base' => '1',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ],
        [
            'unit_id' => $box->getKey(),
            'is_base' => false,
            'is_purchase' => false,
            'is_sale' => true,
            'is_display' => false,
            'factor_to_base' => '100',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ],
    ]);

    return [$variant->refresh(), $piece, $box];
}

function acceptSalesUomQuotation(Quotation $quotation): Quotation
{
    $service = app(QuotationService::class);
    $service->send($quotation);
    $service->recordDecision(
        $quotation,
        QuotationDecision::Accepted,
        CarbonImmutable::today(),
        null,
        User::factory()->create(),
    );

    return $quotation->refresh();
}
