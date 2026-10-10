<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\InventoryDiagnosticSeverity;
use App\Enums\InventoryPermission;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\PurchaseInbounds\Pages\ViewPurchaseInbound;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\Diagnostics\PurchaseInboundDiagnoser;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Purchasing\SupplierConfirmationService;
use Carbon\CarbonImmutable;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $this->manager = User::factory()->admin()->create();
    $this->manager->givePermissionTo(array_map(static fn (InventoryPermission $permission): string => $permission->value, InventoryPermission::cases()));
    $this->actingAs($this->manager);
});

/**
 * @return array{order: PurchaseOrder, inbound: PurchaseInbound, inbound_line: PurchaseInboundLine}
 */
function inboundDiagnosisOrder(?Supplier $supplier = null, ?callable $beforeInbound = null): array
{
    $variant = ProductVariant::factory()->create();
    /** @var Unit $unit */
    $unit = $variant->unit()->firstOrFail();
    $factory = PurchaseOrder::factory()->sent();
    $order = ($supplier instanceof Supplier ? $factory->for($supplier) : $factory)->create();

    /** @var PurchaseOrderLine $line */
    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity_ordered' => '100',
        'unit_cost' => '5.00',
        'line_total' => '500.00',
    ]);
    $line->forceFill([
        'transaction_quantity' => '100.000000',
        'transaction_unit_id' => $unit->getKey(),
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '100.000000',
        'received_base_quantity' => '0.000000',
    ])->save();

    if ($beforeInbound !== null) {
        $beforeInbound($order);
    }

    $inbound = app(PurchaseInboundService::class)->ensureForAccepted($order->refresh());

    return [
        'order' => $order,
        'inbound' => $inbound,
        'inbound_line' => $inbound->lines()->sole(),
    ];
}

function inboundDiagnosisAllocate(PurchaseInboundLine $line, string $quantity): PurchaseInboundAllocation
{
    $allocator = User::factory()->create();
    $allocator->givePermissionTo(InventoryPermission::InboundAllocate->value);

    return app(PurchaseInboundService::class)->allocate($allocator, $line, Warehouse::factory()->create(['is_active' => true]), $quantity);
}

function diagnoseInbound(PurchaseInbound $inbound): App\Data\Inventory\InventoryDiagnosis
{
    return app(PurchaseInboundDiagnoser::class)->diagnose($inbound->fresh() ?? $inbound);
}

it('asks to allocate confirmed quantity and names the header button only for an allocator', function (): void {
    $context = inboundDiagnosisOrder();

    $diagnosis = diagnoseInbound($context['inbound']);

    expect($diagnosis->codes())->toBe(['inbound.not_allocated'])
        ->and($diagnosis->find('inbound.not_allocated')?->severity)->toBe(InventoryDiagnosticSeverity::Notice)
        ->and($diagnosis->find('inbound.not_allocated')?->action?->allowed)->toBeTrue();

    $viewer = User::factory()->admin()->create();
    $viewer->givePermissionTo(InventoryPermission::ReceiptView->value);
    $this->actingAs($viewer);

    expect(diagnoseInbound($context['inbound'])->find('inbound.not_allocated')?->action?->allowed)->toBeFalse();
});

it('reports an allocation that has no receipt yet', function (): void {
    $context = inboundDiagnosisOrder();
    inboundDiagnosisAllocate($context['inbound_line'], '100');

    $diagnosis = diagnoseInbound($context['inbound']);

    expect($diagnosis->codes())->toBe(['inbound.receipt_not_created'])
        ->and($diagnosis->find('inbound.receipt_not_created')?->action?->allowed)->toBeTrue();
});

it('points to the open draft receipt once it exists', function (): void {
    $context = inboundDiagnosisOrder();
    $allocation = inboundDiagnosisAllocate($context['inbound_line'], '100');
    $receipt = app(PurchaseOrderReceivingService::class)->ensureDraftReceiptForAllocation($this->manager, $allocation);

    $diagnosis = diagnoseInbound($context['inbound']);
    $finding = $diagnosis->find('inbound.receipt_open');

    expect($diagnosis->codes())->toBe(['inbound.receipt_open'])
        ->and($finding?->severity)->toBe(InventoryDiagnosticSeverity::Notice)
        ->and($finding?->action?->url)->toContain((string) $receipt->getKey());
});

