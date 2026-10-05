<?php

declare(strict_types=1);

use App\Enums\PurchaseAgreementStatus;
use App\Filament\Widgets\InventoryKeyMetrics;
use App\Filament\Widgets\PurchasingStatistics;
use App\Models\CustomerVisit;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\ProductVariant;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseAgreementLine;
use App\Models\ReplenishmentRequirement;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Calendar\MaintenanceCalendarEventService;
use App\Services\Calendar\VisitCalendarEventService;
use App\Services\Inventory\BarcodeResolver;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\InventoryLotService;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use App\Services\Purchasing\PurchaseAgreementService;
use App\Services\Purchasing\PurchaseOrderService;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function coverage114WithModelFault(string $modelClass, Closure $fault, Closure $operation, string $event = 'retrieved'): mixed
{
    $original = Model::getEventDispatcher();
    $dispatcher = clone $original;
    Model::setEventDispatcher($dispatcher);
    $dispatcher->listen('eloquent.'.$event.': '.$modelClass, $fault);

    try {
        return $operation();
    } finally {
        Model::setEventDispatcher($original);
    }
}

it('omits a maintenance calendar occurrence whose schedule disappeared after selection', function (): void {
    MaintenanceScheduleOccurrence::factory()->create(['due_on' => today()]);
    $events = coverage114WithModelFault(
        MaintenanceScheduleOccurrence::class,
        static fn (MaintenanceScheduleOccurrence $occurrence) => $occurrence->setAttribute('maintenance_schedule_id', null),
        static fn () => app(MaintenanceCalendarEventService::class)->between(now()->subDay(), now()->addDay()),
    );
    expect($events)->toBeEmpty();
});

it('omits a visit calendar row whose selected planned date is unavailable', function (): void {
    $visit = CustomerVisit::factory()->create(['planned_at' => now()]);
    $visit->planTask->update(['due_at' => today()->addMonth()]);
    $events = coverage114WithModelFault(
        CustomerVisit::class,
        static fn (CustomerVisit $row) => $row->setAttribute('planned_at', null),
        static fn () => app(VisitCalendarEventService::class)->between(now()->subDay(), now()->addDay()),
    );
    expect($events)->toBeEmpty()
        ->and($visit->refresh()->planned_at)->not->toBeNull();
});

it('rejects a scanned serial whose variant became unavailable during loading', function (): void {
    $serial = SerializedInventoryUnit::factory()->create();
    coverage114WithModelFault(
        SerializedInventoryUnit::class,
        static fn (SerializedInventoryUnit $unit) => $unit->setAttribute('product_variant_id', null),
        static function () use ($serial): void {
            expect(fn () => app(BarcodeResolver::class)->resolve($serial->serial_number))
                ->toThrow(DomainException::class, 'The scanned serial is not linked to a product variant.');
        },
    );
});

it('rejects a supplier whose identifier is unavailable after agreement selection', function (): void {
    Gate::before(static fn (): bool => true);
    $supplier = Supplier::factory()->create(['is_active' => true]);
    $actor = User::factory()->create();
    coverage114WithModelFault(
        Supplier::class,
        static fn (Supplier $row) => $row->setAttribute('id', null),
        static function () use ($supplier, $actor): void {
            expect(fn () => app(PurchaseAgreementService::class)->create(
                $actor,
                ['supplier_id' => $supplier->id, 'currency_code' => 'AED', 'starts_on' => today()->toDateString()],
                [['product_variant_id' => 1, 'unit_id' => 1, 'unit_price' => '1.00']],
            ))->toThrow(DomainException::class, 'Supplier has an invalid identifier.');
        },
    );
});

it('omits a delivery product whose product relation disappears during loading', function (): void {
    $variant = ProductVariant::factory()->create(['is_active' => true]);
    $variant->product->update(['is_active' => true]);
    coverage114WithModelFault(
        ProductVariant::class,
        static fn (ProductVariant $row) => $row->setAttribute('product_id', null),
        static function (): void {
            $page = new \App\Filament\Resources\InventoryOperations\Pages\CreateInventoryOperation;
            expect(new ReflectionMethod($page, 'productOptions')->invoke($page, null))->toBe([]);
        },
    );
});

