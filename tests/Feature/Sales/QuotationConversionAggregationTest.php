<?php

declare(strict_types=1);

use App\Enums\QuotationDecision;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Sales\QuotationConversionService;
use App\Services\Sales\QuotationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Invariant I-7 (data-model.md §6, §12): quotation rows with the same variant
 * and the same frozen transaction UOM may aggregate during conversion. Rows
 * using different UOMs remain distinct. The order's document totals still
 * equal the quotation exactly because they are copied verbatim.
 */
it('converts a quotation with the same variant on two lines into one order line, with totals exact to the cent', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100]);
    $recorder = User::factory()->create();

    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [
            ['product_variant_id' => $variant->getKey(), 'quantity' => 2, 'unit_price' => 100, 'tax_amount' => 10],
            ['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 90, 'tax_amount' => 4.5],
        ],
    );
    app(QuotationService::class)->send($quotation);
    app(QuotationService::class)->recordDecision($quotation, QuotationDecision::Accepted, CarbonImmutable::today(), null, $recorder);

    $quotation->refresh();

    $order = app(QuotationConversionService::class)->convert($quotation);

    expect($order->lines()->count())->toBe(1);

    $line = $order->lines()->sole();

    expect((float) $line->quantity)->toBe(3.0)
        ->and($line->transaction_quantity)->toBe('3.000000')
        ->and($line->transaction_unit_id)->toBe($variant->unit_id)
        ->and($line->conversion_factor_snapshot)->toBe('1.000000')
        ->and($line->base_quantity)->toBe('3.000000')
        ->and((float) $line->tax_amount)->toBe(14.5)
        ->and((float) $line->line_total)->toBe(304.5)
        ->and((float) $order->subtotal)->toBe((float) $quotation->subtotal)
        ->and((float) $order->tax_total)->toBe((float) $quotation->tax_total)
        ->and((float) $order->grand_total)->toBe((float) $quotation->grand_total)
        ->and((float) $order->grand_total)->toBe(304.5);
});
