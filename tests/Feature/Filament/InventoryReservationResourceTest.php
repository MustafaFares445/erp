<?php

declare(strict_types=1);

use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Models\InventoryReservation;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the canonical reservation model and exposes no manual create form', function (): void {
    expect(InventoryReservationResource::getModel())->toBe(InventoryReservation::class)
        ->and(InventoryReservationResource::canCreate())->toBeFalse()
        ->and(InventoryReservationResource::form(Schema::make())->getComponents())->toBe([]);
});

it('keeps the legacy stock reservation runtime retired', function (): void {
    expect(class_exists('App\\Filament\\Resources\\StockReservations\\StockReservationResource'))->toBeFalse()
        ->and(class_exists('App\\Services\\Inventory\\ReservationService'))->toBeFalse()
        ->and(class_exists('App\\Policies\\StockReservationPolicy'))->toBeFalse();
});
