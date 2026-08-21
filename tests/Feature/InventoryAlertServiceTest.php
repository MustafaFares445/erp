<?php

declare(strict_types=1);

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryAlertType;
use App\Enums\InventoryImportRunStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryAlert;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventorySetting;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\Inventory\InventoryAlertService;
use App\Services\Inventory\InventoryIdentityGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps out of stock and low stock alerts mutually exclusive', function (): void {
    $service = app(InventoryAlertService::class);
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 5,
        'reserved_quantity' => 5,
        'damaged_quantity' => 0,
        'available_quantity' => 0,
        'reorder_level' => 2,
    ]);

    $service->syncStock($stock);
    $service->syncStock($stock);

    expect(activeAlert($stock, InventoryAlertType::OutOfStock))->not->toBeNull()
        ->and(activeAlert($stock, InventoryAlertType::LowStock))->toBeNull()
        ->and(InventoryAlert::query()->where('subject_id', $stock->getKey())->count())->toBe(1);

    $stock->forceFill(['reserved_quantity' => 4, 'available_quantity' => 1])->save();
    $service->syncStock($stock->fresh());

    expect(activeAlert($stock, InventoryAlertType::OutOfStock))->toBeNull()
        ->and(activeAlert($stock, InventoryAlertType::LowStock))->not->toBeNull();

    $stock->forceFill(['reserved_quantity' => 1, 'available_quantity' => 4])->save();
    $service->syncStock($stock->fresh());

    expect(activeAlert($stock, InventoryAlertType::OutOfStock))->toBeNull()
        ->and(activeAlert($stock, InventoryAlertType::LowStock))->toBeNull();
});

it('activates updates resolves and reopens import errors', function (): void {
    $service = app(InventoryAlertService::class);
    $run = InventoryImportRun::factory()->create([
        'status' => InventoryImportRunStatus::ReadyWithErrors,
        'failed_rows' => 2,
        'rejected_rows' => 2,
    ]);

    $service->syncImport($run);
    $alert = activeAlert($run, InventoryAlertType::ImportError);

    expect($alert?->severity)->toBe(InventoryAlertSeverity::Warning)
        ->and($alert?->context)->toMatchArray(['status' => 'ready_with_errors', 'failed_rows' => 2]);

    $run->forceFill([
        'status' => InventoryImportRunStatus::Confirmed,
        'failed_rows' => 0,
        'rejected_rows' => 0,
    ])->save();
    $service->syncImport($run->fresh());
    expect(activeAlert($run, InventoryAlertType::ImportError))->toBeNull();

    $run->forceFill([
        'status' => InventoryImportRunStatus::Failed,
        'failure_message' => 'Runtime failure',
    ])->save();
    $service->syncImport($run->fresh());

    expect(activeAlert($run, InventoryAlertType::ImportError)?->severity)
        ->toBe(InventoryAlertSeverity::Critical)
        ->and(InventoryAlert::query()
            ->where('type', InventoryAlertType::ImportError->value)
            ->where('subject_id', $run->getKey())
            ->count())->toBe(1);
});

it('detects and resolves missing serialized identities per stock balance', function (): void {
    $service = app(InventoryAlertService::class);
    $variant = ProductVariant::factory()->machine()->create();
    $stock = InventoryStock::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'on_hand_quantity' => 2,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 2,
    ]);
    SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $stock->warehouse_id,
        'status' => SerializedInventoryUnitStatus::Available,
    ]);

    $service->syncMissingDeviceIdentity($stock);
    $alert = activeAlert($stock, InventoryAlertType::MissingDeviceIdentity);

    expect($alert)->not->toBeNull()
        ->and($alert?->context)->toMatchArray([
            'physical_quantity' => 2,
            'registered_devices' => 1,
            'difference' => 1,
        ]);

    SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $stock->warehouse_id,
        'status' => SerializedInventoryUnitStatus::Damaged,
    ]);
    $service->syncMissingDeviceIdentity($stock);

    expect(activeAlert($stock, InventoryAlertType::MissingDeviceIdentity))->toBeNull();
});

