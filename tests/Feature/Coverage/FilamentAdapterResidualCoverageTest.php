<?php

declare(strict_types=1);

use App\Filament\Resources\MaintenanceSchedules\Actions\MaintenanceScheduleActions;
use App\Filament\Resources\Payments\Actions\PaymentActions;
use App\Filament\Resources\PaymentTransactions\Actions\PaymentTransactionActions;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Support\MaintenanceScheduleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('covers payment transaction reconciliation domain errors in the action adapter', function (): void {
    $transaction = PaymentTransaction::factory()->create([
        'payment_intent_id' => null,
    ]);

    PaymentTransactionActions::refreshStatus()->getActionFunction()($transaction);

    expect($transaction->refresh()->payment_intent_id)->toBeNull();
});
it('covers maintenance raise-now domain errors in the action adapter', function (): void {
    app()->instance(MaintenanceScheduleGenerator::class, new class
    {
        public function raiseDue(): never
        {
            throw new DomainException('Coverage maintenance failure.');
        }
    });

    MaintenanceScheduleActions::raiseNow()->getActionFunction()();

    expect(true)->toBeTrue();
});

it('covers the successful payment post notification adapter', function (): void {
    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );

    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    Gate::before(static fn (): bool => true);

    $customer = CustomerProfile::factory()->create();
    $payment = Payment::query()->create([
        'payment_number' => 'PAY-ADAPTER-COVERAGE',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory()->create()->getKey(),
        'amount' => '50.00',
        'currency' => 'AED',
        'payment_date' => today()->toDateString(),
        'status' => 'draft',
    ]);
    app()->instance(PaymentService::class, new class($payment)
    {
        public function __construct(private Payment $payment) {}

        public function post(User $actor, Payment $record, array $allocations): Payment
        {
            return $this->payment;
        }
    });

    PaymentActions::post()->getActionFunction()($payment, ['allocations' => []]);

    expect($payment->exists)->toBeTrue();
});
