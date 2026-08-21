<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Filament\Resources\StockReservations\Pages\ManageStockReservations;
use App\Filament\Resources\StockReservations\StockReservationResource;
use App\Models\InventoryStock;
use App\Models\StockReservation;
use App\Models\User;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has no form components since reservations are managed by other resources', function (): void {
    expect(StockReservationResource::form(Schema::make())->getComponents())->toBe([]);
});

it('releases a reservation through the release record action when an actor is authenticated', function (): void {
    $actor = User::factory()->create();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 10,
        'reserved_quantity' => 3,
        'damaged_quantity' => 1,
        'available_quantity' => 6,
    ]);
    $reservation = StockReservation::factory()->create([
        'product_variant_id' => $stock->product_variant_id,
        'warehouse_id' => $stock->warehouse_id,
        'quantity' => 2,
        'expires_at' => now()->addHour(),
        'status' => ReservationStatus::Active,
    ]);

    $this->actingAs($actor);

    $table = StockReservationResource::table(Table::make(new ManageStockReservations));
    $action = $table->getFlatRecordActions()['release'];
    $action->record($reservation);

    $action->call();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});
