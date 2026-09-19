<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderDocument;
use App\Enums\PurchasePermission;
use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
});

it('previews and downloads purchase order media only for authorized users', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(PurchasePermission::OrderView->value);

    $purchaseOrder = PurchaseOrder::factory()->create();
    $purchaseOrder
        ->addMediaFromString('%PDF-1.4')
        ->usingFileName('customs-payment.pdf')
        ->toMediaCollection(PurchaseOrderDocument::CustomsPayment->value, 'local');

    $media = $purchaseOrder->fresh()->getFirstMedia(PurchaseOrderDocument::CustomsPayment->value);

    $this->actingAs($viewer)
        ->get(route('admin.purchase-orders.media.preview', ['purchaseOrder' => $purchaseOrder, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($viewer)
        ->get(route('admin.purchase-orders.media.download', ['purchaseOrder' => $purchaseOrder, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
});

it('refuses to serve media that does not belong to the requested purchase order', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(PurchasePermission::OrderView->value);

    $purchaseOrder = PurchaseOrder::factory()->create();
    $otherPurchaseOrder = PurchaseOrder::factory()->create();
    $otherPurchaseOrder
        ->addMediaFromString('%PDF-1.4')
        ->usingFileName('customs-payment.pdf')
        ->toMediaCollection(PurchaseOrderDocument::CustomsPayment->value, 'local');

    $media = $otherPurchaseOrder->fresh()->getFirstMedia(PurchaseOrderDocument::CustomsPayment->value);

    $this->actingAs($viewer)
        ->get(route('admin.purchase-orders.media.preview', ['purchaseOrder' => $purchaseOrder, 'media' => $media]))
        ->assertNotFound();
});
