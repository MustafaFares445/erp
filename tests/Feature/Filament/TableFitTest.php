<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Filament\Resources\Adjustments\Pages\ListAdjustments;
use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\InventoryAlerts\Pages\ListInventoryAlerts;
use App\Filament\Resources\InventoryCounts\Pages\ListInventoryCounts;
use App\Filament\Resources\InventoryImportRuns\Pages\ManageInventoryImportRuns;
use App\Filament\Resources\InventoryLots\Pages\ListInventoryLots;
use App\Filament\Resources\InventoryOperations\Pages\ListInventoryOperations;
use App\Filament\Resources\InventoryReservations\Pages\ListInventoryReservations;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use App\Filament\Resources\MonthlyPlans\Pages\ListMonthlyPlans;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\OutboundFulfillments\Pages\ListOutboundFulfillments;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\PaymentTransactions\Pages\ListPaymentTransactions;
use App\Filament\Resources\Performance\Pages\ListPerformanceScores;
use App\Filament\Resources\PriceFloorOverrides\Pages\ListPriceFloorOverrides;
use App\Filament\Resources\PriceHistories\Pages\ListPriceHistories;
use App\Filament\Resources\Products\Pages\ManageProducts;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariants;
use App\Filament\Resources\PurchaseInbounds\Pages\ListPurchaseInbounds;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseRfqs\Pages\ListPurchaseRfqs;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\ReceivableWriteOffs\Pages\ListReceivableWriteOffs;
use App\Filament\Resources\Returns\Pages\ManageReturns;
use App\Filament\Resources\SalaryCalculations\Pages\ListSalaryCalculations;
use App\Filament\Resources\SalesOpportunities\Pages\ListSalesOpportunities;
use App\Filament\Resources\SerializedInventoryUnits\Pages\ListSerializedInventoryUnits;
use App\Filament\Resources\ServiceAppointments\Pages\ListServiceAppointments;
use App\Filament\Resources\ServiceRecords\Pages\ListServiceRecords;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Resources\SlaPolicies\Pages\ListSlaPolicies;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\SupplierConfirmations\Pages\ManageSupplierConfirmations;
use App\Filament\Resources\SupplierProductReferences\Pages\ManageSupplierProductReferences;
use App\Filament\Resources\SupportEntitlements\Pages\ListSupportEntitlements;
use App\Filament\Resources\SupportEquipment\Pages\ListSupportEquipment;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Filament\Resources\WarehouseReplenishmentPolicies\Pages\ManageWarehouseReplenishmentPolicies;
use App\Filament\Tables\TableFit;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\EmployeePermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  class-string  $page
 */
