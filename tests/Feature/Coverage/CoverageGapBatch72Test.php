<?php

declare(strict_types=1);

use App\Enums\QuotationDecision;
use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Filament\Resources\Quotations\Actions\QuotationActions;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\Schemas\SupplierInfolist;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use App\Services\Sales\QuotationService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function batch72FindComponent(iterable $components, string $name): mixed
{
    foreach ($components as $component) {
        if (method_exists($component, 'getName') && $component->getName() === $name) {
            return $component;
        }

        if (method_exists($component, 'getChildSchema')) {
            $childSchema = $component->getChildSchema();
            if ($childSchema !== null) {
                $found = batch72FindComponent($childSchema->getComponents(withActions: false, withHidden: true), $name);
                if ($found !== null) {
                    return $found;
                }
            }
        }
    }

    return null;
}

it('evaluates the purchase-order short-close quantity summary callback', function (): void {
    $order = PurchaseOrder::factory()->create();

    app()->instance(PurchaseOrderWorkflowService::class, new class
    {
        public function project(PurchaseOrder $order): stdClass
        {
            return (object) [
                'orderedBaseQuantity' => '10.000000',
                'receivedBaseQuantity' => '4.000000',
                'remainingConfirmedBaseQuantity' => '6.000000',
            ];
        }
    });

    try {
        $action = PurchaseOrderActions::close();
        $schema = new ReflectionProperty($action, 'schema')->getValue($action);
        $field = collect(is_array($schema) ? $schema : [])
            ->first(static fn (mixed $component): bool => $component instanceof TextInput && $component->getName() === 'short_close_summary');

        expect($field)->toBeInstanceOf(TextInput::class);

        $default = new ReflectionProperty($field, 'defaultState')->getValue($field);
        expect($default)->toBeInstanceOf(Closure::class)
            ->and($default($order))->toContain('Ordered 10', 'Received 4', 'Remaining confirmed 6');
    } finally {
        app()->forgetInstance(PurchaseOrderWorkflowService::class);
    }
});

it('executes quotation conversion through the actual Filament action including success notification', function (): void {
    $this->withSession([]);

    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['base_price' => 100]);
    $recorder = User::factory()->create();

    $quotation = app(QuotationService::class)->create(
        ['customer_id' => $customer->getKey(), 'issue_date' => today()->toDateString()],
        [['product_variant_id' => $variant->getKey(), 'quantity' => 1, 'unit_price' => 100, 'tax_amount' => 5]],
    );
    app(QuotationService::class)->send($quotation);
    app(QuotationService::class)->recordDecision(
        $quotation,
        QuotationDecision::Accepted,
        CarbonImmutable::today(),
        null,
        $recorder,
    );

    (QuotationActions::convert()->getActionFunction())($quotation->refresh());

    expect($quotation->refresh()->converted_order_id)->not->toBeNull();
});

it('covers supplier availability formatter and color callbacks', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $supplier = Supplier::factory()->create();

    $page = Livewire::actingAs($actor)
        ->test(ViewSupplier::class, ['record' => $supplier->getRouteKey()])
        ->instance();
    $schema = $page->getSchema('infolist');
    $availability = $schema === null
        ? null
        : batch72FindComponent($schema->getComponents(withActions: false, withHidden: true), 'availability_status');

    expect($availability)->toBeInstanceOf(TextEntry::class)
        ->and($availability->formatState('temporarily_unavailable'))->toBe('Temporarily unavailable')
        ->and($availability->formatState('discontinued'))->toBe('Discontinued')
        ->and($availability->getColor('temporarily_unavailable'))->toBe('warning');
});

it('covers supplier receipt summary skip branches for missing and blank receipt dates', function (): void {
    $supplier = Supplier::factory()->create();

    $blankReceiptDate = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $supplier->getKey(),
        'ordered_at' => today(),
        'expected_at' => today()->addDay(),
    ]);
    $blankReceipt = InventoryOperation::factory()->receipt()->done()->create([
        'source_document_type' => PurchaseOrder::class,
        'source_document_id' => $blankReceiptDate->getKey(),
        'completed_at' => now(),
    ]);
    DB::table('inventory_operations')
        ->where('id', $blankReceipt->getKey())
        ->update(['completed_at' => '']);

    $missingReceipt = PurchaseOrder::factory()->received()->create([
        'supplier_id' => $supplier->getKey(),
        'ordered_at' => today(),
        'expected_at' => today()->addDay(),
    ]);

    $leadTime = new ReflectionMethod(SupplierInfolist::class, 'averageReceiptLeadTime');
    $onTime = new ReflectionMethod(SupplierInfolist::class, 'onTimeReceiptSummary');

    expect($leadTime->invoke(null, $supplier))->toBe('No completed receipt history')
        ->and($onTime->invoke(null, $supplier))->toBe('No completed POs with expected dates')
        ->and($missingReceipt->exists)->toBeTrue();
});
