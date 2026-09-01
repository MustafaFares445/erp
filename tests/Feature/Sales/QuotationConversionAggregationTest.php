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
 * Invariant I-7 (data-model.md §6, §12): a quotation may carry the same
 * variant on two lines — `quotation_lines` has no unique index forbidding it
 * — but `order_lines` does, so conversion aggregates. The order's document
 * totals must still equal the quotation's exactly, because they are copied
 * verbatim rather than recomputed from the aggregated line.
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
        ->and((float) $line->tax_amount)->toBe(14.5)
        ->and((float) $line->line_total)->toBe(304.5)
        ->and((float) $order->subtotal)->toBe((float) $quotation->subtotal)
        ->and((float) $order->tax_total)->toBe((float) $quotation->tax_total)
        ->and((float) $order->grand_total)->toBe((float) $quotation->grand_total)
        ->and((float) $order->grand_total)->toBe(304.5);
});
