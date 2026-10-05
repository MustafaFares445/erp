<?php

declare(strict_types=1);

use App\Enums\CustomerReturnRequestStatus;
use App\Enums\InventoryReturnStatus;
use App\Enums\NotificationEventKey;
use App\Enums\OperationStage;
use App\Enums\QualityResolutionType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Events\SupportQualityMilestone;
use App\Models\CustomerProfile;
use App\Models\CustomerReturnRequest;
use App\Models\CustomerReturnRequestLine;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\LotQualityAlert;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TicketProductContext;
use App\Models\TicketQualityResolution;
use App\Models\User;
use App\Services\Support\LotQualitySignalService;
use App\Services\Support\TicketIntakeService;
use App\Services\Support\TicketProductContextService;
use App\Services\Support\TicketQualityResolutionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\QualityFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    QualityFixtures::seedPermissions();
});

it('adds the product quality issue ticket type with labels in both languages', function (): void {
    expect(TicketType::from('product_quality_issue'))->toBe(TicketType::ProductQualityIssue)
        ->and(TicketType::ProductQualityIssue->label())->toBe('Product Quality Issue');

    app()->setLocale('ar');

    expect(TicketType::ProductQualityIssue->label())->toBe('مشكلة جودة منتج')
        ->and(QualityResolutionType::NoDefectFound->label())->toBe('لم يُعثر على عيب');
});

it('lists only non-serialized lines of the customer\'s completed deliveries with a quantity left', function (): void {
    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $good = QualityFixtures::delivered($customer, '10');
    QualityFixtures::delivered($other, '5');
    $serialized = QualityFixtures::delivered($customer, '1');
    $serialized->forceFill(['serialized_inventory_unit_id' => SerializedInventoryUnit::factory()->create()->id])->save();
    $notDone = QualityFixtures::delivered($customer, '3');
    $notDone->operation->forceFill(['stage' => OperationStage::Ready])->save();
    $used = QualityFixtures::delivered($customer, '4');

    $service = app(TicketProductContextService::class);
    DB::table('inventory_return_lines')->count();
    CustomerReturnRequestLine::query()->forceCreate([
        'customer_return_request_id' => CustomerReturnRequest::factory()->create(['customer_id' => $customer->id, 'status' => CustomerReturnRequestStatus::Approved])->id,
        'original_inventory_operation_line_id' => $used->id,
        'requested_quantity' => '4',
        'sort_order' => 0,
    ]);

    expect($service->eligibleLines($customer)->pluck('id')->all())->toBe([$good->id])
        ->and($service->lineOptions($customer))->toHaveKey($good->id)
        ->and($service->lineOptions($customer)[$good->id])->toContain($good->lot_number)->toContain('10 left');
});

