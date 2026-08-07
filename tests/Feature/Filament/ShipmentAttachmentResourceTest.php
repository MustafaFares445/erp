<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\ShipmentAttachments\Pages\ListShipmentAttachments;
use App\Filament\Resources\ShipmentAttachments\Pages\ViewShipmentAttachment;
use App\Filament\Resources\ShipmentAttachments\ShipmentAttachmentResource;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('lists only shipment attachments with in-transit as the default status filter', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::ShipmentView->value);

    $inTransit = Shipment::factory()->create(['status' => ShipmentStatus::InTransit]);
    $arrived = Shipment::factory()->arrived()->create();
    $withoutAttachments = Shipment::factory()->create();

    foreach ([$inTransit, $arrived] as $shipment) {
        $shipment
            ->addMediaFromString('%PDF-1.4')
            ->usingFileName($shipment->tracking_number.'.pdf')
            ->toMediaCollection('attachments', 'local');
    }

    Livewire::actingAs($user)
        ->test(ListShipmentAttachments::class)
        ->assertCanSeeTableRecords([$inTransit])
        ->assertCanNotSeeTableRecords([$arrived, $withoutAttachments])
        ->assertDontSee($inTransit->getFirstMedia('attachments')->file_name);

    $media = $inTransit->getFirstMedia('attachments');

    Livewire::actingAs($user)
        ->test(ViewShipmentAttachment::class, ['record' => $inTransit->getKey()])
        ->assertSee($media->file_name)
        ->assertSee(route('admin.shipments.media.preview', ['shipment' => $inTransit, 'media' => $media]))
        ->assertSee(route('admin.shipments.media.download', ['shipment' => $inTransit, 'media' => $media]));

    $this->actingAs($user)
        ->get(ShipmentAttachmentResource::getUrl())
        ->assertSuccessful()
        ->assertSee('Shipments');
});
