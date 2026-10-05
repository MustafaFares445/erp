<?php

declare(strict_types=1);

use App\Enums\WarrantyEntitlementState;
use App\Filament\Resources\SerializedInventoryUnits\Pages\ViewSerializedInventoryUnit;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function coverage96Action(ViewSerializedInventoryUnit $page, string $name): mixed
{
    $actions = new ReflectionMethod(ViewSerializedInventoryUnit::class, 'getHeaderActions');
    foreach ($actions->invoke($page) as $action) {
        if ($action->getName() === $name) {
            return $action;
        }
    }

    throw new LogicException("Missing action {$name}");
}

it('covers serialized warranty activation and cancellation success and handled domain failures', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->admin()->create();
    $unit = SerializedInventoryUnit::factory()->create();
    $entitlement = WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'state' => WarrantyEntitlementState::PendingActivation,
        'starts_on' => null,
        'expires_on' => null,
    ]);

    $page = Livewire::actingAs($actor)
        ->test(ViewSerializedInventoryUnit::class, ['record' => $unit->id])
        ->instance();

    $activate = coverage96Action($page, 'activateWarranty');
    $activateFn = $activate->getActionFunction();
    expect($activateFn)->not->toBeNull();

    $activateFn($unit->refresh(), [
        'starts_on' => today()->toDateString(),
        'reason' => 'Coverage activation',
    ]);

    expect($entitlement->refresh()->state)->toBe(WarrantyEntitlementState::Active)
        ->and($entitlement->starts_on?->toDateString())->toBe(today()->toDateString());

    // Calling activation again reaches the service rejection and the action's handled DomainException branch.
    $activateFn($unit->refresh(), [
        'starts_on' => today()->toDateString(),
        'reason' => 'Duplicate activation',
    ]);
    expect($entitlement->refresh()->state)->toBe(WarrantyEntitlementState::Active);

    $cancel = coverage96Action($page, 'cancelWarrantyEntitlement');
    $cancelFn = $cancel->getActionFunction();
    expect($cancelFn)->not->toBeNull();

    $cancelFn($unit->refresh(), ['reason' => 'Coverage cancellation']);
    expect($entitlement->refresh()->state)->not->toBe(WarrantyEntitlementState::Active);

    // Cancelling the already-ended entitlement exercises the handled DomainException path.
    $cancelFn($unit->refresh(), ['reason' => 'Duplicate cancellation']);
});

it('covers serialized warranty action input guards and unauthenticated actor guard', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->admin()->create();
    $unit = SerializedInventoryUnit::factory()->create();
    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'state' => WarrantyEntitlementState::PendingActivation,
    ]);

    $page = Livewire::actingAs($actor)
        ->test(ViewSerializedInventoryUnit::class, ['record' => $unit->id])
        ->instance();

    $activateFn = coverage96Action($page, 'activateWarranty')->getActionFunction();
    $cancelFn = coverage96Action($page, 'cancelWarrantyEntitlement')->getActionFunction();

    expect(fn () => $activateFn($unit, ['reason' => 'Missing date']))
        ->toThrow(LogicException::class, 'activation data is invalid')
        ->and(fn () => $cancelFn($unit, ['reason' => null]))
        ->toThrow(LogicException::class, 'cancellation data is invalid');

    auth()->logout();

    expect(fn () => new ReflectionMethod(ViewSerializedInventoryUnit::class, 'currentActor')->invoke(null))
        ->toThrow(LogicException::class, 'authenticated User');
});
