<?php

declare(strict_types=1);

use App\Enums\DeliveryDocument;
use App\Enums\InventoryPermission;
use App\Models\InventoryOperation;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('previews and downloads inventory operation media only for authorized users', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::ReceiptView->value);

    $operation = InventoryOperation::factory()->receipt()->create();
    $operation
        ->addMediaFromString('%PDF-1.4')
        ->usingFileName('packing-list.pdf')
        ->toMediaCollection(DeliveryDocument::PackingList->value, 'local');

    $media = $operation->fresh()->getFirstMedia(DeliveryDocument::PackingList->value);

    $this->actingAs($viewer)
        ->get(route('admin.inventory-operations.media.preview', ['operation' => $operation, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($viewer)
        ->get(route('admin.inventory-operations.media.download', ['operation' => $operation, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
});

it('refuses to serve media that does not belong to the requested operation', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::ReceiptView->value);

    $operation = InventoryOperation::factory()->receipt()->create();
    $otherOperation = InventoryOperation::factory()->receipt()->create();
    $otherOperation
        ->addMediaFromString('%PDF-1.4')
        ->usingFileName('packing-list.pdf')
        ->toMediaCollection(DeliveryDocument::PackingList->value, 'local');

    $media = $otherOperation->fresh()->getFirstMedia(DeliveryDocument::PackingList->value);

    $this->actingAs($viewer)
        ->get(route('admin.inventory-operations.media.preview', ['operation' => $operation, 'media' => $media]))
        ->assertNotFound();
});
