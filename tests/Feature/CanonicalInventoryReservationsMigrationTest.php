<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the canonical inventory reservation schema', function (): void {
    expect(Schema::hasColumns('inventory_reservations', [
        'product_variant_id',
        'warehouse_id',
        'source_type',
        'source_id',
        'base_quantity',
        'status',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('inventory_reservation_allocations', [
            'inventory_reservation_id',
            'inventory_lot_id',
            'serialized_inventory_unit_id',
            'base_quantity',
        ]))->toBeTrue();
});
