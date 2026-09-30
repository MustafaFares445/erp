<?php

declare(strict_types=1);

use App\Data\Sales\OrderFinancialProjection;
use App\Data\Sales\OrderWorkflowProjection;
use App\Enums\OrderCloseSource;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PurchaseOrder;
use App\Models\SalesProcurementRequirement;
use App\Services\Sales\OrderFinancialProjectionService;
use App\Services\Sales\OrderWorkflowService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coverage54Children(object $component): array
{
    $property = new ReflectionProperty($component, 'childComponents');
    $value = $property->getValue($component)['default'] ?? [];

    return is_array($value) ? $value : [];
}

function coverage54Find(object $component, string $name): ?object
{
    if (method_exists($component, 'getName') && $component->getName() === $name) {
        return $component;
    }

    foreach (coverage54Children($component) as $child) {
        $found = coverage54Find($child, $name);

        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

function coverage54Closure(object $component, string $property): Closure
{
    $reflection = new ReflectionProperty($component, $property);
    $value = $reflection->getValue($component);

    expect($value)->toBeInstanceOf(Closure::class);

    return $value;
}

function coverage54WorkflowProjection(): OrderWorkflowProjection
{
    return new OrderWorkflowProjection(
        commercialStatus: OrderStatus::Released,
        businessMilestone: 'Awaiting Customer Confirmation',
        fulfillmentProgressPercent: 100.0,
        requestedBase: 1.0,
        plannedBase: 1.0,
        readyBase: 0.0,
        dispatchedBase: 1.0,
        arrivedBase: 1.0,
        returnedBase: 0.0,
        remainingBase: 0.0,
        procurementOutstandingBase: 0.0,
        invoiceTotal: 100.0,
        paidTotal: 100.0,
        creditedTotal: 0.0,
        outstandingReceivable: 0.0,
        blockerCode: null,
        blockerMessage: null,
        nextActionOwner: 'Customer',
        nextActionLabel: 'Confirm receipt',
        nextActionRoute: null,
        financiallySettled: true,
        completionWindowStartedAt: CarbonImmutable::parse('2026-09-29 12:00:00'),
        autoCloseDueAt: CarbonImmutable::parse('2026-10-01 12:00:00'),
        closeSource: null,
        daysUntilAutoClose: 1,
    );
}

it('covers financial deposit and completion schema branches', function (): void {
    $financial = new OrderFinancialProjection(
        orderTotal: 100.0,
        customerDepositCollected: 25.0,
        issuedInvoiceTotal: 0.0,
        invoicePaidAmount: 0.0,
        invoiceCreditedAmount: 0.0,
        invoiceWrittenOffAmount: 0.0,
        invoiceOutstandingAmount: 0.0,
        draftInvoiceCount: 0,
        issuedInvoiceCount: 0,
        financiallySettled: false,
    );

    app()->instance(OrderFinancialProjectionService::class, new readonly class($financial)
    {
        public function __construct(private OrderFinancialProjection $projection) {}

        public function project(Order $order): OrderFinancialProjection
        {
            return $this->projection;
        }
    });

    $financialSection = new ReflectionMethod(OrderInfolist::class, 'financialSection')->invoke(null);
    $financialSchema = coverage54Closure($financialSection, 'childComponents');
    $financialEntries = $financialSchema(new Order);

    expect($financialEntries)->toHaveCount(4);

    $closed = Order::factory()->create([
        'status' => OrderStatus::Closed->value,
        'closed_by_source' => OrderCloseSource::System->value,
        'closed_at' => now(),
        'auto_close_days_snapshot' => 3,
    ]);
    $completionSection = new ReflectionMethod(OrderInfolist::class, 'completionSection')->invoke(null);
    $completionSchema = coverage54Closure($completionSection, 'childComponents');

    expect($completionSchema($closed))->toHaveCount(4);

    app()->instance(OrderWorkflowService::class, new readonly class
    {
        public function project(Order $order): OrderWorkflowProjection
        {
            return coverage54WorkflowProjection();
        }
    });

    $released = Order::factory()->create(['status' => OrderStatus::Released->value]);

    expect($completionSchema($released))->toHaveCount(2);
});

it('covers order-line fulfillment fallback state closure', function (): void {
    $section = new ReflectionMethod(OrderInfolist::class, 'linesSection')->invoke(null);
    $fulfillment = coverage54Find($section, 'fulfillment');

    expect($fulfillment)->not->toBeNull();

    $state = coverage54Closure($fulfillment, 'getConstantStateUsing');
    $line = new OrderLine;
    $line->setRelation('order', null);

    expect($state($line))->toBe('—');
});

it('covers procurement fulfilled color and purchase-order URL closures', function (): void {
    $section = new ReflectionMethod(OrderInfolist::class, 'supplySection')->invoke(null);
    $status = coverage54Find($section, 'status');
    $purchaseOrderEntry = coverage54Find($section, 'purchaseOrder.purchase_order_number');

    expect($status)->not->toBeNull()
        ->and($purchaseOrderEntry)->not->toBeNull();

    $color = coverage54Closure($status, 'color');
    expect($color('fulfilled'))->toBe('success');

    $url = coverage54Closure($purchaseOrderEntry, 'url');
    $purchaseOrder = PurchaseOrder::factory()->create();
    $requirement = new SalesProcurementRequirement;
    $requirement->setRelation('purchaseOrder', $purchaseOrder);

    expect($url($requirement))->toContain((string) $purchaseOrder->getKey());

    $requirement->setRelation('purchaseOrder', null);
    expect($url($requirement))->toBeNull();
});
