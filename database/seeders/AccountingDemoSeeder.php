<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CreditNoteReason;
use App\Enums\DashboardRole;
use App\Enums\UserType;
use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Refund;
use App\Models\SalesSetting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Payments\PaymentService;
use App\Services\Sales\CreditNoteService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * Gives the accounting module a walkable ledger: twelve monthly periods for the
 * current year, several posted entries, one reversal, and one draft awaiting
 * review — so every state the Journal Entries and Chart of Accounts screens can
 * show has a record behind it, exactly like {@see SupportDemoSeeder}.
 *
 * Every write goes through {@see FiscalPeriodService} and
 * {@see JournalPostingService}, the same services Filament's own actions call, so
 * entry numbers, resolved periods, reversal morphs, and audit rows are all
 * internally consistent. Nothing here bypasses a domain rule to fabricate a state
 * the services would refuse.
 *
 * Idempotent: each documented business event has a stable external number. That
 * lets a newly-added accounting surface be seeded into an existing installation
 * that already has the original chart-and-ledger demo records.
 */
final class AccountingDemoSeeder extends Seeder
{
    /**
     * Marks the seeded set. Its presence means the whole set is present.
     */
    private const string FLAGSHIP_DESCRIPTION = 'Owner capital injection';

    public function run(): void
    {
        // SalesPermissionSeeder is required here, not just via DatabaseSeeder's
        // ordering: seedAccountingDocuments() issues an invoice, a Sales
        // ability, through the billing officer role it grants.
        $this->call([AccountingPermissionSeeder::class, SalesPermissionSeeder::class, ChartOfAccountsSeeder::class]);

        $chief = $this->dashboardUser('chief.accountant@ierp.com', 'Nadia Haddad', DashboardRole::ChiefAccountant);
        $accountant = $this->dashboardUser('accountant@ierp.com', 'Omar Sabbagh', DashboardRole::Accountant);

        $periods = $this->seedMonthlyPeriods($chief);

        if (! JournalEntry::query()->where('description', self::FLAGSHIP_DESCRIPTION)->exists()) {
            $this->seedOpeningCapital($chief);
            $this->seedTradingEntries($accountant);
            $this->seedMispostingAndCorrection($chief, $accountant);
            $this->seedPendingDraft($accountant);
        }

        $this->seedAccountingDocuments($chief);

        // The oldest period is closed, so the Reopen action has a subject and a
        // backdated posting is genuinely refused (FR-016).
        app(FiscalPeriodService::class)->close($chief, $periods[0]);
    }

