<?php

declare(strict_types=1);

use App\Data\Sales\OrderWorkflowProjection;
use App\Enums\OrderCloseSource;
use App\Enums\OrderStatus;
use App\Enums\ResolvedPriceSource;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Models\Order;
use App\Models\OrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coverageOrderProjection(
    string $milestone = 'Awaiting Customer Confirmation',
    string $owner = 'Customer',
    string $label = 'Confirm completion',
    ?string $blockerCode = null,
    ?string $blockerMessage = null,
): OrderWorkflowProjection {
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
        blockerCode: $blockerCode,
        blockerMessage: $blockerMessage,
        nextActionOwner: $owner,
        nextActionLabel: $label,
        nextActionRoute: null,
        financiallySettled: true,
        completionWindowStartedAt: CarbonImmutable::parse('2026-09-28 12:00:00'),
        autoCloseDueAt: CarbonImmutable::parse('2026-10-01 12:00:00'),
        closeSource: null,
        daysUntilAutoClose: 1,
    );
}

it('covers order journey step mapping and next-step sentences', function (): void {
    $journeyStep = new ReflectionMethod(OrderInfolist::class, 'journeyStepIndex');
    $nextStep = new ReflectionMethod(OrderInfolist::class, 'nextStepSentence');

    $order = new Order;
    $projection = coverageOrderProjection();

    $order->forceFill(['status' => OrderStatus::Closed->value]);
    expect($journeyStep->invoke(null, $order, $projection))->toBeNull();

    $order->forceFill(['status' => OrderStatus::Draft->value]);
    expect($journeyStep->invoke(null, $order, $projection))->toBe(0);

    $order->forceFill(['status' => OrderStatus::Released->value]);

    expect($journeyStep->invoke(null, $order, coverageOrderProjection('Awaiting Release')))->toBe(1)
        ->and($journeyStep->invoke(null, $order, coverageOrderProjection('Ready to Dispatch')))->toBe(2)
        ->and($journeyStep->invoke(null, $order, coverageOrderProjection('Invoice Pending')))->toBe(3)
        ->and($journeyStep->invoke(null, $order, coverageOrderProjection('Payment Pending')))->toBe(4)
        ->and($journeyStep->invoke(null, $order, coverageOrderProjection('Awaiting Customer Confirmation')))->toBe(5)
        ->and($nextStep->invoke(null, coverageOrderProjection(owner: 'None', label: 'Nothing')))->toBe('No action required.')
        ->and($nextStep->invoke(null, coverageOrderProjection(owner: 'Customer', label: 'Confirm completion')))
        ->toBe('Next: Customer → Confirm completion.');
});

it('covers completion blocker categories and completed-entry branches', function (): void {
    $category = new ReflectionMethod(OrderInfolist::class, 'completionBlockerCategory');
    $completedEntries = new ReflectionMethod(OrderInfolist::class, 'completedEntries');

    expect($category->invoke(null, 'shipment_in_transit', 'ignored'))->toBe([
        'heading' => 'Not ready for final confirmation.',
        'reason' => 'Fulfillment is still in progress.',
    ])->and($category->invoke(null, 'invoice_outstanding', null))->toBe([
        'heading' => 'Waiting for financial settlement.',
        'reason' => 'Delivered goods have not yet been fully invoiced or paid.',
    ])->and($category->invoke(null, 'invoice_missing', 'Custom blocker'))->toBe([
        'heading' => 'Waiting for financial settlement.',
        'reason' => 'Custom blocker',
    ]);

    $customerClosed = Order::factory()->create([
        'status' => OrderStatus::Closed->value,
        'closed_by_source' => OrderCloseSource::Customer->value,
        'closed_at' => now(),
    ]);
    $systemClosed = Order::factory()->create([
        'status' => OrderStatus::Closed->value,
        'closed_by_source' => OrderCloseSource::System->value,
        'closed_at' => now(),
        'auto_close_days_snapshot' => 3,
    ]);
    $systemWithoutSnapshot = Order::factory()->create([
        'status' => OrderStatus::Closed->value,
        'closed_by_source' => OrderCloseSource::System->value,
        'closed_at' => now(),
        'auto_close_days_snapshot' => null,
    ]);

    expect($completedEntries->invoke(null, $customerClosed))->toHaveCount(4)
        ->and($completedEntries->invoke(null, $systemClosed))->toHaveCount(4)
        ->and($completedEntries->invoke(null, $systemWithoutSnapshot))->toHaveCount(4);
});

it('covers line fulfillment fallback and pricing-floor helper branches', function (): void {
    $lineProgress = new ReflectionMethod(OrderInfolist::class, 'lineFulfillmentProgress');
    $priceSourceHelp = new ReflectionMethod(OrderInfolist::class, 'priceSourceHelp');
    $floorLabel = new ReflectionMethod(OrderInfolist::class, 'floorOverrideLabel');
    $floorColor = new ReflectionMethod(OrderInfolist::class, 'floorOverrideColor');
    $floorHelp = new ReflectionMethod(OrderInfolist::class, 'floorOverrideHelp');

    $detached = new OrderLine;
    expect($lineProgress->invoke(null, $detached))->toBeNull();

    $line = new OrderLine;
    $line->forceFill([
        'unit_price' => '12.00',
        'resolved_price_source' => null,
        'floor_price_minor' => null,
    ]);

    expect($priceSourceHelp->invoke(null, $line))->toBeNull()
        ->and($floorLabel->invoke(null, $line))->toBe('Not applicable')
        ->and($floorColor->invoke(null, $line))->toBe('gray')
        ->and($floorHelp->invoke(null, $line))->toBeNull();

    $line->forceFill([
        'resolved_price_source' => ResolvedPriceSource::Base,
        'floor_price_minor' => 1000,
    ]);

    expect($priceSourceHelp->invoke(null, $line))
        ->toBe((string) __('admin.sales.quotation_view.price_source_help.base'))
        ->and($floorLabel->invoke(null, $line))->toBe('✓ Within allowed pricing range')
        ->and($floorColor->invoke(null, $line))->toBe('success')
        ->and($floorHelp->invoke(null, $line))->toBe((string) __('admin.sales.quotation_view.within_floor'));

    $line->forceFill(['floor_price_minor' => 1500]);

    expect($floorLabel->invoke(null, $line))->toBe('⚠ Pending approval')
        ->and($floorColor->invoke(null, $line))->toBe('warning')
        ->and($floorHelp->invoke(null, $line))->toBe((string) __('admin.sales.quotation_view.below_floor_pending'));
});
