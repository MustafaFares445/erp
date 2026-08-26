<?php

declare(strict_types=1);

use App\Enums\QuotationDecision;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use App\Services\Sales\QuotationConversionService;
use App\Services\Sales\QuotationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function acceptedQuotationForConversion(): Quotation
{
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100]);
    $recorder = User::factory()->create();

    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 3, 'unit_price' => 100, 'tax_amount' => 15]],
    );

    app(QuotationService::class)->send($quotation);
    app(QuotationService::class)->recordDecision($quotation, QuotationDecision::Accepted, CarbonImmutable::today(), null, $recorder);

    return $quotation->refresh();
}

it('converts an accepted quotation into an order whose totals equal the quotation exactly (FR-029)', function (): void {
    $quotation = acceptedQuotationForConversion();

    $order = app(QuotationConversionService::class)->convert($quotation);

    expect((float) $order->subtotal)->toBe((float) $quotation->subtotal)
        ->and((float) $order->tax_total)->toBe((float) $quotation->tax_total)
        ->and((float) $order->grand_total)->toBe((float) $quotation->grand_total)
        ->and($order->customer_id)->toBe($quotation->customer_id)
        ->and($order->quotation_id)->toBe($quotation->getKey())
        ->and($order->payment_term_id)->toBe($quotation->payment_term_id)
        ->and($quotation->refresh()->status)->toBe(QuotationStatus::ConvertedToDelivery)
        ->and($quotation->converted_order_id)->toBe($order->getKey())
        ->and(Order::query()->count())->toBe(1);

    $line = $order->lines()->sole();
    expect((float) $line->quantity)->toBe(3.0)
        ->and((float) $line->unit_price)->toBe(100.0)
        ->and((float) $line->tax_amount)->toBe(15.0)
        ->and((float) $line->line_total)->toBe(315.0);
});

it('refuses a second conversion of an already converted quotation and creates no second order', function (): void {
    $quotation = acceptedQuotationForConversion();

    app(QuotationConversionService::class)->convert($quotation);

    expect(fn () => app(QuotationConversionService::class)->convert($quotation->refresh()))
        ->toThrow(InvalidQuotationTransition::class);

    expect(Order::query()->count())->toBe(1);
});

it('refuses to convert a quotation that is still draft', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100]);

    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );

    expect(fn () => app(QuotationConversionService::class)->convert($quotation))
        ->toThrow(InvalidQuotationTransition::class);

    expect(Order::query()->count())->toBe(0);
});

it('refuses to convert a rejected quotation', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100]);
    $recorder = User::factory()->create();

    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );
    app(QuotationService::class)->send($quotation);
    app(QuotationService::class)->recordDecision($quotation, QuotationDecision::Rejected, CarbonImmutable::today(), null, $recorder);

    expect(fn () => app(QuotationConversionService::class)->convert($quotation->refresh()))
        ->toThrow(InvalidQuotationTransition::class);

    expect(Order::query()->count())->toBe(0);
});

it('refuses to convert an expired quotation', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100]);
    $recorder = User::factory()->create();

    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => now()->toDateString(), 'expires_at' => now()->subDay()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );
    app(QuotationService::class)->send($quotation);

    expect(fn () => app(QuotationService::class)->recordDecision($quotation, QuotationDecision::Accepted, CarbonImmutable::today(), null, $recorder))
        ->toThrow(InvalidQuotationTransition::class);

    expect(fn () => app(QuotationConversionService::class)->convert($quotation->refresh()))
        ->toThrow(InvalidQuotationTransition::class);

    expect(Order::query()->count())->toBe(0);
});
