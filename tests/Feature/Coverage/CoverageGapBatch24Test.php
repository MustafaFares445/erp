<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Filament\Resources\MaintenanceRequests\Pages\CreateMaintenanceRequest;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('covers maintenance request dynamic equipment and warranty form callbacks', function (): void {
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
        'serial_number' => 'FORM-COVERAGE-SERIAL',
    ]);

    $test = Livewire::actingAs($manager)->test(CreateMaintenanceRequest::class);
    $schema = $test->instance()->getSchema('form');

    expect($schema)->not->toBeNull();

    $components = collect($schema->getFlatComponents(withHidden: true));
    $linkedTicket = $components->first(
        static fn (mixed $component): bool => $component instanceof Placeholder && $component->getName() === 'linked_ticket',
    );
    $equipment = $components->first(
        static fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'serialized_inventory_unit_id',
    );
    $warranty = $components->first(
        static fn (mixed $component): bool => $component instanceof Placeholder && $component->getName() === 'warranty_resolution',
    );

    expect($linkedTicket)->toBeInstanceOf(Placeholder::class)
        ->and($equipment)->toBeInstanceOf(Select::class)
        ->and($warranty)->toBeInstanceOf(Placeholder::class)
        ->and($linkedTicket->getContent())->toBe('—')
        ->and($equipment->getOptions())->toBe([]);

    $test->set('data.customer_id', $customer->getKey());
    $components = collect($test->instance()->getSchema('form')->getFlatComponents(withHidden: true));
    $equipment = $components->first(
        static fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'serialized_inventory_unit_id',
    );

    expect($equipment?->getOptions())->toHaveKey($unit->getKey());

    $test->set('data.serialized_inventory_unit_id', $unit->getKey());
    $components = collect($test->instance()->getSchema('form')->getFlatComponents(withHidden: true));
    $warranty = $components->first(
        static fn (mixed $component): bool => $component instanceof Placeholder && $component->getName() === 'warranty_resolution',
    );

    expect($warranty?->getContent())
        ->toBe('Resolved automatically from the selected customer equipment.');

    $test->set('data.serialized_inventory_unit_id')
        ->set('data.serial_number', 'EXT-COVERAGE');
    $components = collect($test->instance()->getSchema('form')->getFlatComponents(withHidden: true));
    $warranty = $components->first(
        static fn (mixed $component): bool => $component instanceof Placeholder && $component->getName() === 'warranty_resolution',
    );
    expect($warranty?->getContent())
        ->toBe('Known serials are validated against customer custody; unmatched serials are treated as external equipment.');

    $test->set('data.serial_number');
    $components = collect($test->instance()->getSchema('form')->getFlatComponents(withHidden: true));
    $warranty = $components->first(
        static fn (mixed $component): bool => $component instanceof Placeholder && $component->getName() === 'warranty_resolution',
    );

    expect($warranty?->getContent())
        ->toBe('Select known equipment or enter an external serial number.');
});
