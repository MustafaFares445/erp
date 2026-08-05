<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\ShipmentConfirmationSource;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Shipments\ShipmentAttachmentSynchronizer;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    (new InventoryPermissionSeeder)->run();
});

it('stores multiple shipment attachments and removes deleted files', function (): void {
    $shipment = Shipment::factory()->create();
    $synchronizer = app(ShipmentAttachmentSynchronizer::class);
    $firstPath = UploadedFile::fake()->create('packing-photo.pdf', 200, 'application/pdf')->store('shipment-attachments', 'local');
    $secondPath = UploadedFile::fake()->create('signed-note.pdf', 200, 'application/pdf')->store('shipment-attachments', 'local');

    if (! is_string($firstPath) || ! is_string($secondPath)) {
        throw new RuntimeException('The fake shipment attachments could not be stored.');
    }

    $synchronizer->sync($shipment, [$firstPath, $secondPath]);
    $shipment->refresh();
    $keptPath = $shipment->getMedia('attachments')->firstOrFail()->getPathRelativeToRoot();

    $synchronizer->sync($shipment, [$keptPath]);

    expect($shipment->fresh()->getMedia('attachments'))->toHaveCount(1)
        ->and(Storage::disk('local')->exists($firstPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($secondPath))->toBeFalse();
});

it('previews and downloads shipment media only for authorized users', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(InventoryPermission::ShipmentView->value);
    $shipment = Shipment::factory()->create();
    $path = UploadedFile::fake()->create('shipment-proof.pdf', 200, 'application/pdf')->store('shipment-attachments', 'local');

    if (! is_string($path)) {
        throw new RuntimeException('The fake shipment attachment could not be stored.');
    }

    app(ShipmentAttachmentSynchronizer::class)->sync($shipment, [$path]);
    $media = $shipment->fresh()->getFirstMedia('attachments');

    expect($media)->not->toBeNull();

    if ($media === null) {
        throw new RuntimeException('The shipment media was not persisted.');
    }

    $this->actingAs($viewer)
        ->get(route('admin.shipments.media.preview', ['shipment' => $shipment, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename='.$media->file_name);

    $this->actingAs($viewer)
        ->get(route('admin.shipments.media.download', ['shipment' => $shipment, 'media' => $media]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
});

it('automatically marks shipments older than six hours as arrived without changing the delivery', function (): void {
    $shipment = Shipment::factory()->create(['created_at' => now()->subHours(7)]);
    $recentShipment = Shipment::factory()->create(['created_at' => now()->subHours(5)]);

    $this->artisan('inventory:shipments:auto-arrive')->assertSuccessful();

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Arrived)
        ->and($shipment->fresh()->confirmed_by_type)->toBe(ShipmentConfirmationSource::System)
        ->and($shipment->fresh()->confirmed_at)->not->toBeNull()
        ->and($recentShipment->fresh()->status)->toBe(ShipmentStatus::InTransit);
});

it('records the admin user when a shipment is confirmed', function (): void {
    $admin = User::factory()->create(['name' => 'Warehouse Operator']);
    $shipment = Shipment::factory()->create();

    $shipment->confirmByAdmin($admin);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Arrived)
        ->and($shipment->confirmed_by_type)->toBe(ShipmentConfirmationSource::AdminUser)
        ->and($shipment->confirmedByLabel())->toBe('Warehouse Operator');
});
