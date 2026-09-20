<?php

declare(strict_types=1);

use App\Enums\CustomerQuotationRequestStatus;
use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Crm\CustomerQuotationRequestService;
use App\Services\Crm\Exceptions\InvalidCustomerQuotationRequestTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('submits a quote request for an approved active customer', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $request = app(CustomerQuotationRequestService::class)->submit(
        $customer,
        [['product_variant_id' => $variant->getKey(), 'requested_quantity' => 3]],
        notes: 'Please deliver ASAP.',
    );

    expect($request->status)->toBe(CustomerQuotationRequestStatus::Submitted)
        ->and($request->request_number)->toStartWith('QR-')
        ->and($request->lines)->toHaveCount(1)
        ->and((float) $request->lines->first()->requested_quantity)->toBe(3.0);
});

it('refuses a quote request from a customer that is not approved', function (): void {
    $customer = CustomerProfile::factory()->pending()->create();
    $variant = ProductVariant::factory()->create();

    expect(fn () => app(CustomerQuotationRequestService::class)->submit(
        $customer,
        [['product_variant_id' => $variant->getKey(), 'requested_quantity' => 1]],
    ))->toThrow(InvalidCustomerQuotationRequestTransition::class);

    expect($customer->quotationRequests()->count())->toBe(0);
});

it('refuses an empty line list', function (): void {
    $customer = CustomerProfile::factory()->create();

    expect(fn () => app(CustomerQuotationRequestService::class)->submit($customer, []))
        ->toThrow(InvalidCustomerQuotationRequestTransition::class);
});

it('refuses a delivery address that belongs to another customer', function (): void {
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $foreignAddress = CustomerDeliveryAddress::factory()->for($otherCustomer, 'customer')->create();
    $variant = ProductVariant::factory()->create();

    expect(fn () => app(CustomerQuotationRequestService::class)->submit(
        $customer,
        [['product_variant_id' => $variant->getKey(), 'requested_quantity' => 1]],
        $foreignAddress,
    ))->toThrow(InvalidCustomerQuotationRequestTransition::class);
});

it('moves a submitted request under review', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $request = app(CustomerQuotationRequestService::class)->submit(
        $customer,
        [['product_variant_id' => $variant->getKey(), 'requested_quantity' => 1]],
    );

    $reviewed = app(CustomerQuotationRequestService::class)->startReview($admin, $request);

    expect($reviewed->status)->toBe(CustomerQuotationRequestStatus::UnderReview)
        ->and($reviewed->reviewed_by)->toBe($admin->id);
});

it('rejects an open request with a reason', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $request = app(CustomerQuotationRequestService::class)->submit(
        $customer,
        [['product_variant_id' => $variant->getKey(), 'requested_quantity' => 1]],
    );

    $rejected = app(CustomerQuotationRequestService::class)->reject($admin, $request, 'Discontinued product.');

    expect($rejected->status)->toBe(CustomerQuotationRequestStatus::Rejected)
        ->and($rejected->review_note)->toBe('Discontinued product.');
});

it('converts a request into a quotation with server-resolved pricing and links both records', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 150]);

    $request = app(CustomerQuotationRequestService::class)->submit(
        $customer,
        [['product_variant_id' => $variant->getKey(), 'requested_quantity' => 2]],
    );

    $quotation = app(CustomerQuotationRequestService::class)->convertToQuotation($admin, $request);

    expect($quotation->customer_id)->toBe($customer->getKey())
        ->and($quotation->lines)->toHaveCount(1)
        ->and((float) $quotation->lines->first()->unit_price)->toBe(150.0)
        ->and($request->refresh()->status)->toBe(CustomerQuotationRequestStatus::Quoted)
        ->and($request->resulting_quotation_id)->toBe($quotation->getKey());
});

it('refuses to convert a request that already has a decision', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $request = app(CustomerQuotationRequestService::class)->submit(
        $customer,
        [['product_variant_id' => $variant->getKey(), 'requested_quantity' => 1]],
    );
    app(CustomerQuotationRequestService::class)->reject($admin, $request, 'No longer needed.');

    expect(fn () => app(CustomerQuotationRequestService::class)->convertToQuotation($admin, $request->refresh()))
        ->toThrow(InvalidCustomerQuotationRequestTransition::class);
});
