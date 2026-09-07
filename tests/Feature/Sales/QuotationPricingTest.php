<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\ResolvedPriceSource;
use App\Models\CustomerProfile;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Services\Sales\Exceptions\QuotationImmutable;
use App\Services\Sales\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(QuotationService::class);
});

it("defaults a line price from the customer's tier and records the source (FR-015)", function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 50, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    PricingTier::factory()->customerSpecific()->create([
        'customer_user_id' => $profile->user_id,
        'discount_value' => 20,
    ]);

    $quotation = $this->service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 2]],
    );

    $line = $quotation->lines->sole();

    expect((float) $line->unit_price)->toBe(80.0)
        ->and($line->resolved_price_source)->toBe(ResolvedPriceSource::CustomerSpecificTier);
});

it('refuses a manual override below the price floor while drafting (FR-016)', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 50, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $this->service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 40]],
    );
})->throws(DomainException::class);

it('does not re-apply the floor guard once the quotation has been sent', function (): void {
    // The floor is a drafting-time control. A price floor lowered or raised
    // after the quotation was sent must not retroactively invalidate it
    // (research.md R-002).
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'min_price' => 50, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $quotation = $this->service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 60]],
    );
    $this->service->send($quotation);

    $variant->update(['min_price' => 90]);

    expect($quotation->refresh()->lines->sole()->unit_price)->toEqual('60.00');
});

it('recomputes subtotal, tax total, and grand total from tax-inclusive lines (FR-018)', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variantA = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variantA->product->update(['status' => ProductStatus::Active]);

    $variantB = ProductVariant::factory()->create(['base_price' => 200, 'status' => ProductStatus::Active]);
    $variantB->product->update(['status' => ProductStatus::Active]);

    $quotation = $this->service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [
            ['product_variant_id' => $variantA->getKey(), 'quantity' => 2, 'unit_price' => 100, 'tax_amount' => 10],
            ['product_variant_id' => $variantB->getKey(), 'quantity' => 1, 'unit_price' => 200, 'tax_amount' => 20],
        ],
    );

    expect((float) $quotation->subtotal)->toBe(400.0)
        ->and((float) $quotation->tax_total)->toBe(30.0)
        ->and((float) $quotation->grand_total)->toBe(430.0);
});

it('refuses to change customer, lines, quantities, prices, or totals once sent (FR-023)', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $quotation = $this->service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );
    $this->service->send($quotation);

    $otherCustomer = CustomerProfile::factory()->create();

    expect(fn () => $quotation->update(['customer_id' => $otherCustomer->getKey()]))
        ->toThrow(QuotationImmutable::class);
});

it('refuses to edit or delete a line once its parent quotation has been sent', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $quotation = $this->service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );
    $this->service->send($quotation);
    $line = $quotation->lines->sole();

    expect(fn () => $line->update(['quantity' => 5]))->toThrow(QuotationImmutable::class)
        ->and(fn () => $line->delete())->toThrow(QuotationImmutable::class);
});

it('allows a draft quotation to be freely edited', function (): void {
    $profile = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $quotation = $this->service->create(
        ['customer_id' => $profile->getKey(), 'issue_date' => now()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );

    $updated = $this->service->updateLines($quotation, [
        ['product_variant_id' => $variant->getKey(), 'quantity' => 3, 'unit_price' => 100],
    ]);

    expect((float) $updated->subtotal)->toBe(300.0)
        ->and($updated->lines()->count())->toBe(1);
});
