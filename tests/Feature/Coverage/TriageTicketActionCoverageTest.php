<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Actions\TriageTicketAction;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\SlaPolicySeeder;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SlaPolicySeeder)->run();
});

function triageCoverageComponents(): array
{
    $action = TriageTicketAction::make();
    $schemaProperty = new ReflectionProperty($action, 'schema');
    $sections = $schemaProperty->getValue($action);

    $components = [];
    foreach ($sections as $section) {
        $childrenProperty = new ReflectionProperty($section, 'childComponents');
        $childSets = $childrenProperty->getValue($section);
        foreach ($childSets['default'] ?? [] as $component) {
            $components[$component->getName()] = $component;
        }
    }

    return [$action, $components];
}

function triageCoverageClosure(object $component, string $property): Closure
{
    if ($property === 'content') {
        $property = 'getConstantStateUsing';
    }

    $reflection = new ReflectionProperty($component, $property);
    $value = $reflection->getValue($component);

    expect($value)->toBeInstanceOf(Closure::class);

    return $value;
}

it('covers triage action equipment options warranty preview and helper guards', function (): void {
    [$action, $components] = triageCoverageComponents();

    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['name' => 'Coverage Variant']);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
        'serial_number' => 'SER-COVERAGE-01',
    ]);
    $ticket = Ticket::factory()->for($customer, 'customer')->create([
        'status' => TicketStatus::Pending,
    ]);

    $equipmentOptions = triageCoverageClosure($components['serialized_inventory_unit_id'], 'options');
    $options = $equipmentOptions($ticket);
    expect($options)->toHaveKey($unit->getKey())
        ->and($options[$unit->getKey()])->toContain('Coverage Variant', 'SER-COVERAGE-01');

    $preview = triageCoverageClosure($components['warranty_preview'], 'content');

    $externalGet = Mockery::mock(Get::class);
    $externalGet->shouldReceive('__invoke')->andReturnUsing(
        static fn (string $path): mixed => $path === 'equipment_source'
            ? TicketEquipmentSource::External->value
            : null,
    );
    expect($preview($ticket, $externalGet))->toContain('not applicable');

    $missingGet = Mockery::mock(Get::class);
    $missingGet->shouldReceive('__invoke')->andReturnUsing(
        static fn (string $path): mixed => match ($path) {
            'equipment_source' => TicketEquipmentSource::SoldByUs->value,
            'serialized_inventory_unit_id' => null,
            default => null,
        },
    );
    expect($preview($ticket, $missingGet))->toContain('Select customer equipment');

    $invalidGet = Mockery::mock(Get::class);
    $invalidGet->shouldReceive('__invoke')->andReturnUsing(
        static fn (string $path): mixed => match ($path) {
            'equipment_source' => TicketEquipmentSource::SoldByUs->value,
            'serialized_inventory_unit_id' => 999999999,
            default => null,
        },
    );
    expect($preview($ticket, $invalidGet))->toBe('Warranty unavailable');

    $validGet = Mockery::mock(Get::class);
    $validGet->shouldReceive('__invoke')->andReturnUsing(
        static fn (string $path): mixed => match ($path) {
            'equipment_source' => TicketEquipmentSource::SoldByUs->value,
            'serialized_inventory_unit_id' => $unit->getKey(),
            default => null,
        },
    );
    expect($preview($ticket, $validGet))->not->toBe('');

    $ticketWithoutCustomer = $ticket->replicate();
    $ticketWithoutCustomer->setRelation('customer', null);

    expect($preview($ticketWithoutCustomer, $validGet))->toBe('Warranty unavailable');

    $stringKeyed = new ReflectionMethod(TriageTicketAction::class, 'stringKeyedData');
    expect($stringKeyed->invoke(null, ['a' => 1]))->toBe(['a' => 1])
        ->and(fn (): mixed => $stringKeyed->invoke(null, [0 => 'invalid']))
        ->toThrow(LogicException::class, 'string keys');

    $integerKey = new ReflectionMethod(TriageTicketAction::class, 'integerKey');
    expect($integerKey->invoke(null, $unit))->toBe($unit->getKey())
        ->and(fn (): mixed => $integerKey->invoke(null, new SerializedInventoryUnit))
        ->toThrow(LogicException::class, 'numeric identifier');

    $currentActor = new ReflectionMethod(TriageTicketAction::class, 'currentActor');
    auth()->logout();
    expect(fn (): mixed => $currentActor->invoke(null))
        ->toThrow(LogicException::class, 'authenticated User');

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    expect($currentActor->invoke(null))->toBe($actor);
});

it('executes successful and validation-failure triage action closures', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $successTicket = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $action = TriageTicketAction::make();
    $closure = $action->getActionFunction();
    expect($closure)->toBeInstanceOf(Closure::class);

    $closure($successTicket, [
        'equipment_source' => TicketEquipmentSource::External->value,
        'external_equipment_name' => 'Coverage external device',
        'service_path' => TicketServicePath::RemoteSupport->value,
        'billing_decision' => 'no_charge',
    ]);
    expect($successTicket->refresh()->status)->toBe(TicketStatus::Live);

    $invalidTicket = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $closure($invalidTicket, [
        'equipment_source' => TicketEquipmentSource::External->value,
        'external_equipment_name' => '',
        'service_path' => TicketServicePath::RemoteSupport->value,
        'billing_decision' => 'no_charge',
    ]);
    expect($invalidTicket->refresh()->status)->toBe(TicketStatus::Pending);
});
