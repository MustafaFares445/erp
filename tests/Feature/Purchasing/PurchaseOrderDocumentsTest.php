<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderDocument;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole(DashboardRole::PurchasingManager->value);

    $this->actingAs($manager);
});

function draftPurchaseOrderWithLine(): PurchaseOrder
{
    $order = PurchaseOrder::factory()->create();

    $order->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 5,
        'unit_cost' => '20.00',
        'line_total' => '100.00',
    ]);

    return $order->refresh();
}

it('uploads a customs document when creating a purchase order', function (): void {
    Storage::fake('local');

    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '15.00',
    ]);

    Livewire::test(CreatePurchaseOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->getKey(),
            'currency_code' => 'AED',
            'ordered_at' => today()->toDateString(),
            'lines' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'unit_id' => $variant->unit_id,
                'quantity_ordered' => '2',
                'unit_cost' => '15.00',
            ]],
            PurchaseOrderDocument::CustomsPayment->value => [
                UploadedFile::fake()->create('customs-payment.pdf', 100, 'application/pdf'),
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = PurchaseOrder::query()->sole();

    expect($created->getFirstMedia(PurchaseOrderDocument::CustomsPayment->value))->not->toBeNull();
});

it('keeps an untouched document and stores a newly uploaded one when editing a purchase order', function (): void {
    Storage::fake('local');

    $order = draftPurchaseOrderWithLine();
    $order->addMediaFromString('%PDF-1.4')
        ->usingFileName('customs_payment.pdf')
        ->toMediaCollection(PurchaseOrderDocument::CustomsPayment->value, 'local');

    Livewire::test(EditPurchaseOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm([
            PurchaseOrderDocument::CustomsClearanceDocument->value => [
                UploadedFile::fake()->create('customs_clearance_document.pdf', 100, 'application/pdf'),
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();

    expect($order->getFirstMedia(PurchaseOrderDocument::CustomsPayment->value)?->file_name)->toBe('customs_payment.pdf')
        ->and($order->getFirstMedia(PurchaseOrderDocument::CustomsClearanceDocument->value))->not->toBeNull();
});

it('refuses a purchase order document path that was not legitimately uploaded through the form', function (): void {
    $order = draftPurchaseOrderWithLine();

    Livewire::test(EditPurchaseOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm([
            PurchaseOrderDocument::CustomsPayment->value => 'purchase-order-documents/customs_payment/tampered.pdf',
        ])
        ->call('save')
        ->assertHasFormErrors([PurchaseOrderDocument::CustomsPayment->value]);
});

it('refuses a purchase order document path submitted directly on the create page', function (): void {
    // There is no record yet on create, so preventFilePathTampering's allowFilePathUsing
    // callback always refuses — every path must come from a real upload in this same request.
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '15.00',
    ]);

    Livewire::test(CreatePurchaseOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->getKey(),
            'currency_code' => 'AED',
            'ordered_at' => today()->toDateString(),
            'lines' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'unit_id' => $variant->unit_id,
                'quantity_ordered' => '2',
                'unit_cost' => '15.00',
            ]],
            PurchaseOrderDocument::CustomsPayment->value => 'purchase-order-documents/customs_payment/tampered.pdf',
        ])
        ->call('create')
        ->assertHasFormErrors([PurchaseOrderDocument::CustomsPayment->value]);
});