it('refuses ticket triage when the selected ticket customer is unavailable', function (): void {
    Gate::before(static fn (): bool => true);
    $ticket = \App\Models\Ticket::factory()->create(['status' => \App\Enums\TicketStatus::Pending]);
    $actor = User::factory()->create();
    coverage114WithModelFault(
        \App\Models\Ticket::class,
        static fn (\App\Models\Ticket $row) => $row->setAttribute('customer_id', null),
        static function () use ($ticket, $actor): void {
            expect(fn () => app(\App\Services\Support\TicketTriageService::class)->triage($ticket, [
                'equipment_source' => \App\Enums\TicketEquipmentSource::External,
                'service_path' => \App\Enums\TicketServicePath::RemoteSupport,
                'billing_decision' => 'no_charge',
            ], $actor))->toThrow(DomainException::class, 'Ticket triage requires a customer profile.');
        },
    );
});

it('skips unidentified replenishment requirements in batch suggestions and dashboard summaries', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $policy = WarehouseReplenishmentPolicy::withoutEvents(static fn () => WarehouseReplenishmentPolicy::query()->forceCreate([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'min_quantity' => '1.000000',
        'max_quantity' => '10.000000',
        'is_active' => true,
    ]));
    $requirement = ReplenishmentRequirement::query()->create([
        'warehouse_replenishment_policy_id' => $policy->id,
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'required_base_quantity' => '10.000000',
        'triggered_at' => now(),
    ]);
    coverage114WithModelFault(
        ReplenishmentRequirement::class,
        static fn (ReplenishmentRequirement $row) => $row->setAttribute('id', null),
        static function () use ($requirement): void {
            $requirement->id = null;
            expect(app(ReplenishmentTransferSuggestionService::class)->suggestMany(collect([$requirement])))->toBe([]);
            $purchasing = app(PurchasingStatistics::class);
            expect(new ReflectionMethod($purchasing, 'inventoryPurchaseNeeds')->invoke($purchasing))->toBe([0, 0.0]);
            $inventory = app(InventoryKeyMetrics::class);
            expect(new ReflectionMethod($inventory, 'replenishmentStat')->invoke($inventory))->toBeInstanceOf(Stat::class);
        },
    );
});

