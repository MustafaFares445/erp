<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Enums\StockCondition;
use App\Enums\WarrantyEntitlementState;
use App\Filament\Resources\Adjustments\Pages\EditAdjustment;
use App\Filament\Resources\Adjustments\RelationManagers\AdjustmentItemsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\CustomerOwnedEquipmentRelationManager;
use App\Filament\Resources\Payments\Schemas\PaymentInfolist;
use App\Models\InventoryAdjustment;
use App\Models\InventoryStock;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyEntitlement;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});
function batch67Method(string $class, string $method): ReflectionMethod
{
    return new ReflectionMethod($class, $method);
}

function batch67Hook(Select $component): Closure
{
    $property = new ReflectionProperty($component, 'afterStateUpdated');
    $hooks = $property->getValue($component);

    if (! isset($hooks[0]) || ! $hooks[0] instanceof Closure) {
        throw new LogicException('Expected a Filament afterStateUpdated hook.');
    }

    return $hooks[0];
}

it('covers adjustment variant and lot reactive state callbacks', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);
    $adjustment = InventoryAdjustment::factory()->for($warehouse)->create();
    $actor = User::factory()->admin()->create();
    $manager = Livewire::actingAs($actor)
        ->test(AdjustmentItemsRelationManager::class, [
            'ownerRecord' => $adjustment,
            'pageClass' => EditAdjustment::class,
        ])
        ->instance();
    $schema = $manager->getSchema('form');

    if (! $schema instanceof Schema) {
        throw new LogicException('Adjustment item form schema was not available.');
    }
    $components = collect($schema->getFlatComponents(withHidden: true))
        ->filter(fn (mixed $component): bool => $component instanceof Select)
        ->keyBy(fn (Select $component): string => $component->getName());

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(static fn (string $path): mixed => match ($path) {
        'product_variant_id' => $variant->getKey(),
        'stock_condition' => StockCondition::Saleable->value,
        'inventory_lot_id', 'serialized_inventory_unit_id' => null,
        'new_quantity' => 7,
        default => null,
    });

    $set = Mockery::mock(Set::class);
    $set->shouldReceive('__invoke')->with('inventory_lot_id', null)->once();
    $set->shouldReceive('__invoke')->with('serialized_inventory_unit_id', null)->twice();
    $set->shouldReceive('__invoke')->with('old_quantity', 5.0)->twice();
    $set->shouldReceive('__invoke')->with('difference', 2.0)->twice();

    batch67Hook($components['product_variant_id'])($get, $set, $variant->getKey());
    batch67Hook($components['inventory_lot_id'])($get, $set);

    expect(true)->toBeTrue();
});
it('covers fully-applied posted payment banner allocation description and numeric tax total', function (): void {
    $payment = new Payment;
    $payment->forceFill([
        'amount' => '100.00',
        'currency' => 'AED',
        'status' => PaymentStatus::Posted,
        'posted_at' => now(),
        'allocations_sum_amount' => '100.00',
    ]);

    $banner = batch67Method(PaymentInfolist::class, 'bannerMeta')->invoke(null, $payment);
    expect($banner['status'])->toBe('success')
        ->and($banner['description'])->toContain('100');

    $payment->setRelation('allocations', new EloquentCollection);
    $allocationsSection = batch67Method(PaymentInfolist::class, 'allocations')->invoke(null);
    expect($allocationsSection)->toBeInstanceOf(Section::class);

    $descriptionProperty = new ReflectionProperty($allocationsSection, 'description');
    $description = $descriptionProperty->getValue($allocationsSection);
    expect($description)->toBeInstanceOf(Closure::class)
        ->and($description($payment))->not->toBe('');

    $first = new TaxRecognitionEntry;
    $first->forceFill(['recognised_tax_amount' => '3.25']);

    $second = new TaxRecognitionEntry;
    $second->forceFill(['recognised_tax_amount' => '1.75']);

    $payment->setRelation('taxRecognitionEntries', new EloquentCollection([$first, $second]));

    expect(batch67Method(PaymentInfolist::class, 'recognizedTaxTotal')->invoke(null, $payment))
        ->toBe(5.0);
});

it('covers customer equipment active pending legacy active and expired warranty labels', function (): void {
    $activeUnit = SerializedInventoryUnit::factory()->create();
    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $activeUnit->getKey(),
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => today()->subDay(),
        'expires_on' => today()->addDay(),
    ]);

    $pendingUnit = SerializedInventoryUnit::factory()->create();
    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $pendingUnit->getKey(),
        'state' => WarrantyEntitlementState::PendingActivation,
        'starts_on' => null,
        'expires_on' => null,
    ]);

    $legacyActive = SerializedInventoryUnit::factory()->create([
        'warranty_expires_on' => today()->addDay(),
    ]);
    $legacyExpired = SerializedInventoryUnit::factory()->create([
        'warranty_expires_on' => today()->subDay(),
    ]);
    $state = batch67Method(CustomerOwnedEquipmentRelationManager::class, 'warrantyState');
    $color = batch67Method(CustomerOwnedEquipmentRelationManager::class, 'warrantyColor');

    expect($state->invoke(null, $activeUnit->refresh()))->toBe('Active')
        ->and($color->invoke(null, $activeUnit->refresh()))->toBe('success')
        ->and($state->invoke(null, $pendingUnit->refresh()))->toBe('Pending Activation')
        ->and($color->invoke(null, $pendingUnit->refresh()))->toBe('warning')
        ->and($state->invoke(null, $legacyActive->refresh()))->toBe('Active')
        ->and($state->invoke(null, $legacyExpired->refresh()))->toBe('Expired');
});
