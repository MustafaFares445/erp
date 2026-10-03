<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Filament\Resources\InventoryLots\Schemas\InventoryLotInfolist;
use App\Filament\Widgets\TaxPositionThisPeriod;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers inventory lot origin references and URLs for null, custom, and operation sources', function (): void {
    $originReference = new ReflectionMethod(InventoryLotInfolist::class, 'originReference');
    $originUrl = new ReflectionMethod(InventoryLotInfolist::class, 'originUrl');

    $none = InventoryLot::factory()->make([
        'origin_source_type' => null,
        'origin_source_id' => null,
    ]);

    expect($originReference->invoke(null, $none))->toBe('—')
        ->and($originUrl->invoke(null, $none))->toBeNull();

    $custom = InventoryLot::factory()->make([
        'origin_source_type' => 'supplier_return',
        'origin_source_id' => 42,
    ]);

    expect($originReference->invoke(null, $custom))->toBe('Supplier Return #42')
        ->and($originUrl->invoke(null, $custom))->toBeNull();

    $missingOperation = InventoryLot::factory()->make([
        'origin_source_type' => 'inventory_operation',
        'origin_source_id' => 987654,
    ]);

    expect($originReference->invoke(null, $missingOperation))
        ->toBe(__('admin.resources.inventory_receipts_menu').' #987654');

    $operation = InventoryOperation::factory()->create([
        'operation_number' => 'REC-COV-41',
    ]);
    $withOperation = InventoryLot::factory()->make([
        'origin_source_type' => 'inventory_operation',
        'origin_source_id' => $operation->getKey(),
    ]);

    expect($originReference->invoke(null, $withOperation))->toBe('REC-COV-41')
        ->and($originUrl->invoke(null, $withOperation))->toBeNull();

    $operation->forceFill(['operation_number' => ''])->saveQuietly();
    expect($originReference->invoke(null, $withOperation))
        ->toBe(__('admin.resources.inventory_receipts_menu').' #'.$operation->getKey());
});

it('covers tax-position widget access and current-period fallbacks', function (): void {
    (new AccountingPermissionSeeder)->run();

    auth()->logout();
    expect(TaxPositionThisPeriod::canView())->toBeFalse();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    expect(TaxPositionThisPeriod::canView())->toBeTrue();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(AccountingPermission::TaxView->value);
    $this->actingAs($viewer);
    expect(TaxPositionThisPeriod::canView())->toBeTrue();

    CarbonImmutable::setTestNow('2026-09-30 12:00:00');

    $widget = new ReflectionClass(TaxPositionThisPeriod::class)->newInstanceWithoutConstructor();
    $data = new ReflectionMethod(TaxPositionThisPeriod::class, 'getData');

    expect($data->invoke($widget)['datasets'][0]['data'])->toHaveCount(5);

    $widget->pageFilters = ['period' => 'this_month'];

    expect($data->invoke($widget)['labels'])->toHaveCount(5);

    CarbonImmutable::setTestNow();
});
