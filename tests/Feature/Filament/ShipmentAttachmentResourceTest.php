<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('lists every canonical shipment, including shipments without attachments', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::ShipmentView->value);

    $inTransit = Shipment::factory()->create(['status' => ShipmentStatus::InTransit]);
    $arrived = Shipment::factory()->arrived()->create();
    $withoutAttachments = Shipment::factory()->create();

    $inTransit
        ->addMediaFromString('%PDF-1.4')
        ->usingFileName($inTransit->tracking_number.'.pdf')
        ->toMediaCollection('attachments', 'local');

    Livewire::actingAs($user)
        ->test(ListShipments::class)
        ->assertCanSeeTableRecords([$inTransit, $arrived, $withoutAttachments]);

    $media = $inTransit->getFirstMedia('attachments');

    Livewire::actingAs($user)
        ->test(ViewShipment::class, ['record' => $inTransit->getKey()])
        ->assertSee($media->file_name)
        ->assertSee(route('admin.shipments.media.preview', ['shipment' => $inTransit, 'media' => $media]))
        ->assertSee(route('admin.shipments.media.download', ['shipment' => $inTransit, 'media' => $media]));

    $this->actingAs($user)
        ->get(ShipmentResource::getUrl())
        ->assertSuccessful()
        ->assertSee('Shipments');
});

it('keeps the shipment resource create form empty', function (): void {
    expect(ShipmentResource::form(Schema::make())->getComponents())->toBe([]);
});

it('confirms an in-transit shipment from the attachment table', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo([
        InventoryPermission::ShipmentView->value,
        InventoryPermission::ShipmentConfirm->value,
    ]);
    $shipment = Shipment::factory()->create(['status' => ShipmentStatus::InTransit]);
    $shipment
        ->addMediaFromString('%PDF-1.4')
        ->usingFileName($shipment->tracking_number.'.pdf')
        ->toMediaCollection('attachments', 'local');

    Livewire::actingAs($user)
        ->test(ListShipments::class)
        ->callTableAction('confirm', $shipment)
        ->assertHasNoTableActionErrors();

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Arrived);
});

it('confirms an in-transit shipment from its view page', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo([
        InventoryPermission::ShipmentView->value,
        InventoryPermission::ShipmentConfirm->value,
    ]);
    $shipment = Shipment::factory()->create(['status' => ShipmentStatus::InTransit]);

    Livewire::actingAs($user)
        ->test(ViewShipment::class, ['record' => $shipment->getKey()])
        ->assertActionVisible('confirm')
        ->callAction('confirm')
        ->assertHasNoActionErrors();

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Arrived);
});