    private function seedAccountingDocuments(User $chief): void
    {
        // Issuing an invoice is a Sales ability (permissions.md §4): an
        // Accounting role, even Chief Accountant, is never granted it, so the
        // demo billing officer — not $chief — is the actor for that step.
        $billingOfficer = $this->dashboardUser('billing.officer@ierp.com', 'Layla Nasser', DashboardRole::BillingOfficer);
        // Confirming a credit note is a Sales Manager ability, not the Billing
        // Officer's — permissions.md §4 splits drafting a correction from
        // approving it.
        $salesManager = $this->dashboardUser('sales.manager@ierp.com', 'Rania Kassab', DashboardRole::SalesManager);

        $term = PaymentTerm::query()->firstOrCreate(
            ['name' => 'Net 30'],
            ['due_days' => 30, 'grace_days' => 5, 'is_default' => true],
        );

        $customerUser = User::query()->firstOrCreate(
            ['email' => 'bright.finance@ierp.com'],
            ['name' => 'Bright Orthodontics Finance', 'password' => Hash::make('password'), 'user_type' => UserType::Customer],
        );

        $customer = CustomerProfile::query()->firstOrNew(['customer_code' => 'CUST-DEMO-BRIGHT']);
        $customer->forceFill([
            'user_id' => $customerUser->getKey(),
            'company_name' => 'Bright Orthodontics',
            'email' => 'accounts@bright-orthodontics.example',
            'phone' => '+971 4 555 0140',
            'address' => 'Healthcare City, Dubai',
            'country' => 'AE',
            'city' => 'Dubai',
            'latitude' => 25.2285,
            'longitude' => 55.3273,
            'contact_is_self' => true,
            'default_payment_term_id' => $term->getKey(),
            'is_active' => true,
        ])->save();

        $supplier = Supplier::query()->firstOrCreate(
            ['code' => 'SUP-DEMO-MED'],
            [
                'name' => 'MedTech Supplies FZE',
                'email' => 'accounts@medtech-supplies.example',
                'phone' => '+971 4 555 0250',
                'address' => 'Dubai Silicon Oasis, Dubai',
                'is_active' => true,
            ],
        );

        $paymentMethod = PaymentMethod::query()->firstOrCreate(
            ['name' => 'Operating Bank Transfer'],
            [
                'type' => 'bank_transfer',
                'chart_account_id' => $this->accountId('1110'),
                'is_active' => true,
                'requires_proof' => true,
            ],
        );

        $thisMonth = CarbonImmutable::now()->startOfMonth();
        $documents = app(AccountingDocumentService::class);

        SalesSetting::current()->forceFill([
            'receivable_account_id' => $this->accountId('1200'),
            'revenue_account_id' => $this->accountId('4100'),
            'deferred_tax_account_id' => $this->accountId('2350'),
            'tax_payable_account_id' => $this->accountId('2300'),
            'customer_deposits_account_id' => $this->accountId('2400'),
        ])->save();

        $invoice = Invoice::query()->firstOrCreate(
            ['invoice_number' => 'INV-DEMO-2026-001'],
            [
                'customer_id' => $customer->getKey(),
                'invoice_date' => $thisMonth->addDays(4)->toDateString(),
                'due_date' => $thisMonth->addDays(34)->toDateString(),
                'description' => 'Digital scanner package',
                'subtotal' => '18400.00',
                'tax_total' => '920.00',
                'total_amount' => '19320.00',
                'amount_paid' => '0.00',
                'status' => 'draft',
            ],
        );

        if ($invoice->isDraft() && ! $invoice->lines()->exists()) {
            $invoice->lines()->create([
                'description' => 'Digital scanner package',
                'quantity' => '1.000',
                'unit_price' => '18400.00',
                'tax_amount' => '920.00',
                'line_total' => '19320.00',
                'sort_order' => 1,
            ]);
        }

        if ($invoice->isDraft()) {
            $invoice = $documents->issueInvoice($billingOfficer, $invoice);
        }

        // Collected in full so RefundService::availableCreditMinor() has
        // something to draw on once the standalone credit note below adds its
        // own credit — an unpaid invoice's claim otherwise swallows it whole.
        $customerPaymentMethod = PaymentMethod::query()->firstOrCreate(
            ['name' => 'Customer Bank Transfer'],
            [
                'type' => 'bank_transfer',
                'chart_account_id' => $this->accountId('1110'),
                'is_active' => true,
                'requires_proof' => false,
            ],
        );

        $payment = Payment::query()->where('external_reference', 'BANK-DEMO-2026-001')->first();

        if (! $payment instanceof Payment) {
            $paymentService = app(PaymentService::class);

            $payment = $paymentService->createDraft($billingOfficer, [
                'customer_id' => $customer->getKey(),
                'payment_method_id' => $customerPaymentMethod->getKey(),
                'amount' => $invoice->total_amount,
                'payment_date' => $thisMonth->addDays(6)->toDateString(),
                'external_reference' => 'BANK-DEMO-2026-001',
            ]);

            $paymentService->post($billingOfficer, $payment, [
                ['invoice_id' => $invoice->getKey(), 'amount' => $invoice->total_amount],
            ]);
        }

        $bill = Bill::query()->firstOrCreate(
            ['bill_number' => 'BILL-DEMO-2026-001'],
            [
                'supplier_id' => $supplier->getKey(),
                'expense_account_id' => $this->accountId('5400'),
                'bill_date' => $thisMonth->addDays(8)->toDateString(),
                'due_date' => $thisMonth->addDays(38)->toDateString(),
                'description' => 'Clinical equipment maintenance',
                'subtotal' => '3200.00',
                'tax_total' => '160.00',
                'total_amount' => '3360.00',
                'amount_paid' => '0.00',
            ],
        );

        if ($bill->isDraft() && ! $bill->lines()->exists()) {
            $bill->lines()->create([
                'chart_account_id' => $this->accountId('5400'),
                'description' => 'Clinical equipment maintenance',
                'quantity' => '1.000',
                'unit_price' => '3200.00',
                'tax_amount' => '160.00',
                'line_total' => '3200.00',
                'sort_order' => 1,
            ]);
        }

        if ($bill->isDraft()) {
            $documents->approveBill($chief, $bill);
        }

        $expense = Expense::query()->firstOrCreate(
            ['expense_number' => 'EXP-DEMO-2026-001'],
            [
                'supplier_id' => $supplier->getKey(),
                'expense_account_id' => $this->accountId('5300'),
                'expense_date' => $thisMonth->addDays(10)->toDateString(),
                'due_date' => $thisMonth->addDays(40)->toDateString(),
                'merchant_name' => 'MedTech Supplies FZE',
                'description' => 'Showroom rent',
                'subtotal' => '9000.00',
                'tax_total' => '0.00',
                'total_amount' => '9000.00',
                'amount_paid' => '0.00',
            ],
        );

        if ($expense->isDraft()) {
            $documents->approveExpense($chief, $expense);
        }

        // A standalone credit note (no source invoice) gives the customer
        // available credit, so the refund below has something to draw on
        // (RefundService::availableCreditMinor()).
        $creditNote = CreditNote::query()->firstOrCreate(
            ['credit_note_number' => 'CN-DEMO-2026-001'],
            [
                'customer_id' => $customer->getKey(),
                'reason' => 'Returned accessory credit',
                'reason_category' => CreditNoteReason::SalesReturn,
                'issue_date' => $thisMonth->addDays(14)->toDateString(),
                'subtotal' => '0.00',
                'tax_total' => '0.00',
                'grand_total' => '0.00',
                'status' => 'draft',
            ],
        );

        $creditNoteService = app(CreditNoteService::class);

        if ($creditNote->isDraft() && ! $creditNote->lines()->exists()) {
            $creditNoteService->addLine($billingOfficer, $creditNote, 'Returned scanner accessory', 1.0, 450.0, 0.0);
        }

        if ($creditNote->isDraft()) {
            $creditNoteService->confirm($salesManager, $creditNote);
        }

        $refund = Refund::query()->firstOrCreate(
            ['refund_number' => 'REF-DEMO-2026-001'],
            [
                'customer_id' => $customer->getKey(),
                'payment_method_id' => $paymentMethod->getKey(),
                'refund_date' => $thisMonth->addDays(15)->toDateString(),
                'amount' => '450.00',
                'reason' => 'Returned accessory credit',
            ],
        );

        if ($refund->isDraft()) {
            $documents->approveRefund($chief, $refund);
        }
    }

