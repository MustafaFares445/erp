<?php

declare(strict_types=1);

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\ReconciliationScope;
use App\Enums\ReplenishmentCoverageSourceType;
use App\Enums\ReplenishmentCoverageStatus;
use App\Enums\ReplenishmentRequirementStatus;
use App\Filament\Resources\InventoryCorrections\Pages\ManageInventoryCorrections;
use App\Filament\Resources\SupplierPayments\Pages\ManageSupplierPayments;
use App\Models\ChartAccount;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\ReconciliationRun;
use App\Models\ReplenishmentRequirement;
use App\Models\SalesSetting;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Notifications\BusinessNotification;
use App\Services\Inventory\ReconciliationReportService;
use App\Services\Inventory\ReplenishmentCoverageService;
use App\Services\Payments\PaymentPostingService;
use App\Services\Sales\InvoiceConfirmationService;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function lowCoverageMethod(string $class, string $method): ReflectionMethod
{
    return new ReflectionMethod($class, $method);
}

it('covers reconciliation report filter normalization and validation', function (): void {
    $service = app(ReconciliationReportService::class);
    $scope = ReconciliationScope::cases()[0];

    ReconciliationRun::query()->create([
        'scope' => $scope,
        'invariant' => 'coverage-pass',
        'passed' => true,
        'divergence_count' => 0,
        'detail' => null,
        'started_at' => now(),
        'finished_at' => now(),
        'trigger_source' => 'manual',
    ]);
    ReconciliationRun::query()->create([
        'scope' => $scope,
        'invariant' => 'coverage-fail',
        'passed' => false,
        'divergence_count' => 1,
        'detail' => ['difference'],
        'started_at' => now(),
        'finished_at' => now(),
        'trigger_source' => 'schedule',
    ]);

    expect($service->hasPersistedRuns())->toBeTrue()
        ->and($service->query([
            'scope' => $scope->value,
            'passed' => 'true',
            'trigger_source' => 'manual',
            'from' => today()->toDateString(),
            'until' => today()->toDateString(),
        ])->count())->toBe(1)
        ->and($service->divergences([
            'scope' => 'not-a-scope',
            'passed' => 'not-bool',
            'trigger_source' => 'invalid',
        ])->count())->toBe(1);

    expect(fn () => $service->query(['from' => '18-09-2026']))
        ->toThrow(DomainException::class, 'YYYY-MM-DD')
        ->and(fn () => $service->query(['from' => '2026-09-19', 'until' => '2026-09-18']))
        ->toThrow(DomainException::class, 'start date');
});

