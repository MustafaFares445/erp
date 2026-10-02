<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('keeps location-level stock identity gated until ADR 0013 is amended', function (): void {
    expect(Schema::hasTable('warehouse_locations'))->toBeFalse();

    foreach ([
        'inventory_movements',
        'inventory_lots',
        'serialized_inventory_units',
        'inventory_adjustment_items',
        'inventory_operation_lines',
        'packages',
    ] as $table) {
        expect(Schema::hasColumn($table, 'warehouse_location_id'))
            ->toBeFalse("{$table} must remain warehouse-level while ADR 0013 is active.");
    }
});
