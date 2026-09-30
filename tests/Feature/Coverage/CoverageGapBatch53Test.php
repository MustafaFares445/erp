<?php

declare(strict_types=1);

use App\Data\Sales\OrderWorkflowProjection;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Sales\OrderWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coverageInfolistProjection(string $milestone, string $owner = 'Customer', string $label = 'Continue'): OrderWorkflowProjection
{
    return new OrderWorkflowProjection(
        commercialStatus: OrderStatus::Released,
        businessMilestone: $milestone,
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
        nextActionOwner: $owner,
        nextActionLabel: $label,
        nextActionRoute: null,
        financiallySettled: true,
        completionWindowStartedAt: CarbonImmutable::parse('2026-09-29 12:00:00'),
        autoCloseDueAt: CarbonImmutable::parse('2026-10-01 12:00:00'),
        closeSource: null,
        daysUntilAutoClose: 1,
    );
}

function bindCoverageOrderWorkflow(OrderWorkflowProjection $projection): void
{
    app()->instance(OrderWorkflowService::class, new readonly class($projection)
    {
        public function __construct(private OrderWorkflowProjection $projection) {}

        public function project(Order $order): OrderWorkflowProjection
        {
            return $this->projection;
        }
    });
}

it('covers remaining order infolist milestone callout branches', function (): void {
    $method = new ReflectionMethod(OrderInfolist::class, 'milestoneMeta');
    $order = (new Order)->forceFill(['status' => OrderStatus::Released->value]);

    $cases = [
        'Partially Allocated' => ['warning', 'Partially allocated'],
        'Ready to Dispatch' => ['info', 'Ready to dispatch'],
        'In Transit' => ['info', 'In transit'],
        'Invoice Draft' => ['warning', 'Invoice pending'],
        'Invoice Pending' => ['warning', 'Invoice pending'],
        'Auto Close Pending' => ['info', 'Awaiting customer confirmation'],
        'Awaiting Customer Confirmation' => ['info', 'Awaiting customer confirmation'],
        'Closed' => ['success', 'Closed'],
        'Cancelled' => ['danger', 'Cancelled'],
    ];

    foreach ($cases as $milestone => [$status, $heading]) {
        bindCoverageOrderWorkflow(coverageInfolistProjection($milestone));

        $meta = $method->invoke(null, $order);

        expect($meta['status'])->toBe($status)
            ->and($meta['heading'])->toBe($heading)
            ->and($meta['description'])->not->toBe('');
    }
});

it('renders every journey step complete for a closed order', function (): void {
    bindCoverageOrderWorkflow(coverageInfolistProjection('Closed', 'None', 'No action required'));

    $order = (new Order)->forceFill([
        'status' => OrderStatus::Closed->value,
        'quotation_id' => 42,
    ]);

    $journey = new ReflectionMethod(OrderInfolist::class, 'journeyState')->invoke(null, $order);

    expect($journey)
        ->toContain('Quotation ✓')
        ->toContain('Order ✓')
        ->toContain('Logistics ✓')
        ->toContain('Delivery ✓')
        ->toContain('Invoice ✓')
        ->toContain('Payment ✓')
        ->toContain('Completion ✓');
});

it('covers order infolist product label fallback', function (): void {
    $line = new OrderLine;
    $line->setRelation('productVariant', null);

    $label = new ReflectionMethod(OrderInfolist::class, 'productLabel')->invoke(null, $line);

    expect($label)->toBe('—');
});
