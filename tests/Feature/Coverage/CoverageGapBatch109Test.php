<?php

declare(strict_types=1);

use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStartTrigger;
use App\Filament\Resources\Customers\RelationManagers\CustomerOwnedEquipmentRelationManager;
use App\Filament\Resources\Quotations\Schemas\QuotationLinesRepeater;
use App\Filament\Resources\Tickets\Actions\TriageTicketAction;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\WarrantyEntitlement;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers customer-owned equipment warranty state and color branches', function (): void {
    $state = new ReflectionMethod(CustomerOwnedEquipmentRelationManager::class, 'warrantyState');
    $color = new ReflectionMethod(CustomerOwnedEquipmentRelationManager::class, 'warrantyColor');

    $activeUnit = SerializedInventoryUnit::factory()->create([
        'warranty_expires_on' => today()->addMonth(),
    ]);
    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $activeUnit->id,
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => today()->subDay(),
        'expires_on' => today()->addDay(),
    ]);

    expect($state->invoke(null, $activeUnit))->toBe('Active')
        ->and($color->invoke(null, $activeUnit))->toBe('success');

    $pendingUnit = SerializedInventoryUnit::factory()->create();
    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $pendingUnit->id,
        'state' => WarrantyEntitlementState::PendingActivation,
        'starts_on' => null,
        'expires_on' => null,
    ]);

    expect($state->invoke(null, $pendingUnit))->toBe('Pending Activation')
        ->and($color->invoke(null, $pendingUnit))->toBe('warning');

    $noWarranty = SerializedInventoryUnit::factory()->create([
        'warranty_expires_on' => null,
    ]);
    expect($state->invoke(null, $noWarranty))->toBe('No warranty / needs verification')
        ->and($color->invoke(null, $noWarranty))->toBe('warning');

    $legacyActive = SerializedInventoryUnit::factory()->create([
        'warranty_expires_on' => today()->addDay(),
    ]);
    expect($state->invoke(null, $legacyActive))->toBe('Active')
        ->and($color->invoke(null, $legacyActive))->toBe('success');

    $legacyExpired = SerializedInventoryUnit::factory()->create([
        'warranty_expires_on' => today()->subDay(),
    ]);
    expect($state->invoke(null, $legacyExpired))->toBe('Expired')
        ->and($color->invoke(null, $legacyExpired))->toBe('gray');
});

it('covers triage equipment empty options and entitlement policy preview', function (): void {
    $equipmentOptions = new ReflectionMethod(TriageTicketAction::class, 'equipmentOptions');
    $policyPreview = new ReflectionMethod(TriageTicketAction::class, 'policyPreview');

    $orphanTicket = new Ticket;
    $orphanTicket->setRelation('customer', null);

    expect($equipmentOptions->invoke(null, $orphanTicket))->toBe([]);

    $customer = CustomerProfile::factory()->create();
    $ticket = Ticket::factory()->for($customer, 'customer')->create();
    $unit = SerializedInventoryUnit::factory()->create();

    $missing = Mockery::mock(Get::class);
    $missing->shouldReceive('__invoke')->with('serialized_inventory_unit_id')->andReturn(null);
    expect($policyPreview->invoke(null, $ticket, $missing))->toBe('—');

    $withoutEntitlement = Mockery::mock(Get::class);
    $withoutEntitlement->shouldReceive('__invoke')
        ->with('serialized_inventory_unit_id')
        ->andReturn($unit->id);
    expect($policyPreview->invoke(null, $ticket, $withoutEntitlement))
        ->toBe('No activated entitlement snapshot yet.');

    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'customer_id' => $customer->id,
        'policy_name' => 'Coverage 109',
        'state' => WarrantyEntitlementState::PendingActivation,
        'start_trigger' => WarrantyStartTrigger::ManualActivation,
    ]);

    expect($policyPreview->invoke(null, $ticket, $withoutEntitlement))
        ->toContain('Coverage 109', 'Pending Activation', 'Manual activation');
});

it('covers quotation helper branches for floor comparison default unit factor and anonymous customer pricing', function (): void {
    $variant = ProductVariant::factory()->create([
        'min_price' => '10.00',
        'base_price' => '15.00',
    ]);

    $belowFloor = new ReflectionMethod(QuotationLinesRepeater::class, 'belowFloorHelperText');
    $baseEquivalent = new ReflectionMethod(QuotationLinesRepeater::class, 'baseEquivalentPrice');
    $resolvePrice = new ReflectionMethod(QuotationLinesRepeater::class, 'resolvePrice');

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(static fn (string $path): mixed => match ($path) {
        'product_variant_id' => $variant->id,
        'unit_price' => '20.00',
        'unit_id' => null,
        'price_floor_override_id' => null,
        '../../customer_id' => null,
        default => null,
    });

    expect($belowFloor->invoke(null, $get))->toBeNull()
        ->and($baseEquivalent->invoke(null, $get, $variant))->toBe(20.0)
        ->and($resolvePrice->invoke(null, $variant->id, $get))->not->toBeNull();
});
