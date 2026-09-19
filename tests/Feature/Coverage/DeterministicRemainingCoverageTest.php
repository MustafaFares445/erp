<?php

declare(strict_types=1);

use App\Enums\InventoryReturnType;
use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PeriodCloseCheck;
use App\Enums\ReconciliationScope;
use App\Enums\SerializedCustodyType;
use App\Enums\TicketStatus;
use App\Enums\WarrantyStatus;
use App\Filament\Resources\FiscalPeriods\Pages\ViewFiscalPeriod;
use App\Filament\Resources\Returns\Pages\ManageReturns;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\FiscalPeriodCloseCheck;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ReconciliationRun;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\ReconciliationReportService;
use App\Services\Reconciliation\ReconciliationRunRecorder;
use App\Services\Sales\InvoiceConfirmationService;
use App\Services\Sales\OrderWorkflowService;
use App\Services\Support\TicketSlaStateResolver;
use App\Services\Support\WarrantyResolver;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

function deterministicCoverageMethod(string $class, string $method): ReflectionMethod
{
    return new ReflectionMethod($class, $method);
}

it('covers every order workflow milestone and blocker branch', function (): void {
    $service = app(OrderWorkflowService::class);
    $order = new Order;

    $milestone = deterministicCoverageMethod(OrderWorkflowService::class, 'milestone');
    $blocker = deterministicCoverageMethod(OrderWorkflowService::class, 'blocker');
    $floatValue = deterministicCoverageMethod(OrderWorkflowService::class, 'floatValue');

    $cases = [
        [OrderStatus::Cancelled, [], 'Cancelled'],
        [OrderStatus::Closed, [], 'Closed'],
        [OrderStatus::Draft, [], 'Draft'],
        [OrderStatus::Confirmed, [], 'Awaiting Release'],
        [OrderStatus::Released, ['procurement_outstanding' => 1.0], 'Supply Blocked'],
        [OrderStatus::Released, ['remaining' => 1.0, 'planned' => 0.0], 'Awaiting Logistics Allocation'],
        [OrderStatus::Released, ['remaining' => 1.0, 'planned' => 1.0], 'Partially Allocated'],
        [OrderStatus::Released, ['ready' => 1.0], 'Ready to Dispatch'],
        [OrderStatus::Released, ['dispatched' => 2.0, 'arrived' => 1.0], 'In Transit'],
        [OrderStatus::Released, ['dispatched' => 2.0, 'arrived' => 2.0, 'invoiced' => 1.0], 'Invoice Pending'],
        [OrderStatus::Released, ['outstanding_receivable' => 1.0], 'Payment Pending'],
        [OrderStatus::Released, ['arrived' => 1.0], 'Delivered'],
        [OrderStatus::Released, [], 'Released'],
    ];

    foreach ($cases as [$status, $facts, $expected]) {
        $order->forceFill(['status' => $status->value]);
        expect($milestone->invoke($service, $order, $facts))->toBe($expected);
    }

    $blockerCases = [
        [OrderStatus::Draft, [], 'commercial_not_confirmed'],
        [OrderStatus::Confirmed, [], 'not_released'],
        [OrderStatus::Released, ['procurement_outstanding' => 1.0], 'procurement_open'],
        [OrderStatus::Released, ['remaining' => 1.0], 'awaiting_logistics_allocation'],
        [OrderStatus::Released, ['ready' => 1.0], 'delivery_waiting_stock'],
        [OrderStatus::Released, ['dispatched' => 2.0, 'arrived' => 1.0], 'shipment_in_transit'],
        [OrderStatus::Released, ['dispatched' => 2.0, 'arrived' => 2.0, 'invoiced' => 1.0], 'invoice_pending'],
        [OrderStatus::Released, ['outstanding_receivable' => 1.0], 'payment_pending'],
        [OrderStatus::Released, [], null],
    ];

    foreach ($blockerCases as [$status, $facts, $expectedCode]) {
        $order->forceFill(['status' => $status->value]);
        [$code] = $blocker->invoke($service, $order, $facts);
        expect($code)->toBe($expectedCode);
    }

    expect($floatValue->invoke($service, '12.5'))->toBe(12.5);
    expect(fn (): mixed => $floatValue->invoke($service, []))
        ->toThrow(LogicException::class, 'invoice amount must be numeric');
});