it('recovers a stock balance inserted between the first read and creation', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $originalDispatcher = Model::getEventDispatcher();
    $dispatcher = clone $originalDispatcher;
    Model::setEventDispatcher($dispatcher);
    $dispatcher->listen('eloquent.creating: '.InventoryStock::class, function (): void {
        DB::table('inventory_stocks')->insert([
            'product_variant_id' => test()->raceVariantId,
            'warehouse_id' => test()->raceWarehouseId,
            'on_hand_quantity' => 0,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'available_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
    $this->raceVariantId = $variant->id;
    $this->raceWarehouseId = $warehouse->id;

    try {
        $stock = app(InventoryBalanceService::class)->stockForUpdate($variant->id, $warehouse->id, true);
        expect($stock->product_variant_id)->toBe($variant->id)
            ->and($stock->warehouse_id)->toBe($warehouse->id)
            ->and(InventoryStock::query()->count())->toBe(1);
    } finally {
        Model::setEventDispatcher($originalDispatcher);
    }
});

it('rethrows a balance insert failure when no concurrent balance exists for the requested grain', function (string $grain): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $stock = \App\Models\InventoryStock::factory()->for($variant)->for($warehouse)->create();
    $lot = InventoryLot::factory()->canonical()->create(['product_variant_id' => $variant->id]);
    $condition = \App\Enums\StockCondition::Saleable;
    if ($grain === 'condition') {
        $model = \App\Models\InventoryConditionBalance::class;
        $existing = $model::query()->forceCreate([
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'stock_condition' => \App\Enums\StockCondition::Quarantine,
            'on_hand_base_quantity' => '0.000000',
            'reserved_base_quantity' => '0.000000',
        ]);
        $method = 'conditionBalanceForUpdate';
        $arguments = [$stock, $condition, false];
    } else {
        $model = \App\Models\InventoryLotBalance::class;
        $existing = $model::query()->forceCreate([
            'inventory_lot_id' => $lot->id,
            'warehouse_id' => $warehouse->id,
            'stock_condition' => \App\Enums\StockCondition::Quarantine,
            'on_hand_base_quantity' => '0.000000',
            'reserved_base_quantity' => '0.000000',
        ]);
        $method = 'lotConditionBalanceForUpdate';
        $arguments = [$lot, $warehouse->id, $condition];
    }
    coverage114WithModelFault(
        $model,
        static fn (\Illuminate\Database\Eloquent\Model $row) => $row->forceFill(['id' => $existing->id]),
        static function () use ($method, $arguments): void {
            expect(fn () => new ReflectionMethod(\App\Services\Inventory\InventoryPostingService::class, $method)->invoke(app(\App\Services\Inventory\InventoryPostingService::class), ...$arguments))
                ->toThrow(\Illuminate\Database\QueryException::class);
        },
        'creating',
    );
    expect($model::query()->count())->toBe(1);
})->with(['condition', 'lot']);

it('rethrows a lot insert failure when the conflicting row is not the requested canonical lot', function (): void {
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $warehouse = Warehouse::factory()->create();
    $existing = InventoryLot::factory()->canonical()->create(['product_variant_id' => $variant->id]);
    $line = InventoryOperationLine::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'DIFFERENT-UNIQUE-GRAIN',
        'expires_at' => today()->addMonth(),
    ]);
    coverage114WithModelFault(
        InventoryLot::class,
        static fn (InventoryLot $lot) => $lot->forceFill(['id' => $existing->id]),
        static function () use ($variant, $warehouse, $line): void {
            expect(fn () => app(InventoryLotService::class)->receive($line, $variant, $warehouse->id, '1.000000'))
                ->toThrow(\Illuminate\Database\QueryException::class);
        },
        'creating',
    );
});

it('recovers a condition or lot balance inserted concurrently for the same grain', function (string $grain): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $stock = InventoryStock::factory()->for($variant)->for($warehouse)->create();
    $lot = InventoryLot::factory()->canonical()->create(['product_variant_id' => $variant->id]);
    $condition = \App\Enums\StockCondition::Saleable;
    $model = $grain === 'condition' ? \App\Models\InventoryConditionBalance::class : \App\Models\InventoryLotBalance::class;
    $method = $grain === 'condition' ? 'conditionBalanceForUpdate' : 'lotConditionBalanceForUpdate';
    $arguments = $grain === 'condition' ? [$stock, $condition, false] : [$lot, $warehouse->id, $condition];
    $balance = coverage114WithModelFault(
        $model,
        static fn (Model $row): bool => DB::table($row->getTable())->insert($row->getAttributes()),
        static fn () => new ReflectionMethod(\App\Services\Inventory\InventoryPostingService::class, $method)->invoke(app(\App\Services\Inventory\InventoryPostingService::class), ...$arguments),
        'creating',
    );
    expect($balance)->toBeInstanceOf($model)
        ->and($balance->stock_condition)->toBe($condition)
        ->and($model::query()->count())->toBe(1);
})->with(['condition', 'lot']);

