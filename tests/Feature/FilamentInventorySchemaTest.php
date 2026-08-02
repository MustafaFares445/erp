<?php

declare(strict_types=1);

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryAlertType;
use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryAlerts\Pages\ViewInventoryAlert;
use App\Filament\Resources\InventoryAlerts\Tables\InventoryAlertsTable;
use App\Filament\Resources\InventoryLots\Pages\ViewInventoryLot;
use App\Filament\Resources\SerializedInventoryUnits\Pages\ViewSerializedInventoryUnit;
use App\Filament\Resources\StockLevels\Pages\ViewStockLevel;
use App\Models\InventoryAlert;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('builds the stock balance infolist', function (): void {
    $viewer = schemaViewer();
    $stock = InventoryStock::factory()->create();

    $stockSchema = viewInfolist($viewer, ViewStockLevel::class, $stock);

    expect($stockSchema->getFlatComponents())->not->toBeEmpty();
});

it('evaluates alert origin, state, and context display values', function (): void {
    $viewer = schemaViewer();
    $alert = InventoryAlert::factory()->create([
        'type' => InventoryAlertType::DuplicateIdentity,
        'severity' => InventoryAlertSeverity::Warning,
        'context' => [
            'duplicate' => true,
            'count' => 2,
            'missing' => null,
            'values' => ['SER-1', 'SER-2'],
        ],
    ]);
    $schema = viewInfolist($viewer, ViewInventoryAlert::class, $alert);

    expect(infolistState($schema, 'state'))->toBe('active')
        ->and(infolistState($schema, 'context'))->toBe(
            'duplicate: true; count: 2; missing: null; values: ["SER-1","SER-2"]',
        );

    $alert->forceFill(['resolved_at' => now(), 'context' => null])->save();
    $resolvedSchema = viewInfolist($viewer, ViewInventoryAlert::class, $alert->fresh());

    expect(infolistState($resolvedSchema, 'state'))->toBe('resolved')
        ->and(infolistState($resolvedSchema, 'context'))->toBe('—');
});

it('evaluates lot availability and expiry state in its infolist', function (): void {
    $viewer = schemaViewer();
    $lot = InventoryLot::factory()->create([
        'on_hand_quantity' => 5,
        'reserved_quantity' => 2,
        'expires_at' => today()->addDays(10),
    ]);
    $schema = viewInfolist($viewer, ViewInventoryLot::class, $lot);

    expect(infolistState($schema, 'days_remaining'))->toBe(10)
        ->and(infolistState($schema, 'available_quantity'))->toBe(3.0)
        ->and(infolistState($schema, 'expiry_state'))->toBe('expiring');
});

it('evaluates the serialized device timeline infolist', function (): void {
    $viewer = schemaViewer();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'serial_number' => 'SCHEMA-SERIAL',
    ]);
    $schema = viewInfolist($viewer, ViewSerializedInventoryUnit::class, $unit);

    expect(infolistState($schema, 'timeline'))->toBeArray();
});

it('maps every supported alert origin and rejects unknown origins', function (): void {
    foreach ([
        InventoryStock::class,
        InventoryLot::class,
        StockTransfer::class,
        InventoryImportRun::class,
        SerializedInventoryUnit::class,
        ProductVariant::class,
    ] as $subjectType) {
        $alert = InventoryAlert::factory()->make([
            'subject_type' => $subjectType,
            'subject_id' => 42,
        ]);

        InventoryAlertsTable::subjectUrl($alert);
        expect(InventoryAlertsTable::subjectReference($alert))->toContain('#42');
    }

    $unknown = InventoryAlert::factory()->make([
        'subject_type' => User::class,
        'subject_id' => 99,
    ]);

    expect(InventoryAlertsTable::subjectUrl($unknown))->toBeNull();
});

function infolistState(Schema $schema, string $name): mixed
{
    $entry = collect($schema->getFlatComponents())
        ->first(fn (mixed $component): bool => $component instanceof Entry && $component->getName() === $name);

    if (! $entry instanceof Entry) {
        throw new RuntimeException(sprintf('Missing infolist entry [%s].', $name));
    }

    return $entry->getState();
}

function schemaViewer(): User
{
    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo([
        InventoryPermission::PricingView->value,
        InventoryPermission::StockView->value,
        InventoryPermission::AlertView->value,
    ]);

    return $viewer;
}

/**
 * @param  class-string  $page
 */
function viewInfolist(User $viewer, string $page, Model $record): Schema
{
    /** @var Testable $component */
    $component = Livewire::actingAs($viewer)->test($page, ['record' => $record->getRouteKey()]);
    $schema = $component->instance()->getSchema('infolist');

    if (! $schema instanceof Schema) {
        throw new RuntimeException(sprintf('Missing infolist schema for [%s].', $page));
    }

    return $schema;
}
