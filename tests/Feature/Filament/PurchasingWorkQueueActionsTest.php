<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\BillStatus;
use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Enums\PurchaseInboundStatus;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseRfqStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Filament\Resources\PurchaseInbounds\Pages\ListPurchaseInbounds;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRfqs\Pages\ListPurchaseRfqs;
use App\Filament\Resources\SupplierConfirmations\Pages\ManageSupplierConfirmations;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Models\Bill;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseRfq;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseInboundService;
use App\Services\Purchasing\PurchaseRfqService;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new CurrencySeeder)->run();
    (new PurchasePermissionSeeder)->run();
    (new AccountingPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();
});

function purchasingQueueUser(DashboardRole $role): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole($role->value);
    test()->actingAs($user);

    return $user;
}

function purchasingQueueViewOnlyUser(string ...$permissions): User
{
    // A fixed dashboard role switches policies from the admin bypass to explicit
    // permission checks; Reviewer carries none of the workflow permissions.
    Role::findOrCreate(DashboardRole::Reviewer->value, 'web');

    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::Reviewer->value);
    $user->givePermissionTo($permissions);
    test()->actingAs($user);

    return $user;
}

// ---------------------------------------------------------------------------
// Purchase inbounds
// ---------------------------------------------------------------------------

/** @return array{0:PurchaseInbound,1:PurchaseInboundLine,2:Warehouse} */
function purchasingQueueInbound(User $actor): array
{
    $order = PurchaseOrder::factory()->accepted($actor)->create([
        'sent_at' => now(),
        'supplier_confirmation_required' => false,
    ]);
    $variant = ProductVariant::factory()->create();
    $line = PurchaseOrderLine::factory()->create([
        'purchase_order_id' => $order->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => '2.000000',
    ]);
    $line->forceFill([
        'transaction_quantity' => '2.000000',
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '2.000000',
        'received_base_quantity' => '0.000000',
    ])->save();

    $inbound = PurchaseInbound::factory()->create(['purchase_order_id' => $order->getKey()]);
    $inboundLine = PurchaseInboundLine::factory()->create([
        'purchase_inbound_id' => $inbound->getKey(),
        'purchase_order_line_id' => $line->getKey(),
    ]);

    return [$inbound, $inboundLine, Warehouse::factory()->create(['is_active' => true])];
}

it('offers Allocate stock on an inbound awaiting allocation and allocates through the inbound service once', function (): void {
    $actor = purchasingQueueUser(DashboardRole::PurchasingManager);
    $actor->givePermissionTo([InventoryPermission::InboundAllocate->value, InventoryPermission::ReceiptCreate->value]);
    [$inbound, $line, $warehouse] = purchasingQueueInbound($actor);

    Livewire::test(ListPurchaseInbounds::class)
        ->call('selectTableView', 'preset', 'all')
        ->assertTableActionVisible('allocateInbound', $inbound)
        ->assertTableActionHidden('createOrOpenReceipt', $inbound)
        ->assertSee(__('Allocate stock'))
        ->callTableAction('allocateInbound', $inbound, [
            'purchase_inbound_line_id' => $line->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'allocated_base_quantity' => '2.000000',
        ])
        ->assertHasNoTableActionErrors()
        ->assertTableActionHidden('allocateInbound', $inbound);

    $allocation = PurchaseInboundAllocation::query()->where('purchase_inbound_line_id', $line->getKey())->sole();

    expect((string) $allocation->allocated_base_quantity)->toBe('2.000000');
});

it('offers Create / open receipt once stock is allocated and does not duplicate the draft receipt', function (): void {
    $actor = purchasingQueueUser(DashboardRole::PurchasingManager);
    $actor->givePermissionTo([InventoryPermission::InboundAllocate->value, InventoryPermission::ReceiptCreate->value]);
    [$inbound, $line, $warehouse] = purchasingQueueInbound($actor);
    $allocation = app(PurchaseInboundService::class)->allocate($actor, $line, $warehouse, '2.000000');

    $component = Livewire::test(ListPurchaseInbounds::class)
        ->call('selectTableView', 'preset', 'all')
        ->assertTableActionVisible('createOrOpenReceipt', $inbound)
        ->assertTableActionHidden('allocateInbound', $inbound)
        ->callTableAction('createOrOpenReceipt', $inbound, ['allocation_id' => $allocation->getKey()])
        ->assertHasNoTableActionErrors()
        ->assertRedirect();

    expect(InventoryOperation::query()->count())->toBe(1);

    $component->callTableAction('createOrOpenReceipt', $inbound, ['allocation_id' => $allocation->getKey()]);

    expect(InventoryOperation::query()->count())->toBe(1);
});