it('subtracts approved return requests and posted returns from the quantity left', function (): void {
    $customer = CustomerProfile::factory()->create();
    $line = QualityFixtures::delivered($customer, '10');
    $service = app(TicketProductContextService::class);

    expect($service->remainingQuantity($line))->toBe('10.000000');

    CustomerReturnRequestLine::query()->forceCreate([
        'customer_return_request_id' => CustomerReturnRequest::factory()->create(['customer_id' => $customer->id, 'status' => CustomerReturnRequestStatus::Approved])->id,
        'original_inventory_operation_line_id' => $line->id,
        'requested_quantity' => '3',
        'sort_order' => 0,
    ]);
    // A submitted (not yet accepted) request does not reduce what is left.
    CustomerReturnRequestLine::query()->forceCreate([
        'customer_return_request_id' => CustomerReturnRequest::factory()->create(['customer_id' => $customer->id, 'status' => CustomerReturnRequestStatus::Submitted])->id,
        'original_inventory_operation_line_id' => $line->id,
        'requested_quantity' => '2',
        'sort_order' => 0,
    ]);

    expect($service->remainingQuantity($line))->toBe('7.000000');

    $return = InventoryReturn::factory()->create(['status' => InventoryReturnStatus::Posted]);
    DB::table('inventory_return_lines')->insert([
        'inventory_return_id' => $return->id,
        'product_variant_id' => $line->product_variant_id,
        'transaction_quantity' => '5',
        'transaction_unit_id' => $line->transaction_unit_id,
        'conversion_factor_snapshot' => '1',
        'base_quantity' => '5',
        'source_condition' => 'saleable',
        'original_inventory_operation_line_id' => $line->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($service->remainingQuantity($line))->toBe('2.000000')
        ->and(InventoryReturnLine::query()->count())->toBe(1);
});

it('builds the context from the verified delivery line, never from customer-supplied ids', function (): void {
    $customer = CustomerProfile::factory()->create();
    $line = QualityFixtures::delivered($customer, '10');
    $ticket = QualityFixtures::ticket($customer);

    $contexts = app(TicketProductContextService::class)->attach($ticket, [[
        'original_inventory_operation_line_id' => $line->id,
        'quantity' => '4.5',
        'notes' => '  Chipped edges  ',
        // Forged values the customer might send are ignored.
        'inventory_lot_id' => 999999,
        'product_variant_id' => 999999,
    ]]);

    $context = $contexts->sole();

    expect($context->ticket->is($ticket))->toBeTrue()
        ->and($context->product_variant_id)->toBe($line->product_variant_id)
        ->and($context->inventory_lot_id)->toBe($line->inventory_lot_id)
        ->and($context->original_inventory_operation_line_id)->toBe($line->id)
        ->and($context->quantity)->toBe('4.500000')
        ->and($context->unit_id)->toBe($line->transaction_unit_id)
        ->and($context->notes)->toBe('Chipped edges')
        ->and($context->productVariant->id)->toBe($line->product_variant_id)
        ->and($context->inventoryLot->id)->toBe($line->inventory_lot_id)
        ->and($context->originalOperationLine->is($line))->toBeTrue()
        ->and($context->unit->id)->toBe($line->transaction_unit_id)
        ->and($ticket->productContexts()->count())->toBe(1);
});

it('rejects lines the customer never received, serialized equipment, bad quantities and duplicates', function (): void {
    $customer = CustomerProfile::factory()->create();
    $service = app(TicketProductContextService::class);
    $line = QualityFixtures::delivered($customer, '10');
    $foreign = QualityFixtures::delivered(CustomerProfile::factory()->create(), '10');
    $serialized = QualityFixtures::delivered($customer, '1');
    $serialized->forceFill(['serialized_inventory_unit_id' => SerializedInventoryUnit::factory()->create()->id])->save();
    $notDone = QualityFixtures::delivered($customer, '3');
    $notDone->operation->forceFill(['stage' => OperationStage::Ready])->save();

    $attach = fn (array $row) => $service->attach(QualityFixtures::ticket($customer), [$row]);

    expect(fn () => $attach(['original_inventory_operation_line_id' => $foreign->id, 'quantity' => '1']))->toThrow(ValidationException::class, 'not delivered to this customer')
        ->and(fn () => $attach(['original_inventory_operation_line_id' => $notDone->id, 'quantity' => '1']))->toThrow(ValidationException::class, 'not delivered to this customer')
        ->and(fn () => $attach(['original_inventory_operation_line_id' => 999999, 'quantity' => '1']))->toThrow(ValidationException::class, 'not delivered to this customer')
        ->and(fn () => $attach(['original_inventory_operation_line_id' => $serialized->id, 'quantity' => '1']))->toThrow(ValidationException::class, 'Serialized equipment')
        ->and(fn () => $attach(['original_inventory_operation_line_id' => $line->id, 'quantity' => '0']))->toThrow(ValidationException::class, 'affected quantity')
        ->and(fn () => $attach(['original_inventory_operation_line_id' => $line->id, 'quantity' => 'lots']))->toThrow(ValidationException::class, 'affected quantity')
        ->and(fn () => $attach(['original_inventory_operation_line_id' => $line->id, 'quantity' => '10.5']))->toThrow(ValidationException::class, 'exceeds what was delivered')
        ->and(fn () => $service->attach(QualityFixtures::ticket($customer), []))->toThrow(ValidationException::class, 'at least one');

    $ticket = QualityFixtures::ticket($customer);

    expect(fn () => $service->attach($ticket, [
        ['original_inventory_operation_line_id' => $line->id, 'quantity' => '1'],
        ['original_inventory_operation_line_id' => $line->id, 'quantity' => '1'],
    ]))->toThrow(ValidationException::class, 'only once')
        ->and(TicketProductContext::query()->count())->toBe(0);
});

it('only attaches context to open product quality tickets while the feature is on', function (): void {
    $customer = CustomerProfile::factory()->create();
    $line = QualityFixtures::delivered($customer);
    $service = app(TicketProductContextService::class);
    $row = ['original_inventory_operation_line_id' => $line->id, 'quantity' => '1'];

    expect(fn () => $service->attach(QualityFixtures::ticket($customer, TicketType::HardwareIssue), [$row]))->toThrow(ValidationException::class, 'Only a product quality complaint');

    foreach ([TicketStatus::Closed, TicketStatus::Cancelled] as $status) {
        $ticket = QualityFixtures::ticket($customer);
        $ticket->update(['status' => $status]);

        expect(fn () => $service->attach($ticket, [$row]))->toThrow(ValidationException::class, 'closed or cancelled');
    }

    config(['support.product_quality_enabled' => false]);

    expect(fn () => $service->attach(QualityFixtures::ticket($customer), [$row]))->toThrow(ValidationException::class, 'not enabled');
});

it('requires the quality permission for staff and none for the customer\'s own filing', function (): void {
    $customer = CustomerProfile::factory()->create();
    $line = QualityFixtures::delivered($customer);
    $row = [['original_inventory_operation_line_id' => $line->id, 'quantity' => '1']];
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');
    $service = app(TicketProductContextService::class);

    expect(fn () => $service->attach(QualityFixtures::ticket($customer), $row, $agent))->toThrow(AuthorizationException::class)
        ->and($service->attach(QualityFixtures::ticket($customer), $row, QualityFixtures::manager()))->toHaveCount(1)
        ->and($agent->can('viewAny', TicketProductContext::class))->toBeTrue()
        ->and($agent->can('view', TicketProductContext::class))->toBeTrue();

    config(['support.product_quality_enabled' => false]);

    expect(QualityFixtures::manager()->can('viewAny', TicketProductContext::class))->toBeFalse();
});

it('creates quality tickets with verified context through staff and customer intake', function (): void {
    $customerUser = User::factory()->customer()->create();
    $customer = CustomerProfile::factory()->create(['user_id' => $customerUser->id]);
    $line = QualityFixtures::delivered($customer, '10');
    $manager = QualityFixtures::manager();
    $intake = app(TicketIntakeService::class);
    $data = [
        'title' => 'Zirconia discs chipping',
        'description' => 'Several discs chip on milling.',
        'product_contexts' => [['original_inventory_operation_line_id' => $line->id, 'quantity' => '3']],
    ];

    $own = $intake->createForCustomer([...$data, 'type' => 'product_quality_issue'], $customerUser);

    expect($own->type)->toBe(TicketType::ProductQualityIssue)
        ->and($own->productContexts)->toHaveCount(1)
        ->and($own->productContexts->first()->inventory_lot_id)->toBe($line->inventory_lot_id);

    $staff = $intake->create([...$data, 'customer_id' => $customer->id, 'type' => TicketType::ProductQualityIssue, 'priority' => TicketPriority::Normal], $manager);

    expect($staff->productContexts)->toHaveCount(1);

    $foreignLine = QualityFixtures::delivered(CustomerProfile::factory()->create());

    expect(fn () => $intake->createForCustomer([...$data, 'type' => 'product_quality_issue', 'product_contexts' => [['original_inventory_operation_line_id' => $foreignLine->id, 'quantity' => '1']]], $customerUser))
        ->toThrow(ValidationException::class, 'not delivered to this customer')
        ->and(fn () => $intake->createForCustomer([...$data, 'type' => 'product_quality_issue', 'product_contexts' => []], $customerUser))
        ->toThrow(ValidationException::class, 'at least one');

    // Rejected complaints roll the ticket back.
    expect(Ticket::query()->where('type', 'product_quality_issue')->count())->toBe(2);

    // Other ticket types ignore product context entirely.
    $plain = $intake->createForCustomer([...$data, 'type' => 'general_support'], $customerUser);

    expect($plain->productContexts)->toHaveCount(0);
});

it('does not allow quality tickets while the feature is off', function (): void {
    $customerUser = User::factory()->customer()->create();
    $customer = CustomerProfile::factory()->create(['user_id' => $customerUser->id]);
    $line = QualityFixtures::delivered($customer);
    config(['support.product_quality_enabled' => false]);

    expect(fn () => app(TicketIntakeService::class)->createForCustomer([
        'type' => 'product_quality_issue',
        'title' => 'x',
        'description' => 'y',
        'product_contexts' => [['original_inventory_operation_line_id' => $line->id, 'quantity' => '1']],
    ], $customerUser))->toThrow(ValidationException::class, 'not enabled')
        ->and(Ticket::query()->count())->toBe(0);
});

it('raises one lot alert when open complaints reach the threshold and never quarantines', function (): void {
    Event::fake([SupportQualityMilestone::class]);
    $variant = ProductVariant::factory()->create();
    $lot = InventoryLot::factory()->create(['product_variant_id' => $variant->id, 'lot_number' => 'LOT-HOT']);
    $service = app(TicketProductContextService::class);
    $signals = app(LotQualitySignalService::class);
    $balancesBefore = $lot->conditionBalances()->get()->map->getAttributes()->all();

    foreach (range(1, 2) as $ignored) {
        $customer = CustomerProfile::factory()->create();
        $service->attach(QualityFixtures::ticket($customer), QualityFixtures::lines(QualityFixtures::delivered($customer, '10', $lot)));
    }

    expect($signals->openComplaints($lot))->toBe(2)
        ->and(LotQualityAlert::query()->count())->toBe(0)
        ->and($signals->evaluate($lot))->toBeNull();

    $third = CustomerProfile::factory()->create();
    $service->attach(QualityFixtures::ticket($third), QualityFixtures::lines(QualityFixtures::delivered($third, '10', $lot)));
    $alert = LotQualityAlert::query()->sole();

    expect($alert->inventory_lot_id)->toBe($lot->id)
        ->and($alert->open_complaints)->toBe(3)
        ->and($alert->threshold)->toBe(3)
        ->and($alert->inventoryLot->is($lot))->toBeTrue()
        ->and($alert->acknowledged_at)->toBeNull()
        ->and(Activity::query()->where('description', 'support.quality.lot_threshold_reached')->count())->toBe(1)
        // An alert only: the lot's stock conditions are untouched.
        ->and($lot->fresh()->conditionBalances()->get()->map->getAttributes()->all())->toBe($balancesBefore);

    $fourth = CustomerProfile::factory()->create();
    $service->attach(QualityFixtures::ticket($fourth), QualityFixtures::lines(QualityFixtures::delivered($fourth, '10', $lot)));

    expect(LotQualityAlert::query()->sole()->open_complaints)->toBe(4);

    Event::assertDispatchedTimes(SupportQualityMilestone::class, 5);
    Event::assertDispatched(SupportQualityMilestone::class, fn (SupportQualityMilestone $event): bool => $event->key === NotificationEventKey::LotComplaintThresholdReached && $event->subject->is($lot));
});

it('counts only open complaints toward the threshold and merges complaints across merged lots', function (): void {
    $variant = ProductVariant::factory()->create();
    $canonical = InventoryLot::factory()->create(['product_variant_id' => $variant->id]);
    $merged = InventoryLot::factory()->create(['product_variant_id' => $variant->id, 'canonical_inventory_lot_id' => $canonical->id]);
    $service = app(TicketProductContextService::class);
    $signals = app(LotQualitySignalService::class);

    $tickets = [];
    foreach ([$canonical, $merged, $merged] as $lot) {
        $customer = CustomerProfile::factory()->create();
        $ticket = QualityFixtures::ticket($customer);
        $service->attach($ticket, QualityFixtures::lines(QualityFixtures::delivered($customer, '10', $lot)));
        $tickets[] = $ticket;
    }

    expect($signals->openComplaints($canonical))->toBe(3)
        ->and($signals->openComplaints($merged))->toBe(3)
        ->and($service->canonical($merged)->id)->toBe($canonical->id)
        ->and($service->canonical($canonical)->id)->toBe($canonical->id)
        ->and(LotQualityAlert::query()->sole()->inventory_lot_id)->toBe($canonical->id);

    $tickets[0]->update(['status' => TicketStatus::Resolved]);

    expect($signals->openComplaints($canonical))->toBe(2);
});

it('summarises the lot quality signals and lets a manager acknowledge the alert', function (): void {
    $lot = InventoryLot::factory()->create();
    $service = app(TicketProductContextService::class);
    $signals = app(LotQualitySignalService::class);
    $manager = QualityFixtures::manager();
    $resolutions = app(TicketQualityResolutionService::class);

    $first = CustomerProfile::factory()->create();
    $second = CustomerProfile::factory()->create();
    $tickets = [];
    foreach ([$first, $first, $second] as $customer) {
        $ticket = QualityFixtures::ticket($customer);
        $service->attach($ticket, [['original_inventory_operation_line_id' => QualityFixtures::delivered($customer, '10', $lot)->id, 'quantity' => '2.5']]);
        $tickets[] = $ticket;
    }

    $resolutions->resolve($tickets[0], QualityResolutionType::CustomerReturn, $manager, ['notes' => 'Return the discs']);

    $summary = $signals->summary($lot);

    expect($summary['open_complaints'])->toBe(3)
        ->and($summary['total_complaints'])->toBe(3)
        ->and($summary['affected_customers'])->toBe(2)
        ->and($summary['affected_quantity'])->toBe('7.500000')
        ->and($summary['returns'])->toBe(1)
        ->and($summary['alert'])->toBeInstanceOf(LotQualityAlert::class);

    $alert = $signals->acknowledge($summary['alert'], $manager);
    $again = $signals->acknowledge($alert, $manager);

    expect($alert->acknowledged_at)->not->toBeNull()
        ->and($again->acknowledged_by)->toBe($manager->id)
        ->and(Activity::query()->where('description', 'support.quality.lot_alert_acknowledged')->count())->toBe(1);

    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    expect(fn () => $signals->acknowledge($alert, $agent))->toThrow(AuthorizationException::class);

    config(['support.product_quality_enabled' => false]);

    expect($signals->evaluate($lot))->toBeNull();
});

it('treats a threshold below one as one', function (): void {
    config(['support.lot_complaint_threshold' => 0]);
    $lot = InventoryLot::factory()->create();
    $customer = CustomerProfile::factory()->create();

    app(TicketProductContextService::class)->attach(QualityFixtures::ticket($customer), QualityFixtures::lines(QualityFixtures::delivered($customer, '10', $lot)));

    expect(LotQualityAlert::query()->sole()->threshold)->toBe(1);
});

function complaintWithContext(string $quantity = '2', ?InventoryLot $lot = null): Ticket
{
    $customer = CustomerProfile::factory()->create();
    $ticket = QualityFixtures::ticket($customer);
    app(TicketProductContextService::class)->attach($ticket, [['original_inventory_operation_line_id' => QualityFixtures::delivered($customer, '10', $lot)->id, 'quantity' => $quantity]]);

    return $ticket;
}

it('resolves a complaint as no defect found, replacement or lot investigation without touching returns', function (): void {
    $manager = QualityFixtures::manager();
    $service = app(TicketQualityResolutionService::class);

    foreach ([QualityResolutionType::NoDefectFound, QualityResolutionType::Replacement, QualityResolutionType::LotInvestigation] as $type) {
        $resolution = $service->resolve(complaintWithContext(), $type, $manager, ['notes' => 'Checked with the customer']);

        expect($resolution->resolution_type)->toBe($type)
            ->and($resolution->resolved_by)->toBe($manager->id)
            ->and($resolution->customer_return_request_id)->toBeNull()
            ->and($resolution->resolvedBy->is($manager))->toBeTrue()
            ->and($resolution->ticket->qualityResolution->is($resolution))->toBeTrue();
    }

    expect(CustomerReturnRequest::query()->count())->toBe(0)
        ->and(Activity::query()->where('description', 'support.quality_complaint.resolved')->count())->toBe(3);
});

it('submits a customer return through the existing return request service, never moving stock', function (): void {
    $customer = CustomerProfile::factory()->create();
    $line = QualityFixtures::delivered($customer, '10');
    $ticket = QualityFixtures::ticket($customer);
    app(TicketProductContextService::class)->attach($ticket, [['original_inventory_operation_line_id' => $line->id, 'quantity' => '4', 'notes' => 'Cracked']]);

    $resolution = app(TicketQualityResolutionService::class)->resolve($ticket, QualityResolutionType::CustomerReturn, QualityFixtures::manager(), ['notes' => 'Customer sends the discs back']);
    $request = $resolution->customerReturnRequest;

    expect($request)->toBeInstanceOf(CustomerReturnRequest::class)
        ->and($request->status)->toBe(CustomerReturnRequestStatus::Submitted)
        ->and($request->customer_id)->toBe($customer->id)
        ->and($request->original_inventory_operation_id)->toBe($line->inventory_operation_id)
        ->and($request->reason)->toContain($ticket->ticket_number)
        ->and($request->lines)->toHaveCount(1)
        ->and((string) $request->lines->first()->requested_quantity)->toBe('4.000000')
        ->and($request->lines->first()->customer_note)->toBe('Cracked')
        ->and($request->resulting_inventory_return_id)->toBeNull()
        ->and(InventoryReturn::query()->count())->toBe(0);
});

it('refuses a customer return that spans deliveries or that the customer cannot make', function (): void {
    $manager = QualityFixtures::manager();
    $service = app(TicketQualityResolutionService::class);
    $customer = CustomerProfile::factory()->create();
    $ticket = QualityFixtures::ticket($customer);
    app(TicketProductContextService::class)->attach($ticket, QualityFixtures::lines(QualityFixtures::delivered($customer), QualityFixtures::delivered($customer)));

    expect(fn () => $service->resolve($ticket, QualityResolutionType::CustomerReturn, $manager, ['notes' => 'x']))->toThrow(ValidationException::class, 'single delivery');

    $unapproved = CustomerProfile::factory()->pending()->create();
    $second = QualityFixtures::ticket($unapproved);
    app(TicketProductContextService::class)->attach($second, QualityFixtures::lines(QualityFixtures::delivered($unapproved)));

    expect(fn () => $service->resolve($second, QualityResolutionType::CustomerReturn, $manager, ['notes' => 'x']))->toThrow(ValidationException::class, 'approved, active customer')
        ->and(TicketQualityResolution::query()->count())->toBe(0);
});

it('links an existing return request for refunds and credit notes, but only the customer\'s own', function (): void {
    $manager = QualityFixtures::manager();
    $service = app(TicketQualityResolutionService::class);
    $ticket = complaintWithContext();
    $own = CustomerReturnRequest::factory()->create(['customer_id' => $ticket->customer_id]);
    $foreign = CustomerReturnRequest::factory()->create();

    expect(fn () => $service->resolve($ticket, QualityResolutionType::Refund, $manager, ['notes' => 'x', 'customer_return_request_id' => $foreign->id]))->toThrow(ValidationException::class, 'different customer')
        ->and(fn () => $service->resolve($ticket, QualityResolutionType::Refund, $manager, ['notes' => 'x', 'customer_return_request_id' => 999999]))->toThrow(ValidationException::class, 'different customer');

    $refund = $service->resolve($ticket, QualityResolutionType::Refund, $manager, ['notes' => 'Refund via Sales', 'customer_return_request_id' => $own->id]);

    expect($refund->customer_return_request_id)->toBe($own->id);

    $credit = $service->resolve(complaintWithContext(), QualityResolutionType::CreditNote, $manager, ['notes' => 'Credit note via Sales']);

    expect($credit->customer_return_request_id)->toBeNull()
        ->and(QualityResolutionType::SupplierClaim->mayLinkReturnRequest())->toBeFalse()
        ->and(QualityResolutionType::Refund->mayLinkReturnRequest())->toBeTrue();
});

it('identifies the responsible supplier from the lot purchase origin or an explicit choice', function (): void {
    $manager = QualityFixtures::manager();
    $service = app(TicketQualityResolutionService::class);
    $supplier = Supplier::factory()->create();
    $receipt = InventoryOperation::factory()->receipt()->done()->create(['supplier_id' => $supplier->id]);
    $variant = ProductVariant::factory()->create();
    $traced = InventoryLot::factory()->create(['product_variant_id' => $variant->id, 'origin_source_type' => 'inventory_operation', 'origin_source_id' => $receipt->id]);
    $untraced = InventoryLot::factory()->create(['product_variant_id' => $variant->id]);

    $claim = $service->resolve(complaintWithContext('2', $traced), QualityResolutionType::SupplierClaim, $manager, ['notes' => 'Claim the batch']);

    expect($claim->supplier_id)->toBe($supplier->id)
        ->and($claim->supplier->is($supplier))->toBeTrue()
        ->and(app(TicketProductContextService::class)->supplierForLot($traced)?->is($supplier))->toBeTrue()
        ->and(app(TicketProductContextService::class)->supplierForLot($untraced))->toBeNull();

    expect(fn () => $service->resolve(complaintWithContext('2', $untraced), QualityResolutionType::SupplierClaim, $manager, ['notes' => 'x']))->toThrow(ValidationException::class, 'No supplier could be identified');

    $chosen = Supplier::factory()->create();
    $explicit = $service->resolve(complaintWithContext('2', $untraced), QualityResolutionType::SupplierClaim, $manager, ['notes' => 'x', 'supplier_id' => $chosen->id]);

    expect($explicit->supplier_id)->toBe($chosen->id)
        ->and(fn () => $service->resolve(complaintWithContext('2', $untraced), QualityResolutionType::SupplierClaim, $manager, ['notes' => 'x', 'supplier_id' => Supplier::factory()->create(['is_active' => false])->id]))->toThrow(ValidationException::class, 'active supplier');
});

it('guards resolutions: notes, ticket type, context, single resolution, lots and permissions', function (): void {
    $manager = QualityFixtures::manager();
    $service = app(TicketQualityResolutionService::class);
    $ticket = complaintWithContext();

    expect(fn () => $service->resolve($ticket, QualityResolutionType::NoDefectFound, $manager, ['notes' => '  ']))->toThrow(ValidationException::class, 'notes are required')
        ->and(fn () => $service->resolve(QualityFixtures::ticket(CustomerProfile::factory()->create()), QualityResolutionType::NoDefectFound, $manager, ['notes' => 'x']))->toThrow(ValidationException::class, 'no product context');

    $other = QualityFixtures::ticket(CustomerProfile::factory()->create(), TicketType::HardwareIssue);

    expect(fn () => $service->resolve($other, QualityResolutionType::NoDefectFound, $manager, ['notes' => 'x']))->toThrow(ValidationException::class, 'Only a product quality complaint');

    $cancelled = complaintWithContext();
    $cancelled->update(['status' => TicketStatus::Cancelled]);
    expect(fn () => $service->resolve($cancelled, QualityResolutionType::NoDefectFound, $manager, ['notes' => 'x']))->toThrow(ValidationException::class, 'cancelled');

    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');
    expect(fn () => $service->resolve($ticket, QualityResolutionType::NoDefectFound, $agent, ['notes' => 'x']))->toThrow(AuthorizationException::class);

    $service->resolve($ticket, QualityResolutionType::NoDefectFound, $manager, ['notes' => 'x']);
    expect(fn () => $service->resolve($ticket, QualityResolutionType::Replacement, $manager, ['notes' => 'x']))->toThrow(ValidationException::class, 'already has a resolution');

    $noLot = complaintWithContext();
    $noLot->productContexts()->update(['inventory_lot_id' => null]);
    expect(fn () => $service->resolve($noLot, QualityResolutionType::LotInvestigation, $manager, ['notes' => 'x']))->toThrow(ValidationException::class, 'with a lot');

    config(['support.product_quality_enabled' => false]);
    expect($manager->can('create', TicketQualityResolution::class))->toBeFalse();
});
