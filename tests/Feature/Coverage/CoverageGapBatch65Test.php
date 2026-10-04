<?php

declare(strict_types=1);

use App\Enums\PaymentLinkStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStartTrigger;
use App\Filament\Resources\Tickets\Actions\TriageTicketAction;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\Tables\TicketsTable;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Services\Support\TicketProviderSettlementService;
use App\Services\Support\TicketWorkspaceStateResolver;
use Database\Seeders\SlaPolicySeeder;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    (new SlaPolicySeeder)->run();
    $this->paymentMethod = configurePaymentAccounting();
    $this->actor = User::factory()->admin()->create();
    $this->actingAs($this->actor);
});

function batch65Invoke(string $class, string $method, mixed $instance, mixed ...$arguments): mixed
{
    return new ReflectionMethod($class, $method)->invoke($instance, ...$arguments);
}

it('covers warranty entitlement active-window evaluation', function (): void {
    $entitlement = WarrantyEntitlement::factory()->create([
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => today()->subDay(),
        'expires_on' => today()->addDay(),
    ]);

    expect($entitlement->isActiveAt())->toBeTrue()
        ->and($entitlement->isActiveAt(today()->subDays(3)))->toBeFalse();

    $entitlement->forceFill(['state' => WarrantyEntitlementState::Ended])->save();
    expect($entitlement->refresh()->isActiveAt())->toBeFalse();

    $entitlement->forceFill([
        'state' => WarrantyEntitlementState::Active,
        'starts_on' => null,
        'expires_on' => null,
    ])->save();
    expect($entitlement->refresh()->isActiveAt())->toBeFalse();
});

it('rejects a provider settlement whose amount does not match the ticket charge', function (): void {
    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->create([
        'amount' => '75.00',
        'currency' => 'AED',
    ]);

    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $ticket->customer_id,
        'purpose_type' => TicketPaymentLink::class,
        'purpose_id' => $link->getKey(),
        'amount_minor' => 7400,
        'currency' => 'AED',
    ]);

    expect(fn () => app(TicketProviderSettlementService::class)->settle($transaction))
        ->toThrow(DomainException::class, 'does not match');
});

it('covers view-ticket assignment settlement and transition defensive branches', function (): void {
    $employee = EmployeeProfile::factory()->create();

    $pending = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $pendingPage = Livewire::actingAs($this->actor)
        ->test(ViewTicket::class, ['record' => $pending->getKey()])
        ->instance();

    $assign = batch65Invoke(ViewTicket::class, 'makeAssignAction', $pendingPage);
    ($assign->getActionFunction())(['employee_id' => $employee->getKey()]);
    expect($pending->refresh()->status)->toBe(TicketStatus::Pending);

    $chargeable = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($chargeable)->settled()->create([
        'payment_id' => null,
    ]);
    $paymentPage = Livewire::actingAs($this->actor)
        ->test(ViewTicket::class, ['record' => $chargeable->getKey()])
        ->instance();

    $settle = batch65Invoke(ViewTicket::class, 'makeSettlePaymentAction', $paymentPage);
    ($settle->getActionFunction())([]);
    ($settle->getActionFunction())([
        'payment_method_id' => $this->paymentMethod->getKey(),
        'payment_method_reference' => 'STALE-LINK',
    ]);
    expect($link->refresh()->status)->toBe(PaymentLinkStatus::Settled);

    $transition = batch65Invoke(
        ViewTicket::class,
        'transitionAction',
        $pendingPage,
        'coverageInvalidTransition',
        'Coverage Invalid Transition',
        TicketStatus::InProgress,
    );
    ($transition->getActionFunction())([]);

    expect($pending->refresh()->status)->toBe(TicketStatus::Pending);
});

it('covers ticket-table maintenance next action settlement catch actor guard and equipment label', function (): void {
    $maintenanceTicket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'service_path' => TicketServicePath::Maintenance,
    ]);

    expect(app(TicketWorkspaceStateResolver::class)->resolve($maintenanceTicket)->nextAction)
        ->toBe('Raise the maintenance job');

    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->settled()->create(['payment_id' => null]);

    batch65Invoke(
        TicketsTable::class,
        'applySettlement',
        null,
        $ticket->refresh(),
        'ALREADY-SETTLED',
        (int) $this->paymentMethod->getKey(),
    );
    expect($link->refresh()->status)->toBe(PaymentLinkStatus::Settled);

    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['name' => 'Covered Equipment']);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
        'serial_number' => 'COVER-SERIAL-65',
    ]);
    $equipmentTicket = Ticket::factory()->for($customer, 'customer')->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'status' => TicketStatus::Live,
    ]);

    Livewire::actingAs($this->actor)
        ->test(ListTickets::class)
        ->assertCanSeeTableRecords([$equipmentTicket])
        ->assertTableColumnStateSet('equipment', 'Covered Equipment · SN COVER-SERIAL-65', $equipmentTicket);

    auth()->logout();
    expect(fn (): mixed => batch65Invoke(TicketsTable::class, 'currentActor', null))
        ->toThrow(LogicException::class, 'authenticated User');
});

it('covers triage equipment missing-customer covered warranty and entitlement policy preview', function (): void {
    $equipmentOptions = new ReflectionMethod(TriageTicketAction::class, 'equipmentOptions');

    $noCustomer = new Ticket;
    $noCustomer->setRelation('customer', null);

    expect($equipmentOptions->invoke(null, $noCustomer))->toBe([]);

    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create(['name' => 'Warranty Device']);
    $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
        'serial_number' => 'WAR-65',
    ]);
    $ticket = Ticket::factory()->for($customer, 'customer')->create();

    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'customer_id' => $customer->getKey(),
        'state' => WarrantyEntitlementState::Active,
        'policy_name' => 'Premium Warranty',
        'duration_value' => 12,
        'duration_unit' => WarrantyDurationUnit::Months,
        'start_trigger' => WarrantyStartTrigger::ConfirmedDelivery,
        'starts_on' => today()->subMonth(),
        'expires_on' => today()->addMonths(11),
    ]);

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(
        static fn (string $path): mixed => match ($path) {
            'equipment_source' => 'sold_by_us',
            'serialized_inventory_unit_id' => $unit->getKey(),
            default => null,
        },
    );

    $warrantyPreview = new ReflectionMethod(TriageTicketAction::class, 'warrantyPreview');
    expect($warrantyPreview->invoke(null, $ticket, $get))
        ->toContain('Warranty active', 'until');

    $policyPreview = new ReflectionMethod(TriageTicketAction::class, 'policyPreview');
    expect($policyPreview->invoke(null, $ticket, $get))
        ->toContain('Premium Warranty');
});