it('hides inbound workflow actions for cancelled inbounds', function (): void {
    $actor = purchasingQueueUser(DashboardRole::PurchasingManager);
    $actor->givePermissionTo([InventoryPermission::InboundAllocate->value, InventoryPermission::ReceiptCreate->value]);
    [$inbound] = purchasingQueueInbound($actor);
    $cancelled = PurchaseInbound::factory()->create(['status' => PurchaseInboundStatus::Cancelled]);

    Livewire::test(ListPurchaseInbounds::class)
        ->call('selectTableView', 'preset', 'all')
        ->assertTableActionVisible('allocateInbound', $inbound)
        ->assertTableActionHidden('allocateInbound', $cancelled)
        ->assertTableActionHidden('createOrOpenReceipt', $cancelled)
        ->assertTableActionExists('view', record: $cancelled);
});

it('hides inbound workflow actions from users without inventory permissions', function (): void {
    [$inbound] = purchasingQueueInbound(User::factory()->create());
    purchasingQueueViewOnlyUser(InventoryPermission::ReceiptView->value);

    Livewire::test(ListPurchaseInbounds::class)
        ->call('selectTableView', 'preset', 'all')
        ->assertTableActionHidden('allocateInbound', $inbound)
        ->assertTableActionHidden('createOrOpenReceipt', $inbound);
});

// ---------------------------------------------------------------------------
// RFQs
// ---------------------------------------------------------------------------

/** @return array{0:PurchaseRfq,1:Supplier} */
function purchasingQueueRfq(User $actor): array
{
    $supplier = Supplier::factory()->create(['is_active' => true]);
    $variant = ProductVariant::factory()->create(['is_active' => true]);
    $unit = Unit::factory()->create();
    ProductVariantUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'is_purchase' => true,
        'is_active' => true,
        'factor_to_base' => '1.000000',
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '10.00',
        'currency_code' => 'AED',
        'availability_status' => 'active',
        'is_active' => true,
    ]);

    $rfq = app(PurchaseRfqService::class)->create($actor, ['currency_code' => 'AED'], [[
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '5',
    ]], [$supplier->getKey()]);

    return [$rfq, $supplier];
}

it('walks an RFQ through its primary row actions from the table', function (): void {
    $actor = purchasingQueueUser(DashboardRole::PurchasingManager);
    [$rfq] = purchasingQueueRfq($actor);

    $component = Livewire::test(ListPurchaseRfqs::class)
        ->assertTableActionVisible('send', $rfq)
        ->assertTableActionHidden('recordResponse', $rfq)
        ->assertTableActionHidden('award', $rfq)
        ->assertTableActionHidden('openPurchaseOrder', $rfq)
        ->callTableAction('send', $rfq)
        ->assertTableActionHidden('send', $rfq);

    expect($rfq->refresh()->status)->toBe(PurchaseRfqStatus::AwaitingResponses);

    $candidate = $rfq->suppliers()->firstOrFail();
    $rfqLine = $rfq->lines()->firstOrFail();

    $component
        ->assertTableActionVisible('recordResponse', $rfq)
        ->callTableAction('recordResponse', $rfq, [
            'rfq_supplier_id' => $candidate->getKey(),
            'responses' => [[
                'rfq_line_id' => $rfqLine->getKey(),
                'unit_price' => '12.50',
                'offered_quantity' => '5',
            ]],
        ])
        ->assertHasNoTableActionErrors()
        ->assertTableActionHidden('recordResponse', $rfq)
        ->assertTableActionVisible('award', $rfq)
        ->assertTableActionVisible('recordAdditionalResponse', $rfq);

    expect($rfq->refresh()->status)->toBe(PurchaseRfqStatus::Evaluating);

    $component
        ->callTableAction('award', $rfq, ['rfq_supplier_id' => $candidate->getKey()])
        ->assertHasNoTableActionErrors();

    $rfq->refresh();
    expect($rfq->status)->toBe(PurchaseRfqStatus::Awarded)
        ->and(PurchaseOrder::query()->count())->toBe(1);

    $component
        ->assertTableActionHidden('award', $rfq)
        ->assertTableActionVisible('openPurchaseOrder', $rfq)
        ->assertTableActionHasUrl('openPurchaseOrder', PurchaseOrderResource::getUrl('view', ['record' => $rfq->awardedPurchaseOrder]), $rfq)
        ->assertTableActionVisible('close', $rfq)
        ->assertTableActionHidden('cancel', $rfq)
        ->callTableAction('close', $rfq);

    expect($rfq->refresh()->status)->toBe(PurchaseRfqStatus::Closed);

    foreach (['send', 'recordResponse', 'award', 'openPurchaseOrder', 'close', 'cancel', 'expire'] as $name) {
        $component->assertTableActionHidden($name, $rfq);
    }
});

