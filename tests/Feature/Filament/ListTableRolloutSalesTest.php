<?php

declare(strict_types=1);

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use App\Enums\CustomerApprovalStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\OperationStage;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Models\Concerns\Favoritable;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SystemPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
    (new CrmPermissionSeeder)->run();

    $this->admin = User::factory()->admin()->create();
    $this->admin->assignRole('System Admin');
    $this->actingAs($this->admin);
});

function salesRolloutLead(LeadStatus $status): Lead
{
    static $sequence = 0;
    $sequence++;

    $lead = new Lead;
    $lead->forceFill([
        'lead_number' => sprintf('LEAD-ROLL-%04d', $sequence),
        'status' => $status,
        'source' => LeadSource::Website,
        'first_name' => 'Rollout',
        'last_name' => "Lead {$sequence}",
        'email' => "rollout-lead-{$sequence}@example.test",
        'created_by' => auth()->id(),
    ])->save();

    return $lead;
}

function salesRolloutPayment(PaymentStatus $status): Payment
{
    static $sequence = 0;
    $sequence++;

    return Payment::factory()->create([
        'payment_number' => sprintf('PAY-ROLL-%04d', $sequence),
        'customer_id' => CustomerProfile::factory(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '100.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => today(),
        'status' => $status,
    ]);
}

/**
 * Each page gets two records: one matching the value used by the
 * query-builder rule, one that must be filtered out.
 *
 * @return array<string, array{class-string, string, Closure(): array{Model&Favoritable, Model&Favoritable}, string}>
 */
function salesRolloutPages(): array
{
    return [
        'invoices' => [ListInvoices::class, 'status', static fn (): array => [
            Invoice::factory()->create(['status' => InvoiceStatus::Draft]),
            Invoice::factory()->create(['status' => InvoiceStatus::Sent]),
        ], InvoiceStatus::Draft->value],
        'customers' => [ListCustomers::class, 'approval_status', static fn (): array => [
            CustomerProfile::factory()->create(['approval_status' => CustomerApprovalStatus::Approved]),
            CustomerProfile::factory()->create(['approval_status' => CustomerApprovalStatus::Pending, 'is_active' => false]),
        ], CustomerApprovalStatus::Approved->value],
        'quotations' => [ListQuotations::class, 'status', static fn (): array => [
            Quotation::factory()->create(['status' => QuotationStatus::Draft]),
            Quotation::factory()->create(['status' => QuotationStatus::Accepted]),
        ], QuotationStatus::Draft->value],
        'orders' => [ListOrders::class, 'status', static fn (): array => [
            Order::factory()->draft()->create(),
            Order::factory()->confirmed()->create(),
        ], OrderStatus::Draft->value],
        'credit notes' => [ListCreditNotes::class, 'status', static fn (): array => [
            CreditNote::factory()->create(['status' => CreditNoteStatus::Draft]),
            CreditNote::factory()->create(['status' => CreditNoteStatus::Cancelled]),
        ], CreditNoteStatus::Draft->value],
        'delivery notes' => [ListDeliveryNotes::class, 'stage', static fn (): array => [
            InventoryOperation::factory()->delivery()->ready()->create(),
            InventoryOperation::factory()->delivery()->done()->create(),
        ], OperationStage::Ready->value],
        'payments' => [ListPayments::class, 'status', static fn (): array => [
            salesRolloutPayment(PaymentStatus::Draft),
            salesRolloutPayment(PaymentStatus::Reversed),
        ], PaymentStatus::Draft->value],
        'leads' => [ListLeads::class, 'status', static fn (): array => [
            salesRolloutLead(LeadStatus::New),
            salesRolloutLead(LeadStatus::Qualified),
        ], LeadStatus::New->value],
    ];
}

dataset('sales rollout pages', salesRolloutPages());

it('filters the list with a query-builder rule', function (string $page, string $field, Closure $records, string $value): void {
    [$wanted, $unwanted] = $records();

    Livewire::test($page)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$wanted, $unwanted])
        ->filterTable('queryBuilder', [
            'rules' => [
                'rule' => [
                    'type' => $field,
                    'data' => ['operator' => 'is', 'settings' => ['values' => [$value]]],
                ],
            ],
        ])
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
})->with('sales rollout pages');

it('stars a record and lists it under the Starred tab', function (string $page, string $field, Closure $records): void {
    [$starred, $other] = $records();

    Livewire::test($page)
        ->callTableColumnAction('is_favorited', $starred)
        ->call('selectTableView', 'preset', 'starred')
        ->assertSet('activeTab', 'starred')
        ->assertCanSeeTableRecords([$starred])
        ->assertCanNotSeeTableRecords([$other]);

    expect($starred->isFavoritedBy($this->admin))->toBeTrue()
        ->and($other->isFavoritedBy($this->admin))->toBeFalse();
})->with('sales rollout pages');

it('groups the list by its status column', function (string $page, string $field, Closure $records): void {
    [$first, $second] = $records();

    Livewire::test($page)
        ->set('tableGrouping', $field)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$first, $second]);
})->with('sales rollout pages');

it('groups by secondary labelled columns', function (string $page, string $group, Closure $record, Closure $title): void {
    $record();

    Livewire::test($page)
        ->set('tableGrouping', $group)
        ->assertSuccessful()
        ->assertSee($title());
})->with([
    'order payment status' => [
        ListOrders::class,
        'payment_status',
        static fn (): Order => Order::factory()->create(['payment_status' => OrderPaymentStatus::Paid]),
        OrderPaymentStatus::Paid->label(...),
    ],
    'credit note reason' => [
        ListCreditNotes::class,
        'reason_category',
        static fn (): CreditNote => CreditNote::factory()->create(['reason_category' => CreditNoteReason::SalesReturn]),
        CreditNoteReason::SalesReturn->label(...),
    ],
]);

it('labels every select constraint option', function (string $page): void {
    EmployeeProfile::factory()->create();

    $filter = Livewire::test($page)->instance()->getTable()->getFilter('queryBuilder');

    expect($filter)->toBeInstanceOf(QueryBuilder::class);

    $selects = collect($filter->getConstraints())->filter(static fn (object $constraint): bool => $constraint instanceof SelectConstraint);

    expect($selects)->not->toBeEmpty();

    foreach ($selects as $constraint) {
        expect($constraint->getOptions())->not->toBeEmpty()->each->toBeString();
    }
})->with([
    ListQuotations::class,
    ListOrders::class,
    ListCreditNotes::class,
    ListDeliveryNotes::class,
    ListPayments::class,
    ListLeads::class,
]);