function tableFitListPage(string $page): Testable
{
    (new SystemPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
    (new AccountingPermissionSeeder)->run();
    (new SupportPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();
    (new CrmPermissionSeeder)->run();
    (new EmployeePermissionSeeder)->run();

    $user = User::factory()->admin()->create();
    $user->assignRole('System Admin', 'Support Manager');

    return Livewire::actingAs($user)->test($page);
}

function tableFitRecordAction(Table $table, string $name): Action
{
    $action = collect($table->getRecordActions())
        ->first(static fn (mixed $action): bool => $action instanceof Action && $action->getName() === $name);

    expect($action)->toBeInstanceOf(Action::class);

    return $action;
}

it('collapses an icon row button to its icon below the label breakpoint and keeps the label as its tooltip', function (): void {
    [$button] = TableFit::rowActions([
        Action::make('issue')->label('Issue invoice')->icon(Heroicon::OutlinedCheckCircle)->button(),
    ]);

    expect($button->getLabeledFromBreakpoint())->toBe('xl')
        ->and($button->getTooltip())->toBe('Issue invoice');
});

it('keeps a row button without an icon labelled at every width', function (): void {
    [$button] = TableFit::rowActions([Action::make('approve')->label('Approve')->button()]);

    expect($button->getLabeledFromBreakpoint())->toBeNull();
});

it('keeps the tooltip a row button already explains itself with', function (): void {
    [$button] = TableFit::rowActions([
        Action::make('retry')->label('Retry')->icon(Heroicon::OutlinedArrowPath)->tooltip('Retries the failed settlement')->button(),
    ]);

    expect($button->getTooltip())->toBe('Retries the failed settlement');
});

it('leaves a row action the table renders as a link as it is', function (): void {
    $table = tableFitListPage(ListReceivableWriteOffs::class)->instance()->getTable();

    $approve = tableFitRecordAction($table, 'approve_write_off');

    expect($approve->getLabeledFromBreakpoint())->toBeNull()
        ->and($approve->getTooltip())->toBeNull();
});

it('leaves the actions inside an overflow menu as they are', function (): void {
    $edit = Action::make('edit')->icon(Heroicon::OutlinedPencil)->button();

    TableFit::rowActions([ActionGroup::make([$edit])]);

    expect($edit->hasLabeledFromBreakpoint())->toBeFalse();
});

it('fits the list with the shared table policy and stacks its rows on mobile', function (string $page): void {
    $table = tableFitListPage($page)->instance()->getTable();

    expect($table->getExtraAttributes()['class'] ?? null)->toBe(TableFit::CSS_CLASS)
        ->and($table->isStackedOnMobile())->toBeTrue();
})->with([
    'invoices' => ListInvoices::class,
    'payments' => ListPayments::class,
    'quotations' => ListQuotations::class,
    'orders' => ListOrders::class,
    'receivable write-offs' => ListReceivableWriteOffs::class,
    'maintenance requests' => ListMaintenanceRequests::class,
    'tickets' => ListTickets::class,
    'delivery notes' => ListDeliveryNotes::class,
    'credit notes' => ListCreditNotes::class,
    'returns' => ManageReturns::class,
    'payment transactions' => ListPaymentTransactions::class,
    'price floor overrides' => ListPriceFloorOverrides::class,
    'price histories' => ListPriceHistories::class,
    'outbound fulfillments' => ListOutboundFulfillments::class,
    'shipments' => ListShipments::class,
    'bills' => ManageBills::class,
    'service records' => ListServiceRecords::class,
    'sla policies' => ListSlaPolicies::class,
    'support entitlements' => ListSupportEntitlements::class,
    'support equipment' => ListSupportEquipment::class,
    'service appointments' => ListServiceAppointments::class,
    'adjustments' => ListAdjustments::class,
    'inventory alerts' => ListInventoryAlerts::class,
    'inventory counts' => ListInventoryCounts::class,
    'inventory import runs' => ManageInventoryImportRuns::class,
    'inventory lots' => ListInventoryLots::class,
    'inventory operations' => ListInventoryOperations::class,
    'inventory reservations' => ListInventoryReservations::class,
    'product variants' => ManageProductVariants::class,
    'products' => ManageProducts::class,
    'stock movements' => ListStockMovements::class,
    'stock levels' => ListStockLevels::class,
    'purchase inbounds' => ListPurchaseInbounds::class,
    'purchase orders' => ListPurchaseOrders::class,
    'purchase rfqs' => ListPurchaseRfqs::class,
    'serialized inventory units' => ListSerializedInventoryUnits::class,
    'supplier confirmations' => ManageSupplierConfirmations::class,
    'supplier product references' => ManageSupplierProductReferences::class,
    'warehouse replenishment policies' => ManageWarehouseReplenishmentPolicies::class,
    'campaigns' => ListCampaigns::class,
    'customers' => ListCustomers::class,
    'leads' => ListLeads::class,
    'sales opportunities' => ListSalesOpportunities::class,
    'visits' => ListVisits::class,
    'tasks' => ListTasks::class,
    'monthly plans' => ListMonthlyPlans::class,
    'salary calculations' => ListSalaryCalculations::class,
    'performance scores' => ListPerformanceScores::class,
    'employees' => ListEmployees::class,
]);

it('moves secondary columns behind the column manager so the list fits a 1440px desktop', function (string $page, array $columns): void {
    $list = tableFitListPage($page)->instance();

    foreach ($columns as $column) {
        expect($list->isTableColumnToggledHidden($column))->toBeTrue("{$column} should start hidden")
            ->and($list->getTable()->getColumn($column)?->isToggleable())->toBeTrue();
    }
})->with([
    'invoices' => [ListInvoices::class, ['status', 'next_action']],
    'payments' => [ListPayments::class, ['source', 'allocations_count']],
    'quotations' => [ListQuotations::class, ['reservation_coverage']],
    'orders' => [ListOrders::class, ['availability_state', 'scheduled_at']],
    'delivery notes' => [ListDeliveryNotes::class, ['sourceWarehouse.name']],
    'credit notes' => [ListCreditNotes::class, ['stock_consequence']],
    'returns' => [ManageReturns::class, ['originalPurchaseOrder.purchase_order_number']],
    'payment transactions' => [ListPaymentTransactions::class, ['provider', 'status', 'payment.payment_number', 'created_at']],
    'price floor overrides' => [ListPriceFloorOverrides::class, ['pricingTier.name', 'approved_at']],
    'price histories' => [ListPriceHistories::class, ['changedBy.name']],
    'shipments' => [ListShipments::class, ['warehouse.name', 'delivery.operation_number', 'confirmed_by']],
    'bills' => [ManageBills::class, ['supplier_reference', 'supplier_reference_source', 'purchaseOrder.purchase_order_number', 'description', 'amount_paid']],
    'service records' => [ListServiceRecords::class, ['maintenanceRecord.serial_number', 'started_at', 'completed_at']],
    'sla policies' => [ListSlaPolicies::class, ['code', 'updatedBy.name']],
    'support entitlements' => [ListSupportEntitlements::class, ['external_reference']],
    'support equipment' => [ListSupportEquipment::class, ['updated_at']],
    'adjustments' => [ListAdjustments::class, ['warehouse.code', 'correctsAdjustment.adjustment_number', 'conditions', 'items_count', 'createdBy.name']],
    'inventory alerts' => [ListInventoryAlerts::class, ['resolved_at']],
    'inventory lots' => [ListInventoryLots::class, ['quarantine_quantity', 'damaged_quantity', 'reserved_quantity', 'warehouse_count', 'days_remaining']],
    'inventory operations' => [ListInventoryOperations::class, ['delivery_type', 'sourceWarehouse.name', 'destinationWarehouse.name', 'scheduled_at']],
    'product variants' => [ManageProductVariants::class, ['unit.symbol', 'tracking_mode', 'is_active']],
    'products' => [ManageProducts::class, ['manufacturer.name', 'brand.name']],
    'stock movements' => [ListStockMovements::class, ['createdBy.name', 'warehouse.code']],
    'purchase inbounds' => [ListPurchaseInbounds::class, ['confirmed_qty', 'allocated_qty', 'received_qty', 'remaining_qty', 'warehouses', 'overdue', 'purchaseOrder.expected_at']],
    'purchase orders' => [ListPurchaseOrders::class, ['supplier.logo_path', 'expected_at']],
    'purchase rfqs' => [ListPurchaseRfqs::class, ['currency_code']],
    'serialized inventory units' => [ListSerializedInventoryUnits::class, ['iot_number']],
    'supplier confirmations' => [ManageSupplierConfirmations::class, ['communication_state', 'requested_total', 'confirmed_total', 'backordered_total', 'supplier_reference', 'notes', 'created_at']],
    'warehouse replenishment policies' => [ManageWarehouseReplenishmentPolicies::class, ['reserved', 'incoming']],
    'customers' => [ListCustomers::class, ['user.name', 'user.email', 'latestInteraction.occurred_at']],
    'leads' => [ListLeads::class, ['last_interaction_at', 'source']],
    'sales opportunities' => [ListSalesOpportunities::class, ['currency', 'sourceVisit.reference', 'owner.name', 'expected_close_date']],
    'visits' => [ListVisits::class, ['scheduled_end_at', 'duration', 'location_warning', 'follow_up_required']],
]);

it('collapses the next-step row button to its icon below the label breakpoint', function (string $page, string $action): void {
    $table = tableFitListPage($page)->instance()->getTable();

    expect(tableFitRecordAction($table, $action)->getLabeledFromBreakpoint())->toBe(TableFit::ROW_BUTTON_LABELS_FROM);
})->with([
    'issue invoice' => [ListInvoices::class, 'issue'],
    'post payment' => [ListPayments::class, 'post_payment'],
    'send quotation' => [ListQuotations::class, 'send'],
    'confirm order' => [ListOrders::class, 'confirm_order'],
    'start repair' => [ListMaintenanceRequests::class, 'startRepairRow'],
]);

it('offers the quotation workflow steps as row buttons and tucks view into the overflow menu', function (): void {
    $table = tableFitListPage(ListQuotations::class)->instance()->getTable();

    $inline = collect($table->getRecordActions())->reject(static fn (mixed $action): bool => $action instanceof ActionGroup);
    $grouped = collect($table->getRecordActions())->first(static fn (mixed $action): bool => $action instanceof ActionGroup);

    expect($inline->map(static fn (Action $action): string => (string) $action->getName())->values()->all())->toBe(['send', 'record_decision', 'convert', 'requote'])
        ->and($inline->every(static fn (Action $action): bool => $action->isButton()))->toBeTrue()
        ->and(array_keys($grouped->getFlatActions()))->toBe(['view']);
});

it('explains a payment status in its tooltip instead of under every row', function (): void {
    $status = tableFitListPage(ListPayments::class)->instance()->getTable()->getColumn('status');

    expect($status->getTooltip(PaymentStatus::Draft))->toBe('Prepared but not posted. It has not changed the customer balance.')
        ->and($status->getDescriptionBelow())->toBeNull();
});
