<?php

declare(strict_types=1);

use App\Enums\StockCondition;
use App\Enums\TransferDiscrepancyDisposition;
use App\Filament\Resources\Adjustments\Pages\EditAdjustment;
use App\Filament\Resources\Adjustments\RelationManagers\AdjustmentItemsRelationManager;
use App\Filament\Resources\InventoryOperations\Pages\ViewInventoryOperation;
use App\Models\InventoryAdjustment;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});
function batch68Method(string $class, string $method): ReflectionMethod
{
    return new ReflectionMethod($class, $method);
}

function batch68Hook(Select $component): Closure
{
    $property = new ReflectionProperty($component, 'afterStateUpdated');
    $hooks = $property->getValue($component);

    if (! isset($hooks[0]) || ! $hooks[0] instanceof Closure) {
        throw new LogicException('Expected afterStateUpdated callback.');
    }

    return $hooks[0];
}

it('covers adjustment stock-condition reactive reset and recalculation', function (): void {
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
    $components = collect($schema?->getFlatComponents(withHidden: true) ?? [])
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
    $set->shouldReceive('__invoke')->with('serialized_inventory_unit_id', null)->once();
    $set->shouldReceive('__invoke')->with('old_quantity', 5.0)->once();
    $set->shouldReceive('__invoke')->with('difference', 2.0)->once();

    batch68Hook($components['stock_condition'])($get, $set);

    expect(true)->toBeTrue();
});

it('covers transfer receipt parsing guards valid disposition decimal precision and zero conversion factor', function (): void {
    $page = new ReflectionClass(ViewInventoryOperation::class)->newInstanceWithoutConstructor();
    $parse = batch68Method(ViewInventoryOperation::class, 'transferReceiptLines');

    expect(fn (): mixed => $parse->invoke($page, ['lines' => ['bad-line']]))
        ->toThrow(DomainException::class, 'line is invalid');

    expect(fn (): mixed => $parse->invoke($page, ['lines' => [[
        'operation_line_id' => new stdClass,
        'received_transaction_quantity' => '1',
    ]]]))->toThrow(DomainException::class, 'invalid field values');

    $valid = $parse->invoke($page, ['lines' => [[
        'operation_line_id' => '42',
        'received_transaction_quantity' => '1',
        'discrepancy_disposition' => TransferDiscrepancyDisposition::Damaged->value,
        'discrepancy_reason' => 'Damaged in transit',
    ]]]);
    expect($valid)->toHaveCount(1)
        ->and($valid[0]->discrepancyDisposition)->toBe(TransferDiscrepancyDisposition::Damaged);

    expect(fn (): mixed => $parse->invoke($page, ['lines' => [[
        'operation_line_id' => 42,
        'received_transaction_quantity' => '1',
        'discrepancy_disposition' => 'not-valid',
    ]]]))->toThrow(DomainException::class, 'invalid discrepancy disposition');

    $quantity = batch68Method(ViewInventoryOperation::class, 'receiptTransactionQuantity');
    expect(fn (): mixed => $quantity->invoke($page, 1.1234567))
        ->toThrow(DomainException::class, 'at most six decimal places');

    $line = new InventoryOperationLine;
    $line->forceFill([
        'dispatched_base_quantity' => '5.000000',
        'received_base_quantity' => '1.000000',
        'conversion_factor_snapshot' => '0.000000',
    ]);

    expect(batch68Method(ViewInventoryOperation::class, 'remainingTransactionQuantity')->invoke($page, $line))
        ->toBe('0.000000');
});
it('covers unauthenticated transfer receipt action actor guard', function (): void {
    auth()->logout();

    $page = new ReflectionClass(ViewInventoryOperation::class)->newInstanceWithoutConstructor();
    $action = batch68Method(ViewInventoryOperation::class, 'transferReceiptAction')->invoke($page);
    $record = InventoryOperation::factory()->internalTransfer()->inTransit()->create();

    expect(fn () => ($action->getActionFunction())($record, ['lines' => []]))
        ->toThrow(LogicException::class, 'authenticated inventory operation actor');
});
