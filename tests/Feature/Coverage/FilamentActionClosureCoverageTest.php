<?php

declare(strict_types=1);

use App\Enums\InventoryReturnType;
use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Filament\Resources\Returns\Pages\ManageReturns;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coverageCreateAction(string $pageClass): CreateAction
{
    $page = new ReflectionClass($pageClass)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($pageClass, 'getHeaderActions');
    $actions = $method->invoke($page);

    expect($actions)->toHaveCount(1)->and($actions[0])->toBeInstanceOf(CreateAction::class);

    return $actions[0];
}
it('executes bill create-action normalization and authentication guards', function (): void {
    $action = coverageCreateAction(ManageBills::class);

    expect(fn (): mixed => $action->process(null, ['data' => []]))
        ->toThrow(LogicException::class, 'authenticated accounting user');

    $this->actingAs(User::factory()->admin()->create());
    $data = [
        99 => 'ignored',
        'supplier_id' => null,
        'lines' => [
            ['description' => 'Coverage line', 5 => 'ignored'],
            'not-an-array',
        ],
    ];

    expect(fn (): mixed => $action->process(null, ['data' => $data]))
        ->toThrow(DomainException::class, 'supplier invoice reference');
});

it('executes return create-action validation branches', function (): void {
    $action = coverageCreateAction(ManageReturns::class);

    expect(fn (): mixed => $action->process(null, ['data' => []]))
        ->toThrow(LogicException::class, 'authenticated inventory return actor');

    $this->actingAs(User::factory()->admin()->create());
    $warehouse = Warehouse::factory()->create();

    expect(fn (): mixed => $action->process(null, ['data' => ['warehouse_id' => $warehouse->getKey()]]))
        ->toThrow(DomainException::class, 'return type and warehouse');

    expect(fn (): mixed => $action->process(null, ['data' => [
        'return_type' => InventoryReturnType::Customer->value,
        'warehouse_id' => $warehouse->getKey(),
        'reason' => '  Customer reason  ',
        'notes' => '',
    ]]))->toThrow(DomainException::class, 'original delivery');

    expect(fn (): mixed => $action->process(null, ['data' => [
        'return_type' => InventoryReturnType::Supplier->value,
        'warehouse_id' => $warehouse->getKey(),
        'reason' => 123,
        'notes' => '  Supplier note  ',
    ]]))->toThrow(DomainException::class, 'requires a supplier');

    expect(fn (): mixed => $action->process(null, ['data' => [
        'return_type' => 'invalid-type',
        'warehouse_id' => $warehouse->getKey(),
    ]]))->toThrow(DomainException::class, 'selected return type is invalid');

    $page = new ReflectionClass(ManageReturns::class)->newInstanceWithoutConstructor();
    expect($page->getSubheading())->toBeString()->not->toBeEmpty();
});
