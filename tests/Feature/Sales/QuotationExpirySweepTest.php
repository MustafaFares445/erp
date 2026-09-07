<?php

declare(strict_types=1);

use App\Enums\OperationType;
use App\Enums\QuotationStatus;
use App\Enums\ReservationStatus;
use App\Events\QuotationExpired;
use App\Models\AuditLog;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('expires a lapsed sent quotation and releases its reservation', function (): void {
    $quotation = Quotation::factory()->expired()->create();

    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '5.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);

    $operation = InventoryOperation::factory()->create([
        'operation_type' => OperationType::Delivery,
        'source_document_type' => Quotation::class,
        'source_document_id' => $quotation->getKey(),
    ]);

    $reservation = InventoryReservation::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'status' => ReservationStatus::Active,
        'base_quantity' => '5.000000',
    ]);

    Artisan::call('sales:quotations:expire');

    expect($quotation->refresh()->status)->toBe(QuotationStatus::Expired)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Released);
});

it('leaves an accepted or rejected quotation untouched', function (): void {
    $accepted = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'expires_at' => now()->subDays(10),
    ]);
    $rejected = Quotation::factory()->create([
        'status' => QuotationStatus::Rejected,
        'expires_at' => now()->subDays(10),
    ]);

    Artisan::call('sales:quotations:expire');

    expect($accepted->refresh()->status)->toBe(QuotationStatus::Accepted)
        ->and($rejected->refresh()->status)->toBe(QuotationStatus::Rejected);
});

it('leaves a sent quotation with no expiry date untouched', function (): void {
    $quotation = Quotation::factory()->sent()->create(['expires_at' => null]);

    Artisan::call('sales:quotations:expire');

    expect($quotation->refresh()->status)->toBe(QuotationStatus::Sent);
});

it('is idempotent across two sweep runs', function (): void {
    $quotation = Quotation::factory()->expired()->create();

    Artisan::call('sales:quotations:expire');
    $firstRunStatus = $quotation->refresh()->status;

    Artisan::call('sales:quotations:expire');

    expect($firstRunStatus)->toBe(QuotationStatus::Expired)
        ->and($quotation->refresh()->status)->toBe(QuotationStatus::Expired);
});

it('dispatches QuotationExpired exactly once per quotation', function (): void {
    Event::fake([QuotationExpired::class]);

    $first = Quotation::factory()->expired()->create();
    $second = Quotation::factory()->expired()->create();

    Artisan::call('sales:quotations:expire');

    Event::assertDispatchedTimes(QuotationExpired::class, 2);
    Event::assertDispatched(QuotationExpired::class, fn (QuotationExpired $event): bool => $event->quotation->is($first));
    Event::assertDispatched(QuotationExpired::class, fn (QuotationExpired $event): bool => $event->quotation->is($second));
});

it('writes an audit entry proving the service path ran, not a mass update', function (): void {
    $quotation = Quotation::factory()->expired()->create();

    Artisan::call('sales:quotations:expire');

    $log = AuditLog::query()
        ->where('subject_type', Quotation::class)
        ->where('subject_id', $quotation->getKey())
        ->where('description', 'quotation.expired')
        ->sole();

    expect(data_get($log->attribute_changes, 'old.status'))->toBe(QuotationStatus::Sent->value)
        ->and(data_get($log->attribute_changes, 'attributes.status'))->toBe(QuotationStatus::Expired->value)
        ->and($log->getProperty('source_channel'))->toBe('scheduler');
});
