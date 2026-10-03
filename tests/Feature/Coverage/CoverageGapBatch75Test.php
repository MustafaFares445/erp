<?php

declare(strict_types=1);

use App\Enums\InventoryConditionChangeType;
use App\Enums\QuotationStatus;
use App\Enums\StockCondition;
use App\Filament\Resources\Adjustments\Schemas\AdjustmentForm;
use App\Filament\Resources\InventoryAlerts\Tables\InventoryAlertsTable;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\InventoryOperations\Schemas\InventoryOperationInfolist;
use App\Filament\Resources\InventoryReports\Tables\InventoryReportsTable;
use App\Filament\Resources\MaintenanceSchedules\Tables\MaintenanceSchedulesTable;
use App\Filament\Resources\Quotations\Schemas\QuotationInfolist;
use App\Filament\Resources\StockLevels\Schemas\StockLevelInfolist;
use App\Filament\Resources\SupplierProductReferences\SupplierProductReferenceResource;
use App\Filament\Widgets\PurchasingUpcomingReceipts;
use App\Models\InventoryAlert;
use App\Models\InventoryLotBalance;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\MaintenanceSchedule;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;

uses(RefreshDatabase::class);

function batch75Property(object $object, string $name): mixed
{
    $reflection = new ReflectionClass($object);

    do {
        if ($reflection->hasProperty($name)) {
            return $reflection->getProperty($name)->getValue($object);
        }
    } while ($reflection = $reflection->getParentClass());

    throw new LogicException("Property {$name} not found.");
}

function batch75TableOwner(): Component&HasTable
{
    return new class extends Component implements HasTable
    {
        use InteractsWithTable;

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

        public function render(): string
        {
            return '<div></div>';
        }
    };
}

function batch75FindComponent(iterable $components, string $name): mixed
{
    foreach ($components as $component) {
        if (method_exists($component, 'getName') && $component->getName() === $name) {
            return $component;
        }

        try {
            $property = new ReflectionProperty($component, 'childComponents');
            foreach ((array) $property->getValue($component) as $children) {
                if (! is_iterable($children)) {
                    continue;
                }

                $found = batch75FindComponent($children, $name);
                if ($found !== null) {
                    return $found;
                }
            }
        } catch (ReflectionException) {
            // Leaf component.
        }
    }

    return null;
}

it('covers supplier availability status label and color arms', function (): void {
    $table = SupplierProductReferenceResource::table(Table::make(batch75TableOwner()));
    $column = $table->getColumn('availability_status');

    expect($column)->not->toBeNull()
        ->and($column->formatState('temporarily_unavailable'))->toBe('Temporarily unavailable')
        ->and($column->formatState('discontinued'))->toBe('Discontinued')
        ->and($column->getColor('temporarily_unavailable'))->toBe('warning');
});

it('covers inventory alert operation URL and reference resolution', function (): void {
    $operation = InventoryOperation::factory()->create();
    $operation->forceFill(['operation_number' => 'OP-B75-ALERT'])->save();

    $alert = InventoryAlert::factory()->make([
        'subject_type' => InventoryOperation::class,
        'subject_id' => $operation->getKey(),
    ]);

    InventoryAlertsTable::subjectUrl($alert);

    expect(InventoryAlertsTable::subjectReference($alert))->toBe($operation->operation_number);
});

it('covers inventory operation purchase-order source label URL and unknown source', function (): void {
    $purchaseOrder = PurchaseOrder::factory()->create();
    $operation = InventoryOperation::factory()->create([
        'source_document_type' => PurchaseOrder::class,
        'source_document_id' => $purchaseOrder->getKey(),
    ]);

    $label = new ReflectionMethod(InventoryOperationInfolist::class, 'sourceDocumentLabel');
    $url = new ReflectionMethod(InventoryOperationInfolist::class, 'sourceDocumentUrl');

    expect($label->invoke(null, $operation))->toBe($purchaseOrder->purchase_order_number);

    $url->invoke(null, $operation);

    $operation->forceFill([
        'source_document_type' => ProductVariant::class,
        'source_document_id' => 999999,
    ]);

    expect($url->invoke(null, $operation))->toBeNull();
});

it('covers quotation changes-requested and expired callout text', function (): void {
    $changesRequested = Quotation::factory()->create([
        'status' => QuotationStatus::ChangesRequested,
    ]);

    $heading = new ReflectionMethod(QuotationInfolist::class, 'calloutHeading');
    $description = new ReflectionMethod(QuotationInfolist::class, 'calloutDescription');
    $statusHelp = new ReflectionMethod(QuotationInfolist::class, 'statusHelp');

    expect($heading->invoke(null, $changesRequested))
        ->toBe((string) __('admin.sales.quotation_view.changes_requested_callout_heading'))
        ->and($description->invoke(null, $changesRequested))
        ->toBe((string) __('admin.sales.quotation_view.changes_requested_callout_description'));

    $expired = Quotation::factory()->create([
        'status' => QuotationStatus::Sent,
        'expires_at' => today()->subDay(),
    ]);

    expect($statusHelp->invoke(null, $expired))
        ->toBe((string) __('admin.sales.quotation_view.status_help.expired'));
});