it('covers business notification channels mail options array payload and failure persistence', function (): void {
    $mail = new BusinessNotification(
        deliveryId: 999,
        channel: NotificationChannel::Mail,
        subject: 'Coverage subject',
        body: 'Coverage body',
        attachments: [
            ['path' => ''],
            ['path' => __FILE__, 'name' => 'coverage.txt'],
            ['path' => __FILE__, 'mime' => 'text/plain'],
            ['path' => __FILE__, 'name' => 'coverage-2.txt', 'mime' => 'text/plain'],
        ],
    );

    expect($mail->via(null))->toBe(['mail'])
        ->and(new BusinessNotification(1, NotificationChannel::Database, null, 'body')->via(null))->toBe(['database'])
        ->and(new BusinessNotification(1, NotificationChannel::Sms, null, 'body')->via(null))->toBe([])
        ->and(new BusinessNotification(1, NotificationChannel::Whatsapp, null, 'body')->via(null))->toBe([])
        ->and($mail->toMail(null))->not->toBeNull()
        ->and($mail->toArray(null)['attachments'])->toHaveCount(4);

    $delivery = NotificationDelivery::query()->create([
        'notifiable_type' => User::class,
        'notifiable_id' => User::factory()->create()->getKey(),
        'template_key' => 'coverage.notification',
        'channel' => NotificationChannel::Mail,
        'locale' => 'en',
        'route' => 'coverage@example.test',
        'status' => NotificationDeliveryStatus::Queued,
        'attempt' => 1,
    ]);

    $notification = new BusinessNotification(
        (int) $delivery->getKey(),
        NotificationChannel::Mail,
        '',
        'body',
    );
    $notification->toMail(null);
    $notification->failed(new RuntimeException(str_repeat('x', 600)));

    $delivery->refresh();
    expect($delivery->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($delivery->error)->toHaveLength(500)
        ->and($delivery->failed_at)->not->toBeNull();
});

it('covers supplier payment page normalization auth guard and valid creation', function (): void {
    Gate::before(static fn (): bool => true);

    $normalize = lowCoverageMethod(ManageSupplierPayments::class, 'normalizeData');
    expect($normalize->invoke(null, null))->toBe([])
        ->and($normalize->invoke(null, [0 => 'skip', 'amount' => '10.00']))->toBe(['amount' => '10.00']);

    $page = new ReflectionClass(ManageSupplierPayments::class)->newInstanceWithoutConstructor();
    $actions = lowCoverageMethod(ManageSupplierPayments::class, 'getHeaderActions')->invoke($page);
    $action = $actions[0];
    expect($action)->toBeInstanceOf(CreateAction::class);

    auth()->logout();
    expect(fn () => $action->process(null, ['data' => []]))
        ->toThrow(LogicException::class, 'authenticated accounting user');

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $supplier = Supplier::factory()->create();
    $method = PaymentMethod::factory()->create();

    $created = $action->process(null, ['data' => [
        'supplier_id' => $supplier->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => '25.00',
        'payment_date' => today()->toDateString(),
        'reference' => ' COVERAGE ',
    ]]);

    expect($created)->toBeInstanceOf(SupplierPayment::class)
        ->and($created->supplier_id)->toBe($supplier->getKey());
});

it('covers inventory correction page input guards and valid receipt correction creation', function (): void {
    $page = new ReflectionClass(ManageInventoryCorrections::class)->newInstanceWithoutConstructor();
    $action = lowCoverageMethod(ManageInventoryCorrections::class, 'getHeaderActions')->invoke($page)[0];

    auth()->logout();
    expect(fn () => $action->process(null, ['data' => []]))
        ->toThrow(LogicException::class, 'authenticated inventory correction actor');

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    expect(fn () => $action->process(null, ['data' => ['original_inventory_operation_id' => 'x']]))
        ->toThrow(DomainException::class, 'completed receipt and correction reason');

    $receipt = InventoryOperation::factory()->receipt()->done()->create();
    $created = $action->process(null, ['data' => [
        'original_inventory_operation_id' => $receipt->getKey(),
        'reason' => 'Coverage correction',
        'notes' => '  coverage note  ',
    ]]);

    expect($created->original_inventory_operation_id)->toBe($receipt->getKey())
        ->and($created->notes)->toBe('coverage note');
});

it('covers payment posting collection settlement deposit and no-allocation guards', function (): void {
    Gate::before(static fn (): bool => true);
    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
    FiscalPeriod::factory()->create();
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();

    $receivable = ChartAccount::factory()->create();
    $deposits = ChartAccount::factory()->create();
    SalesSetting::query()->updateOrCreate([], [
        'default_tax_percent' => '0.00',
        'default_quotation_validity_days' => 30,
        'receivable_account_id' => $receivable->getKey(),
        'customer_deposits_account_id' => $deposits->getKey(),
    ]);

    $collection = ChartAccount::factory()->create();
    $method = PaymentMethod::factory()->create([
        'chart_account_id' => $collection->getKey(),
        'is_active' => true,
    ]);

    $makePayment = (static fn (string $number, string $amount): Payment => Payment::query()->forceCreate([
        'payment_number' => $number,
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => $amount,
        'currency' => 'AED',
        'payment_date' => today(),
        'status' => 'draft',
    ]));

    $service = app(PaymentPostingService::class);

    $zero = $makePayment('PAY-COV-ZERO', '0.00');
    expect(fn () => $service->post($actor, $zero, 0.0))
        ->toThrow(DomainException::class, 'either settle a receivable or create a customer deposit');

    $depositPayment = $makePayment('PAY-COV-DEP', '100.00');
    expect($service->post($actor, $depositPayment, 0.0)->lines()->count())->toBe(2);

    $settlementPayment = $makePayment('PAY-COV-AR', '100.00');
    expect($service->post($actor, $settlementPayment, 100.0)->lines()->count())->toBe(2);

    $inactiveMethod = PaymentMethod::factory()->create(['is_active' => false]);
    $invalid = Payment::query()->forceCreate([
        'payment_number' => 'PAY-COV-BAD',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $inactiveMethod->getKey(),
        'amount' => '10.00',
        'currency' => 'AED',
        'payment_date' => today(),
        'status' => 'draft',
    ]);

    expect(fn () => $service->post($actor, $invalid, 10.0))
        ->toThrow(DomainException::class, 'active payment method');
});

it('covers invoice confirmation unsupported and lifecycle guards plus valid confirmation', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $service = app(InvoiceConfirmationService::class);

    $draft = Invoice::factory()->create(['status' => InvoiceStatus::Draft, 'issued_at' => null]);
    expect(fn () => $service->confirm($actor, $draft, 'unsupported'))
        ->toThrow(DomainException::class, 'Unsupported invoice receipt confirmation type')
        ->and(fn () => $service->confirm($actor, $draft, InvoiceConfirmationType::CustomerReceived))
        ->toThrow(DomainException::class, 'Only a sent invoice');

    $sent = Invoice::factory()->create([
        'status' => InvoiceStatus::Sent,
        'issued_at' => now()->subHour(),
        'sent_at' => now()->subMinutes(30),
    ]);

    $confirmation = $service->confirm(
        $actor,
        $sent,
        InvoiceConfirmationType::EmployeeConfirmedReceived,
        'Coverage received',
    );

    expect($confirmation->confirmation_type)->toBe(InvoiceConfirmationType::EmployeeConfirmedReceived)
        ->and($sent->refresh()->received_confirmation_type)->toBe(InvoiceConfirmationType::EmployeeConfirmedReceived);
});

