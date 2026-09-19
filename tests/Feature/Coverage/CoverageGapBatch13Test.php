<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Filament\Resources\InventoryReservations\Actions\InventoryReservationActions;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Inventory\InventoryReservationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('covers warranty backfill shipments without deliveries without units and apply mode', function (): void {
    Shipment::factory()->arrived()->create([
        'inventory_operation_id' => null,
    ]);

    Shipment::factory()->arrived()->create();

    $eligible = Shipment::factory()->arrived()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'warehouse_id' => $eligible->warehouse_id,
    ]);

    InventoryMovement::factory()->create([
        'product_variant_id' => $unit->product_variant_id,
        'warehouse_id' => $eligible->warehouse_id,
        'source_type' => 'inventory_operation',
        'source_id' => $eligible->inventory_operation_id,
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    expect(Artisan::call('support:warranties:backfill'))->toBe(0);
    expect(Artisan::output())
        ->toContain('eligible serialized movements')
        ->toContain('apply');
});

it('covers reservation release success and bulk skip-success-failure notification branches', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $successfulService = new class
    {
        public int $calls = 0;

        public function release(InventoryReservation $reservation, User $actor, string $reason): void
        {
            $this->calls++;
        }
    };

    app()->instance(InventoryReservationService::class, $successfulService);

    $active = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Active,
    ]);
    $resolved = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Released,
        'released_at' => now(),
        'release_reason' => 'Already released',
    ]);

    $release = InventoryReservationActions::release()->getActionFunction();
    expect($release)->toBeInstanceOf(Closure::class);
    $release($active, ['reason' => 'Coverage release reason']);

    expect($successfulService->calls)->toBe(1);

    $bulk = InventoryReservationActions::releaseSelected()->getActionFunction();
    expect($bulk)->toBeInstanceOf(Closure::class);

    $bulk(new Collection([$active, $resolved, User::factory()->create()]), [
        'reason' => 'Coverage bulk release reason',
    ]);

    expect($successfulService->calls)->toBe(2);

    $failingService = new class
    {
        public function release(InventoryReservation $reservation, User $actor, string $reason): void
        {
            throw new DomainException('Coverage forced reservation failure.');
        }
    };

    app()->instance(InventoryReservationService::class, $failingService);

    $failing = InventoryReservation::factory()->create([
        'status' => ReservationStatus::Active,
    ]);

    $bulk(new Collection([$failing]), [
        'reason' => 'Coverage failing release reason',
    ]);

    expect($failing->refresh()->status)->toBe(ReservationStatus::Active);
});
