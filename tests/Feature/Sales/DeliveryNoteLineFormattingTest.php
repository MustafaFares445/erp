<?php

declare(strict_types=1);

use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The Sales-facing Delivery Note view must present raw decimal quantities as
 * human-readable numbers, label the field "Quantity" rather than the
 * inventory-internal "Demand", and expose only read-only warehouse
 * preparation status — no inventory mutation actions.
 */
function deliveryNoteWithLine(string $quantity, bool $isPicked = false): InventoryOperation
{
    $unit = Unit::factory()->create(['name' => 'Each']);
    $variant = ProductVariant::factory()->create(['sku' => 'FORMLABS-FORM-WASH-V2-'.uniqid()]);
    $delivery = InventoryOperation::factory()->delivery()->create();

    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $quantity,
        'unit_id' => $unit->getKey(),
        'is_picked' => $isPicked,
    ]);

    return $delivery->refresh();
}

it('renders whole and fractional quantities without trailing zeros', function (string $raw, string $expected): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $delivery = deliveryNoteWithLine($raw);

    Livewire::actingAs($actor)
        ->test(ViewDeliveryNote::class, ['record' => $delivery->getRouteKey()])
        ->assertSee($expected.' Each')
        ->assertDontSee($raw);
})->with([
    ['1.000000', '1'],
    ['3.000000', '3'],
    ['1.500000', '1.5'],
    ['0.250000', '0.25'],
]);

it('labels the delivery line quantity field "Quantity" instead of "Demand"', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $delivery = deliveryNoteWithLine('1.000000');

    Livewire::actingAs($actor)
        ->test(ViewDeliveryNote::class, ['record' => $delivery->getRouteKey()])
        ->assertSee(__('admin.inventory.operation.fields.quantity'))
        ->assertDontSee(__('admin.inventory.operation.fields.demand'));
});

it('renders warehouse preparation status as Prepared or Not prepared yet', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();

    $prepared = deliveryNoteWithLine('1.000000', isPicked: true);
    Livewire::actingAs($actor)
        ->test(ViewDeliveryNote::class, ['record' => $prepared->getRouteKey()])
        ->assertSee(__('admin.inventory.operation.values.prepared'));

    $notPrepared = deliveryNoteWithLine('1.000000', isPicked: false);
    Livewire::actingAs($actor)
        ->test(ViewDeliveryNote::class, ['record' => $notPrepared->getRouteKey()])
        ->assertSee(__('admin.inventory.operation.values.not_prepared'));
});

it('never exposes inventory mutation actions on the Sales delivery note view', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $delivery = deliveryNoteWithLine('1.000000');

    $page = Livewire::actingAs($actor)
        ->test(ViewDeliveryNote::class, ['record' => $delivery->getRouteKey()])
        ->instance();

    $method = new ReflectionMethod($page, 'getHeaderActions');
    $actionNames = array_map(
        fn (Action $action): ?string => $action->getName(),
        $method->invoke($page),
    );

    expect($actionNames)->toEqualCanonicalizing(['generate_packing_list', 'create_invoice']);
});