it('exposes cancel for an open RFQ', function (): void {
    $actor = purchasingQueueUser(DashboardRole::PurchasingManager);
    [$rfq] = purchasingQueueRfq($actor);

    Livewire::test(ListPurchaseRfqs::class)
        ->assertTableActionVisible('cancel', $rfq)
        ->callTableAction('cancel', $rfq);

    expect($rfq->refresh()->status)->toBe(PurchaseRfqStatus::Cancelled);
});

it('hides every RFQ workflow action from view-only users', function (): void {
    $draft = PurchaseRfq::query()->forceCreate([
        'rfq_number' => 'RFQ-WQ-1',
        'status' => PurchaseRfqStatus::Draft,
        'currency_code' => 'AED',
        'requested_by' => User::factory()->create()->getKey(),
    ]);
    purchasingQueueViewOnlyUser(PurchasePermission::RfqView->value);

    $component = Livewire::test(ListPurchaseRfqs::class);

    foreach (['send', 'recordResponse', 'award', 'close', 'cancel', 'expire'] as $name) {
        $component->assertTableActionHidden($name, $draft);
    }
});

// ---------------------------------------------------------------------------
// Supplier confirmations
// ---------------------------------------------------------------------------

it('routes each supplier confirmation state to its single primary action', function (): void {
    purchasingQueueUser(DashboardRole::PurchasingManager)->givePermissionTo(InventoryPermission::ReceiptView->value);

    $unsent = SupplierConfirmation::factory()->create([
        'purchase_order_id' => PurchaseOrder::factory()->accepted()->create(),
    ]);
    $awaiting = SupplierConfirmation::factory()->create([
        'purchase_order_id' => PurchaseOrder::factory()->accepted()->sent()->create(),
    ]);
    $confirmedOrder = PurchaseOrder::factory()->accepted()->sent()->create();
    $inbound = PurchaseInbound::factory()->create(['purchase_order_id' => $confirmedOrder->getKey()]);
    $confirmed = SupplierConfirmation::factory()->confirmed()->create(['purchase_order_id' => $confirmedOrder->getKey()]);
    $overdue = SupplierConfirmation::factory()->confirmed()->create([
        'purchase_order_id' => PurchaseOrder::factory()->accepted()->sent()->create(),
        'promised_at' => today()->subDays(3)->toDateString(),
    ]);
    $rejected = SupplierConfirmation::factory()->rejected()->create([
        'purchase_order_id' => PurchaseOrder::factory()->accepted()->sent()->create(),
    ]);

    $component = Livewire::test(ManageSupplierConfirmations::class)
        ->assertTableActionVisible('reviewAndSendPurchaseOrder', $unsent)
        ->assertTableActionHasUrl('reviewAndSendPurchaseOrder', PurchaseOrderResource::getUrl('view', ['record' => $unsent->purchase_order_id]), $unsent)
        ->assertTableActionHidden('supplierResponse', $unsent)
        ->assertTableActionVisible('supplierResponse', $awaiting)
        ->assertTableActionHidden('reviewAndSendPurchaseOrder', $awaiting)
        ->assertTableActionVisible('openInbound', $confirmed)
        ->assertTableActionHasUrl('openInbound', PurchaseInboundResource::getUrl('view', ['record' => $inbound]), $confirmed)
        ->assertTableActionHidden('reviewSupplierCommitment', $confirmed)
        ->assertTableActionVisible('reviewSupplierCommitment', $overdue)
        ->assertTableActionHasUrl('reviewSupplierCommitment', SupplierConfirmationResource::getUrl('view', ['record' => $overdue]), $overdue)
        ->assertTableActionHidden('openInbound', $overdue);

    foreach (['reviewAndSendPurchaseOrder', 'supplierResponse', 'openInbound', 'reviewSupplierCommitment'] as $name) {
        $component->assertTableActionHidden($name, $rejected);
    }

    expect($rejected->confirmation_status)->toBe(SupplierConfirmationStatus::Rejected);
});

