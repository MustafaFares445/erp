<?php

declare(strict_types=1);

use App\Data\Purchasing\SupplierConfirmationRequestData;
use App\Enums\DashboardRole;
use App\Enums\SupplierConfirmationStatus;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductSupport;
use App\Models\User;
use App\Services\Purchasing\SupplierConfirmationService;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
});

it('records items for a quotation and derives its customer', function (): void {
    $salesOfficer = User::factory()->create();
    $salesOfficer->assignRole(DashboardRole::SalesOfficer->value);

    $customer = CustomerProfile::factory()->create();
    $quotation = Quotation::factory()->for($customer, 'customer')->create();
    $variant = ProductVariant::factory()->create();
    $supplier = Supplier::factory()->create();
    SupplierProductSupport::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
    ]);

    $confirmation = app(SupplierConfirmationService::class)->recordItems($salesOfficer, new SupplierConfirmationRequestData(
        target: $quotation,
        customer: null,
        supplierId: $supplier->getKey(),
        items: [['product_variant_id' => $variant->getKey(), 'requested_quantity' => 2.5]],
    ));

    expect($confirmation->customer_id)->toBe($customer->getKey())
        ->and($confirmation->confirmable_type)->toBe(Quotation::class)
        ->and($confirmation->items)->toHaveCount(1)
        ->and((float) $confirmation->items->sole()->requested_quantity)->toBe(2.5);
});

it('records responses per item and derives partial progress', function (): void {
    $purchasingOfficer = User::factory()->create();
    $purchasingOfficer->assignRole(DashboardRole::PurchasingOfficer->value);

    $confirmation = SupplierConfirmation::factory()->create();
    $firstItem = $confirmation->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'requested_quantity' => 1,
    ]);
    $secondItem = $confirmation->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'requested_quantity' => 1,
    ]);

    $answered = app(SupplierConfirmationService::class)->answerItems($purchasingOfficer, $confirmation, [[
        'id' => $firstItem->getKey(),
        'confirmation_status' => SupplierConfirmationStatus::Confirmed,
    ]]);

    expect($answered->confirmation_status)->toBe(SupplierConfirmationStatus::Pending)
        ->and($answered->items->find($firstItem->getKey())?->confirmation_status)->toBe(SupplierConfirmationStatus::Confirmed)
        ->and($answered->items->find($secondItem->getKey())?->confirmation_status)->toBe(SupplierConfirmationStatus::Pending);
});
