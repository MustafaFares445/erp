<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\ShipmentConfirmationSource;
use App\Enums\ShipmentStatus;
use App\Models\CustomerProfile;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Shipments\ShipmentAttachmentSynchronizer;
use App\Services\Shipments\ShipmentService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

it('rejects a shipment attachment path that does not exist on disk', function (): void {
    $shipment = Shipment::factory()->create();

    app(ShipmentAttachmentSynchronizer::class)
        ->sync($shipment, ['shipment-attachments/missing-file.pdf']);
})->throws(ValidationException::class, 'The uploaded shipment attachment could not be found.');

it('rejects a shipment attachment that exceeds the maximum file size', function (): void {
    $shipment = Shipment::factory()->create();
    $path = 'shipment-attachments/large-photo.pdf';
    Storage::disk('local')->put($path, str_repeat('a', 5200 * 1024));

    app(ShipmentAttachmentSynchronizer::class)->sync($shipment, [$path]);
})->throws(ValidationException::class, 'The shipment attachment may not be greater than 5 MB.');

it('rejects a shipment attachment with an unsupported mime type', function (): void {
    $shipment = Shipment::factory()->create();
    $path = UploadedFile::fake()->create('notes.txt', 10, 'text/plain')->store('shipment-attachments', 'local');

    if (! is_string($path)) {
        throw new RuntimeException('The fake shipment attachment could not be stored.');
    }

    app(ShipmentAttachmentSynchronizer::class)->sync($shipment, [$path]);
})->throws(ValidationException::class, 'The shipment attachment type is not supported.');

it('confirms a shipment as an admin through the shipment service', function (): void {
    $admin = User::factory()->create();
    $shipment = Shipment::factory()->create();

    $confirmed = app(ShipmentService::class)->confirmByAdmin($shipment, $admin);

    expect($confirmed->status)->toBe(ShipmentStatus::Arrived)
        ->and($confirmed->confirmed_by_type)->toBe(ShipmentConfirmationSource::AdminUser)
        ->and($confirmed->confirmed_by_id)->toBe($admin->getKey());
});

it('confirms a shipment as a customer through the shipment service', function (): void {
    $customer = CustomerProfile::factory()->create();
    $shipment = Shipment::factory()->create();

    $confirmed = app(ShipmentService::class)->confirmByCustomer($shipment, $customer);

    expect($confirmed->status)->toBe(ShipmentStatus::Arrived)
        ->and($confirmed->confirmed_by_type)->toBe(ShipmentConfirmationSource::Customer)
        ->and($confirmed->confirmed_by_id)->toBe($customer->getKey());
});

it('confirms a shipment as the system through the shipment service', function (): void {
    $shipment = Shipment::factory()->create();

    $confirmed = app(ShipmentService::class)->confirmBySystem($shipment);

    expect($confirmed->status)->toBe(ShipmentStatus::Arrived)
        ->and($confirmed->confirmed_by_type)->toBe(ShipmentConfirmationSource::System)
        ->and($confirmed->confirmed_by_id)->toBeNull();
});

it('exposes in-transit and arrived status predicates', function (): void {
    $inTransit = Shipment::factory()->create();
    $arrived = Shipment::factory()->arrived()->create();

    expect($inTransit->isInTransit())->toBeTrue()
        ->and($inTransit->isArrived())->toBeFalse()
        ->and($arrived->isInTransit())->toBeFalse()
        ->and($arrived->isArrived())->toBeTrue();
});

it('labels a customer confirmation by company name, falling back when blank', function (): void {
    $customer = CustomerProfile::factory()->create(['company_name' => 'Acme Trading LLC']);
    $shipment = Shipment::factory()->create();
    $shipment->confirmByCustomer($customer);

    expect($shipment->confirmedByLabel())->toBe('Acme Trading LLC');

    $blankCustomer = CustomerProfile::factory()->create(['company_name' => null]);
    $anotherShipment = Shipment::factory()->create();
    $anotherShipment->confirmByCustomer($blankCustomer);

    expect($anotherShipment->confirmedByLabel())->toBe(ShipmentConfirmationSource::Customer->label());
});

it('uses already-loaded confirmation relations when labeling a shipment', function (): void {
    $admin = User::factory()->create(['name' => 'Loaded Admin']);
    $customer = CustomerProfile::factory()->create(['company_name' => 'Loaded Customer']);
    $adminShipment = Shipment::factory()->create(['confirmed_by_type' => ShipmentConfirmationSource::AdminUser]);
    $customerShipment = Shipment::factory()->create(['confirmed_by_type' => ShipmentConfirmationSource::Customer]);

    expect($adminShipment->setRelation('confirmedByAdminUser', $admin)->confirmedByLabel())->toBe('Loaded Admin')
        ->and($customerShipment->setRelation('confirmedByCustomer', $customer)->confirmedByLabel())->toBe('Loaded Customer');
});

it('labels a system confirmation with the system source label and no label when unconfirmed', function (): void {
    $shipment = Shipment::factory()->create();
    $shipment->confirmBySystem();

    expect($shipment->confirmedByLabel())->toBe(ShipmentConfirmationSource::System->label());

    $unconfirmed = Shipment::factory()->create();

    expect($unconfirmed->confirmedByLabel())->toBeNull();
});

it('only selects in-transit shipments older than six hours as eligible for automatic arrival', function (): void {
    $eligible = Shipment::factory()->create(['created_at' => now()->subHours(7)]);
    $tooRecent = Shipment::factory()->create(['created_at' => now()->subHours(1)]);
    $alreadyArrived = Shipment::factory()->arrived()->create(['created_at' => now()->subHours(7)]);

    $eligibleIds = app(ShipmentService::class)->eligibleForAutomaticArrival()->pluck('id');

    expect($eligibleIds)->toContain($eligible->id)
        ->and($eligibleIds)->not->toContain($tooRecent->id)
        ->and($eligibleIds)->not->toContain($alreadyArrived->id);
});