it('covers every ticket SLA presentation state and risk boundary', function (): void {
    $resolver = app(TicketSlaStateResolver::class);
    $ticket = new Ticket;

    $ticket->forceFill(['status' => TicketStatus::Pending->value, 'live_at' => null]);
    expect($resolver->label($ticket))->toBe('Not Started')
        ->and($resolver->color($ticket))->toBe('gray');

    $ticket->forceFill([
        'status' => TicketStatus::WaitingCustomer->value,
        'live_at' => now(),
        'response_breached' => false,
        'resolution_breached' => false,
    ]);
    expect($resolver->label($ticket))->toBe('Paused — Customer')
        ->and($resolver->color($ticket))->toBe('info');

    $ticket->forceFill([
        'status' => TicketStatus::Live->value,
        'response_breached' => true,
        'resolution_breached' => false,
    ]);
    expect($resolver->label($ticket))->toBe('Response Breached')
        ->and($resolver->color($ticket))->toBe('danger');

    $ticket->forceFill([
        'response_breached' => false,
        'resolution_breached' => true,
    ]);
    expect($resolver->label($ticket))->toBe('Resolution Breached');

    $ticket->forceFill([
        'status' => TicketStatus::Resolved->value,
        'resolution_breached' => false,
        'response_due_at' => null,
        'resolution_due_at' => null,
    ]);
    expect($resolver->label($ticket))->toBe('Completed')
        ->and($resolver->color($ticket))->toBe('success');

    $ticket->forceFill([
        'status' => TicketStatus::Live->value,
        'first_response_at' => null,
        'response_due_at' => now()->addMinutes(10),
        'sla_response_target_minutes' => 100,
        'resolved_at' => null,
        'resolution_due_at' => null,
    ]);
    expect($resolver->label($ticket))->toBe('At Risk')
        ->and($resolver->color($ticket))->toBe('warning');

    $ticket->forceFill([
        'first_response_at' => now(),
        'response_due_at' => now()->addHour(),
        'resolution_due_at' => now()->addMinutes(10),
        'sla_resolution_target_minutes' => 100,
    ]);
    expect($resolver->label($ticket))->toBe('At Risk');

    $ticket->forceFill([
        'resolution_due_at' => now()->addHours(2),
        'sla_resolution_target_minutes' => 100,
    ]);
    expect($resolver->label($ticket))->toBe('On Track');

    $deadlineAtRisk = deterministicCoverageMethod(TicketSlaStateResolver::class, 'deadlineAtRisk');
    expect($deadlineAtRisk->invoke($resolver, now()->subMinute(), 100))->toBeFalse()
        ->and($deadlineAtRisk->invoke($resolver, now()->addMinute(), 0))->toBeFalse()
        ->and($deadlineAtRisk->invoke($resolver, now()->addMinute(), 100))->toBeTrue();
});