it('covers replenishment coverage guards update transition and idempotent transitions', function (): void {
    $service = app(ReplenishmentCoverageService::class);
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $policy = WarehouseReplenishmentPolicy::withoutEvents(fn (): WarehouseReplenishmentPolicy => WarehouseReplenishmentPolicy::query()->forceCreate([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'min_quantity' => '1.000000',
        'max_quantity' => '10.000000',
        'is_active' => true,
    ]));

    $requirement = ReplenishmentRequirement::query()->create([
        'warehouse_replenishment_policy_id' => $policy->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => '10.000000',
        'covered_base_quantity' => '0.000000',
        'fulfilled_base_quantity' => '0.000000',
        'status' => ReplenishmentRequirementStatus::Open,
        'triggered_at' => now(),
    ]);

    expect(fn () => $service->attach($requirement, ReplenishmentCoverageSourceType::InternalTransfer, 0, 1))
        ->toThrow(DomainException::class, 'valid source id')
        ->and(fn () => $service->attach($requirement, ReplenishmentCoverageSourceType::InternalTransfer, 1, 0))
        ->toThrow(DomainException::class, 'greater than zero');

    $coverage = $service->attach(
        $requirement,
        ReplenishmentCoverageSourceType::InternalTransfer,
        10,
        4,
    );

    expect($coverage->status)->toBe(ReplenishmentCoverageStatus::Active)
        ->and((float) $requirement->refresh()->covered_base_quantity)->toBe(4.0);

    $same = $service->attach(
        $requirement->refresh(),
        ReplenishmentCoverageSourceType::InternalTransfer,
        10,
        6,
    );
    expect((float) $same->covered_base_quantity)->toBe(6.0);

    expect(fn () => $service->attach(
        $requirement->refresh(),
        ReplenishmentCoverageSourceType::PurchaseOrderLine,
        11,
        5,
    ))->toThrow(DomainException::class, 'cannot exceed');

    $released = $service->release($same);
    expect($released->status)->toBe(ReplenishmentCoverageStatus::Released)
        ->and($service->release($released)->status)->toBe(ReplenishmentCoverageStatus::Released);

    $fulfilledRequirement = ReplenishmentRequirement::query()->create([
        'warehouse_replenishment_policy_id' => $policy->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => '1.000000',
        'covered_base_quantity' => '1.000000',
        'fulfilled_base_quantity' => '1.000000',
        'status' => ReplenishmentRequirementStatus::Fulfilled,
        'triggered_at' => now(),
        'resolved_at' => now(),
    ]);

    expect(fn () => $service->attach(
        $fulfilledRequirement,
        ReplenishmentCoverageSourceType::SupplierReplacement,
        12,
        1,
    ))->toThrow(DomainException::class, 'terminal replenishment requirement');
});
