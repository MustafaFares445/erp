<?php

declare(strict_types=1);

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses invoice delivery links as the only invoice to delivery relationship', function (): void {
    expect(Schema::hasColumn('invoices', 'inventory_operation_id'))->toBeFalse()
        ->and(method_exists(Invoice::class, 'inventoryOperation'))->toBeFalse();
});
