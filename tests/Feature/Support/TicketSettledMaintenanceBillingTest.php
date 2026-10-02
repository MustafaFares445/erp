<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Models\Invoice;
use App\Models\MaintenanceRecord;
use App\Models\TaxRecognitionEntry;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Support\MaintenanceBillingService;
use App\Services\Support\TicketPaymentService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
    $this->paymentMethod = configurePaymentAccounting(taxPercent: 5.0);
});

function ticketSettledBillingManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

it('posts and applies a settled ticket fee through the invoice, tax and journal workflow', function (): void {
    $manager = ticketSettledBillingManager();
    $settler = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = app(TicketPaymentService::class)->createForTicket($ticket, 105.00, 'AED');
    app(TicketPaymentService::class)->settle($link, 'REF-TICKET-FEE', $settler, $this->paymentMethod->getKey());

    $record = MaintenanceRecord::factory()->for($ticket)->create([
        'customer_id' => $ticket->customer_id,
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    app(MaintenanceBillingService::class)->markTicketSettled($record, $manager, 'Support fee already collected on ticket.');

    $record->refresh();
    $invoice = Invoice::query()->findOrFail($record->invoice_id);
    $paymentId = $link->refresh()->payment_id;

    expect($record->billing_type)->toBe(MaintenanceBillingType::TicketSettled)
        ->and($record->billed_at)->not->toBeNull()
        ->and($invoice->maintenance_record_id)->toBe($record->getKey())
        ->and((float) $invoice->total_amount)->toBe(105.0)
        ->and((float) $invoice->amount_paid)->toBe(105.0)
        ->and($invoice->outstandingAmount())->toBe(0.0)
        ->and($paymentId)->not->toBeNull()
        ->and(TaxRecognitionEntry::query()->where('payment_id', $paymentId)->where('invoice_id', $invoice->getKey())->count())->toBe(1)
        ->and((float) TaxRecognitionEntry::query()->where('payment_id', $paymentId)->value('recognised_tax_amount'))->toBe(5.0);
});

it('rejects ticket-settled billing when the linked ticket payment is not settled', function (): void {
    $manager = ticketSettledBillingManager();
    $ticket = Ticket::factory()->chargeable()->create();
    TicketPaymentLink::factory()->for($ticket)->create();
    $record = MaintenanceRecord::factory()->for($ticket)->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    expect(fn () => app(MaintenanceBillingService::class)->markTicketSettled($record, $manager, 'Attempt before settlement.'))
        ->toThrow(ValidationException::class);

    expect($record->refresh()->billing_type)->toBe(MaintenanceBillingType::Unbilled)
        ->and($record->billed_at)->toBeNull();
});