    private function seedOpeningCapital(User $chief): void
    {
        app(JournalPostingService::class)->postNew($chief, CarbonImmutable::now()->startOfYear()->addDays(2), [
            $this->debit('1110', '150000.00', 'Bank transfer received'),
            $this->credit('3100', '150000.00', 'Share capital issued'),
        ], self::FLAGSHIP_DESCRIPTION);
    }

    private function seedTradingEntries(User $accountant): void
    {
        $posting = app(JournalPostingService::class);
        $thisMonth = CarbonImmutable::now()->startOfMonth();

        $posting->postNew($accountant, $thisMonth->addDays(4), [
            $this->debit('1200', '18400.00', 'Invoice to Bright Orthodontics'),
            $this->credit('4100', '18400.00', 'Chair and scanner package'),
        ], 'Product sales invoiced');

        $posting->postNew($accountant, $thisMonth->addDays(9), [
            $this->debit('5200', '22750.00', 'Monthly payroll run'),
            $this->credit('1110', '22750.00', 'Paid from operating account'),
        ], 'Payroll for the month');
    }

    /**
     * A mistake, its reversal, and the corrected entry — so the Reverse action has
     * a subject and the reversed/reversal pair nets to zero (FR-028, SC-006).
     *
     * Reversed by the chief accountant rather than the accountant who posted it,
     * because the Accountant role deliberately lacks the reverse permission
     * (FR-040).
     */
    private function seedMispostingAndCorrection(User $chief, User $accountant): void
    {
        $posting = app(JournalPostingService::class);
        $thisMonth = CarbonImmutable::now()->startOfMonth();

        $misposted = $posting->postNew($accountant, $thisMonth->addDays(11), [
            $this->debit('5300', '9000.00', 'Booked to Rent by mistake'),
            $this->credit('1110', '9000.00', 'Paid from operating account'),
        ], 'Warehouse rent — misposted');

        $posting->reverse($chief, $misposted, $thisMonth->addDays(12));

        $posting->postNew($accountant, $thisMonth->addDays(12), [
            $this->debit('5400', '9000.00', 'Correctly booked to Utilities'),
            $this->credit('1110', '9000.00', 'Paid from operating account'),
        ], 'Utilities — corrected posting');
    }

