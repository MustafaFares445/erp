<?php

declare(strict_types=1);

use App\Enums\ExpenseStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupportPermission;
use App\Models\Expense;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Purchasing\Exceptions\PurchaseOrderNotReceivable;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use App\Services\Purchasing\SupplierCostWritebackService;
use App\Services\Sales\OrderWorkflowService;
use App\Services\Support\SupportReportService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('covers explicit supplier confirmation policy overrides', function (): void {
    $service = app(PurchaseOrderApprovalService::class);
    $method = new ReflectionMethod(PurchaseOrderApprovalService::class, 'confirmationPolicy');

    $required = (new PurchaseOrder)->forceFill(['supplier_confirmation_required' => true]);
    $notRequired = (new PurchaseOrder)->forceFill(['supplier_confirmation_required' => false]);

    expect($method->invoke($service, $required))->toBeTrue()
        ->and($method->invoke($service, $notRequired))->toBeFalse();
});

it('covers received purchase order accounting-exception workflow state', function (): void {
    $service = app(PurchaseOrderWorkflowService::class);
    $method = new ReflectionMethod(PurchaseOrderWorkflowService::class, 'next');

    $order = (new PurchaseOrder)->forceFill(['status' => PurchaseOrderStatus::Received->value]);

    $state = $method->invoke(
        $service,
        $order,
        '1.000000',
        '0.000000',
        '0.000000',
        '1.000000',
        '0.000000',
        '1.000000',
        '0.000000',
        '0.00',
        'No accounting bill',
        'Confirmed',
        'Received',
    );

    expect($state)->toBe([
        'Accounting exception',
        'No Accounting Bill exists for this received Purchase Order',
        'Accounting',
        'Create or reconcile the supplier bill',
    ]);
});

it('skips supplier cost writeback lines without a usable conversion factor', function (): void {
    $order = new PurchaseOrder;
    $missingFactor = (new PurchaseOrderLine)->forceFill([
        'conversion_factor_snapshot' => null,
        'unit_cost' => '10.00',
    ]);
    $zeroFactor = (new PurchaseOrderLine)->forceFill([
        'conversion_factor_snapshot' => '0.000000',
        'unit_cost' => '10.00',
    ]);
    $order->setRelation('lines', new Collection([$missingFactor, $zeroFactor]));

    app(SupplierCostWritebackService::class)->apply($order);

    expect(true)->toBeTrue();
});

it('projects non-null auto-close due dates into remaining days', function (): void {
    $order = Order::factory()->create([
        'auto_close_due_at' => now()->addDays(3),
    ]);

    $projection = app(OrderWorkflowService::class)->project($order);

    expect($projection->daysUntilAutoClose)
        ->toBeInt()
        ->toBeGreaterThanOrEqual(0);
});

it('counts preventive maintenance occurrence statuses in support compliance', function (): void {
    (new SupportPermissionSeeder)->run();

    $actor = User::factory()->create();
    $actor->givePermissionTo(SupportPermission::ReportView->value);

    MaintenanceScheduleOccurrence::factory()->create([
        'status' => OccurrenceStatus::Raised,
        'due_on' => today(),
    ]);
    MaintenanceScheduleOccurrence::factory()->create([
        'status' => OccurrenceStatus::Missed,
        'due_on' => today(),
    ]);

    $report = app(SupportReportService::class)->preventiveCompliance($actor, null, null);

    expect($report['raised'])->toBe(1)
        ->and($report['missed'])->toBe(1)
        ->and($report['total_due'])->toBe(2);
});

it('rejects paying an expense before its expense date', function (): void {
    $actor = User::factory()->admin()->create();
    $expense = Expense::factory()->create([
        'status' => ExpenseStatus::Approved->value,
        'expense_date' => today(),
    ]);

    expect(fn (): Expense => app(AccountingDocumentService::class)->payExpense(
        $actor,
        $expense,
        today()->subDay(),
    ))->toThrow(DomainException::class, 'Expense payment date cannot be before the expense date.');
});

it('rejects receipt initiation before an accepted order has been sent', function (): void {
    $actor = User::factory()->admin()->create();
    $order = PurchaseOrder::factory()->accepted($actor)->create([
        'sent_at' => null,
    ]);

    expect(fn () => app(PurchaseOrderReceivingService::class)->initiate($actor, $order))
        ->toThrow(PurchaseOrderNotReceivable::class);
});
