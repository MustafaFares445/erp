<?php

declare(strict_types=1);

use App\Enums\OpportunityStage;
use App\Enums\ProductStatus;
use App\Enums\QuotationResponseType;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use App\Services\Sales\QuotationResponseService;
use App\Services\Sales\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function sentQuotationWithOpportunity(): Quotation
{
    $customer = CustomerProfile::factory()->create();
    $opportunity = SalesOpportunity::factory()->manual()->create([
        'customer_id' => $customer->getKey(),
        'stage' => OpportunityStage::Qualification,
    ]);
    $variant = ProductVariant::factory()->create(['base_price' => 100, 'status' => ProductStatus::Active]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $quotation = app(QuotationService::class)->create(
        [
            'customer_id' => $opportunity->customer_id,
            'sales_opportunity_id' => $opportunity->getKey(),
            'issue_date' => now()->toDateString(),
        ],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100]],
    );

    return app(QuotationService::class)->send($quotation);
}

it('accepts a quotation, closes the opportunity won, and records response evidence', function (): void {
    $admin = User::factory()->admin()->create();
    $quotation = sentQuotationWithOpportunity();

    $accepted = app(QuotationResponseService::class)->accept($quotation, now(), 'Confirmed by phone.', null, $admin);

    expect($accepted->status)->toBe(QuotationStatus::Accepted)
        ->and($accepted->salesOpportunity->refresh()->stage)->toBe(OpportunityStage::ClosedWon)
        ->and($accepted->responses)->toHaveCount(1)
        ->and($accepted->responses->first()->response_type)->toBe(QuotationResponseType::Accepted)
        ->and($accepted->responses->first()->recorded_by_user_id)->toBe($admin->id)
        ->and($accepted->responses->first()->responded_by_user_id)->toBeNull();
});

it('rejects a quotation, closes the opportunity lost, and requires a reason', function (): void {
    $admin = User::factory()->admin()->create();
    $quotation = sentQuotationWithOpportunity();

    expect(fn () => app(QuotationResponseService::class)->reject($quotation, now(), '', null, $admin))
        ->toThrow(ValidationException::class);

    $rejected = app(QuotationResponseService::class)->reject($quotation, now(), 'Too expensive.', null, $admin);

    expect($rejected->status)->toBe(QuotationStatus::Rejected)
        ->and($rejected->salesOpportunity->refresh()->stage)->toBe(OpportunityStage::ClosedLost)
        ->and($rejected->responses()->where('response_type', QuotationResponseType::Rejected)->exists())->toBeTrue();
});

it('requests changes without closing the opportunity, keeping the original frozen', function (): void {
    $admin = User::factory()->admin()->create();
    $quotation = sentQuotationWithOpportunity();
    $originalGrandTotal = $quotation->grand_total;

    expect(fn () => app(QuotationResponseService::class)->requestChanges($quotation, now(), '', null, $admin))
        ->toThrow(ValidationException::class);

    $updated = app(QuotationResponseService::class)->requestChanges($quotation, now(), 'Please add a bulk discount.', null, $admin);

    expect($updated->status)->toBe(QuotationStatus::ChangesRequested)
        ->and($updated->salesOpportunity->refresh()->stage)->toBe(OpportunityStage::Qualification)
        ->and((string) $updated->grand_total)->toBe((string) $originalGrandTotal)
        ->and($updated->responses()->where('response_type', QuotationResponseType::ChangesRequested)->exists())->toBeTrue();
});

it('creates a revised quotation from a ChangesRequested quotation with freshly resolved prices', function (): void {
    $admin = User::factory()->admin()->create();
    $quotation = sentQuotationWithOpportunity();
    $variant = $quotation->lines->first()->productVariant;

    app(QuotationResponseService::class)->requestChanges($quotation, now(), 'Price is too high.', null, $admin);

    $variant->update(['base_price' => 80]);

    $revised = app(QuotationService::class)->requote($quotation->refresh());

    expect($revised->status)->toBe(QuotationStatus::Draft)
        ->and($revised->requoted_from_id)->toBe($quotation->getKey())
        ->and((float) $revised->lines->first()->unit_price)->toBe(80.0)
        ->and((float) $quotation->refresh()->lines->first()->unit_price)->toBe(100.0);
});

it('refuses to record a customer response on a quotation that is not Sent', function (): void {
    $admin = User::factory()->admin()->create();
    $quotation = sentQuotationWithOpportunity();
    app(QuotationResponseService::class)->accept($quotation, now(), null, null, $admin);

    expect(fn () => app(QuotationResponseService::class)->requestChanges($quotation->refresh(), now(), 'Too late.', null, $admin))
        ->toThrow(InvalidQuotationTransition::class);
});