it('covers maintenance schedule overdue and due-soon color callbacks', function (): void {
    $table = MaintenanceSchedulesTable::configure(Table::make(batch75TableOwner()));
    $column = $table->getColumn('due_state');
    $color = batch75Property($column, 'color');

    expect($color)->toBeInstanceOf(Closure::class);

    $overdue = MaintenanceSchedule::factory()->make([
        'is_active' => true,
        'next_due_on' => today()->subDay(),
    ]);
    $dueSoon = MaintenanceSchedule::factory()->make([
        'is_active' => true,
        'next_due_on' => today()->addDay(),
        'lead_time_days' => 7,
    ]);

    expect($color($overdue))->toBe('danger')
        ->and($color($dueSoon))->toBe('warning');
});

it('covers low-stock health and shortage-to-target states', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $stock = InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse, 'warehouse')->create([
        'on_hand_quantity' => '2.000000',
        'reserved_quantity' => '0.000000',
        'available_quantity' => '2.000000',
    ]);
    WarehouseReplenishmentPolicy::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'min_quantity' => '5.000000',
        'max_quantity' => '10.000000',
        'is_active' => true,
    ]);

    $schema = StockLevelInfolist::configure(Schema::make());
    $health = batch75FindComponent($schema->getComponents(withActions: false, withHidden: true), 'health');
    $shortage = batch75FindComponent($schema->getComponents(withActions: false, withHidden: true), 'shortage_to_target');

    $healthState = batch75Property($health, 'getConstantStateUsing');
    $shortageState = batch75Property($shortage, 'getConstantStateUsing');

    expect($healthState($stock))->toBe(__('admin.inventory.stock.low_stock'))
        ->and($shortageState($stock))->toBe(8.0);
});

it('covers purchasing upcoming-receipts inbound URLs', function (): void {
    $order = PurchaseOrder::factory()->create();
    $inbound = PurchaseInbound::factory()->for($order)->create();
    $order->load('purchaseInbound');

    $widget = app(PurchasingUpcomingReceipts::class);
    $table = $widget->table(Table::make($widget));

    $recordUrl = batch75Property($table, 'recordUrl');
    $withoutInbound = PurchaseOrder::factory()->create();
    $withoutInbound->setRelation('purchaseInbound', null);

    expect($recordUrl($order))->toContain((string) $inbound->getKey())
        ->and($recordUrl($withoutInbound))->toContain((string) $withoutInbound->getKey());
});

it('covers adjustment enum and digit-string normalization helpers', function (): void {
    $selectedCondition = new ReflectionMethod(AdjustmentForm::class, 'selectedCondition');
    $nullableInteger = new ReflectionMethod(AdjustmentForm::class, 'nullableInteger');

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->with('stock_condition')->andReturn(StockCondition::Damaged);

    expect($selectedCondition->invoke(null, $get))->toBe(StockCondition::Damaged)
        ->and($nullableInteger->invoke(null, '42'))->toBe(42);
});

it('covers quarantine ageing middle buckets and inbound-document formatting', function (): void {
    $columns = new ReflectionMethod(InventoryReportsTable::class, 'quarantineAgeingColumns')->invoke(null);

    $ageing = collect($columns)->first(fn (mixed $column): bool => method_exists($column, 'getName') && $column->getName() === 'ageing_bucket');
    $inbound = collect($columns)->first(fn (mixed $column): bool => method_exists($column, 'getName') && $column->getName() === 'inbound_document');

    $ageingState = batch75Property($ageing, 'getStateUsing');
    $inboundState = batch75Property($inbound, 'getStateUsing');

    $twentyDays = new InventoryLotBalance;
    $twentyDays->forceFill(['oldest_quarantine_at' => now()->subDays(20)]);

    $sixtyDays = new InventoryLotBalance;
    $sixtyDays->forceFill(['oldest_quarantine_at' => now()->subDays(60)]);

    $provenance = new InventoryLotBalance;
    $provenance->forceFill([
        'inbound_source_type' => 'purchase_order',
        'inbound_source_id' => 77,
    ]);

    expect($ageingState($twentyDays))->toBe('8-30')
        ->and($ageingState($sixtyDays))->toBe('31-90')
        ->and($inboundState($provenance))->toBe('purchase_order #77');
});

it('covers valid inventory-condition-change query defaults', function (): void {
    request()->query->set('type', InventoryConditionChangeType::Damage->value);

    $schema = InventoryConditionChangeResource::form(Schema::make());
    $type = batch75FindComponent($schema->getComponents(withActions: false, withHidden: true), 'type');
    $default = batch75Property($type, 'defaultState');

    expect($default)->toBeInstanceOf(Closure::class)
        ->and($default())->toBe(InventoryConditionChangeType::Damage->value);
});