it('covers all warranty resolver outcomes and identifier guard', function (): void {
    $resolver = app(WarrantyResolver::class);
    $customer = new CustomerProfile;
    $customer->forceFill(['id' => 77]);

    $variant = new ProductVariant;
    $variant->forceFill([
        'warranty_duration_value' => null,
        'warranty_duration_unit' => null,
    ]);

    $unit = new SerializedInventoryUnit;
    $unit->forceFill([
        'id' => 11,
        'custody_type' => SerializedCustodyType::Warehouse->value,
        'custody_reference_id' => null,
        'warranty_started_on' => null,
        'warranty_expires_on' => null,
    ]);
    $unit->setRelation('productVariant', $variant);

    expect($resolver->resolveForSerializedUnit($unit, $customer)->status)->toBe(WarrantyStatus::Unknown);

    $unit->forceFill([
        'custody_type' => SerializedCustodyType::Customer->value,
        'custody_reference_id' => 77,
    ]);
    expect($resolver->resolveForSerializedUnit($unit, $customer)->status)->toBe(WarrantyStatus::NotCovered);

    $variant->forceFill(['warranty_duration_value' => 12, 'warranty_duration_unit' => 'months']);
    expect($resolver->resolveForSerializedUnit($unit, $customer)->status)->toBe(WarrantyStatus::Unknown);

    $unit->forceFill([
        'warranty_started_on' => now()->subMonth()->toDateString(),
        'warranty_expires_on' => null,
    ]);
    expect($resolver->resolveForSerializedUnit($unit, $customer)->status)->toBe(WarrantyStatus::Unknown);

    $unit->forceFill(['warranty_expires_on' => now()->addMonth()->toDateString()]);
    expect($resolver->resolveForSerializedUnit($unit, $customer)->status)->toBe(WarrantyStatus::Covered);

    expect($resolver->resolveForSerializedUnit($unit, $customer, now()->addMonths(2))->status)
        ->toBe(WarrantyStatus::Expired);

    expect($resolver->externalEquipment()->status)->toBe(WarrantyStatus::NotApplicable);

    $unkeyed = new SerializedInventoryUnit;
    expect(fn () => $resolver->resolveForSerializedUnit($unkeyed, $customer))
        ->toThrow(LogicException::class, 'numeric identifiers');
});

it('covers reconciliation recorder validation persistence filtering and truncation', function (): void {
    $recorder = app(ReconciliationRunRecorder::class);
    $scope = ReconciliationScope::cases()[0];
    $actor = User::factory()->create();

    expect(fn () => $recorder->record($scope, [], 'invalid', $actor))
        ->toThrow(DomainException::class, 'Unsupported reconciliation trigger source');

    $errors = ['first divergence', '', ...array_map(
        static fn (int $index): string => 'error-'.$index,
        range(1, 105),
    )];

    $recorder->record($scope, [
        ['name' => 'clean', 'errors' => []],
        ['name' => 'dirty', 'errors' => $errors],
    ], 'manual', $actor);

    $clean = ReconciliationRun::query()->where('invariant', 'clean')->sole();
    $dirty = ReconciliationRun::query()->where('invariant', 'dirty')->sole();

    expect($clean->passed)->toBeTrue()
        ->and($clean->detail)->toBeNull()
        ->and($dirty->passed)->toBeFalse()
        ->and($dirty->divergence_count)->toBe(106)
        ->and($dirty->detail)->toHaveCount(101)
        ->and($dirty->detail[100])->toContain('truncated');
});

it('executes fiscal-period checklist CSV export content branches', function (): void {
    $period = FiscalPeriod::factory()->create();
    $cases = PeriodCloseCheck::cases();

    FiscalPeriodCloseCheck::query()->create([
        'fiscal_period_id' => $period->getKey(),
        'check_key' => $cases[0],
        'passed' => true,
        'detail' => ['ok' => true],
        'measured_at' => now(),
    ]);

    FiscalPeriodCloseCheck::query()->create([
        'fiscal_period_id' => $period->getKey(),
        'check_key' => $cases[1],
        'passed' => false,
        'detail' => ['reason' => 'coverage'],
        'measured_at' => now(),
    ]);

    $page = new ReflectionClass(ViewFiscalPeriod::class)->newInstanceWithoutConstructor();
    $method = deterministicCoverageMethod(ViewFiscalPeriod::class, 'exportChecklistCsv');
    $response = $method->invoke($page, $period);

    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('check,mandatory,passed,measured_at,detail')
        ->and($csv)->toContain(',yes,')
        ->and($csv)->toContain(',no,')
        ->and($csv)->toContain('not run');
});