it('hides supplier confirmation workflow actions from users who cannot act on them', function (): void {
    $unsent = SupplierConfirmation::factory()->create([
        'purchase_order_id' => PurchaseOrder::factory()->accepted()->create(),
    ]);
    $awaiting = SupplierConfirmation::factory()->create([
        'purchase_order_id' => PurchaseOrder::factory()->accepted()->sent()->create(),
    ]);

    purchasingQueueViewOnlyUser(PurchasePermission::ConfirmationView->value);

    Livewire::test(ManageSupplierConfirmations::class)
        ->assertTableActionHidden('reviewAndSendPurchaseOrder', $unsent)
        ->assertTableActionHidden('supplierResponse', $awaiting);
});

// ---------------------------------------------------------------------------
// Bills
// ---------------------------------------------------------------------------

/**
 * Bills record their creator, and the approval policy forbids self-approval,
 * so the fixture is attributed to a different user than the one acting.
 *
 * @param  array<string, mixed>  $attributes
 */
function purchasingQueueBill(array $attributes = []): Bill
{
    $bill = Bill::factory()->create($attributes);
    $bill->forceFill(['created_by' => User::factory()->create()->getKey()])->saveQuietly();

    return $bill;
}

it('offers Approve bill for clean drafts and Review bill for drafts with a blocker', function (): void {
    purchasingQueueUser(DashboardRole::ChiefAccountant);
    $clean = purchasingQueueBill();
    $provisional = purchasingQueueBill(['supplier_reference' => 'PO-AUTO:900']);

    Livewire::test(ManageBills::class)
        ->assertTableActionVisible('approve', $clean)
        ->assertTableActionHidden('reviewBill', $clean)
        ->assertTableActionHidden('approve', $provisional)
        ->assertTableActionVisible('reviewBill', $provisional)
        ->assertTableActionHasUrl('reviewBill', BillResource::getUrl('view', ['record' => $provisional]), $provisional)
        ->assertSee('Replace the provisional reference');

    expect($provisional->refresh()->status)->toBe(BillStatus::Draft);
});

it('sends a clean draft bill to the accounting service when approved from the table', function (): void {
    purchasingQueueUser(DashboardRole::ChiefAccountant);
    $clean = purchasingQueueBill();

    // A draft without lines is rejected by AccountingDocumentService::approveBill(),
    // proving the row action delegates to it rather than flipping the status itself.
    expect(fn () => Livewire::test(ManageBills::class)->callTableAction('approve', $clean))
        ->toThrow(DomainException::class, 'must contain at least one line');

    expect($clean->refresh()->status)->toBe(BillStatus::Draft);
});

it('links open bills to the prefilled supplier payment form and gives terminal bills no workflow action', function (): void {
    purchasingQueueUser(DashboardRole::Accountant);
    $approved = purchasingQueueBill(['status' => BillStatus::Approved]);
    $partial = purchasingQueueBill(['status' => BillStatus::PartiallyPaid, 'amount_paid' => 1]);
    $paid = purchasingQueueBill(['status' => BillStatus::Paid]);
    $cancelled = purchasingQueueBill(['status' => BillStatus::Cancelled]);

    $component = Livewire::test(ManageBills::class)
        ->assertTableActionVisible('recordSupplierPayment', $approved)
        ->assertTableActionHasUrl('recordSupplierPayment', SupplierPaymentResource::getUrl('index', ['bill_id' => $approved->id]), $approved)
        ->assertTableActionVisible('recordSupplierPayment', $partial)
        ->assertTableActionHidden('approve', $approved);

    foreach ([$paid, $cancelled] as $terminal) {
        foreach (['approve', 'reviewBill', 'recordSupplierPayment'] as $name) {
            $component->assertTableActionHidden($name, $terminal);
        }
    }
});

it('hides bill workflow actions from users who may only view bills', function (): void {
    $draft = purchasingQueueBill();
    $approved = purchasingQueueBill(['status' => BillStatus::Approved]);

    purchasingQueueViewOnlyUser(AccountingPermission::BillView->value);

    Livewire::test(ManageBills::class)
        ->assertTableActionHidden('approve', $draft)
        ->assertTableActionHidden('recordSupplierPayment', $approved);
});