it('recovers from a canonical inventory-lot unique-key race and returns the concurrent lot', function (): void {
    $variant = ProductVariant::factory()->expiryMaterial()->create();
    $warehouse = Warehouse::factory()->create();
    $operation = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->id,
    ]);
    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->id,
        'product_variant_id' => $variant->id,
        'lot_number' => 'RACE LOT 114',
        'expires_at' => today()->addMonth(),
    ]);

    $event = 'eloquent.creating: '.InventoryLot::class;
    $inserted = false;

    Event::listen($event, function (InventoryLot $lot) use (&$inserted, $variant, $line): void {
        if ($inserted || $lot->normalized_lot_number !== 'RACE LOT 114') {
            return;
        }

        $inserted = true;

        DB::table('inventory_lots')->insert([
            'product_variant_id' => $variant->id,
            'warehouse_id' => null,
            'lot_number' => 'Race Lot 114',
            'normalized_lot_number' => 'RACE LOT 114',
            'canonical_inventory_lot_id' => null,
            'origin_source_type' => 'inventory_operation',
            'origin_source_id' => $line->inventory_operation_id,
            'origin_source_line_id' => $line->id,
            'expires_at' => $line->expires_at?->toDateString(),
            'on_hand_quantity' => 0,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $lot = app(InventoryLotService::class)->receive(
            $line,
            $variant,
            $warehouse->id,
            '1.000000',
        );
    } finally {
        Event::forget($event);
    }

    expect($lot)->toBeInstanceOf(InventoryLot::class)
        ->and($lot?->normalized_lot_number)->toBe('RACE LOT 114')
        ->and(InventoryLot::query()->where('normalized_lot_number', 'RACE LOT 114')->count())->toBe(1);
});

it('covers a purchase agreement line whose agreement disappears between selection and eager loading', function (): void {
    $actor = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = Unit::query()->findOrFail($variant->unit_id);

    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->id,
        'product_variant_id' => $variant->id,
        'currency_code' => 'AED',
    ]);

    $agreement = new PurchaseAgreement([
        'supplier_id' => $supplier->id,
        'currency_code' => 'AED',
        'starts_on' => today()->subDay(),
        'ends_on' => today()->addDay(),
    ]);
    $agreement->forceFill([
        'agreement_number' => 'AGR-COVERAGE-114-A',
        'status' => PurchaseAgreementStatus::Active,
        'created_by' => $actor->id,
    ])->save();

    $line = PurchaseAgreementLine::query()->create([
        'purchase_agreement_id' => $agreement->id,
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'unit_price' => '12.50',
    ]);

    $event = 'eloquent.retrieved: '.PurchaseAgreementLine::class;
    Event::listen($event, function (PurchaseAgreementLine $retrieved) use ($line): void {
        if ($retrieved->id === $line->id) {
            $retrieved->setAttribute('purchase_agreement_id', 999999999);
        }
    });

    $method = new ReflectionMethod(PurchaseOrderService::class, 'resolveUnitCost');

    try {
        expect(fn () => $method->invoke(
            app(PurchaseOrderService::class),
            null,
            SupplierProductReference::query()
                ->where('supplier_id', $supplier->id)
                ->where('product_variant_id', $variant->id)
                ->firstOrFail(),
            '1.000000',
            'AED',
            $supplier->id,
            $variant->id,
            $unit->id,
        ))->toThrow(DomainException::class, 'not linked to an agreement');
    } finally {
        Event::forget($event);
    }
});

it('covers an agreement currency changed in memory after database selection', function (): void {
    $actor = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = Unit::query()->findOrFail($variant->unit_id);

    $reference = SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->id,
        'product_variant_id' => $variant->id,
        'currency_code' => 'AED',
    ]);

    $agreement = new PurchaseAgreement([
        'supplier_id' => $supplier->id,
        'currency_code' => 'AED',
        'starts_on' => today()->subDay(),
        'ends_on' => today()->addDay(),
    ]);
    $agreement->forceFill([
        'agreement_number' => 'AGR-COVERAGE-114-B',
        'status' => PurchaseAgreementStatus::Active,
        'created_by' => $actor->id,
    ])->save();

    PurchaseAgreementLine::query()->create([
        'purchase_agreement_id' => $agreement->id,
        'product_variant_id' => $variant->id,
        'unit_id' => $unit->id,
        'unit_price' => '12.50',
    ]);

    $event = 'eloquent.retrieved: '.PurchaseAgreement::class;
    Event::listen($event, function (PurchaseAgreement $retrieved) use ($agreement): void {
        if ($retrieved->id === $agreement->id) {
            $retrieved->setAttribute('currency_code', 'USD');
        }
    });

    $method = new ReflectionMethod(PurchaseOrderService::class, 'resolveUnitCost');

    try {
        expect(fn () => $method->invoke(
            app(PurchaseOrderService::class),
            null,
            $reference,
            '1.000000',
            'AED',
            $supplier->id,
            $variant->id,
            $unit->id,
        ))->toThrow(DomainException::class);
    } finally {
        Event::forget($event);
    }
});
