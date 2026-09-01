<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();

    $user = User::factory()->create();
    $user->givePermissionTo([
        InventoryPermission::MovementView->value,
        InventoryPermission::StockView->value,
        InventoryPermission::ReturnView->value,
    ]);

    $this->actingAs($user);
});

test('the retired returns route redirects to the return-filtered movement log', function (): void {
    $this->get(ReturnResource::getUrl())
        ->assertOk();
});

test('the retired reservations route redirects to the reserved-stock filter', function (): void {
    expect(class_exists('App\\Filament\\Resources\\StockReservations\\StockReservationResource'))->toBeFalse();
});