    /**
     * Left unposted on purpose, so the Post action has a subject and the Draft
     * status has a row.
     */
    private function seedPendingDraft(User $accountant): void
    {
        app(JournalPostingService::class)->draft($accountant, CarbonImmutable::now(), [
            $this->debit('1300', '5600.00', 'Consumables received, awaiting invoice'),
            $this->credit('2100', '5600.00', 'Supplier accrual'),
        ], 'Stock accrual awaiting review');
    }

    /**
     * Twelve consecutive monthly periods for the current calendar year.
     *
     * Created through the service so the no-overlap rule (FR-015) is exercised
     * rather than assumed, and skipped individually when one already exists — a
     * period may have been created by another seeder or by hand.
     *
     * @return list<FiscalPeriod>
     */
    private function seedMonthlyPeriods(User $chief): array
    {
        $service = app(FiscalPeriodService::class);
        $month = CarbonImmutable::now()->startOfYear();
        $periods = [];

        for ($index = 0; $index < 12; $index++) {
            $start = $month->addMonthsNoOverflow($index);
            $existing = FiscalPeriod::query()->where('name', $start->format('F Y'))->first();

            $periods[] = $existing instanceof FiscalPeriod
                ? $existing
                : $service->create($chief, $start->format('F Y'), $start, $start->endOfMonth());
        }

        return $periods;
    }

    /**
     * @return array{chart_account_id: int, debit: string, credit: string, description: string}
     */
    private function debit(string $code, string $amount, string $description): array
    {
        return [
            'chart_account_id' => $this->accountId($code),
            'debit' => $amount,
            'credit' => '0.00',
            'description' => $description,
        ];
    }

    /**
     * @return array{chart_account_id: int, debit: string, credit: string, description: string}
     */
    private function credit(string $code, string $amount, string $description): array
    {
        return [
            'chart_account_id' => $this->accountId($code),
            'debit' => '0.00',
            'credit' => $amount,
            'description' => $description,
        ];
    }

    /**
     * Resolves a seeded account by its code.
     *
     * Throws rather than creating one: every code used above is a postable leaf
     * from {@see ChartOfAccountsSeeder}, so a miss means that chart changed and
     * the demo entries need revisiting — not that an account should be invented.
     */
    private function accountId(string $code): int
    {
        $id = ChartAccount::query()->where('code', $code)->value('id');

        if (! is_numeric($id)) {
            throw new LogicException(sprintf('The demo ledger expects a seeded account with code [%s].', $code));
        }

        return (int) $id;
    }

    private function dashboardUser(string $email, string $name, DashboardRole $role): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password'), 'user_type' => UserType::Admin],
        );

        if (! $user->hasRole($role->value)) {
            $user->assignRole($role->value);
        }

        return $user;
    }
}