it('raises a persistent duplicate alert before returning the domain error', function (): void {
    $guard = app(InventoryIdentityGuard::class);
    $variant = ProductVariant::factory()->create(['sku' => 'DUP-SKU']);
    $unit = SerializedInventoryUnit::factory()->create([
        'serial_number' => 'DUP-SERIAL',
        'iot_number' => 'DUP-IOT',
    ]);

    expect(fn () => $guard->ensureSkuAvailable('DUP-SKU'))->toThrow(DomainException::class);
    expect(fn () => $guard->ensureSerialAvailable('DUP-SERIAL'))->toThrow(DomainException::class);
    expect(fn () => $guard->ensureIotAvailable('DUP-IOT'))->toThrow(DomainException::class);

    expect(activeAlert($variant, InventoryAlertType::DuplicateIdentity))->not->toBeNull()
        ->and(activeAlert($unit, InventoryAlertType::DuplicateIdentity)?->context)
        ->toBe(['kind' => 'iot', 'value' => 'DUP-IOT']);
});

it('allows unchanged identities and absent IoT identifiers', function (): void {
    $guard = app(InventoryIdentityGuard::class);
    $variant = ProductVariant::factory()->create(['sku' => 'CURRENT-SKU']);
    $unit = SerializedInventoryUnit::factory()->create([
        'serial_number' => 'CURRENT-SERIAL',
        'iot_number' => 'CURRENT-IOT',
    ]);

    $guard->ensureSkuAvailable('CURRENT-SKU', $variant->getKey());
    $guard->ensureSerialAvailable('CURRENT-SERIAL', $unit->getKey());
    $guard->ensureIotAvailable('CURRENT-IOT', $unit->getKey());
    $guard->ensureIotAvailable(null);
    $guard->ensureIotAvailable(' ');

    expect(InventoryAlert::query()->where('type', InventoryAlertType::DuplicateIdentity->value)->count())
        ->toBe(0);
});

it('reconciles all alert sources idempotently and is scheduled daily', function (): void {
    InventorySetting::query()->create([
        'default_markup_percent' => 0,
        'expiry_alert_days' => 30,
    ]);
    InventoryStock::factory()->create([
        'on_hand_quantity' => 0,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 0,
    ]);
    InventoryLot::factory()->create([
        'expires_at' => today()->addDays(5),
        'on_hand_quantity' => 2,
    ]);
    $transfer = StockTransfer::factory()->dispatched()->create();
    StockTransferItem::factory()->create(['stock_transfer_id' => $transfer->getKey()]);
    InventoryImportRun::factory()->create(['status' => InventoryImportRunStatus::Failed]);

    $this->artisan('inventory:alerts:reconcile')
        ->expectsOutputToContain('Reconciled 4 inventory records.')
        ->assertSuccessful();
    $alertCount = InventoryAlert::query()->count();

    $this->artisan('inventory:alerts:reconcile')->assertSuccessful();

    expect(InventoryAlert::query()->count())->toBe($alertCount)
        ->and(InventoryAlert::query()->where('type', InventoryAlertType::OutOfStock->value)->exists())->toBeTrue()
        ->and(InventoryAlert::query()->where('type', InventoryAlertType::Expiry->value)->exists())->toBeTrue()
        ->and(InventoryAlert::query()->where('type', InventoryAlertType::TransferDiscrepancy->value)->exists())->toBeTrue()
        ->and(InventoryAlert::query()->where('type', InventoryAlertType::ImportError->value)->exists())->toBeTrue();

    $this->artisan('schedule:list')
        ->expectsOutputToContain('inventory:alerts:reconcile')
        ->assertSuccessful();
});

function activeAlert(Model $subject, InventoryAlertType $type): ?InventoryAlert
{
    return InventoryAlert::query()
        ->where('type', $type->value)
        ->where('subject_type', $subject::class)
        ->where('subject_id', $subject->getKey())
        ->whereNull('resolved_at')
        ->first();
}
