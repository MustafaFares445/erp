<?php

declare(strict_types=1);

use App\Enums\CrmPermission;
use App\Enums\OccurrenceStatus;
use App\Filament\Pages\CrmDashboard;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Filament\Resources\MaintenanceSchedules\RelationManagers\OccurrencesRelationManager;
use App\Filament\Resources\PurchaseInbounds\Tables\PurchaseInboundsTable;
use App\Filament\Resources\ReceivableWriteOffs\Pages\CreateReceivableWriteOff;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Models\InventoryStock;
use App\Models\MaintenanceSchedule;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers every maintenance occurrence status color branch', function (): void {
    $manager = new ReflectionClass(OccurrencesRelationManager::class)->newInstanceWithoutConstructor();
    $manager->ownerRecord = MaintenanceSchedule::factory()->create();
    $manager->pageClass = MaintenanceScheduleResource::class;

    $table = $manager->table(Table::make($manager));
    $status = $table->getColumn('status');

    expect($status?->getColor(OccurrenceStatus::Missed))->toBe('danger')
        ->and($status?->getColor(OccurrenceStatus::Completed))->toBe('success')
        ->and($status?->getColor(OccurrenceStatus::Raised))->toBe('warning')
        ->and($status?->getColor(OccurrenceStatus::Skipped))->toBe('gray')
        ->and($status?->getColor(OccurrenceStatus::Pending))->toBe('info');
});

it('covers CRM dashboard campaign permission fallback', function (): void {
    (new CrmPermissionSeeder)->run();

    $user = User::factory()->create();
    $user->givePermissionTo(CrmPermission::CampaignView->value);
    $this->actingAs($user);

    expect(CrmDashboard::canAccess())->toBeTrue();
});

it('covers unauthenticated shipment confirmation callback', function (): void {
    auth()->logout();

    $page = new ReflectionClass(ViewShipment::class)->newInstanceWithoutConstructor();
    $actions = new ReflectionMethod(ViewShipment::class, 'getHeaderActions');
    $confirm = $actions->invoke($page)[0];

    $confirm->getActionFunction()(new Shipment);

    expect(true)->toBeTrue();
});

it('covers receivable write-off auth guard and string integer normalization', function (): void {
    auth()->logout();

    $page = new ReflectionClass(CreateReceivableWriteOff::class)->newInstanceWithoutConstructor();
    $create = new ReflectionMethod(CreateReceivableWriteOff::class, 'handleRecordCreation');

    expect(fn (): mixed => $create->invoke($page, []))
        ->toThrow(LogicException::class, 'authenticated accounting user');

    $integer = new ReflectionMethod(CreateReceivableWriteOff::class, 'integerValue');

    expect($integer->invoke(null, '42', 'customer'))->toBe(42);
});

it('covers purchase inbound business-state colors', function (): void {
    $color = new ReflectionMethod(PurchaseInboundsTable::class, 'stateColor');

    expect($color->invoke(null, 'Awaiting Allocation'))->toBe('warning')
        ->and($color->invoke(null, 'Ready to Receive'))->toBe('info')
        ->and($color->invoke(null, 'Partially Received'))->toBe('primary')
        ->and($color->invoke(null, 'Received'))->toBe('success')
        ->and($color->invoke(null, 'Cancelled / Closed'))->toBe('gray')
        ->and($color->invoke(null, 'Unknown'))->toBe('gray');
});

it('covers inventory stock saleable availability fallback calculation', function (): void {
    $stock = new InventoryStock;
    $stock->forceFill([
        'product_variant_id' => 99999991,
        'warehouse_id' => 99999992,
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '3.000000',
        'damaged_quantity' => '2.000000',
        'available_quantity' => null,
    ]);

    expect($stock->saleableAvailableQuantity())->toBe(5.0);
});