it('executes valid customer and supplier return create actions', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $warehouse = Warehouse::factory()->create();

    $page = new ReflectionClass(ManageReturns::class)->newInstanceWithoutConstructor();
    $actions = deterministicCoverageMethod(ManageReturns::class, 'getHeaderActions')->invoke($page);
    $action = $actions[0];

    expect($action)->toBeInstanceOf(CreateAction::class);

    $customer = CustomerProfile::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'customer_id' => $customer->getKey(),
    ]);

    $customerReturn = $action->process(null, ['data' => [
        'return_type' => InventoryReturnType::Customer->value,
        'warehouse_id' => $warehouse->getKey(),
        'original_inventory_operation_id' => $delivery->getKey(),
        'reason' => '  customer coverage  ',
        'notes' => '  note  ',
    ]]);

    expect($customerReturn)->toBeInstanceOf(InventoryReturn::class)
        ->and($customerReturn->reason)->toBe('customer coverage')
        ->and($customerReturn->notes)->toBe('note');

    $supplier = Supplier::factory()->create();
    $supplierReturn = $action->process(null, ['data' => [
        'return_type' => InventoryReturnType::Supplier->value,
        'warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $supplier->getKey(),
        'reason' => '',
        'notes' => ' supplier note ',
    ]]);

    expect($supplierReturn)->toBeInstanceOf(InventoryReturn::class)
        ->and($supplierReturn->supplier_id)->toBe($supplier->getKey())
        ->and($supplierReturn->reason)->toBeNull()
        ->and($supplierReturn->notes)->toBe('supplier note');
});

it('covers reconciliation report filters and date validation branches', function (): void {
    $recorder = app(ReconciliationRunRecorder::class);
    $scope = ReconciliationScope::cases()[0];

    $recorder->record($scope, [
        ['name' => 'passed-run', 'errors' => []],
        ['name' => 'failed-run', 'errors' => ['divergence']],
    ], 'manual');

    $service = app(ReconciliationReportService::class);
    $date = today()->toDateString();

    $filtered = $service->query([
        'scope' => $scope->value,
        'passed' => 'true',
        'trigger_source' => 'manual',
        'from' => $date,
        'until' => $date,
    ])->get();

    expect($filtered)->toHaveCount(1)
        ->and($filtered->first()?->invariant)->toBe('passed-run')
        ->and($service->divergences(['trigger_source' => 'manual'])->count())->toBe(1)
        ->and($service->hasPersistedRuns())->toBeTrue();

    expect($service->query([
        'scope' => 'not-a-scope',
        'passed' => 'not-a-bool',
        'trigger_source' => 'not-a-source',
    ])->count())->toBe(2);

    expect(fn (): mixed => $service->query(['from' => '2026/09/18']))
        ->toThrow(DomainException::class, 'YYYY-MM-DD');

    expect(fn (): mixed => $service->query([
        'from' => '2026-09-19',
        'until' => '2026-09-18',
    ]))->toThrow(DomainException::class, 'start date must be before');
});

it('covers invoice receipt confirmation validation and signature media path', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $service = app(InvoiceConfirmationService::class);

    $draft = Invoice::factory()->create([
        'status' => InvoiceStatus::Draft,
        'issued_at' => null,
    ]);

    expect(fn () => $service->confirm($actor, $draft, 'unsupported'))
        ->toThrow(DomainException::class, 'Unsupported invoice receipt confirmation type');

    expect(fn () => $service->confirm(
        $actor,
        $draft,
        InvoiceConfirmationType::CustomerReceived,
    ))->toThrow(DomainException::class, 'Only a sent invoice');

    $sent = Invoice::factory()->create([
        'status' => InvoiceStatus::Sent,
        'issued_at' => now(),
        'sent_at' => now(),
    ]);

    $signaturePath = storage_path('framework/testing/invoice-confirmation-signature.txt');
    if (! is_dir(dirname($signaturePath))) {
        mkdir(dirname($signaturePath), 0777, true);
    }
    file_put_contents($signaturePath, 'coverage signature');

    $confirmation = $service->confirm(
        $actor,
        $sent,
        InvoiceConfirmationType::CustomerReceived,
        'received',
        $signaturePath,
    );

    expect($confirmation->confirmation_type)->toBe(InvoiceConfirmationType::CustomerReceived)
        ->and($confirmation->notes)->toBe('received')
        ->and($confirmation->getFirstMedia('invoice-confirmation-signature'))->not->toBeNull()
        ->and($sent->refresh()->received_confirmation_type)->toBe(InvoiceConfirmationType::CustomerReceived);
});
