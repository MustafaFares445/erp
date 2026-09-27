<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\OperationStage;
use App\Enums\PurchaseOrderStatus;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseOrderAcceptanceOrchestrator;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use App\Services\Purchasing\PurchaseOrderSupplierCommitmentService;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $this->manager = User::factory()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

it('snapshots supplier confirmation policy when an accepted PO is activated', function (): void {
    $supplier = Supplier::factory()->create(['requires_confirmation' => true]);
    $variant = ProductVariant::factory()->create();

    $order = PurchaseOrder::factory()
        ->for($supplier)
        ->create(['status' => PurchaseOrderStatus::Accepted]);

    $line = $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 5,
        'unit_cost' => '10.00',
    ]);

    $line->forceFill([
        'transaction_quantity' => '5.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '5.000000',
        'received_base_quantity' => '0.000000',
        'line_total' => '50.00',
    ])->save();

    app(PurchaseOrderAcceptanceOrchestrator::class)->handle($this->manager, $order);

    $order->refresh();
    expect($order->supplier_confirmation_required)->toBeTrue()
        ->and($order->confirmations()->count())->toBe(1);

    $supplier->update(['requires_confirmation' => false]);

    $quantities = app(PurchaseOrderSupplierCommitmentService::class)
        ->quantities($order->lines()->firstOrFail());

    expect($quantities['confirmed'])->toBe('0.000000')
        ->and($quantities['awaiting_confirmation'])->toBeTrue();
});

it('blocks cancelling a PO while an inventory receipt is still open', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();

    $order->receipts()->create([
        'operation_type' => 'receipt',
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
        'supplier_id' => $order->supplier_id,
    ])->forceFill(['stage' => OperationStage::Ready])->save();

    expect($this->manager->can('cancel', $order->refresh()))->toBeFalse()
        ->and(fn (): PurchaseOrder => app(PurchaseOrderApprovalService::class)
            ->cancel($this->manager, $order->refresh(), 'No longer required'))
        ->toThrow(AuthorizationException::class);
});

it('shows Send to supplier before waiting for supplier confirmation', function (): void {
    $supplier = Supplier::factory()->create(['requires_confirmation' => true]);
    $order = PurchaseOrder::factory()
        ->for($supplier)
        ->accepted()
        ->create([
            'supplier_confirmation_required' => true,
            'sent_at' => null,
        ]);

    $projection = app(PurchaseOrderWorkflowService::class)->project($order);

    expect($projection->supplierState)->toBe('Not sent · confirmation required')
        ->and($projection->businessState)->toBe('Ready to send')
        ->and($projection->nextOwner)->toBe('Purchasing')
        ->and($projection->nextAction)->toBe('Send Purchase Order to supplier');
});

it('shows supplier response waiting only after the accepted PO has been sent', function (): void {
    $supplier = Supplier::factory()->create(['requires_confirmation' => true]);
    $order = PurchaseOrder::factory()
        ->for($supplier)
        ->accepted()
        ->create([
            'supplier_confirmation_required' => true,
            'sent_at' => now(),
        ]);

    $order->confirmations()->create([
        'supplier_id' => $supplier->getKey(),
        'confirmation_status' => 'pending',
    ]);

    $projection = app(PurchaseOrderWorkflowService::class)->project($order->refresh());

    expect($projection->supplierState)->toBe('Awaiting supplier response')
        ->and($projection->businessState)->toBe('Awaiting supplier confirmation')
        ->and($projection->nextAction)->toBe('Record supplier response');
});