it('blocks when warehouse allocation exceeds the supplier commitment', function (): void {
    $context = inboundDiagnosisOrder();
    $allocation = inboundDiagnosisAllocate($context['inbound_line'], '100');
    $allocation->forceFill(['allocated_base_quantity' => '150.000000'])->save();

    $diagnosis = diagnoseInbound($context['inbound']);

    expect($diagnosis->find('inbound.over_allocated')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker)
        ->and($diagnosis->severity())->toBe(InventoryDiagnosticSeverity::Blocker);
});

it('warns when the expected date has passed with quantity outstanding', function (): void {
    $context = inboundDiagnosisOrder();
    $context['order']->forceFill(['expected_at' => now()->subDays(4)])->save();

    $diagnosis = diagnoseInbound($context['inbound']);

    expect($diagnosis->find('inbound.overdue')?->severity)->toBe(InventoryDiagnosticSeverity::Warning);
});

it('explains supplier confirmation that is pending or short of the ordered quantity', function (): void {
    $officer = User::factory()->create();
    $officer->assignRole(DashboardRole::PurchasingOfficer->value);
    $confirmations = app(SupplierConfirmationService::class);

    $pending = inboundDiagnosisOrder(
        Supplier::factory()->create(['requires_confirmation' => true]),
        static fn (PurchaseOrder $order) => $confirmations->recordPurchaseOrder($officer, $order),
    );

    expect(diagnoseInbound($pending['inbound'])->codes())->toContain('inbound.awaiting_confirmation');

    $short = inboundDiagnosisOrder(
        Supplier::factory()->create(['requires_confirmation' => true]),
        static function (PurchaseOrder $order) use ($confirmations, $officer): void {
            $confirmation = $confirmations->recordPurchaseOrder($officer, $order);
            $confirmations->respond($officer, $confirmation, SupplierConfirmationStatus::Partial,
                CarbonImmutable::parse($order->ordered_at)->addWeek(), 'Only part in stock', [[
                    'id' => $confirmation->items->sole()->id,
                    'confirmed_base_quantity' => '70',
                    'backordered_base_quantity' => '30',
                ]]);
        },
    );

    expect(diagnoseInbound($short['inbound'])->find('inbound.supplier_shortfall')?->severity)
        ->toBe(InventoryDiagnosticSeverity::Warning);
});

it('warns when completed receipts exceed the confirmed quantity', function (): void {
    $context = inboundDiagnosisOrder();
    $allocation = inboundDiagnosisAllocate($context['inbound_line'], '100');
    $receipt = app(PurchaseOrderReceivingService::class)->ensureDraftReceiptForAllocation($this->manager, $allocation);
    $receipt->forceFill(['stage' => 'done'])->save();
    $receipt->lines()->update(['base_quantity' => '120.000000']);

    $diagnosis = diagnoseInbound($context['inbound']);

    expect($diagnosis->find('inbound.over_received')?->severity)->toBe(InventoryDiagnosticSeverity::Warning)
        ->and($diagnosis->has('inbound.receipt_open'))->toBeFalse();
});

it('reports only the cancellation for a cancelled inbound', function (): void {
    $context = inboundDiagnosisOrder();
    $context['inbound']->forceFill(['status' => 'cancelled'])->save();

    expect(diagnoseInbound($context['inbound'])->codes())->toBe(['inbound.cancelled']);
});

it('finds nothing for a fully received inbound', function (): void {
    $context = inboundDiagnosisOrder();
    $allocation = inboundDiagnosisAllocate($context['inbound_line'], '100');
    $receipt = app(PurchaseOrderReceivingService::class)->ensureDraftReceiptForAllocation($this->manager, $allocation);
    $receipt->forceFill(['stage' => 'done'])->save();

    $diagnosis = diagnoseInbound($context['inbound']);

    expect($diagnosis->findings)->toBe([])
        ->and($diagnosis->checks)->not->toBeEmpty()
        ->and($diagnosis->limits)->not->toBeEmpty();
});

it('renders the operational health panel on the view page without changing the inbound', function (): void {
    $context = inboundDiagnosisOrder();
    $before = $context['inbound']->fresh()?->updated_at;
    $operations = InventoryOperation::query()->count();
    $allocations = PurchaseInboundAllocation::query()->count();

    Livewire::actingAs($this->manager)
        ->test(ViewPurchaseInbound::class, ['record' => $context['inbound']->getKey()])
        ->assertOk()
        ->assertSee(__('inventory_view_ux.title'))
        ->assertSee(__('inventory_view_ux.rules.inbound.not_allocated.title', ['count' => 1]));

    expect($context['inbound']->fresh()?->updated_at?->equalTo($before))->toBeTrue()
        ->and(InventoryOperation::query()->count())->toBe($operations)
        ->and(PurchaseInboundAllocation::query()->count())->toBe($allocations);
});
