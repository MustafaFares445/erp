<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\QuotationStatus;
use App\Enums\ResolvedPriceSource;
use App\Models\CustomerProfile;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use App\Services\Sales\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(QuotationService::class);
});

it('requotes an expired quotation with freshly resolved prices and links it to the original', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 10, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $original = $this->service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 2]],
    );
    $originalUnitPrice = (float) $original->lines->sole()->unit_price;

    $this->service->send($original);
    $original->update(['status' => QuotationStatus::Expired]);

    // Policy changes between the original quotation and the requote: a new
    // customer-specific tier now applies, so the resolved price must differ.
    PricingTier::factory()->customerSpecific()->create([
        'customer_user_id' => $profile->user_id,
        'discount_value' => 25,
    ]);

    $requoted = $this->service->requote($original->fresh());

    $newLine = $requoted->lines->sole();

    expect($requoted->status)->toBe(QuotationStatus::Draft)
        ->and($requoted->requoted_from_id)->toBe($original->getKey())
        ->and($requoted->getKey())->not->toBe($original->getKey())
        ->and($newLine->resolved_price_source)->toBe(ResolvedPriceSource::CustomerSpecificTier)
        ->and((float) $newLine->unit_price)->toBe(75.0)
        ->and((float) $newLine->unit_price)->not->toBe($originalUnitPrice)
        ->and((float) $newLine->quantity)->toBe(2.0);
});

it('refuses to requote a quotation that has not expired', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $quotation = $this->service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
    );

    expect(fn () => $this->service->requote($quotation))
        ->toThrow(InvalidQuotationTransition::class);
});
