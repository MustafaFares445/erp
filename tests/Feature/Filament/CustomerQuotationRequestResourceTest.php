<?php

declare(strict_types=1);

use App\Enums\CustomerQuotationRequestStatus;
use App\Filament\Resources\CustomerQuotationRequests\Pages\CreateCustomerQuotationRequest;
use App\Filament\Resources\CustomerQuotationRequests\Pages\ListCustomerQuotationRequests;
use App\Filament\Resources\CustomerQuotationRequests\Pages\ViewCustomerQuotationRequest;
use App\Models\CustomerProfile;
use App\Models\CustomerQuotationRequest;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Crm\CustomerQuotationRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates a quote request on behalf of a customer from the dashboard', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    Livewire::actingAs($admin)
        ->test(CreateCustomerQuotationRequest::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'notes' => 'Phone order.',
            'lines' => [
                ['product_variant_id' => $variant->getKey(), 'requested_quantity' => 5],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $request = CustomerQuotationRequest::query()->sole();

    expect($request->customer_id)->toBe($customer->getKey())
        ->and($request->lines)->toHaveCount(1);
});

it('lists quote requests and shows their status', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $request = app(CustomerQuotationRequestService::class)->submit(
        $customer,
        [['product_variant_id' => $variant->getKey(), 'requested_quantity' => 1]],
    );

    Livewire::actingAs($admin)
        ->test(ListCustomerQuotationRequests::class)
        ->assertCanSeeTableRecords([$request]);
});

it('converts a quote request to a quotation from the view page', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();

    $request = app(CustomerQuotationRequestService::class)->submit(
        $customer,
        [['product_variant_id' => $variant->getKey(), 'requested_quantity' => 1]],
    );

    Livewire::actingAs($admin)
        ->test(ViewCustomerQuotationRequest::class, ['record' => $request->getKey()])
        ->callAction('convertToQuotation')
        ->assertNotified();

    expect($request->refresh()->status)->toBe(CustomerQuotationRequestStatus::Quoted)
        ->and($request->resulting_quotation_id)->not->toBeNull();
});
