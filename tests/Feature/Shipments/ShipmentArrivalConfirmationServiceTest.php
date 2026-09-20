<?php

declare(strict_types=1);

use App\Enums\ShipmentConfirmationSource;
use App\Enums\ShipmentStatus;
use App\Models\CustomerProfile;
use App\Models\Shipment;
use App\Models\ShipmentArrivalConfirmation;
use App\Models\User;
use App\Services\Shipments\ShipmentArrivalConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('requires at least one photo for a customer confirmation', function (): void {
    $customer = CustomerProfile::factory()->create();
    $shipment = Shipment::factory()->forCustomer($customer)->create();

    expect(fn () => app(ShipmentArrivalConfirmationService::class)->confirmByCustomer($shipment, $customer, []))
        ->toThrow(DomainException::class);

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::InTransit)
        ->and(ShipmentArrivalConfirmation::query()->count())->toBe(0);
});

it('confirms arrival as a customer with photo evidence', function (): void {
    $customer = CustomerProfile::factory()->create();
    $shipment = Shipment::factory()->forCustomer($customer)->create();
    $photo = UploadedFile::fake()->image('delivery.jpg');

    $confirmed = app(ShipmentArrivalConfirmationService::class)->confirmByCustomer($shipment, $customer, [$photo], 'Left at the front desk.');

    expect($confirmed->status)->toBe(ShipmentStatus::Arrived);

    $evidence = ShipmentArrivalConfirmation::query()->where('shipment_id', $shipment->getKey())->sole();

    expect($evidence->confirmed_by_type)->toBe(ShipmentConfirmationSource::Customer)
        ->and($evidence->confirmed_by_id)->toBe($customer->getKey())
        ->and($evidence->note)->toBe('Left at the front desk.')
        ->and($evidence->getMedia('delivery-confirmation-photos'))->toHaveCount(1);
});

it('refuses a customer confirmation for a shipment belonging to another customer', function (): void {
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $shipment = Shipment::factory()->forCustomer($otherCustomer)->create();
    $photo = UploadedFile::fake()->image('delivery.jpg');

    expect(fn () => app(ShipmentArrivalConfirmationService::class)->confirmByCustomer($shipment, $customer, [$photo]))
        ->toThrow(DomainException::class);
});

it('does not require a photo for an admin confirmation', function (): void {
    $admin = User::factory()->admin()->create();
    $shipment = Shipment::factory()->create();

    $confirmed = app(ShipmentArrivalConfirmationService::class)->confirmByAdmin($shipment, $admin);

    expect($confirmed->status)->toBe(ShipmentStatus::Arrived);

    $evidence = ShipmentArrivalConfirmation::query()->where('shipment_id', $shipment->getKey())->sole();

    expect($evidence->confirmed_by_type)->toBe(ShipmentConfirmationSource::AdminUser)
        ->and($evidence->getMedia('delivery-confirmation-photos'))->toHaveCount(0);
});

it('is idempotent when the same shipment is confirmed twice', function (): void {
    $customer = CustomerProfile::factory()->create();
    $shipment = Shipment::factory()->forCustomer($customer)->create();
    $photo = UploadedFile::fake()->image('delivery.jpg');

    app(ShipmentArrivalConfirmationService::class)->confirmByCustomer($shipment, $customer, [$photo]);
    app(ShipmentArrivalConfirmationService::class)->confirmByCustomer($shipment->refresh(), $customer, [UploadedFile::fake()->image('second.jpg')]);

    expect(ShipmentArrivalConfirmation::query()->where('shipment_id', $shipment->getKey())->count())->toBe(1)
        ->and(ShipmentArrivalConfirmation::query()->sole()->getMedia('delivery-confirmation-photos'))->toHaveCount(1);
});

it('treats a historical photo-less customer confirmation as still valid', function (): void {
    $customer = CustomerProfile::factory()->create();
    $shipment = Shipment::factory()->forCustomer($customer)->arrived()->create([
        'confirmed_by_type' => ShipmentConfirmationSource::Customer,
        'confirmed_by_id' => $customer->getKey(),
    ]);

    expect($shipment->status)->toBe(ShipmentStatus::Arrived)
        ->and($shipment->arrivalConfirmation)->toBeNull();
});
